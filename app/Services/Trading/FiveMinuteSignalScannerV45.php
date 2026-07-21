<?php

namespace App\Services\Trading;

use App\Services\GainersLosersAnalysisService;
use App\Services\Market\BestPerformers5mService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Version 45 - Five-minute scanner for a confirmed VWAP higher-low entry.
 *
 * This scanner does not try to predict the one-minute entry. It finds orderly,
 * liquid intraday uptrends that are suitable for a controlled pullback toward
 * VWAP followed by a higher-low confirmation. Recent three-bar activity is
 * used so a low-volume pullback is not rejected simply because its latest bar
 * has less volume than the preceding impulse.
 *
 * Pattern passed to OneMinuteEntryFinderV45:
 * - EMA9 above EMA21
 * - price above session VWAP, but not excessively extended
 * - positive 30-minute progress with bounded volatility
 * - meaningful recent three-bar RVOL and notional liquidity
 * - orderly recent five-minute price action
 */
class FiveMinuteSignalScannerV45
{
    use HasPriceTables;

    private string $version = 'v45';

    private string $name = 'Confirmed VWAP Higher Low';

    // Scanner defaults. All can be overridden under trading.v45.scanner.
    public float $minNotional5m = 75000.0;

    public float $minAtrPct5m = 0.20;

    public float $maxAtrPct5m = 2.00;

    public float $minRvol5m = 1.05;

    public float $minMove30m = 0.30;

    public float $maxMove30m = 6.00;

    public float $maxAboveVwapPct = 3.00;

    // Require an actual recent pullback so the immediate entry check is aligned
    // with pipelines that do not keep scanner candidates on a watch list.
    public float $minPullbackFromRecentHighPct = 0.00;

    public float $maxPullbackFromRecentHighPct = 3.00;

    public float $minGreenBarPct = 40.0;

    public float $minNetProgress = 0.05;

    public int $maxDirectionalChanges = 5;

    public float $maxDistanceFromEma9Atr = 2.50;

    public int $activeWindowMinutes = 10;

    public int $analysisLookbackMinutes = 120;

    // Entry defaults exposed for pipeline/config discovery.
    public float $entryMinNotional1m = 50000.0;

    public float $entryMinConfirmationVolRatio = 0.85;

    public float $entryMinImpulsePct = 0.40;

    public float $entryMinImpulseAtr = 0.90;

    public float $entryMinPullbackPct = 8.0;

    public float $entryMaxPullbackPct = 70.0;

    public int $entryMinPullbackBars = 2;

    public int $entryMaxPullbackBars = 6;

    public float $entryMaxPullbackVolumeRatio = 1.00;

    public float $entryMinStopPct = 0.35;

    public float $entryMaxStopPct = 1.00;

    public float $entryMinRewardRisk = 1.50;

    public int $entryMaxAgeMinutes = 4;

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

    /** @return array<string, mixed> */
    public function scanConfig(): array
    {
        return [
            'min_notional_5m' => $this->minNotional5m,
            'min_atr_pct_5m' => $this->minAtrPct5m,
            'max_atr_pct_5m' => $this->maxAtrPct5m,
            'min_rvol_5m' => $this->minRvol5m,
            'min_move_30m_pct' => $this->minMove30m,
            'max_move_30m_pct' => $this->maxMove30m,
            'max_above_vwap_pct' => $this->maxAboveVwapPct,
            'min_pullback_from_recent_high_pct' => $this->minPullbackFromRecentHighPct,
            'max_pullback_from_recent_high_pct' => $this->maxPullbackFromRecentHighPct,
            'min_green_bar_pct' => $this->minGreenBarPct,
            'min_net_progress' => $this->minNetProgress,
            'max_directional_changes' => $this->maxDirectionalChanges,
            'max_distance_from_ema9_atr' => $this->maxDistanceFromEma9Atr,
            'active_window_minutes' => $this->activeWindowMinutes,
            'analysis_lookback_minutes' => $this->analysisLookbackMinutes,
            'entry_min_notional_1m' => $this->entryMinNotional1m,
            'entry_min_confirmation_vol_ratio' => $this->entryMinConfirmationVolRatio,
            'entry_min_impulse_pct' => $this->entryMinImpulsePct,
            'entry_min_impulse_atr' => $this->entryMinImpulseAtr,
            'entry_min_pullback_pct' => $this->entryMinPullbackPct,
            'entry_max_pullback_pct' => $this->entryMaxPullbackPct,
            'entry_min_pullback_bars' => $this->entryMinPullbackBars,
            'entry_max_pullback_bars' => $this->entryMaxPullbackBars,
            'entry_max_pullback_volume_ratio' => $this->entryMaxPullbackVolumeRatio,
            'entry_min_stop_pct' => $this->entryMinStopPct,
            'entry_max_stop_pct' => $this->entryMaxStopPct,
            'entry_min_reward_risk' => $this->entryMinRewardRisk,
            'entry_max_age_minutes' => $this->entryMaxAgeMinutes,
        ];
    }

