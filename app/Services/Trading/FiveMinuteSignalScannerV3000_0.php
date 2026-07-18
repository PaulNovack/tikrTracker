<?php

namespace App\Services\Trading;

use App\Services\GainersLosersAnalysisService;
use App\Services\Market\BestPerformers5mService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Version 3000.0 — Multi-Timeframe EMA Alignment Scanner (Strategy 3)
 *
 * Strategy:
 * - On the 5-minute chart, the 9 EMA must be above the 21 EMA.
 * - Serves as the first half of the two-timeframe confirmation.
 * - The 1-minute entry finder completes the trade by requiring the 1m EMA
 *   alignment AND entering on a pullback to the 5m 9 EMA.
 *
 * Universe: top performers (5d) + prior-day losers (bounce candidates).
 * Gates: 5m EMA 9 > EMA 21, liquidity (notional), activity (RVOL), move.
 */
class FiveMinuteSignalScannerV3000_0
{
    use HasPriceTables;

    private string $version = 'v3000.0';

    private string $name = 'Multi-Timeframe EMA Alignment';

    // ── Scanner Configuration ──
    public int $topDays = 5;

    public int $topLimit = 500;

    public int $losersLimit = 75;

    public float $minNotional5m = 75000;

    public float $minAtrPct5m = 0.35;

    public float $minRvol5m = 2.0;

    public float $minMove30m = 0.8;

    public float $minRsMultVsSpy = 1.20;

    public int $activeWindowMinutes = 6;

    // ── Entry Finder Configuration ──
    public float $entryMinNotional1m = 80000;

    public float $entryMinVolRatio1m = 1.0;

    public float $entryMinBodyPct1m = 0.05;

    public float $entryMaxAboveVwapPct = 1.2;

    public float $entryMinRoomToRunPct = 0.6;

    public float $entryRoomAtrMult = 1.5;

    public int $entryAllowLunch = 0;

    public int $entryMinBars = 15;

    public int $entryMaxAgeMinutes = 10;

    // Strategy-3 specific: pullback depth tolerance vs 5m EMA9
    public float $pullbackMaxDepthPct = 0.4;

    public float $reclaimMinStrengthPct = 0.05;

    /** @return array<string, mixed> */
    public function scanConfig(): array
    {
        return [
            'top_days' => $this->topDays,
            'top_limit' => $this->topLimit,
            'losers_limit' => $this->losersLimit,
            'min_notional_5m' => $this->minNotional5m,
            'min_atr_pct_5m' => $this->minAtrPct5m,
            'min_rvol_5m' => $this->minRvol5m,
            'min_move_30m_pct' => $this->minMove30m,
            'min_rs_mult_vs_spy' => $this->minRsMultVsSpy,
            'active_window_minutes' => $this->activeWindowMinutes,
            'entry_min_notional_1m' => $this->entryMinNotional1m,
            'entry_min_vol_ratio_1m' => $this->entryMinVolRatio1m,
            'entry_min_body_pct_1m' => $this->entryMinBodyPct1m,
            'entry_max_above_vwap_pct' => $this->entryMaxAboveVwapPct,
            'entry_min_room_to_run_pct' => $this->entryMinRoomToRunPct,
            'entry_room_atr_mult' => $this->entryRoomAtrMult,
            'entry_allow_lunch' => $this->entryAllowLunch,
            'entry_min_bars' => $this->entryMinBars,
            'entry_max_age_minutes' => $this->entryMaxAgeMinutes,
            'pullback_max_depth_pct' => $this->pullbackMaxDepthPct,
            'reclaim_min_strength_pct' => $this->reclaimMinStrengthPct,
        ];
    }

    public function __construct(
        private readonly BestPerformers5mService $bestPerformersService,
        private readonly GainersLosersAnalysisService $gainersLosersService
    ) {}

