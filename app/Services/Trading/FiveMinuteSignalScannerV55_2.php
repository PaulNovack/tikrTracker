<?php

namespace App\Services\Trading;

use App\Services\GainersLosersAnalysisService;
use App\Services\Market\BestPerformers5mService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Version 55.2 - Balanced Trend Compression Continuation scanner.
 *
 * This is intentionally a different strategy from v45. Instead of requiring a
 * completed impulse/pullback/higher-low sequence on the one-minute chart, v55
 * looks for an established five-minute uptrend that has tightened near its
 * highs. OneMinuteEntryFinderV55 then waits for a fresh one-minute breakout or
 * EMA9 pivot continuation with strong close quality and controlled risk.
 */
class FiveMinuteSignalScannerV55_2
{
    use HasPriceTables;

    private string $version = 'v55.2';

    private string $name = 'Balanced Trend Compression Continuation';

    // All v55.2 scanner settings live in this class. Edit these properties to tune it.

    public bool $debug = false;

    public int $atrPeriod5m = 14;

    public int $rvolLookback5m = 20;

    public int $marketMoversLimit = 0;

    public int $scanCacheSeconds = 240;

    public int $universeCacheSeconds = 28800;

    public int $cacheLockSeconds = 45;

    public float $minNotional5m = 100000.0;

    public float $minAtrPct5m = 0.22;

    public float $maxAtrPct5m = 2.10;

    public float $minRecentRvol5m = 1.05;

    public float $minMove20mPct = 0.40;

    public float $maxMove20mPct = 4.25;

    public float $maxAboveVwapPct = 1.90;

    public float $minEmaSpreadPct = 0.02;

    public float $minEma9SlopePct = 0.018;

    public float $minCompressionRatio = 0.18;

    public float $maxCompressionRatio = 0.88;

    public float $maxFlagDepthPct = 1.60;

    public float $minCloseLocation = 0.50;

    public float $minGreenBarPct = 40.0;

    public float $maxDistanceFromEma9Atr = 1.30;

    public float $minScannerScore = 44.0;

    public int $activeWindowMinutes = 10;

