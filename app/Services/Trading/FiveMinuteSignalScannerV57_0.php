<?php

namespace App\Services\Trading;

use App\Services\GainersLosersAnalysisService;
use App\Services\Market\BestPerformers5mService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Version 57.0 - Opening Range Breakout Retest scanner.
 *
 * The first 15 minutes (09:30-09:45 ET) define the opening range. This
 * scanner emits a setup only after a five-minute bar closes above the opening
 * range high. The corresponding one-minute finder waits for a retest of that
 * level instead of buying the initial breakout extension.
 */
class FiveMinuteSignalScannerV57_0
{
    use HasPriceTables;

    private string $version = 'v57.0';

    private string $name = '15-Minute ORB Retest';

    // All v57.0 scanner settings are intentionally kept in this class.
    public bool $debug = false;

    public int $marketOpenMinute = 570;       // 09:30 ET

    public int $openingRangeEndMinute = 585;  // 09:45 ET, exclusive

    public int $scanEndMinute = 705;          // 11:45 ET

    public int $minimumOpeningRangeBars = 3;

    public int $atrPeriod5m = 14;

    public int $rvolLookback5m = 20;

    public int $breakoutLookbackBars = 6;

    public int $activeWindowMinutes = 30;

    public int $analysisLookbackMinutes = 150;

    public int $marketMoversLimit = 0;

    public int $scanCacheSeconds = 120;

    public int $universeCacheSeconds = 28800;

    public int $cacheLockSeconds = 45;

    public float $minOpeningRangePct = 0.25;

    public float $maxOpeningRangePct = 5.00;

    public float $minAverageNotional5m = 50000.0;

    public float $minBreakoutNotional5m = 30000.0;

    public float $minAtrPct5m = 0.15;

    public float $maxAtrPct5m = 3.50;

    public float $minBreakoutRvol = 0.80;

    public float $minRecentRvol = 0.75;

    public float $breakoutCloseBufferPct = 0.03;

    public float $breakoutHighBufferPct = 0.00;

    public float $holdToleranceBelowOrbPct = 0.20;

    public float $maxExtensionAboveOrbPct = 3.00;

    public float $maxExtensionAboveOrbAtr = 3.00;

    public float $maxAboveVwapPct = 4.00;

    public float $minimumEmaSpreadPct = -0.08;

    public float $minBreakoutCloseLocation = 0.52;

    public float $minBreakoutBodyRangeFraction = 0.25;

    public float $minScannerScore = 38.0;

    // Compatibility arguments passed to scan() cannot make this strategy
    // materially stricter than these caps.
    public float $maxCallerMoveFloorPct = 0.60;

    public float $maxCallerRvolFloor = 1.10;

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