    public function getVersion(): string
    {
        return $this->version;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setFullTable(bool $full): void
    {
        $this->fiveMinuteTable = $full ? 'five_minute_prices_full' : 'five_minute_prices';
        $this->oneMinuteTable = $full ? 'one_minute_prices_full' : 'one_minute_prices';
        $this->bestPerformersService->setFullTable($full);
        $this->gainersLosersService->setFullTable($full);
    }

    /**
     * Scan for stocks where 5m EMA 9 > EMA 21 (multi-timeframe alignment first half).
     *
     * @param  string  $assetType  'stock' or 'crypto'
     * @param  string  $asOfTsEst  'YYYY-mm-dd HH:ii:ss' EST
     * @param  int  $lookbackMinutes  minimum lookback window
     * @param  float  $minMovePct  minimum 30m move gate
     * @param  float  $volMult  kept for compatibility; minRvol5m config is the gate
     * @param  int  $limit  max output signals
     * @return array<int, array>
     */
    public function scan(
        string $assetType,
        string $asOfTsEst,
        int $lookbackMinutes = 60,
        float $minMovePct = 1.2,
        float $volMult = 3.5,
        int $limit = 60
    ): array {
        $topDays = $this->topDays;
        $topLimit = $this->topLimit;
        $losersLimit = $this->losersLimit;

        $minNotional5m = $this->minNotional5m;
        $minAtrPct5m = $this->minAtrPct5m;
        $minRvol5m = $this->minRvol5m;
        $minMove30m = $this->minMove30m;

        $activeWindowMinutes = $this->activeWindowMinutes;
        $atrPeriod = 14;
        $rvolLookback = 20;
        $moveBars = 6; // 6*5m = 30m
        $minimumLookbackMinutes = max(5, ($moveBars + 1) * 5);
        $lookbackMinutes = max($lookbackMinutes, $minimumLookbackMinutes);

        // ---------- 1) Universe: top performers + prior-day losers ----------
        $tradeDate = substr($asOfTsEst, 0, 10);
        $symbols = $this->buildUniverseSymbols($assetType, $asOfTsEst);
        if (empty($symbols)) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($symbols), '?'));

        // ---------- 2) Compute gates on 5m ----------
        // Strategy 3: ema9_above_ema21 must be 1 on the latest bar.
        // Uses pre-computed columns (ema9_above_ema21, atr_pct) to avoid expensive CTE.
        $sql = '
SELECT
    f.symbol,
    f.asset_type,
    f.ts_est AS signal_ts_est,
    f.price AS last_close,
    f.volume AS last_vol,
    f.ema9,
    f.ema21,
    f.ema9_above_ema21,
    f.atr,
    f.atr_pct,
    f.open AS last_open,
    f.high,
    f.low,
    (f.price * f.volume) AS notional_last5m,
    ((f.price - f.open) / NULLIF(f.open, 0)) * 100 AS bar_move_pct
FROM five_minute_prices f
WHERE f.asset_type = ?
  AND f.trading_date_est = ?
  AND f.ts_est <= ?
  AND f.ts_est >= DATE_SUB(?, INTERVAL ? MINUTE)
  AND f.symbol IN ('.$placeholders.')
  AND f.ema9_above_ema21 = 1
  AND f.price IS NOT NULL
  AND f.price > 0
ORDER BY f.symbol, f.ts_est DESC
';

        $params = array_merge(
            [$assetType, $tradeDate, $asOfTsEst, $asOfTsEst, $lookbackMinutes],
            $symbols
        );

        $bucketTs = date('Y-m-d H:i', strtotime(floor(strtotime($asOfTsEst) / 300) * 300));
        $cacheKey = "scan_v3000_0_s3:{$assetType}:{$bucketTs}:{$lookbackMinutes}";
        $rows = Cache::get($cacheKey);
        if ($rows === null) {
            $lock = Cache::lock("lock:{$cacheKey}", 60);
            if ($lock->get()) {
                try {
                    $rows = $this->dbSelect($sql, $params);
                    Cache::put($cacheKey, $rows, 240);
                } finally {
                    $lock->release();
                }
            } else {
                $rows = Cache::get($cacheKey) ?? $this->dbSelect($sql, $params);
            }
        }

        $spyMove30m = $this->getSpyMovement30m($asOfTsEst, $assetType, $moveBars);

        $asOfEpochRaw = strtotime($asOfTsEst);
        $asOfEpoch = $asOfEpochRaw !== false
            ? strtotime(date('Y-m-d H:i:00', $asOfEpochRaw))
            : false;
        if ($asOfEpoch === false) {
            return [];
        }
        $maxSignalAgeSeconds = max(1, $activeWindowMinutes) * 60;

        $enableRsFilter = (bool) config('trading.enable_relative_strength_filter', false);
        $minRsMult = (float) config('trading.v3000.scanner.min_rs_mult_vs_spy', $this->minRsMultVsSpy);