    /**
     * Keep the existing pipeline method signature.
     *
     * @return array<int, array<string, mixed>>
     */
    public function scan(
        string $assetType,
        string $asOfTsEst,
        int $lookbackMinutes = 60,
        float $minMovePct = 0.30,
        float $volMult = 1.05,
        int $limit = 60,
        bool $skipCache = false
    ): array {
        $cfg = (array) config('trading.v45.scanner', []);

        $minNotional5m = (float) ($cfg['min_notional_5m'] ?? $this->minNotional5m);
        $minAtrPct5m = (float) ($cfg['min_atr_pct_5m'] ?? $this->minAtrPct5m);
        $maxAtrPct5m = (float) ($cfg['max_atr_pct_5m'] ?? $this->maxAtrPct5m);
        $minRvol5m = max($volMult, (float) ($cfg['min_rvol_5m'] ?? $this->minRvol5m));
        $minMove30m = max($minMovePct, (float) ($cfg['min_move_30m_pct'] ?? $this->minMove30m));
        $maxMove30m = (float) ($cfg['max_move_30m_pct'] ?? $this->maxMove30m);
        $maxAboveVwapPct = (float) ($cfg['max_above_vwap_pct'] ?? $this->maxAboveVwapPct);
        $minPullbackFromRecentHighPct = (float) ($cfg['min_pullback_from_recent_high_pct'] ?? $this->minPullbackFromRecentHighPct);
        $maxPullbackFromRecentHighPct = (float) ($cfg['max_pullback_from_recent_high_pct'] ?? $this->maxPullbackFromRecentHighPct);
        $minGreenBarPct = (float) ($cfg['min_green_bar_pct'] ?? $this->minGreenBarPct);
        $minNetProgress = (float) ($cfg['min_net_progress'] ?? $this->minNetProgress);
        $maxDirectionalChanges = (int) ($cfg['max_directional_changes'] ?? $this->maxDirectionalChanges);
        $maxDistanceFromEma9Atr = (float) ($cfg['max_distance_from_ema9_atr'] ?? $this->maxDistanceFromEma9Atr);
        $activeWindowMinutes = (int) ($cfg['active_window_minutes'] ?? $this->activeWindowMinutes);
        $analysisLookbackMinutes = (int) ($cfg['analysis_lookback_minutes'] ?? $this->analysisLookbackMinutes);
        $atrPeriod = (int) ($cfg['atr_period_5m'] ?? 14);
        $rvolLookback = (int) ($cfg['rvol_lookback_5m'] ?? 20);
        $moveBars = (int) ($cfg['move_bars_5m'] ?? 6);
        $orderlyBars = (int) ($cfg['orderly_bars_5m'] ?? 6);
        $enableRsFilter = (bool) ($cfg['enable_relative_strength_filter']
            ?? config('trading.enable_relative_strength_filter', false));
        $minRsExcessPct = (float) ($cfg['min_rs_excess_pct'] ?? 0.35);

        $symbols = $this->loadUniverse($assetType, $skipCache);
        if ($symbols === []) {
            return [];
        }

        $asOfEpoch = strtotime($asOfTsEst);
        if ($asOfEpoch === false) {
            return [];
        }

        $tradeDate = substr($asOfTsEst, 0, 10);
        $marketOpen = $tradeDate.' 09:30:00';
        // Session VWAP must start at the market open. Limiting the SQL to the
        // recent lookback would turn it into a rolling VWAP and change the pattern.
        $lookbackMinutes = max($lookbackMinutes, $analysisLookbackMinutes);
        $analysisStart = $marketOpen;

        $bucketTs = date('Y-m-d H:i', intdiv($asOfEpoch, 300) * 300);
        $table = $this->fiveMinuteTable;
        $cacheKey = "scan_v45:{$table}:{$assetType}:{$bucketTs}:{$lookbackMinutes}";

        $rows = null;
        if (! $skipCache) {
            $rows = Cache::get($cacheKey);
        }

        if ($rows === null) {
            $placeholders = implode(',', array_fill(0, count($symbols), '?'));
            $sql = "
                SELECT symbol, asset_type, ts_est, `open`, high, low, price AS close, volume
                FROM {$table}
                WHERE asset_type = ?
                  AND symbol IN ({$placeholders})
                  AND ts_est >= ?
                  AND ts_est <= ?
                ORDER BY symbol ASC, ts_est ASC
            ";
            $params = array_merge([$assetType], $symbols, [$analysisStart, $asOfTsEst]);

            if ($skipCache) {
                $rows = $this->dbSelect($sql, $params);
            } else {
                $lock = Cache::lock("lock:{$cacheKey}", 45);
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
        }

        $grouped = [];
        foreach ($rows as $row) {
            $grouped[(string) $row->symbol][] = $row;
        }

        $benchmarkMove30m = $this->getBenchmarkMovement30m($asOfTsEst, $moveBars);
        $maxSignalAgeSeconds = max(1, $activeWindowMinutes) * 60;
        // Permit morning scans before a full 14-bar ATR history exists. The ATR
        // calculation below uses all available true ranges up to the configured cap.
        $requiredBars = max(8, $moveBars + 2, $orderlyBars + 1);

        $debugEnabled = $this->isDebugEnabled();
        $drops = [
            'symbols_total' => count($grouped),
            'not_enough_bars' => 0,
            'stale' => 0,
            'trend' => 0,
            'liquidity' => 0,
            'atr' => 0,
            'rvol' => 0,
            'move' => 0,
            'extended' => 0,
            'pullback_location' => 0,
            'orderliness' => 0,
            'ema_distance' => 0,
            'relative_strength' => 0,
            'passed' => 0,
        ];

        $out = [];
        foreach ($grouped as $symbol => $bars) {
            if (count($bars) < $requiredBars) {
                $drops['not_enough_bars']++;

                continue;
            }

            $metrics = $this->calculateMetrics(
                $bars,
                $atrPeriod,
                $rvolLookback,
                $moveBars,
                $orderlyBars
            );

            if ($metrics === null) {
                $drops['not_enough_bars']++;

                continue;
            }

            $signalEpoch = strtotime((string) $metrics['signal_ts_est']);
            if ($signalEpoch === false) {
                $drops['stale']++;

                continue;
            }
            $signalAgeSeconds = $asOfEpoch - $signalEpoch;
            if ($signalAgeSeconds < 0 || $signalAgeSeconds > $maxSignalAgeSeconds) {
                $drops['stale']++;

                continue;
            }

            // Scanner trend is intentionally broader than the final one-minute
            // entry. A near EMA9/EMA21 cross may enter the candidate funnel, but
            // OneMinuteEntryFinderV45 still requires EMA9 > EMA21 at confirmation.
            $emaTrendOk = $metrics['ema9_above_ema21']
                || (
                    $metrics['ema_spread_pct'] >= -0.05
                    && $metrics['last_close'] >= $metrics['ema9']
                );

            if (! $metrics['above_vwap'] || ! $emaTrendOk) {
                $drops['trend']++;

                continue;
            }

            // This is a pullback setup, so the current 5-minute bar may have
            // contracting volume. Use recent three-bar activity instead of
            // requiring the latest pullback bar itself to be highly liquid.
            $liquidityOk =
                $metrics['average_notional_last3'] >= $minNotional5m
                || $metrics['max_notional_last3'] >= ($minNotional5m * 1.50);

            if (! $liquidityOk) {
                $drops['liquidity']++;

                continue;
            }
            if ($metrics['atr_pct'] < $minAtrPct5m || $metrics['atr_pct'] > $maxAtrPct5m) {
                $drops['atr']++;

                continue;
            }

            // Accept either recent elevated volume or meaningful 30-minute
            // price progress. This avoids rejecting a healthy low-volume
            // pullback immediately after an active impulse.
            $activityOk =
                $metrics['max_rvol_last3'] >= $minRvol5m
                || $metrics['move_30m_pct'] >= $minMove30m;

            if (! $activityOk) {
                $drops['rvol']++;

                continue;
            }
            if ($metrics['move_30m_pct'] < $minMove30m || $metrics['move_30m_pct'] > $maxMove30m) {
                $drops['move']++;

                continue;
            }
            if ($metrics['above_vwap_pct'] > $maxAboveVwapPct) {
                $drops['extended']++;

                continue;
            }
            if (
                $metrics['pullback_from_recent_high_pct'] < $minPullbackFromRecentHighPct
                || $metrics['pullback_from_recent_high_pct'] > $maxPullbackFromRecentHighPct
            ) {
                $drops['pullback_location']++;

                continue;
            }
            if (
                $metrics['green_bar_pct'] < $minGreenBarPct
                || $metrics['net_progress'] < $minNetProgress
                || $metrics['directional_changes'] > $maxDirectionalChanges
            ) {
                $drops['orderliness']++;

                continue;
            }
            if (
                $metrics['atr'] > 0
                && $metrics['distance_from_ema9_atr'] > $maxDistanceFromEma9Atr
            ) {
                $drops['ema_distance']++;

                continue;
            }
            if (
                $enableRsFilter
                && $metrics['move_30m_pct'] < ($benchmarkMove30m + $minRsExcessPct)
            ) {
                $drops['relative_strength']++;

                continue;
            }

            $scoreParts = $this->scoreMetrics($metrics, $maxAboveVwapPct, $maxDirectionalChanges);
            $drops['passed']++;

            $out[] = [
                'symbol' => $symbol,
                'asset_type' => $assetType,
                'signal_type' => 'VWAP_HIGHER_LOW_SETUP_5M_V45',
                'signal_ts_est' => $metrics['signal_ts_est'],
                'score' => $scoreParts['score'],
                'atr' => round($metrics['atr'], 6),
                'atr_pct' => round($metrics['atr_pct'], 4),
                'meta' => [
                    'pattern' => 'VWAP_HIGHER_LOW_CONFIRMATION',
                    'move_30m_pct' => round($metrics['move_30m_pct'], 4),
                    'rvol_5m' => round($metrics['rvol_5m'], 4),
                    'max_rvol_last3' => round($metrics['max_rvol_last3'], 4),
                    'atr_pct_5m' => round($metrics['atr_pct'], 4),
                    'notional_last5m' => round($metrics['notional_last5m'], 2),
                    'average_notional_last3' => round($metrics['average_notional_last3'], 2),
                    'max_notional_last3' => round($metrics['max_notional_last3'], 2),
                    'session_vwap' => round($metrics['vwap'], 6),
                    'above_vwap_pct' => round($metrics['above_vwap_pct'], 4),
                    'ema9' => round($metrics['ema9'], 6),
                    'ema21' => round($metrics['ema21'], 6),
                    'ema_spread_pct' => round($metrics['ema_spread_pct'], 4),
                    'distance_from_ema9_atr' => round($metrics['distance_from_ema9_atr'], 4),
                    'five_min_green_bar_pct' => round($metrics['green_bar_pct'], 2),
                    'five_min_directional_changes' => $metrics['directional_changes'],
                    'five_min_net_progress' => round($metrics['net_progress'], 4),
                    'pullback_from_recent_high_pct' => round($metrics['pullback_from_recent_high_pct'], 4),
                    'benchmark_move_30m_pct' => round($benchmarkMove30m, 4),
                    'relative_strength_excess_pct' => round(
                        $metrics['move_30m_pct'] - $benchmarkMove30m,
                        4
                    ),
                    'trend_score' => $scoreParts['trend_score'],
                    'activity_score' => $scoreParts['activity_score'],
                    'orderliness_score' => $scoreParts['orderliness_score'],
                    'location_score' => $scoreParts['location_score'],
                    'signal_age_seconds' => $signalAgeSeconds,
                    'current_price' => round($metrics['last_close'], 6),
                    'universe_size' => count($symbols),
                    'version' => $this->version,
                ],
            ];
        }

        usort($out, static fn (array $a, array $b): int => ($b['score'] <=> $a['score']));
        $out = array_slice($out, 0, max(1, $limit));

        if ($debugEnabled) {
            Log::info('[ScannerV45] gate summary', [
                'as_of' => $asOfTsEst,
                'asset_type' => $assetType,
                'universe_size' => count($symbols),
                'gates' => $drops,
                'returned' => count($out),
                'pid' => getmypid(),
            ]);
        }

        return $out;
    }

    private function isDebugEnabled(): bool
    {
        $values = [
            env('TRADING_V45_DEBUG', '0'),
            env('SCANNER_V45_DEBUG', '0'),
            config('trading.v45.debug', false),
        ];

        foreach ($values as $value) {
            if (filter_var($value, FILTER_VALIDATE_BOOLEAN)) {
                return true;
            }
        }

        return false;
    }

    /** @return array<int, string> */
    private function loadUniverse(string $assetType, bool $skipCache): array
    {
        $cacheKey = "scan_v45:universe_symbols:{$assetType}";
        $symbols = $skipCache ? null : Cache::get($cacheKey);

        if ($symbols === null) {
            $symbols = DB::table('intraday_universe')
                ->where('asset_type', $assetType)
                ->orderBy('symbol')
                ->pluck('symbol')
                ->map(static fn ($symbol): string => (string) $symbol)
                ->all();

            $moversLimit = (int) config('trading.market_movers.pipeline_h', 0);
            if ($moversLimit > 0) {
                $movers = app(\App\Services\MarketMoversService::class)
                    ->getTodaysTopMoversFromCache(null, $moversLimit);
                $symbols = array_values(array_unique(array_merge($symbols, $movers)));
            }

            if (! $skipCache) {
                Cache::put($cacheKey, $symbols, 28800);
            }
        }

        return array_values(array_filter(
            array_map(static fn ($symbol): string => trim((string) $symbol), $symbols),
            static fn (string $symbol): bool => $symbol !== ''
        ));
    }

    /**
     * @param  array<int, object>  $bars
     * @return array<string, float|int|string|bool>|null
     */
    private function calculateMetrics(
        array $bars,
        int $atrPeriod,
        int $rvolLookback,
        int $moveBars,
        int $orderlyBars
    ): ?array {
        $count = count($bars);
        if ($count < max(8, $moveBars + 2)) {
            return null;
        }

        $cumPv = 0.0;
        $cumVolume = 0.0;
        $ema9 = null;
        $ema21 = null;
        $k9 = 2.0 / 10.0;
        $k21 = 2.0 / 22.0;
        $trueRanges = [];
        $previousClose = null;

        foreach ($bars as $bar) {
            $high = (float) $bar->high;
            $low = (float) $bar->low;
            $close = (float) $bar->close;
            $volume = max(0.0, (float) $bar->volume);

            $typical = ($high + $low + $close) / 3.0;
            $cumPv += $typical * $volume;
            $cumVolume += $volume;

            $ema9 = $ema9 === null ? $close : (($close * $k9) + ($ema9 * (1.0 - $k9)));
            $ema21 = $ema21 === null ? $close : (($close * $k21) + ($ema21 * (1.0 - $k21)));

            if ($previousClose !== null) {
                $trueRanges[] = max(
                    $high - $low,
                    abs($high - $previousClose),
                    abs($low - $previousClose)
                );
            }
            $previousClose = $close;
        }

        $last = $bars[$count - 1];
        $lastClose = (float) $last->close;
        if ($lastClose <= 0 || $cumVolume <= 0 || $ema9 === null || $ema21 === null) {
            return null;
        }

        $vwap = $cumPv / $cumVolume;
        $atrSlice = array_slice($trueRanges, -$atrPeriod);
        $atr = $atrSlice !== [] ? array_sum($atrSlice) / count($atrSlice) : 0.0;
        $atrPct = $atr > 0 ? ($atr / $lastClose) * 100.0 : 0.0;

        $volumeStart = max(0, $count - 1 - $rvolLookback);
        $volumeSlice = array_slice($bars, $volumeStart, ($count - 1) - $volumeStart);
        $averageVolume = $this->averageObjectField($volumeSlice, 'volume');
        $lastVolume = max(0.0, (float) $last->volume);
        $rvol = $averageVolume > 0 ? $lastVolume / $averageVolume : 0.0;

        // Measure recent activity over the last three completed 5-minute bars.
        // A valid pullback can have low current volume even though the impulse
        // immediately before it was liquid and active.
        $recentActivityBars = array_slice($bars, -3);
        $recentNotionalTotal = 0.0;
        $recentNotionalCount = 0;
        $maxRecentNotional = 0.0;
        $maxRecentRvol = 0.0;

        foreach ($recentActivityBars as $recentBar) {
            $recentClose = (float) $recentBar->close;
            $recentVolume = max(0.0, (float) $recentBar->volume);
            $recentNotional = $recentClose * $recentVolume;

            $recentNotionalTotal += $recentNotional;
            $recentNotionalCount++;
            $maxRecentNotional = max($maxRecentNotional, $recentNotional);

            if ($averageVolume > 0) {
                $maxRecentRvol = max(
                    $maxRecentRvol,
                    $recentVolume / $averageVolume
                );
            }
        }

        $averageRecentNotional = $recentNotionalCount > 0
            ? $recentNotionalTotal / $recentNotionalCount
            : 0.0;

        $moveIndex = $count - 1 - $moveBars;
        if ($moveIndex < 0) {
            return null;
        }
        $moveClose = (float) $bars[$moveIndex]->close;
        $move30mPct = $moveClose > 0 ? (($lastClose - $moveClose) / $moveClose) * 100.0 : 0.0;

        $recent = array_slice($bars, -max(2, $orderlyBars));
        $greenBars = 0;
        $directionalChanges = 0;
        $previousDirection = null;
        $totalRange = 0.0;
        $recentHigh = 0.0;

        foreach ($recent as $bar) {
            $open = (float) $bar->open;
            $close = (float) $bar->close;
            $high = (float) $bar->high;
            $low = (float) $bar->low;
            $direction = $close >= $open ? 1 : -1;

            if ($direction > 0) {
                $greenBars++;
            }
            if ($previousDirection !== null && $direction !== $previousDirection) {
                $directionalChanges++;
            }
            $previousDirection = $direction;
            $totalRange += max(0.0, $high - $low);
            $recentHigh = max($recentHigh, $high);
        }

        $firstRecent = $recent[0];
        $netMove = $lastClose - (float) $firstRecent->open;
        $netProgress = $totalRange > 0 ? $netMove / $totalRange : 0.0;
        $greenBarPct = count($recent) > 0 ? ($greenBars / count($recent)) * 100.0 : 0.0;
        $aboveVwapPct = $vwap > 0 ? (($lastClose - $vwap) / $vwap) * 100.0 : 999.0;
        $emaSpreadPct = ($ema9 / $ema21 - 1.0) * 100.0;
        $distanceFromEma9Atr = $atr > 0 ? abs($lastClose - $ema9) / $atr : 999.0;
        $pullbackFromRecentHighPct = $recentHigh > 0
            ? (($recentHigh - $lastClose) / $recentHigh) * 100.0
            : 0.0;

        return [
            'signal_ts_est' => (string) $last->ts_est,
            'last_close' => $lastClose,
            'vwap' => $vwap,
            'ema9' => $ema9,
            'ema21' => $ema21,
            'atr' => $atr,
            'atr_pct' => $atrPct,
            'rvol_5m' => $rvol,
            'max_rvol_last3' => $maxRecentRvol,
            'move_30m_pct' => $move30mPct,
            'notional_last5m' => $lastClose * $lastVolume,
            'average_notional_last3' => $averageRecentNotional,
            'max_notional_last3' => $maxRecentNotional,
            'above_vwap' => $lastClose > $vwap,
            'above_vwap_pct' => $aboveVwapPct,
            'ema9_above_ema21' => $ema9 > $ema21,
            'ema_spread_pct' => $emaSpreadPct,
            'distance_from_ema9_atr' => $distanceFromEma9Atr,
            'green_bar_pct' => $greenBarPct,
            'directional_changes' => $directionalChanges,
            'net_progress' => $netProgress,
            'pullback_from_recent_high_pct' => $pullbackFromRecentHighPct,
        ];
    }

    /** @param array<int, object> $rows */
    private function averageObjectField(array $rows, string $field): float
    {
        if ($rows === []) {
            return 0.0;
        }

        $sum = 0.0;
        $count = 0;
        foreach ($rows as $row) {
            $sum += (float) ($row->{$field} ?? 0.0);
            $count++;
        }

        return $count > 0 ? $sum / $count : 0.0;
    }

    /**
     * @param  array<string, float|int|string|bool>  $m
     * @return array<string, float>
     */
    private function scoreMetrics(array $m, float $maxAboveVwapPct, int $maxDirectionalChanges): array
    {
        $spreadScore = $this->clamp(((float) $m['ema_spread_pct'] - 0.03) / 0.45);
        $moveScore = $this->clamp(((float) $m['move_30m_pct'] - 0.50) / 2.50);
        $trendScore = 100.0 * ((0.55 * $spreadScore) + (0.45 * $moveScore));

        $rvolScore = $this->clamp(((float) $m['max_rvol_last3'] - 1.0) / 2.5);
        $atrScore = 1.0 - $this->clamp(abs((float) $m['atr_pct'] - 0.70) / 0.80);
        $activityScore = 100.0 * ((0.65 * $rvolScore) + (0.35 * $atrScore));

        $greenScore = $this->clamp(((float) $m['green_bar_pct'] - 40.0) / 45.0);
        $progressScore = $this->clamp(((float) $m['net_progress'] - 0.05) / 0.55);
        $changeScore = 1.0 - $this->clamp(
            ((float) $m['directional_changes']) / max(1.0, (float) $maxDirectionalChanges + 1.0)
        );
        $orderlinessScore = 100.0 * (
            (0.35 * $greenScore)
            + (0.45 * $progressScore)
            + (0.20 * $changeScore)
        );

        $vwapLocation = 1.0 - $this->clamp(((float) $m['above_vwap_pct']) / max(0.01, $maxAboveVwapPct));
        $emaLocation = 1.0 - $this->clamp(((float) $m['distance_from_ema9_atr']) / 1.75);
        $pullbackReadiness = 1.0 - $this->clamp(
            abs((float) $m['pullback_from_recent_high_pct'] - 0.60) / 1.50
        );
        $locationScore = 100.0 * (
            (0.35 * $vwapLocation)
            + (0.35 * $emaLocation)
            + (0.30 * $pullbackReadiness)
        );

        $final =
            (0.30 * $trendScore)
            + (0.25 * $activityScore)
            + (0.25 * $orderlinessScore)
            + (0.20 * $locationScore);

        return [
            'score' => round($final, 3),
            'trend_score' => round($trendScore, 3),
            'activity_score' => round($activityScore, 3),
            'orderliness_score' => round($orderlinessScore, 3),
            'location_score' => round($locationScore, 3),
        ];
    }

    private function getBenchmarkMovement30m(string $asOfTsEst, int $moveBars): float
    {
        $benchmarkSymbol = (string) config('trading.market_benchmark_symbol', 'QQQM');
        $table = $this->fiveMinuteTable;
        $start = date('Y-m-d H:i:s', strtotime($asOfTsEst.' -120 minutes'));

        $rows = $this->dbSelect("
            SELECT ts_est, price AS close
            FROM {$table}
            WHERE symbol = ?
              AND asset_type = 'stock'
              AND ts_est >= ?
              AND ts_est <= ?
            ORDER BY ts_est ASC
        ", [$benchmarkSymbol, $start, $asOfTsEst]);

        if (count($rows) <= $moveBars) {
            return 0.0;
        }

        $last = (float) $rows[count($rows) - 1]->close;
        $previous = (float) $rows[count($rows) - 1 - $moveBars]->close;

        return $previous > 0 ? (($last - $previous) / $previous) * 100.0 : 0.0;
    }

    private function clamp(float $value, float $min = 0.0, float $max = 1.0): float
    {
        return max($min, min($max, $value));
    }
}