    /** @return array<string, int|float|bool> */
    public function scanConfig(): array
    {
        return [
            'debug' => $this->debug,
            'market_open_minute' => $this->marketOpenMinute,
            'opening_range_end_minute' => $this->openingRangeEndMinute,
            'scan_end_minute' => $this->scanEndMinute,
            'minimum_opening_range_bars' => $this->minimumOpeningRangeBars,
            'atr_period_5m' => $this->atrPeriod5m,
            'rvol_lookback_5m' => $this->rvolLookback5m,
            'breakout_lookback_bars' => $this->breakoutLookbackBars,
            'active_window_minutes' => $this->activeWindowMinutes,
            'min_opening_range_pct' => $this->minOpeningRangePct,
            'max_opening_range_pct' => $this->maxOpeningRangePct,
            'min_average_notional_5m' => $this->minAverageNotional5m,
            'min_breakout_notional_5m' => $this->minBreakoutNotional5m,
            'min_atr_pct_5m' => $this->minAtrPct5m,
            'max_atr_pct_5m' => $this->maxAtrPct5m,
            'min_breakout_rvol' => $this->minBreakoutRvol,
            'min_recent_rvol' => $this->minRecentRvol,
            'breakout_close_buffer_pct' => $this->breakoutCloseBufferPct,
            'hold_tolerance_below_orb_pct' => $this->holdToleranceBelowOrbPct,
            'max_extension_above_orb_pct' => $this->maxExtensionAboveOrbPct,
            'max_extension_above_orb_atr' => $this->maxExtensionAboveOrbAtr,
            'max_above_vwap_pct' => $this->maxAboveVwapPct,
            'minimum_ema_spread_pct' => $this->minimumEmaSpreadPct,
            'min_scanner_score' => $this->minScannerScore,
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
        $asOfEpoch = strtotime($asOfTsEst);
        if ($asOfEpoch === false) {
            return [];
        }

        $asOfMinute = $this->minuteOfDay($asOfTsEst);
        if ($asOfMinute < $this->openingRangeEndMinute || $asOfMinute > $this->scanEndMinute) {
            return [];
        }

        $symbols = $this->loadUniverse($assetType, $skipCache);
        if ($symbols === []) {
            return [];
        }

        $effectiveRvolFloor = max(
            $this->minRecentRvol,
            min(max(0.0, $volMult), $this->maxCallerRvolFloor)
        );
        $effectiveMoveFloor = min(max(0.0, $minMovePct), $this->maxCallerMoveFloorPct);

        $tradeDate = substr($asOfTsEst, 0, 10);
        $marketOpen = $tradeDate.' 09:30:00';
        $lookbackMinutes = max($lookbackMinutes, $this->analysisLookbackMinutes);
        $table = $this->fiveMinuteTable;
        $bucketTs = date('Y-m-d H:i', intdiv($asOfEpoch, 300) * 300);
        $cacheKey = "scan_v57_0:{$table}:{$assetType}:{$bucketTs}:{$lookbackMinutes}";

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
            'bad_data' => 0,
            'opening_range' => 0,
            'no_recent_breakout' => 0,
            'stale' => 0,
            'failed_hold' => 0,
            'liquidity' => 0,
            'atr' => 0,
            'activity' => 0,
            'trend' => 0,
            'extended' => 0,
            'quality' => 0,
            'score' => 0,
            'passed' => 0,
        ];

        $out = [];
        foreach ($grouped as $symbol => $bars) {
            $metrics = $this->calculateMetrics(
                $bars,
                max(5, $this->atrPeriod5m),
                max(5, $this->rvolLookback5m)
            );
            if ($metrics === null) {
                $drops['bad_data']++;

                continue;
            }

            if (
                (int) $metrics['opening_range_bars'] < $this->minimumOpeningRangeBars
                || (float) $metrics['opening_range_pct'] < $this->minOpeningRangePct
                || (float) $metrics['opening_range_pct'] > $this->maxOpeningRangePct
            ) {
                $drops['opening_range']++;

                continue;
            }

            if (! (bool) $metrics['has_recent_breakout']) {
                $drops['no_recent_breakout']++;

                continue;
            }

            $signalEpoch = strtotime((string) $metrics['breakout_ts_est']);
            if ($signalEpoch === false) {
                $drops['stale']++;

                continue;
            }

            $signalAgeSeconds = $asOfEpoch - $signalEpoch;
            if ($signalAgeSeconds < 0 || $signalAgeSeconds > ($this->activeWindowMinutes * 60)) {
                $drops['stale']++;

                continue;
            }

            $minimumHoldPrice = (float) $metrics['orb_high']
                * (1.0 - ($this->holdToleranceBelowOrbPct / 100.0));
            if ((float) $metrics['last_close'] < $minimumHoldPrice) {
                $drops['failed_hold']++;

                continue;
            }

            if (
                (float) $metrics['average_notional_last3'] < $this->minAverageNotional5m
                || (float) $metrics['breakout_notional'] < $this->minBreakoutNotional5m
            ) {
                $drops['liquidity']++;

                continue;
            }

            if (
                (float) $metrics['atr_pct'] < $this->minAtrPct5m
                || (float) $metrics['atr_pct'] > $this->maxAtrPct5m
            ) {
                $drops['atr']++;

                continue;
            }

            if (
                (float) $metrics['breakout_rvol'] < $this->minBreakoutRvol
                && (float) $metrics['max_rvol_last3'] < $effectiveRvolFloor
            ) {
                $drops['activity']++;

                continue;
            }

            $trendOk =
                (float) $metrics['last_close'] > (float) $metrics['vwap']
                && (
                    (float) $metrics['ema_spread_pct'] >= $this->minimumEmaSpreadPct
                    || (float) $metrics['breakout_move_pct'] >= $effectiveMoveFloor
                );
            if (! $trendOk) {
                $drops['trend']++;

                continue;
            }

            if (
                (float) $metrics['extension_above_orb_pct'] > $this->maxExtensionAboveOrbPct
                || (float) $metrics['extension_above_orb_atr'] > $this->maxExtensionAboveOrbAtr
                || (float) $metrics['above_vwap_pct'] > $this->maxAboveVwapPct
            ) {
                $drops['extended']++;

                continue;
            }

            if (
                (float) $metrics['breakout_close_location'] < $this->minBreakoutCloseLocation
                || (float) $metrics['breakout_body_range_fraction'] < $this->minBreakoutBodyRangeFraction
            ) {
                $drops['quality']++;

                continue;
            }

            $scores = $this->scoreMetrics($metrics);
            if ((float) $scores['score'] < $this->minScannerScore) {
                $drops['score']++;

                continue;
            }

            $drops['passed']++;
            $out[] = [
                'symbol' => $symbol,
                'asset_type' => $assetType,
                'signal_type' => 'ORB_RETEST_SETUP_5M_V57_0',
                'signal_ts_est' => $metrics['breakout_ts_est'],
                'score' => $scores['score'],
                'atr' => round((float) $metrics['atr'], 6),
                'atr_pct' => round((float) $metrics['atr_pct'], 4),
                'meta' => [
                    'pattern' => 'OPENING_RANGE_BREAKOUT_RETEST',
                    'version' => $this->version,
                    'opening_range_minutes' => 15,
                    'orb_high' => round((float) $metrics['orb_high'], 6),
                    'orb_low' => round((float) $metrics['orb_low'], 6),
                    'opening_range_pct' => round((float) $metrics['opening_range_pct'], 4),
                    'opening_range_volume' => round((float) $metrics['opening_range_volume'], 2),
                    'breakout_ts_est' => $metrics['breakout_ts_est'],
                    'breakout_close' => round((float) $metrics['breakout_close'], 6),
                    'breakout_move_pct' => round((float) $metrics['breakout_move_pct'], 4),
                    'breakout_rvol' => round((float) $metrics['breakout_rvol'], 4),
                    'breakout_notional' => round((float) $metrics['breakout_notional'], 2),
                    'breakout_close_location' => round((float) $metrics['breakout_close_location'], 4),
                    'breakout_body_range_fraction' => round((float) $metrics['breakout_body_range_fraction'], 4),
                    'current_price' => round((float) $metrics['last_close'], 6),
                    'session_vwap' => round((float) $metrics['vwap'], 6),
                    'above_vwap_pct' => round((float) $metrics['above_vwap_pct'], 4),
                    'ema9' => round((float) $metrics['ema9'], 6),
                    'ema21' => round((float) $metrics['ema21'], 6),
                    'ema_spread_pct' => round((float) $metrics['ema_spread_pct'], 4),
                    'extension_above_orb_pct' => round((float) $metrics['extension_above_orb_pct'], 4),
                    'extension_above_orb_atr' => round((float) $metrics['extension_above_orb_atr'], 4),
                    'average_notional_last3' => round((float) $metrics['average_notional_last3'], 2),
                    'max_rvol_last3' => round((float) $metrics['max_rvol_last3'], 4),
                    'signal_age_seconds' => $signalAgeSeconds,
                    'breakout_score' => $scores['breakout_score'],
                    'trend_score' => $scores['trend_score'],
                    'activity_score' => $scores['activity_score'],
                    'range_score' => $scores['range_score'],
                    'universe_size' => count($symbols),
                ],
            ];
        }

        usort($out, static fn (array $a, array $b): int => ($b['score'] ?? 0) <=> ($a['score'] ?? 0));
        $out = array_slice($out, 0, max(1, $limit));

        if ($this->debug) {
            Log::info('[ScannerV57_0] debug counters', array_merge($drops, [
                'as_of_ts_est' => $asOfTsEst,
                'asset_type' => $assetType,
                'effective_move_floor' => $effectiveMoveFloor,
                'effective_rvol_floor' => $effectiveRvolFloor,
                'returned' => count($out),
                'pid' => getmypid(),
            ]));
        }

        return $out;
    }