        // Deduplicate: keep only the latest bar per symbol (ORDER BY ts_est DESC)
        $seen = [];
        $latest = [];
        foreach ($rows as $r) {
            $sym = (string) $r->symbol;
            if (! isset($seen[$sym])) {
                $seen[$sym] = true;
                $latest[] = $r;
            }
        }

        // Pre-compute RVOL and 30m move per symbol using a single bulk query
        $rvolData = [];
        $moveData = [];
        if (! empty($latest)) {
            // RVOL: pre-compute avg volume (last 20 bars per symbol) via a bulk query
            $rvolSql = '
SELECT symbol, AVG(volume) AS avg_vol
FROM (
    SELECT symbol, volume,
        ROW_NUMBER() OVER (PARTITION BY symbol ORDER BY ts_est DESC) AS rn
    FROM five_minute_prices
    WHERE asset_type = ?
      AND trading_date_est = ?
      AND ts_est <= ?
      AND symbol IN ('.implode(',', array_fill(0, count($latest), '?')).')
) sub
WHERE rn <= 20
GROUP BY symbol
';
            $rvolParams = array_merge([$assetType, $tradeDate, $asOfTsEst], array_keys($seen));
            $rvolRows = $this->dbSelect($rvolSql, $rvolParams);
            foreach ($rvolRows as $rv) {
                $rvolData[(string) $rv->symbol] = (float) ($rv->avg_vol ?? 0);
            }

            // 30m move: close vs close 6 bars ago
            $moveSql = '
SELECT symbol,
    MAX(CASE WHEN rn = 1 THEN price END) AS last_p,
    MAX(CASE WHEN rn = 7 THEN price END) AS p_6back
FROM (
    SELECT symbol, price,
        ROW_NUMBER() OVER (PARTITION BY symbol ORDER BY ts_est DESC) AS rn
    FROM five_minute_prices
    WHERE asset_type = ?
      AND trading_date_est = ?
      AND ts_est <= ?
      AND symbol IN ('.implode(',', array_fill(0, count($latest), '?')).')
) sub
WHERE rn <= 7
GROUP BY symbol
';
            $moveParams = array_merge([$assetType, $tradeDate, $asOfTsEst], array_keys($seen));
            $moveRows = $this->dbSelect($moveSql, $moveParams);
            foreach ($moveRows as $mv) {
                $lastP = (float) ($mv->last_p ?? 0);
                $p6back = (float) ($mv->p_6back ?? 0);
                $moveData[(string) $mv->symbol] = ($p6back > 0)
                    ? (($lastP - $p6back) / $p6back) * 100.0
                    : 0.0;
            }
        }

        $out = [];
        foreach ($latest as $r) {
            $sym = (string) $r->symbol;
            $signalAgeSeconds = $asOfEpoch - strtotime((string) $r->signal_ts_est);
            if ($signalAgeSeconds < 0 || $signalAgeSeconds > $maxSignalAgeSeconds) {
                continue;
            }

            $lastClose = (float) $r->last_close;
            if ($lastClose <= 0) {
                continue;
            }

            $atrPct = (float) ($r->atr_pct ?? 0);
            $lastVol = (float) ($r->last_vol ?? 0);
            $avgVol = $rvolData[$sym] ?? 0;
            $rvolRatio = $avgVol > 0 ? ($lastVol / $avgVol) : 1.0;
            $move30m = $moveData[$sym] ?? (float) ($r->bar_move_pct ?? 0);
            $notional = $lastClose * $lastVol;

            if ($notional < $minNotional5m) {
                continue;
            }

            if ($atrPct < $minAtrPct5m) {
                continue;
            }

            if (! ($rvolRatio >= $minRvol5m || $move30m >= $minMove30m)) {
                continue;
            }

            if ($move30m < $minMovePct) {
                continue;
            }

            if ($enableRsFilter && $spyMove30m > 0.10 && $move30m < $spyMove30m * $minRsMult) {
                continue;
            }

            $rvolCapped = min(6.0, $rvolRatio);
            $score = ($move30m * 1.2) + ($rvolCapped * 1.0) + ($atrPct * 0.8);

            $atrDollars = ($atrPct && $lastClose) ? round(($atrPct / 100) * $lastClose, 6) : null;

            $out[] = [
                'symbol' => (string) $r->symbol,
                'asset_type' => $assetType,
                'signal_type' => 'EMA_ALIGNMENT_V3000',
                'signal_ts_est' => (string) $r->signal_ts_est,
                'score' => round($score, 3),
                'atr' => $atrDollars,
                'atr_pct' => round($atrPct, 3),
                'meta' => [
                    'move_30m_pct' => round($move30m, 3),
                    'rvol_5m' => round($rvolRatio, 3),
                    'atr_pct_5m' => round($atrPct, 3),
                    'notional_last5m' => round($notional, 2),
                    'pct_nd' => null,
                    'spy_move_30m_pct' => round($spyMove30m, 3),
                    'universe_size' => count($symbols),
                    'signal_age_seconds' => $signalAgeSeconds,
                    'version' => $this->version,
                    'current_price' => $lastClose,
                    'ema9' => round((float) ($r->ema9 ?? 0), 6),
                    'ema21' => round((float) ($r->ema21 ?? 0), 6),
                    'ema9_above_ema21' => 1,
                ],
            ];
        }

