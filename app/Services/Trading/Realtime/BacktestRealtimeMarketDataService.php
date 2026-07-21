<?php

namespace App\Services\Trading\Realtime;

use Illuminate\Support\Facades\DB;

/**
 * Simulates the RealtimeMarketDataService for backtesting.
 *
 * Uses pre-loaded day data in memory (no per-symbol DB queries).
 *
 * Quotes are simulated using BacktestQuoteSimulatorService to avoid
 * "quote_stale" rejections and produce realistic bid/ask spreads.
 */
class BacktestRealtimeMarketDataService extends RealtimeMarketDataService
{
    private string $simulatedNow = '';

    private array $allBarsForDay = [];

    private BacktestQuoteSimulatorService $quoteSimulator;

    public function getSimulatedNow(): string
    {
        return $this->simulatedNow;
    }

    public function __construct(?string $table = null)
    {
        set_time_limit(0);

        $this->maxQuoteAge = 60;
        $this->oneMinTable = $table ?? (string) config('trading_realtime.tables.one_minute_prices', 'one_minute_prices');
        $this->quoteSimulator = new BacktestQuoteSimulatorService;
    }

    public function setSimulatedNow(string $estTimestamp): void
    {
        $this->simulatedNow = $estTimestamp;
    }

    public function clearCache(): void
    {
        $this->quoteCache = [];
        $this->barCache = [];
        $this->recentBarCache = [];
    }

    /**
     * Load all bars for all symbols for a specific trading day into memory.
     * This eliminates per-symbol DB queries during time point iteration.
     */
    public function loadAllBarsForDay(string $tradingDate, array $symbols): void
    {
        if (empty($symbols)) {
            return;
        }

        $this->allBarsForDay = [];

        $rows = DB::table($this->oneMinTable)
            ->select([
                'symbol', 'ts_est', 'open', 'high', 'low', 'price as close', 'volume',
                'vwap', 'vwap_dist_pct', 'above_vwap', 'ema9', 'ema21',
                'ema9_above_ema21', 'atr_pct',
            ])
            ->where('trading_date_est', $tradingDate)
            ->whereIn('symbol', array_map('strtoupper', $symbols))
            ->orderBy('symbol')
            ->orderBy('ts_est')
            ->get();

        // Index by symbol, then store all bars chronologically
        foreach ($rows as $row) {
            $sym = strtoupper((string) $row->symbol);
            if (! isset($this->allBarsForDay[$sym])) {
                $this->allBarsForDay[$sym] = [];
            }
            $this->allBarsForDay[$sym][] = $row;
        }
    }