    /** @return array<int, string> */
    private function loadUniverse(string $assetType, bool $skipCache): array
    {
        $cacheKey = "scan_v57_0:universe_symbols:{$assetType}";
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

            // Add 4-bar 1-min up streak symbols from Redis
            $redisSymbols = \Illuminate\Support\Facades\Redis::get('last_4_1min_up:symbols');
            if ($redisSymbols) {
                $streakSymbols = json_decode($redisSymbols, true);
                if (is_array($streakSymbols) && $streakSymbols !== []) {
                    $symbols = array_values(array_unique(array_merge($symbols, $streakSymbols)));
                }
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
     * @param  array<int, object>  $rows
     * @return array<string, int|float|bool|string>|null
     */
    private function calculateMetrics(array $rows, int $atrPeriod, int $rvolLookback): ?array
    {
        if (count($rows) < ($this->minimumOpeningRangeBars + 1)) {
            return null;
        }

        $bars = [];
        $ema9 = null;
        $ema21 = null;
        $k9 = 2.0 / 10.0;
        $k21 = 2.0 / 22.0;
        $cumPv = 0.0;
        $cumVolume = 0.0;
        $trueRanges = [];
        $volumes = [];
        $previousClose = null;

        foreach ($rows as $row) {
            $open = (float) $row->open;
            $high = (float) $row->high;
            $low = (float) $row->low;
            $close = (float) $row->close;
            $volume = max(0.0, (float) $row->volume);

            if (
                $open <= 0 || $high <= 0 || $low <= 0 || $close <= 0
                || $high < $low || $high < max($open, $close) || $low > min($open, $close)
            ) {
                return null;
            }

            $typical = ($high + $low + $close) / 3.0;
            $cumPv += $typical * $volume;
            $cumVolume += $volume;
            $vwap = $cumVolume > 0 ? $cumPv / $cumVolume : $close;

            $ema9 = $ema9 === null ? $close : (($close * $k9) + ($ema9 * (1.0 - $k9)));
            $ema21 = $ema21 === null ? $close : (($close * $k21) + ($ema21 * (1.0 - $k21)));

            $tr = $previousClose === null
                ? $high - $low
                : max($high - $low, abs($high - $previousClose), abs($low - $previousClose));
            $trueRanges[] = max(0.0, $tr);
            $previousClose = $close;
            $atrSlice = array_slice($trueRanges, -min($atrPeriod, count($trueRanges)));
            $atr = $atrSlice === [] ? 0.0 : array_sum($atrSlice) / count($atrSlice);

            $priorVolumes = array_slice($volumes, -min($rvolLookback, count($volumes)));
            $avgPriorVolume = $priorVolumes === [] ? 0.0 : array_sum($priorVolumes) / count($priorVolumes);
            $rvol = $avgPriorVolume > 0 ? $volume / $avgPriorVolume : 0.0;
            $volumes[] = $volume;

            $bars[] = [
                'ts' => (string) $row->ts_est,
                'minute' => $this->minuteOfDay((string) $row->ts_est),
                'open' => $open,
                'high' => $high,
                'low' => $low,
                'close' => $close,
                'volume' => $volume,
                'vwap' => $vwap,
                'ema9' => $ema9,
                'ema21' => $ema21,
                'atr' => $atr,
                'rvol' => $rvol,
                'notional' => $close * $volume,
            ];
        }

        $openingBars = array_values(array_filter(
            $bars,
            fn (array $bar): bool => (int) $bar['minute'] >= $this->marketOpenMinute
                && (int) $bar['minute'] < $this->openingRangeEndMinute
        ));
        if (count($openingBars) < $this->minimumOpeningRangeBars) {
            return null;
        }

        $postBars = array_values(array_filter(
            $bars,
            fn (array $bar): bool => (int) $bar['minute'] >= $this->openingRangeEndMinute
        ));
        if ($postBars === []) {
            return null;
        }

        $orbHigh = max(array_column($openingBars, 'high'));
        $orbLow = min(array_column($openingBars, 'low'));
        if ($orbHigh <= 0 || $orbLow <= 0 || $orbHigh <= $orbLow) {
            return null;
        }

        $orbMid = ($orbHigh + $orbLow) / 2.0;
        $openingRangePct = (($orbHigh - $orbLow) / $orbMid) * 100.0;
        $openingRangeVolume = array_sum(array_column($openingBars, 'volume'));
        $breakoutThreshold = $orbHigh * (1.0 + ($this->breakoutCloseBufferPct / 100.0));
        $breakoutHighThreshold = $orbHigh * (1.0 + ($this->breakoutHighBufferPct / 100.0));

        $breakoutIndex = null;
        $postCount = count($postBars);
        $searchStart = max(0, $postCount - max(1, $this->breakoutLookbackBars));
        for ($i = $searchStart; $i < $postCount; $i++) {
            $bar = $postBars[$i];
            $previousClose = $i > 0 ? (float) $postBars[$i - 1]['close'] : $orbHigh;
            $crossedFromBelow = $previousClose < $breakoutThreshold;
            if (
                $crossedFromBelow
                && (float) $bar['close'] >= $breakoutThreshold
                && (float) $bar['high'] >= $breakoutHighThreshold
            ) {
                $breakoutIndex = $i;
            }
        }

        if ($breakoutIndex === null) {
            return [
                'opening_range_bars' => count($openingBars),
                'opening_range_pct' => $openingRangePct,
                'has_recent_breakout' => false,
            ];
        }

        $breakout = $postBars[$breakoutIndex];
        $latest = $bars[count($bars) - 1];
        $last3 = array_slice($bars, -3);
        $last3Notional = array_column($last3, 'notional');
        $last3Rvol = array_column($last3, 'rvol');
        $breakoutRange = max(1e-9, (float) $breakout['high'] - (float) $breakout['low']);
        $breakoutCloseLocation = ((float) $breakout['close'] - (float) $breakout['low']) / $breakoutRange;
        $breakoutBodyRangeFraction = abs((float) $breakout['close'] - (float) $breakout['open']) / $breakoutRange;
        $breakoutMovePct = (((float) $breakout['close'] - $orbHigh) / $orbHigh) * 100.0;
        $lastClose = (float) $latest['close'];
        $atr = max(1e-9, (float) $latest['atr']);
        $extensionAboveOrbPct = (($lastClose - $orbHigh) / $orbHigh) * 100.0;
        $extensionAboveOrbAtr = max(0.0, ($lastClose - $orbHigh) / $atr);
        $vwap = max(1e-9, (float) $latest['vwap']);
        $ema21 = max(1e-9, (float) $latest['ema21']);

        return [
            'opening_range_bars' => count($openingBars),
            'opening_range_pct' => $openingRangePct,
            'opening_range_volume' => $openingRangeVolume,
            'orb_high' => $orbHigh,
            'orb_low' => $orbLow,
            'has_recent_breakout' => true,
            'breakout_ts_est' => (string) $breakout['ts'],
            'breakout_close' => (float) $breakout['close'],
            'breakout_move_pct' => $breakoutMovePct,
            'breakout_rvol' => (float) $breakout['rvol'],
            'breakout_notional' => (float) $breakout['notional'],
            'breakout_close_location' => $this->clamp($breakoutCloseLocation),
            'breakout_body_range_fraction' => $this->clamp($breakoutBodyRangeFraction),
            'last_close' => $lastClose,
            'vwap' => $vwap,
            'above_vwap_pct' => (($lastClose - $vwap) / $vwap) * 100.0,
            'ema9' => (float) $latest['ema9'],
            'ema21' => (float) $latest['ema21'],
            'ema_spread_pct' => (((float) $latest['ema9'] - $ema21) / $ema21) * 100.0,
            'atr' => $atr,
            'atr_pct' => ($atr / $lastClose) * 100.0,
            'extension_above_orb_pct' => $extensionAboveOrbPct,
            'extension_above_orb_atr' => $extensionAboveOrbAtr,
            'average_notional_last3' => $last3Notional === [] ? 0.0 : array_sum($last3Notional) / count($last3Notional),
            'max_rvol_last3' => $last3Rvol === [] ? 0.0 : max($last3Rvol),
        ];
    }

    /**
     * @param  array<string, int|float|bool|string>  $m
     * @return array<string, float>
     */
    private function scoreMetrics(array $m): array
    {
        $breakoutMoveScore = $this->clamp(((float) $m['breakout_move_pct'] - 0.02) / 1.25);
        $closeScore = $this->clamp(((float) $m['breakout_close_location'] - 0.45) / 0.50);
        $bodyScore = $this->clamp(((float) $m['breakout_body_range_fraction'] - 0.20) / 0.65);
        $breakoutScore = 100.0 * (
            (0.40 * $breakoutMoveScore)
            + (0.35 * $closeScore)
            + (0.25 * $bodyScore)
        );

        $emaScore = $this->clamp(((float) $m['ema_spread_pct'] + 0.08) / 0.55);
        $vwapScore = 1.0 - $this->clamp((float) $m['above_vwap_pct'] / max(0.01, $this->maxAboveVwapPct));
        $trendScore = 100.0 * ((0.60 * $emaScore) + (0.40 * $vwapScore));

        $breakoutRvolScore = $this->clamp(((float) $m['breakout_rvol'] - 0.60) / 2.20);
        $recentRvolScore = $this->clamp(((float) $m['max_rvol_last3'] - 0.60) / 2.20);
        $activityScore = 100.0 * ((0.65 * $breakoutRvolScore) + (0.35 * $recentRvolScore));

        $rangePct = (float) $m['opening_range_pct'];
        $rangeCenter = 1.50;
        $rangeScore = 100.0 * (1.0 - $this->clamp(abs($rangePct - $rangeCenter) / 3.50));

        $score =
            (0.38 * $breakoutScore)
            + (0.24 * $trendScore)
            + (0.23 * $activityScore)
            + (0.15 * $rangeScore);

        return [
            'score' => round($this->clamp($score, 0.0, 100.0), 3),
            'breakout_score' => round($breakoutScore, 3),
            'trend_score' => round($trendScore, 3),
            'activity_score' => round($activityScore, 3),
            'range_score' => round($rangeScore, 3),
        ];
    }

    private function minuteOfDay(string $timestamp): int
    {
        $time = substr($timestamp, 11, 5);
        [$hour, $minute] = array_map('intval', explode(':', $time));

        return ($hour * 60) + $minute;
    }

    private function clamp(float $value, float $min = 0.0, float $max = 1.0): float
    {
        return max($min, min($max, $value));
    }
}