    public int $analysisLookbackMinutes = 120;

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
            'debug' => $this->debug,
            'atr_period_5m' => $this->atrPeriod5m,
            'rvol_lookback_5m' => $this->rvolLookback5m,
            'market_movers_limit' => $this->marketMoversLimit,
            'scan_cache_seconds' => $this->scanCacheSeconds,
            'universe_cache_seconds' => $this->universeCacheSeconds,
            'cache_lock_seconds' => $this->cacheLockSeconds,
            'min_notional_5m' => $this->minNotional5m,
            'min_atr_pct_5m' => $this->minAtrPct5m,
            'max_atr_pct_5m' => $this->maxAtrPct5m,
            'min_recent_rvol_5m' => $this->minRecentRvol5m,
            'min_move_20m_pct' => $this->minMove20mPct,
            'max_move_20m_pct' => $this->maxMove20mPct,
            'max_above_vwap_pct' => $this->maxAboveVwapPct,
            'min_ema_spread_pct' => $this->minEmaSpreadPct,
            'min_ema9_slope_pct' => $this->minEma9SlopePct,
            'min_compression_ratio' => $this->minCompressionRatio,
            'max_compression_ratio' => $this->maxCompressionRatio,
            'max_flag_depth_pct' => $this->maxFlagDepthPct,
            'min_close_location' => $this->minCloseLocation,
            'min_green_bar_pct' => $this->minGreenBarPct,
            'max_distance_from_ema9_atr' => $this->maxDistanceFromEma9Atr,
            'min_scanner_score' => $this->minScannerScore,
            'active_window_minutes' => $this->activeWindowMinutes,
            'analysis_lookback_minutes' => $this->analysisLookbackMinutes,
        ];
    }

    /**
     * Keep the existing scanner pipeline signature.
     *
     * @return array<int, array<string, mixed>>
     */
    public function scan(
        string $assetType,
        string $asOfTsEst,
        int $lookbackMinutes = 60,
        float $minMovePct = 0.35,
        float $volMult = 1.00,
        int $limit = 60,
        bool $skipCache = false
    ): array {
        $minNotional5m = $this->minNotional5m;
        $minAtrPct5m = $this->minAtrPct5m;
        $maxAtrPct5m = $this->maxAtrPct5m;
        $minRecentRvol5m = max(0.75, max($this->minRecentRvol5m, $volMult));
        $minMove20mPct = max(0.0, max($this->minMove20mPct, $minMovePct));
        $maxMove20mPct = $this->maxMove20mPct;
        $maxAboveVwapPct = $this->maxAboveVwapPct;
        $minEmaSpreadPct = $this->minEmaSpreadPct;
        $minEma9SlopePct = $this->minEma9SlopePct;
        $minCompressionRatio = $this->minCompressionRatio;
        $maxCompressionRatio = $this->maxCompressionRatio;
        $maxFlagDepthPct = $this->maxFlagDepthPct;
        $minCloseLocation = $this->minCloseLocation;
        $minGreenBarPct = $this->minGreenBarPct;
        $maxDistanceFromEma9Atr = $this->maxDistanceFromEma9Atr;
        $minScannerScore = $this->minScannerScore;
        $activeWindowMinutes = $this->activeWindowMinutes;
        $analysisLookbackMinutes = $this->analysisLookbackMinutes;
        $atrPeriod = max(5, $this->atrPeriod5m);
        $rvolLookback = max(5, $this->rvolLookback5m);

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
        $lookbackMinutes = max($lookbackMinutes, $analysisLookbackMinutes);
        $table = $this->fiveMinuteTable;
        $bucketTs = date('Y-m-d H:i', intdiv($asOfEpoch, 300) * 300);
        $cacheKey = "scan_v55_2:{$table}:{$assetType}:{$bucketTs}:{$lookbackMinutes}";

        $rows = $skipCache ? null : Cache::get($cacheKey);
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
            $params = array_merge([$assetType], $symbols, [$marketOpen, $asOfTsEst]);

            if ($skipCache) {
                $rows = $this->dbSelect($sql, $params);
            } else {
                $lock = Cache::lock("lock:{$cacheKey}", $this->cacheLockSeconds);
                if ($lock->get()) {
                    try {
                        $rows = $this->dbSelect($sql, $params);
                        Cache::put($cacheKey, $rows, $this->scanCacheSeconds);
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

        $drops = [
            'symbols_total' => count($grouped),
            'not_enough_bars' => 0,
            'stale' => 0,
            'trend' => 0,
            'liquidity' => 0,
            'atr' => 0,
            'activity' => 0,
            'move' => 0,
            'extended' => 0,
            'compression' => 0,
            'location' => 0,
            'ema_distance' => 0,
            'score' => 0,
            'passed' => 0,
        ];

        $out = [];
        $maxSignalAgeSeconds = max(1, $activeWindowMinutes) * 60;

        foreach ($grouped as $symbol => $bars) {
            $metrics = $this->calculateMetrics($bars, $atrPeriod, $rvolLookback);
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

            if (
                ! $metrics['above_vwap']
                || ! $metrics['ema9_above_ema21']
                || (float) $metrics['ema_spread_pct'] < $minEmaSpreadPct
                || (float) $metrics['ema9_slope_pct'] < $minEma9SlopePct
                || (float) $metrics['last_close'] < ((float) $metrics['ema9'] - (0.15 * (float) $metrics['atr']))
            ) {
                $drops['trend']++;

                continue;
            }

            $liquidityOk =
                (float) $metrics['average_notional_last3'] >= $minNotional5m
                && (float) $metrics['notional_last5m'] >= ($minNotional5m * 0.65);
            if (! $liquidityOk) {
                $drops['liquidity']++;

                continue;
            }

            if (
                (float) $metrics['atr_pct'] < $minAtrPct5m
                || (float) $metrics['atr_pct'] > $maxAtrPct5m
            ) {
                $drops['atr']++;

                continue;
            }

            $activityOk =
                (float) $metrics['max_rvol_last4'] >= $minRecentRvol5m
                && (float) $metrics['rvol_5m'] >= 0.75;
            if (! $activityOk) {
                $drops['activity']++;

                continue;
            }

            if (
                (float) $metrics['move_20m_pct'] < $minMove20mPct
                || (float) $metrics['move_20m_pct'] > $maxMove20mPct
            ) {
                $drops['move']++;

                continue;
            }

            if ((float) $metrics['above_vwap_pct'] > $maxAboveVwapPct) {
                $drops['extended']++;

                continue;
            }

            if (
                (float) $metrics['compression_ratio'] < $minCompressionRatio
                || (float) $metrics['compression_ratio'] > $maxCompressionRatio
            ) {
                $drops['compression']++;

                continue;
            }

            if (
                (float) $metrics['flag_depth_pct'] > $maxFlagDepthPct
                || (float) $metrics['close_location'] < $minCloseLocation
                || (float) $metrics['green_bar_pct'] < $minGreenBarPct
            ) {
                $drops['location']++;

                continue;
            }

            if (
                (float) $metrics['atr'] > 0
                && (float) $metrics['distance_from_ema9_atr'] > $maxDistanceFromEma9Atr
            ) {
                $drops['ema_distance']++;

                continue;
            }

            $scores = $this->scoreMetrics($metrics, $maxCompressionRatio, $maxFlagDepthPct);
            if ((float) $scores['score'] < $minScannerScore) {
                $drops['score']++;

                continue;
            }
            $drops['passed']++;

            $out[] = [
                'symbol' => $symbol,
                'asset_type' => $assetType,
                'signal_type' => 'BALANCED_TREND_COMPRESSION_SETUP_5M_V55_2',
                'signal_ts_est' => $metrics['signal_ts_est'],
                'score' => $scores['score'],
                'atr' => round((float) $metrics['atr'], 6),
                'atr_pct' => round((float) $metrics['atr_pct'], 4),
                'meta' => [
                    'pattern' => 'BALANCED_TREND_COMPRESSION_CONTINUATION',
                    'version' => $this->version,
                    'current_price' => round((float) $metrics['last_close'], 6),
                    'session_vwap' => round((float) $metrics['vwap'], 6),
                    'above_vwap_pct' => round((float) $metrics['above_vwap_pct'], 4),
                    'ema9' => round((float) $metrics['ema9'], 6),
                    'ema21' => round((float) $metrics['ema21'], 6),
                    'ema_spread_pct' => round((float) $metrics['ema_spread_pct'], 4),
                    'ema9_slope_pct' => round((float) $metrics['ema9_slope_pct'], 4),
                    'distance_from_ema9_atr' => round((float) $metrics['distance_from_ema9_atr'], 4),
                    'move_20m_pct' => round((float) $metrics['move_20m_pct'], 4),
                    'rvol_5m' => round((float) $metrics['rvol_5m'], 4),
                    'max_rvol_last4' => round((float) $metrics['max_rvol_last4'], 4),
                    'notional_last5m' => round((float) $metrics['notional_last5m'], 2),
                    'average_notional_last3' => round((float) $metrics['average_notional_last3'], 2),
                    'compression_ratio' => round((float) $metrics['compression_ratio'], 4),
                    'recent_range_pct' => round((float) $metrics['recent_range_pct'], 4),
                    'flag_depth_pct' => round((float) $metrics['flag_depth_pct'], 4),
                    'close_location' => round((float) $metrics['close_location'], 4),
                    'five_min_green_bar_pct' => round((float) $metrics['green_bar_pct'], 2),
                    'trend_score' => $scores['trend_score'],
                    'compression_score' => $scores['compression_score'],
                    'activity_score' => $scores['activity_score'],
                    'location_score' => $scores['location_score'],
                    'signal_age_seconds' => $signalAgeSeconds,
                    'universe_size' => count($symbols),
                ],
            ];
        }

        usort($out, static fn (array $a, array $b): int => ($b['score'] ?? 0) <=> ($a['score'] ?? 0));
        $out = array_slice($out, 0, max(1, $limit));

        if ($this->isDebugEnabled()) {
            Log::info('[ScannerV55_2] debug counters', array_merge($drops, [
                'as_of_ts_est' => $asOfTsEst,
                'asset_type' => $assetType,
                'returned' => count($out),
                'pid' => getmypid(),
            ]));
        }

        return $out;
    }

    /** @return array<int, string> */
    private function loadUniverse(string $assetType, bool $skipCache): array
    {
        $cacheKey = "scan_v55_2:universe_symbols:{$assetType}";
        $symbols = $skipCache ? null : Cache::get($cacheKey);

        if ($symbols === null) {
            $symbols = DB::table('intraday_universe')
                ->where('asset_type', $assetType)
                ->orderBy('symbol')
                ->pluck('symbol')
                ->map(static fn ($symbol): string => (string) $symbol)
                ->all();

            if ($this->marketMoversLimit > 0) {
                $movers = app(\App\Services\MarketMoversService::class)
                    ->getTodaysTopMoversFromCache(null, $this->marketMoversLimit);
                $symbols = array_values(array_unique(array_merge($symbols, $movers)));
            }

            if (! $skipCache) {
                Cache::put($cacheKey, $symbols, $this->universeCacheSeconds);
            }
        }

        return array_values(array_filter(
            array_map(static fn ($symbol): string => trim((string) $symbol), $symbols),
            static fn (string $symbol): bool => $symbol !== ''
        ));
    }

    /**
     * @param  array<int, object>  $bars
     * @return array<string, float|bool|string>|null
     */
    private function calculateMetrics(array $bars, int $atrPeriod, int $rvolLookback): ?array
    {
        $count = count($bars);
        if ($count < 12) {
            return null;
        }

        $ema9 = null;
        $ema21 = null;
        $ema9History = [];
        $trueRanges = [];
        $rvols = [];
        $notionals = [];
        $cumPv = 0.0;
        $cumVolume = 0.0;
        $previousClose = null;
        $k9 = 2.0 / 10.0;
        $k21 = 2.0 / 22.0;

        foreach ($bars as $i => $bar) {
            $open = (float) $bar->open;
            $high = (float) $bar->high;
            $low = (float) $bar->low;
            $close = (float) $bar->close;
            $volume = max(0.0, (float) $bar->volume);

            if ($open <= 0 || $high <= 0 || $low <= 0 || $close <= 0 || $high < $low) {
                return null;
            }

            $typical = ($high + $low + $close) / 3.0;
            $cumPv += $typical * $volume;
            $cumVolume += $volume;

            $ema9 = $ema9 === null ? $close : (($close * $k9) + ($ema9 * (1.0 - $k9)));
            $ema21 = $ema21 === null ? $close : (($close * $k21) + ($ema21 * (1.0 - $k21)));
            $ema9History[] = $ema9;

            $tr = $previousClose === null
                ? ($high - $low)
                : max($high - $low, abs($high - $previousClose), abs($low - $previousClose));
            $trueRanges[] = max(0.0, $tr);
            $previousClose = $close;

            $previousVolumes = [];
            $start = max(0, $i - $rvolLookback);
            for ($j = $start; $j < $i; $j++) {
                $previousVolumes[] = max(0.0, (float) $bars[$j]->volume);
            }
            $avgPreviousVolume = $previousVolumes === []
                ? 0.0
                : array_sum($previousVolumes) / count($previousVolumes);
            $rvols[] = $avgPreviousVolume > 0 ? $volume / $avgPreviousVolume : 0.0;
            $notionals[] = $close * $volume;
        }

        $last = $bars[$count - 1];
        $lastClose = (float) $last->close;
        $lastVolume = max(0.0, (float) $last->volume);
        $vwap = $cumVolume > 0 ? $cumPv / $cumVolume : 0.0;
        if ($vwap <= 0 || $ema9 === null || $ema21 === null) {
            return null;
        }

        $atrSlice = array_slice($trueRanges, -min($atrPeriod, count($trueRanges)));
        $atr = $atrSlice === [] ? 0.0 : array_sum($atrSlice) / count($atrSlice);
        if ($atr <= 0) {
            return null;
        }

        $moveStartIndex = max(0, $count - 5);
        $moveStartClose = (float) $bars[$moveStartIndex]->close;
        $move20mPct = $moveStartClose > 0
            ? (($lastClose - $moveStartClose) / $moveStartClose) * 100.0
            : 0.0;

        $recent3 = array_slice($bars, -3);
        $prior6 = array_slice($bars, max(0, $count - 9), 6);
        $recent6 = array_slice($bars, -6);

        $recentAverageRange = $this->averageRange($recent3);
        $priorAverageRange = $this->averageRange($prior6);
        $compressionRatio = $priorAverageRange > 0
            ? $recentAverageRange / $priorAverageRange
            : 1.0;

        $recentHigh = $this->maxObjectField($recent6, 'high');
        $recentLow = $this->minObjectField($recent6, 'low');
        $recentRange = max(0.0, $recentHigh - $recentLow);
        $recentRangePct = $lastClose > 0 ? ($recentRange / $lastClose) * 100.0 : 0.0;
        $flagDepthPct = $recentHigh > 0 ? (($recentHigh - $lastClose) / $recentHigh) * 100.0 : 0.0;
        $closeLocation = $recentRange > 0 ? ($lastClose - $recentLow) / $recentRange : 0.5;

        $greenCount = 0;
        foreach ($recent6 as $bar) {
            if ((float) $bar->close >= (float) $bar->open) {
                $greenCount++;
            }
        }
        $greenBarPct = count($recent6) > 0 ? ($greenCount / count($recent6)) * 100.0 : 0.0;

        $emaSlopeBaseIndex = max(0, count($ema9History) - 4);
        $emaSlopeBase = (float) $ema9History[$emaSlopeBaseIndex];
        $ema9SlopePct = $emaSlopeBase > 0
            ? (($ema9 - $emaSlopeBase) / $emaSlopeBase) * 100.0
            : 0.0;

        $recentRvol = array_slice($rvols, -4);
        $recentNotionals = array_slice($notionals, -3);
        $averageNotionalLast3 = $recentNotionals === []
            ? 0.0
            : array_sum($recentNotionals) / count($recentNotionals);

        return [
            'signal_ts_est' => (string) $last->ts_est,
            'last_close' => $lastClose,
            'vwap' => $vwap,
            'above_vwap' => $lastClose > $vwap,
            'above_vwap_pct' => (($lastClose - $vwap) / $vwap) * 100.0,
            'ema9' => $ema9,
            'ema21' => $ema21,
            'ema9_above_ema21' => $ema9 > $ema21,
            'ema_spread_pct' => (($ema9 - $ema21) / $ema21) * 100.0,
            'ema9_slope_pct' => $ema9SlopePct,
            'atr' => $atr,
            'atr_pct' => ($atr / $lastClose) * 100.0,
            'distance_from_ema9_atr' => max(0.0, ($lastClose - $ema9) / $atr),
            'move_20m_pct' => $move20mPct,
            'rvol_5m' => (float) ($rvols[count($rvols) - 1] ?? 0.0),
            'max_rvol_last4' => $recentRvol === [] ? 0.0 : max($recentRvol),
            'notional_last5m' => $lastClose * $lastVolume,
            'average_notional_last3' => $averageNotionalLast3,
            'max_notional_last3' => $recentNotionals === [] ? 0.0 : max($recentNotionals),
            'compression_ratio' => $compressionRatio,
            'recent_range_pct' => $recentRangePct,
            'flag_depth_pct' => max(0.0, $flagDepthPct),
            'close_location' => $this->clamp($closeLocation),
            'green_bar_pct' => $greenBarPct,
        ];
    }

    /**
     * @param  array<string, float|bool|string>  $m
     * @return array<string, float>
     */
    private function scoreMetrics(array $m, float $maxCompressionRatio, float $maxFlagDepthPct): array
    {
        $spreadScore = $this->clamp(((float) $m['ema_spread_pct'] - 0.01) / 0.45);
        $slopeScore = $this->clamp(((float) $m['ema9_slope_pct'] - 0.01) / 0.35);
        $moveScore = $this->clamp(((float) $m['move_20m_pct'] - 0.35) / 2.50);
        $trendScore = 100.0 * ((0.35 * $spreadScore) + (0.35 * $slopeScore) + (0.30 * $moveScore));

        $compressionScore = 100.0 * (
            1.0 - $this->clamp(((float) $m['compression_ratio'] - 0.35) / max(0.01, $maxCompressionRatio - 0.35))
        );

        $rvolScore = $this->clamp(((float) $m['max_rvol_last4'] - 0.90) / 2.00);
        $atrScore = 1.0 - $this->clamp(abs((float) $m['atr_pct'] - 0.70) / 1.00);
        $activityScore = 100.0 * ((0.60 * $rvolScore) + (0.40 * $atrScore));

        $depthScore = 1.0 - $this->clamp((float) $m['flag_depth_pct'] / max(0.01, $maxFlagDepthPct));
        $closeScore = $this->clamp(((float) $m['close_location'] - 0.40) / 0.55);
        $locationScore = 100.0 * ((0.45 * $depthScore) + (0.55 * $closeScore));

        $score =
            (0.34 * $trendScore)
            + (0.28 * $compressionScore)
            + (0.20 * $activityScore)
            + (0.18 * $locationScore);

        return [
            'score' => round($this->clamp($score, 0.0, 100.0), 3),
            'trend_score' => round($trendScore, 3),
            'compression_score' => round($compressionScore, 3),
            'activity_score' => round($activityScore, 3),
            'location_score' => round($locationScore, 3),
        ];
    }

    /** @param array<int, object> $rows */
    private function averageRange(array $rows): float
    {
        if ($rows === []) {
            return 0.0;
        }

        $sum = 0.0;
        foreach ($rows as $row) {
            $sum += max(0.0, (float) $row->high - (float) $row->low);
        }

        return $sum / count($rows);
    }

    /** @param array<int, object> $rows */
    private function maxObjectField(array $rows, string $field): float
    {
        $values = array_map(static fn (object $row): float => (float) ($row->{$field} ?? 0.0), $rows);

        return $values === [] ? 0.0 : max($values);
    }

    /** @param array<int, object> $rows */
    private function minObjectField(array $rows, string $field): float
    {
        $values = array_map(static fn (object $row): float => (float) ($row->{$field} ?? 0.0), $rows);

        return $values === [] ? 0.0 : min($values);
    }

    private function isDebugEnabled(): bool
    {
        return $this->debug;
    }

    private function clamp(float $value, float $min = 0.0, float $max = 1.0): float
    {
        return max($min, min($max, $value));
    }
}