    /**
     * Get the latest bar for a symbol as of simulated time, using pre-loaded day data.
     */
    public function latestBarForSymbol(string $symbol): ?array
    {
        $sym = strtoupper($symbol);
        $asOf = $this->simulatedNow;

        // If we have day data loaded, use memory (no DB query)
        if (! empty($this->allBarsForDay[$sym])) {
            $bars = $this->allBarsForDay[$sym];
            $latestRow = null;
            foreach ($bars as $bar) {
                if ((string) $bar->ts_est <= $asOf) {
                    $latestRow = $bar;
                } else {
                    break;
                }
            }

            if ($latestRow) {
                $close = (float) $latestRow->close;
                $volume = (int) ($latestRow->volume ?? 0);

                // Compute RVOL from bars BEFORE this one in the current day
                $prevVolumes = [];
                foreach ($bars as $b) {
                    if ((string) $b->ts_est >= (string) $latestRow->ts_est) {
                        break;
                    }
                    $prevVolumes[] = (int) $b->volume;
                }
                $avgVol = ! empty($prevVolumes) ? array_sum($prevVolumes) / count($prevVolumes) : 0;
                $rvol = $avgVol > 0 ? round($volume / $avgVol, 4) : 1.0;

                // Compute move_30m_pct: ~6 bars before (30 min)
                $move30mPct = 0;
                $targetIdx = count($prevVolumes) - 6;
                if ($targetIdx >= 0 && $targetIdx < count($bars)) {
                    $oldOpen = (float) ($bars[$targetIdx]->open ?? $bars[$targetIdx]->close ?? 0);
                    if ($oldOpen > 0) {
                        $move30mPct = round((($close - $oldOpen) / $oldOpen) * 100, 4);
                    }
                }

                $bar = [
                    'symbol' => $sym,
                    'start_ts_est' => (string) $latestRow->ts_est,
                    'updated_ts_est' => (string) $latestRow->ts_est,
                    'open' => (float) $latestRow->open,
                    'high' => (float) ($latestRow->high ?? $close),
                    'low' => (float) ($latestRow->low ?? $close),
                    'close' => $close,
                    'volume' => $volume,
                    'vwap' => isset($latestRow->vwap) ? (float) $latestRow->vwap : null,
                    'vwap_dist_pct' => isset($latestRow->vwap_dist_pct) ? (float) $latestRow->vwap_dist_pct : null,
                    'above_vwap' => isset($latestRow->above_vwap) ? (int) $latestRow->above_vwap : null,
                    'ema9' => isset($latestRow->ema9) ? (float) $latestRow->ema9 : null,
                    'ema21' => isset($latestRow->ema21) ? (float) $latestRow->ema21 : null,
                    'ema9_above_ema21' => isset($latestRow->ema9_above_ema21) ? (int) $latestRow->ema9_above_ema21 : null,
                    'atr_pct' => isset($latestRow->atr_pct) ? (float) $latestRow->atr_pct : null,
                    'rvol' => $rvol,
                    'avg_vol_20' => round($avgVol, 2),
                    'move_30m_pct' => $move30mPct,
                ];

                $this->barCache[$sym] = $bar;

                // Simulate a realistic quote that appears "live" at the simulated time
                $this->quoteCache[$sym] = $this->quoteSimulator->simulateQuote($bar, $this->simulatedNow);

                return $bar;
            }
        }

        // Fallback to DB query (for dispatch-jobs mode or data outside loaded range)
        $row = DB::table($this->oneMinTable)
            ->select([
                'symbol', 'ts_est', 'open', 'high', 'low', 'price as close', 'volume',
                'vwap', 'vwap_dist_pct', 'above_vwap', 'ema9', 'ema21',
                'ema9_above_ema21', 'atr_pct',
            ])
            ->where('symbol', $sym)
            ->where('ts_est', '<=', $asOf)
            ->orderByDesc('ts_est')
            ->limit(1)
            ->first();

        if (! $row) {
            return null;
        }

        return $this->formatBar($row, $asOf);
    }

    private function formatBar($row, string $asOf): array
    {
        $close = (float) $row->close;
        $open = (float) $row->open;

        $bar = [
            'symbol' => strtoupper((string) $row->symbol),
            'start_ts_est' => (string) $row->ts_est,
            'updated_ts_est' => (string) $row->ts_est,
            'open' => $open,
            'high' => (float) ($row->high ?? $close),
            'low' => (float) ($row->low ?? $close),
            'close' => $close,
            'volume' => (int) ($row->volume ?? 0),
            'vwap' => isset($row->vwap) ? (float) $row->vwap : null,
            'vwap_dist_pct' => isset($row->vwap_dist_pct) ? (float) $row->vwap_dist_pct : null,
            'above_vwap' => isset($row->above_vwap) ? (int) $row->above_vwap : null,
            'ema9' => isset($row->ema9) ? (float) $row->ema9 : null,
            'ema21' => isset($row->ema21) ? (float) $row->ema21 : null,
            'ema9_above_ema21' => isset($row->ema9_above_ema21) ? (int) $row->ema9_above_ema21 : null,
            'atr_pct' => isset($row->atr_pct) ? (float) $row->atr_pct : null,
            'rvol' => null,
            'avg_vol_20' => null,
            'move_30m_pct' => null,
        ];

        if (empty($bar['vwap'])) {
            $bar['vwap'] = round(($bar['high'] + $bar['low'] + $close) / 3, 4);
        }

        // Compute RVOL and move_30m_pct from memory data if available
        $sym = $bar['symbol'];
        $bars = $this->allBarsForDay[$sym] ?? [];

        // RVOL: avg volume of bars before this one
        $prevVolumes = [];
        foreach ($bars as $b) {
            if ((string) $b->ts_est >= (string) $row->ts_est) {
                break;
            }
            $prevVolumes[] = (int) $b->volume;
        }
        $avg = ! empty($prevVolumes) ? array_sum($prevVolumes) / count($prevVolumes) : 0;
        $vol = (int) ($bar['volume'] ?? 0);
        $bar['avg_vol_20'] = round($avg, 2);
        $bar['rvol'] = $avg > 0 ? round($vol / $avg, 4) : 1.0;

        // move_30m_pct: ~6 bars before
        if (count($bars) >= 6) {
            $targetIdx = count($prevVolumes) - 6;
            if ($targetIdx >= 0 && $targetIdx < count($prevVolumes)) {
                $oldOpen = (float) ($bars[$targetIdx]->open ?? $bars[$targetIdx]->close ?? 0);
                if ($oldOpen > 0) {
                    $bar['move_30m_pct'] = round((($close - $oldOpen) / $oldOpen) * 100, 4);
                }
            }
        }

        // Update caches
        $sym = $bar['symbol'];
        $this->quoteCache[$sym] = [
            'symbol' => $sym,
            'bid' => $close,
            'ask' => $close,
            'bid_qty' => 100,
            'ask_qty' => 100,
            'ts_est' => (string) $row->ts_est,
        ];
        $this->barCache[$sym] = $bar;

        return $bar;
    }