        usort($out, fn ($a, $b) => ($b['score'] <=> $a['score']));

        return array_slice($out, 0, max(1, $limit));
    }

    /** @return array<int, string> */
    private function buildUniverseSymbols(string $assetType, string $asOfTsEst): array
    {
        $tradeDate = substr($asOfTsEst, 0, 10);
        $cacheKey = 'scan_v3000_0_s3_universe:'.implode(':', [
            $assetType,
            $this->fiveMinuteTable,
            $tradeDate,
            $this->topDays,
            $this->topLimit,
            $this->losersLimit,
        ]);

        $symbols = Cache::remember($cacheKey, 60, function () use ($assetType, $asOfTsEst, $tradeDate) {
            $topPerformers = $this->bestPerformersService->getBestPerformers([
                'assetType' => $assetType,
                'testDateTime' => $asOfTsEst,
                'days' => $this->topDays,
                'minBars' => 200,
                'minVol' => 0,
                'rthOnly' => true,
                'limit' => $this->topLimit,
                'tz' => 'America/New_York',
            ]);

            $baseSymbols = array_column($topPerformers, 'symbol');

            try {
                $prevTradingDay = DB::table($this->fiveMinuteTable)
                    ->where('asset_type', $assetType)
                    ->where('trading_date_est', '<', $tradeDate)
                    ->orderBy('trading_date_est', 'desc')
                    ->value('trading_date_est');

                if ($prevTradingDay) {
                    $losersData = $this->gainersLosersService->getGainersAndLosers($prevTradingDay, $assetType, $this->losersLimit);
                    $baseSymbols = array_merge($baseSymbols, array_column($losersData['losers'] ?? [], 'symbol'));
                }
            } catch (\Throwable $e) {
                // Universe should degrade gracefully; the scan gates still protect entries.
            }

            return $this->normalizeSymbols($baseSymbols);
        });

        return $this->normalizeSymbols($symbols);
    }

    /** @return array<int, string> */
    private function normalizeSymbols(array $symbols): array
    {
        $clean = [];
        foreach ($symbols as $symbol) {
            $symbol = strtoupper(trim((string) $symbol));
            if ($symbol !== '') {
                $clean[$symbol] = true;
            }
        }

        return array_keys($clean);
    }

    private function getSpyMovement30m(string $asOfTsEst, string $assetType, int $moveBars): float
    {
        if ($assetType !== 'stock') {
            return 0.0;
        }

        $benchmarkSymbol = config('trading.market_benchmark_symbol', 'QQQM');

        $sql = "
SELECT
  price AS last_close,
  LAG(price, ?) OVER (ORDER BY ts_est) AS prev_close
FROM five_minute_prices
WHERE symbol = ?
  AND asset_type = 'stock'
  AND ts_est <= ?
ORDER BY ts_est ASC
";
        $rows = $this->dbSelect($sql, [$moveBars, $benchmarkSymbol, $asOfTsEst]);
        if (! $rows) {
            return 0.0;
        }
        $last = end($rows);
        if (! $last || empty($last->prev_close)) {
            return 0.0;
        }
        $prev = (float) $last->prev_close;
        $lc = (float) $last->last_close;
        if ($prev <= 0) {
            return 0.0;
        }

        return (($lc - $prev) / $prev) * 100.0;
    }
}
