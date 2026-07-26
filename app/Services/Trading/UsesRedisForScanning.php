<?php

namespace App\Services\Trading;

use App\Repositories\RedisBarRepository;

/**
 * Trait UsesRedisForScanning - Redis data source for signal scanners.
 *
 * Replaces the SQL-based doScan() with one that reads completed 5-minute bars
 * from Redis sorted sets (rt:bars:5m:{date}:stock:{symbol}), eliminating MySQL
 * queries from the realtime scanning path.
 *
 * Each pipeline has its own .env toggle:
 *   TRADING_V25_SCANNER_USE_REDIS=true   → Pipeline H (v25.2)
 *   TRADING_V27_SCANNER_USE_REDIS=false  → Pipeline Q (v27.0)  (default off)
 *   etc.
 *
 * When disabled, the parent SQL-based doScan() runs as usual.
 */
trait UsesRedisForScanning
{
    private ?RedisBarRepository $_redisRepo = null;

    private function redisRepo(): RedisBarRepository
    {
        return $this->_redisRepo ??= new RedisBarRepository;
    }

    /**
     * Check whether Redis scanning is enabled for this pipeline.
     *
     * Reads config: trading.{version}.scanner.use_redis
     * e.g. trading.v25.scanner.use_redis → env TRADING_V25_SCANNER_USE_REDIS
     */
    protected function shouldUseRedis(): bool
    {
        $version = $this->getVersion();
        $pipeline = explode('.', $version)[0];

        return (bool) config("trading.{$pipeline}.scanner.use_redis", false);
    }