    public function latestQuote(string $symbol): ?array
    {
        $sym = strtoupper($symbol);
        if (array_key_exists($sym, $this->quoteCache)) {
            return $this->quoteCache[$sym];
        }

        // Lazy-load from DB if not warmed up
        $this->latestBarForSymbol($sym);

        return $this->quoteCache[$sym] ?? null;
    }

    public function latestPartialOneMinuteBar(string $symbol): ?array
    {
        $sym = strtoupper($symbol);
        if (array_key_exists($sym, $this->barCache)) {
            return $this->barCache[$sym];
        }

        // Lazy-load from DB if not warmed up
        $this->latestBarForSymbol($sym);

        return $this->barCache[$sym] ?? null;
    }

    /**
     * Override to serve recent bars from pre-loaded day data instead of DB.
     * Returns bars ordered oldest→newest (same as parent), so callers get
     * the last N bars as of the simulated time.
     */
    public function recentOneMinuteBars(string $symbol, int $limit = 5): array
    {
        $sym = strtoupper($symbol);

        if (array_key_exists($sym, $this->recentBarCache)) {
            $cached = $this->recentBarCache[$sym];
            $sliced = array_slice($cached, 0, $limit);

            return array_reverse($sliced);
        }

        // Serve from pre-loaded day data when available
        $bars = $this->allBarsForDay[$sym] ?? [];
        if (empty($bars)) {
            return parent::recentOneMinuteBars($symbol, $limit);
        }

        // Collect bars up to the simulated time
        $recentBars = [];
        foreach ($bars as $bar) {
            if ((string) $bar->ts_est > $this->simulatedNow) {
                break;
            }
            $arr = (array) $bar;
            // Ensure both 'price' and 'close' keys exist (loadAllBarsForDay aliases price→close)
            if (! isset($arr['price']) && isset($arr['close'])) {
                $arr['price'] = $arr['close'];
            }
            $recentBars[] = $arr;
        }

        // Take the last N bars (most recent)
        $recentBars = array_slice($recentBars, -$limit);

        // Cache for repeat lookups within the same time point
        $this->recentBarCache[$sym] = $recentBars;

        // Return oldest→newest (same order as parent: array_reverse of DESC query)
        return $recentBars;
    }

    public function quoteAgeSeconds(array $quote): ?int
    {
        return 0;
    }

    public function putQuote(array $quote): void {}

    public function putPartialOneMinuteBar(array $bar): void {}

    public function quoteKey(string $symbol): string
    {
        return 'backtest:quote:'.strtoupper($symbol);
    }

    public function partialBarKey(string $symbol): string
    {
        return 'backtest:bar:'.strtoupper($symbol);
    }
}