    protected function doScan(
        string $asOfTsEst,
        int $lookbackMinutes,
        float $minMovePct,
        float $volMult,
        int $limit,
        bool $skipCache,
        ?string $symbol = null
    ): array {
        if (! $this->shouldUseRedis()) {
            return parent::doScan(...func_get_args());
        }

        $symbols = $this->buildIntradayUniverse(
            'scan_'.str_replace('.', '_', $this->getVersion()).':universe_symbols',
            'trading.market_movers.pipeline_h',
            $asOfTsEst,
            $skipCache
        );

        if ($symbols === []) {
            return [];
        }

        // ── Config ──
        $cfg = $this->scanConfig();
        $atrPeriod = (int) ($cfg['atr_period_5m'] ?? 14);
        $rvolLookback = (int) ($cfg['rvol_lookback_5m'] ?? 20);
        $moveBars = (int) ($cfg['move_bars_5m'] ?? 6);
        $activeWindowMinutes = (int) ($cfg['active_window_minutes'] ?? 6);
        $minNotional5m = (float) ($cfg['min_notional_5m'] ?? 75000);
        $minAtrPct5m = (float) ($cfg['min_atr_pct_5m'] ?? 0.35);
        $minRvol5m = (float) ($cfg['min_rvol_5m'] ?? 2.0);
        $minMove30m = (float) ($cfg['min_move_30m_pct'] ?? 1.2);

        $minimumLookbackMinutes = max(5, ($moveBars + 1) * 5);
        $lookbackMinutes = max($lookbackMinutes, $minimumLookbackMinutes);
        $barsNeeded = max($moveBars, $rvolLookback, $atrPeriod) + 10;

        // ── Read 5m bars from Redis for all symbols ──
        $allBars = $this->redisRepo()->getLatestBars('5m', $symbols, 'stock', $asOfTsEst, $barsNeeded);

        // ── Benchmark ──
        $spyMove30m = $this->getSpyMovement30m($asOfTsEst, $moveBars);

        $asOfEpochRaw = strtotime($asOfTsEst);
        $asOfEpoch = $asOfEpochRaw !== false
            ? strtotime(date('Y-m-d H:i:00', $asOfEpochRaw))
            : false;
        if ($asOfEpoch === false) {
            return [];
        }
        $maxSignalAgeSeconds = max(1, $activeWindowMinutes) * 60;

        $out = [];
        foreach ($allBars as $sym => $bars) {
            if (count($bars) < $moveBars + 2) {
                continue;
            }

            $barCount = count($bars);
            $last = $bars[$barCount - 1];
            $lastClose = $last->close;
            $lastVol = $last->volume;
            $lastTsEst = $last->tsEst;

            // Age check
            $signalAgeSeconds = $asOfEpoch - (int) strtotime($lastTsEst.' EST');
            if ($signalAgeSeconds < 0 || $signalAgeSeconds > $maxSignalAgeSeconds) {
                continue;
            }
            if ($lastClose <= 0) {
                continue;
            }

            // 30m move vs N bars ago
            $nbackIdx = $barCount - 1 - $moveBars;
            $closeNback = $nbackIdx >= 0 ? $bars[$nbackIdx]->close : null;
            $move30m = ($closeNback !== null && $closeNback > 0)
                ? (($lastClose - $closeNback) / $closeNback) * 100
                : 0.0;

            // RVOL: last volume / average volume over lookback
            $volSlice = array_slice($bars, max(0, $barCount - $rvolLookback), $rvolLookback);
            $avgVol = count($volSlice) > 0
                ? array_sum(array_map(static fn ($b): float => $b->volume, $volSlice)) / count($volSlice)
                : 0.0;
            $rvolRatio = $avgVol > 0 ? $lastVol / $avgVol : 0.0;

            // ATR
            $atrBars = array_slice($bars, max(0, $barCount - $atrPeriod - 1));
            $trs = [];
            for ($i = 1, $n = count($atrBars); $i < $n; $i++) {
                $prevC = $atrBars[$i - 1]->close;
                $h = $atrBars[$i]->high;
                $l = $atrBars[$i]->low;
                $trs[] = max($h - $l, abs($h - $prevC), abs($l - $prevC));
            }
            $atrVal = count($trs) > 0 ? array_sum($trs) / count($trs) : 0.0;
            $atrPct = $lastClose > 0 ? ($atrVal / $lastClose) * 100 : 0.0;

            // Notional
            $notional = $lastClose * $lastVol;

            // ── Gates ──
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

            // RS filter
            $enableRsFilter = (bool) config('trading.enable_relative_strength_filter', false);
            if ($enableRsFilter && $spyMove30m > 0.10) {
                $minRsMult = (float) ($cfg['min_rs_mult_vs_spy'] ?? 1.10);
                if ($move30m < $spyMove30m * $minRsMult) {
                    continue;
                }
            }

            // ── Score ──
            $rvolCapped = min(6.0, $rvolRatio);
            $score = ($move30m * 1.2) + ($rvolCapped * 1.0) + ($atrPct * 0.8);

            $atr = ($atrPct !== 0.0 && $lastClose > 0) ? round(($atrPct / 100) * $lastClose, 6) : null;

            $out[] = [
                'symbol' => $sym,
                'signal_type' => 'MOMO_5M_'.strtoupper(str_replace('.', '', $this->getVersion())),
                'signal_ts_est' => $lastTsEst,
                'score' => round($score, 3),
                'atr' => $atr,
                'atr_pct' => $atrPct,
                'meta' => [
                    'move_30m_pct' => round($move30m, 3),
                    'rvol_5m' => round($rvolRatio, 3),
                    'atr_pct_5m' => round($atrPct, 3),
                    'notional_last5m' => round($notional, 2),
                    'pct_nd' => null,
                    'spy_move_30m_pct' => round($spyMove30m, 3),
                    'universe_size' => count($symbols),
                    'signal_age_seconds' => $signalAgeSeconds,
                    'version' => $this->getVersion(),
                    'current_price' => $lastClose,
                ],
            ];
        }

        usort($out, static fn (array $a, array $b): int => ($b['score'] <=> $a['score']));

        return array_slice($out, 0, max(1, $limit));
    }

    /**
     * Scan a single symbol triggered by a bar event (event-driven path).
     *
     * Reads the latest 5m bars for one symbol from Redis, computes metrics,
     * applies gates, and returns a signal array or null if it doesn't pass.
     *
     * @return array<string, mixed>|null Signal array or null if no signal
     */
    public function scanSymbol(string $symbol, string $asOfTsEst): ?array
    {
        $cfg = $this->scanConfig();
        $atrPeriod = (int) ($cfg['atr_period_5m'] ?? 14);
        $rvolLookback = (int) ($cfg['rvol_lookback_5m'] ?? 20);
        $moveBars = (int) ($cfg['move_bars_5m'] ?? 6);
        $activeWindowMinutes = (int) ($cfg['active_window_minutes'] ?? 6);
        $minNotional5m = (float) ($cfg['min_notional_5m'] ?? 75000);
        $minAtrPct5m = (float) ($cfg['min_atr_pct_5m'] ?? 0.35);
        $minRvol5m = (float) ($cfg['min_rvol_5m'] ?? 2.0);
        $minMove30m = (float) ($cfg['min_move_30m_pct'] ?? 1.2);

        $barsNeeded = max($moveBars, $rvolLookback, $atrPeriod) + 10;
        $bars = $this->redisRepo()->getBars('5m', $symbol, 'stock', date('Y-m-d H:i:s', strtotime($asOfTsEst) - ($barsNeeded * 300)), $asOfTsEst, $barsNeeded);

        if (count($bars) < $moveBars + 2) {
            return null;
        }

        $barCount = count($bars);
        $last = $bars[$barCount - 1];
        $lastClose = $last->close;
        $lastVol = $last->volume;
        $lastTsEst = $last->tsEst;

        $asOfEpoch = strtotime($asOfTsEst);
        $signalAgeSeconds = $asOfEpoch - (int) strtotime($lastTsEst.' EST');
        $maxSignalAgeSeconds = max(1, $activeWindowMinutes) * 60;
        if ($signalAgeSeconds < 0 || $signalAgeSeconds > $maxSignalAgeSeconds || $lastClose <= 0) {
            return null;
        }

        // 30m move
        $nbackIdx = $barCount - 1 - $moveBars;
        $closeNback = $nbackIdx >= 0 ? $bars[$nbackIdx]->close : null;
        $move30m = ($closeNback !== null && $closeNback > 0)
            ? (($lastClose - $closeNback) / $closeNback) * 100 : 0.0;

        // RVOL
        $volSlice = array_slice($bars, max(0, $barCount - $rvolLookback), $rvolLookback);
        $avgVol = count($volSlice) > 0
            ? array_sum(array_map(static fn ($b): float => $b->volume, $volSlice)) / count($volSlice) : 0.0;
        $rvolRatio = $avgVol > 0 ? $lastVol / $avgVol : 0.0;

        // ATR
        $atrBars = array_slice($bars, max(0, $barCount - $atrPeriod - 1));
        $trs = [];
        for ($i = 1, $n = count($atrBars); $i < $n; $i++) {
            $prevC = $atrBars[$i - 1]->close;
            $h = $atrBars[$i]->high;
            $l = $atrBars[$i]->low;
            $trs[] = max($h - $l, abs($h - $prevC), abs($l - $prevC));
        }
        $atrVal = count($trs) > 0 ? array_sum($trs) / count($trs) : 0.0;
        $atrPct = $lastClose > 0 ? ($atrVal / $lastClose) * 100 : 0.0;
        $notional = $lastClose * $lastVol;

        // Gates
        if ($notional < $minNotional5m) {
            return null;
        }
        if ($atrPct < $minAtrPct5m) {
            return null;
        }
        if (! ($rvolRatio >= $minRvol5m || $move30m >= $minMove30m)) {
            return null;
        }

        $score = ($move30m * 1.2) + (min(6.0, $rvolRatio) * 1.0) + ($atrPct * 0.8);
        $atr = ($atrPct !== 0.0 && $lastClose > 0) ? round(($atrPct / 100) * $lastClose, 6) : null;

        return [
            'symbol' => $symbol,
            'signal_type' => 'MOMO_5M_'.strtoupper(str_replace('.', '', $this->getVersion())),
            'signal_ts_est' => $lastTsEst,
            'score' => round($score, 3),
            'atr' => $atr,
            'atr_pct' => $atrPct,
            'meta' => [
                'move_30m_pct' => round($move30m, 3),
                'rvol_5m' => round($rvolRatio, 3),
                'atr_pct_5m' => round($atrPct, 3),
                'notional_last5m' => round($notional, 2),
                'pct_nd' => null,
                'spy_move_30m_pct' => 0.0,
                'universe_size' => 1,
                'signal_age_seconds' => $signalAgeSeconds,
                'version' => $this->getVersion(),
                'current_price' => $lastClose,
            ],
        ];
    }
}
