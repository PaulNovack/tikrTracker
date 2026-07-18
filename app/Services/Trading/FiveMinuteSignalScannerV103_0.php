<?php

namespace App\Services\Trading;

use App\Services\Market\BestPerformers5mService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Version 103.0 - ORB Retest scanner.
 *
 * This scanner is intentionally narrow:
 * - build a liquid, strength-biased universe
 * - require a completed opening range
 * - require a clean 5m breakout above the opening range high
 * - require trend, volume, and extension quality before emitting a signal
 */
class FiveMinuteSignalScannerV103_0
{
    use HasPriceTables;

    private string $version = 'v103.0';

    private string $name = 'ORB Retest';

    public bool $debug = false;

    public int $marketOpenMinute = 570;

    public int $openingRangeEndMinute = 585;

    public int $scanEndMinute = 705;

    public int $minimumOpeningRangeBars = 3;

    public int $atrPeriod5m = 14;

    public int $rvolLookback5m = 20;

    public int $activeWindowMinutes = 5;

    public int $analysisLookbackMinutes = 150;

    public int $marketMoversLimit = 40;

    public int $scanCacheSeconds = 120;

    public int $universeCacheSeconds = 1800;

    public int $cacheLockSeconds = 45;

    public float $minOpeningRangePct = 0.20;

    public float $maxOpeningRangePct = 4.50;

    public float $minAverageNotional5m = 75000.0;

    public float $minBreakoutNotional5m = 50000.0;

    public float $minAtrPct5m = 0.25;

    public float $maxAtrPct5m = 4.50;

    public float $minBreakoutRvol = 1.15;

    public float $minRecentRvol = 1.10;

    public float $breakoutCloseBufferPct = 0.03;

    public float $holdToleranceBelowOrbPct = 0.15;

    public float $maxExtensionAboveOrbPct = 1.80;

    public float $maxExtensionAboveOrbAtr = 2.20;

    public float $maxAboveVwapPct = 2.10;

    public float $minimumEmaSpreadPct = 0.00;

    public float $minBreakoutCloseLocation = 0.58;

    public float $minBreakoutBodyRangeFraction = 0.28;

    public float $minScannerScore = 54.0;

    public function __construct(
        private readonly BestPerformers5mService $bestPerformersService,
    ) {}

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
            'active_window_minutes' => $this->activeWindowMinutes,
            'analysis_lookback_minutes' => $this->analysisLookbackMinutes,
            'market_movers_limit' => $this->marketMoversLimit,
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
            'min_breakout_close_location' => $this->minBreakoutCloseLocation,
            'min_breakout_body_range_fraction' => $this->minBreakoutBodyRangeFraction,
            'min_scanner_score' => $this->minScannerScore,
        ];
    }

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
    }

    /**
     * Keep the existing pipeline signature.
     *
     * @return array<int, array<string, mixed>>
     */
    public function scan(
        string $assetType,
        string $asOfTsEst,
        int $lookbackMinutes = 60,
        float $minMovePct = 0.35,
        float $volMult = 1.0,
        int $limit = 60
    ): array {
        $asOfEpoch = strtotime($asOfTsEst);
        if ($asOfEpoch === false) {
            return [];
        }

        $asOfMinute = $this->minuteOfDay($asOfTsEst);
        if ($asOfMinute < $this->openingRangeEndMinute || $asOfMinute > $this->scanEndMinute) {
            return [];
        }

        $symbols = $this->loadUniverse($assetType);
        if ($symbols === []) {
            return [];
        }

        $tradeDate = substr($asOfTsEst, 0, 10);
        $marketOpen = $tradeDate.' 09:30:00';
        $bucketTs = date('Y-m-d H:i', intdiv($asOfEpoch, 300) * 300);
        $cacheKey = "scan_v103_0:{$assetType}:{$bucketTs}:{$limit}";

        $rows = Cache::get($cacheKey);
        if ($rows === null) {
            $placeholders = implode(',', array_fill(0, count($symbols), '?'));
            $sql = "
                SELECT symbol, asset_type, ts_est, `open`, high, low, price AS close, volume
                FROM {$this->fiveMinuteTable}
                WHERE asset_type = ?
                  AND symbol IN ({$placeholders})
                  AND ts_est >= ?
                  AND ts_est <= ?
                ORDER BY symbol ASC, ts_est ASC
            ";

            $params = array_merge([$assetType], $symbols, [$marketOpen, $asOfTsEst]);
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

        $grouped = [];
        foreach ($rows as $row) {
            $grouped[(string) $row->symbol][] = $row;
        }

        $drops = [
            'symbols_total' => count($grouped),
            'invalid_data' => 0,
            'opening_range' => 0,
            'no_breakout' => 0,
            'stale' => 0,
            'liquidity' => 0,
            'atr' => 0,
            'activity' => 0,
            'trend' => 0,
            'extension' => 0,
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
                $drops['invalid_data']++;

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

            if (! (bool) $metrics['has_breakout']) {
                $drops['no_breakout']++;

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

            $minimumHoldPrice = (float) $metrics['orb_high'] * (1.0 - ($this->holdToleranceBelowOrbPct / 100.0));
            if ((float) $metrics['last_close'] < $minimumHoldPrice) {
                $drops['trend']++;

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
                && (float) $metrics['max_rvol_last3'] < max($this->minRecentRvol, min(max(0.0, $volMult), 3.0))
            ) {
                $drops['activity']++;

                continue;
            }

            $trendOk =
                (float) $metrics['last_close'] > (float) $metrics['vwap']
                && (float) $metrics['ema_spread_pct'] >= $this->minimumEmaSpreadPct
                && (float) $metrics['breakout_move_pct'] >= max(0.0, $minMovePct);
            if (! $trendOk) {
                $drops['trend']++;

                continue;
            }

            if (
                (float) $metrics['extension_above_orb_pct'] > $this->maxExtensionAboveOrbPct
                || (float) $metrics['extension_above_orb_atr'] > $this->maxExtensionAboveOrbAtr
                || (float) $metrics['above_vwap_pct'] > $this->maxAboveVwapPct
            ) {
                $drops['extension']++;

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
                'signal_type' => 'ORB_RETEST_SETUP_5M_V103_0',
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

        return array_slice($out, 0, max(1, $limit));
    }

    /** @return array<int, string> */
    private function loadUniverse(string $assetType): array
    {
        $cacheKey = "scan_v103_0:universe_symbols:{$assetType}";
        $symbols = Cache::get($cacheKey);

        if ($symbols === null) {
            $topPerformers = $this->bestPerformersService->getBestPerformers([
                'assetType' => $assetType,
                'testDateTime' => now('America/New_York')->format('Y-m-d H:i:s'),
                'days' => 5,
                'minBars' => 200,
                'minVol' => 0,
                'rthOnly' => true,
                'limit' => 300,
                'tz' => 'America/New_York',
            ]);

            $symbols = array_column($topPerformers, 'symbol');

            $intradayUniverse = DB::table('intraday_universe')
                ->where('asset_type', $assetType)
                ->orderBy('symbol')
                ->pluck('symbol')
                ->map(static fn ($symbol): string => (string) $symbol)
                ->all();
            $symbols = array_values(array_unique(array_merge($symbols, $intradayUniverse)));

            $moversLimit = (int) config('trading.market_movers.pipeline_c', config('trading.market_movers.pipeline_h', $this->marketMoversLimit));
            if ($moversLimit > 0) {
                $tradeDate = now('America/New_York')->format('Y-m-d');
                $movers = app(\App\Services\MarketMoversService::class)->getTodaysTopMoversFromCache($tradeDate, $moversLimit);
                $symbols = array_values(array_unique(array_merge($symbols, $movers)));
            }

            $redisSymbols = \Illuminate\Support\Facades\Redis::get('last_4_1min_up:symbols');
            if ($redisSymbols) {
                $streakSymbols = json_decode($redisSymbols, true);
                if (is_array($streakSymbols) && $streakSymbols !== []) {
                    $symbols = array_values(array_unique(array_merge($symbols, $streakSymbols)));
                }
            }

            $symbols = array_values(array_filter(array_map(
                static fn ($symbol): string => trim((string) $symbol),
                $symbols
            ), static fn (string $symbol): bool => $symbol !== ''));

            Cache::put($cacheKey, $symbols, $this->universeCacheSeconds);
        }

        return $symbols;
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
            $volumeRatio = $avgPriorVolume > 0 ? $volume / $avgPriorVolume : 0.0;
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
                'volume_ratio' => $volumeRatio,
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

        $breakoutIndex = null;
        $breakoutBar = null;
        foreach ($bars as $index => $bar) {
            if ((int) $bar['minute'] < $this->openingRangeEndMinute) {
                continue;
            }

            $close = (float) $bar['close'];
            $open = (float) $bar['open'];
            $high = (float) $bar['high'];
            $low = (float) $bar['low'];
            $vwap = (float) $bar['vwap'];
            $ema9 = (float) $bar['ema9'];
            $ema21 = (float) $bar['ema21'];
            $volumeRatio = (float) $bar['volume_ratio'];
            $range = max(1e-9, $high - $low);
            $bodyRangeFraction = abs($close - $open) / $range;
            $closePosition = ($close - $low) / $range;
            $aboveVwapPct = $vwap > 0 ? (($close - $vwap) / $vwap) * 100.0 : 999.0;
            $emaSpreadPct = $ema21 > 0 ? (($ema9 - $ema21) / $ema21) * 100.0 : 0.0;
            $breakoutExtensionPct = $orbHigh > 0 ? (($close - $orbHigh) / $orbHigh) * 100.0 : 999.0;

            if (
                $close > ($orbHigh * (1.0 + ($this->breakoutCloseBufferPct / 100.0)))
                && $vwap > 0
                && $close > $vwap
                && $ema9 > $ema21
                && $emaSpreadPct >= $this->minimumEmaSpreadPct
                && $volumeRatio >= $this->minBreakoutRvol
                && $breakoutExtensionPct <= $this->maxExtensionAboveOrbPct
                && $aboveVwapPct <= $this->maxAboveVwapPct
                && $closePosition >= $this->minBreakoutCloseLocation
                && $bodyRangeFraction >= $this->minBreakoutBodyRangeFraction
            ) {
                $breakoutIndex = $index;
                $breakoutBar = $bar;
            }
        }

        if ($breakoutIndex === null || $breakoutBar === null) {
            return null;
        }

        $lastBar = $bars[count($bars) - 1];
        $recentBars = array_slice($bars, max(0, count($bars) - 3));
        $recentNotional = array_map(static fn (array $bar): float => (float) $bar['notional'], $recentBars);
        $recentRvol = array_map(static fn (array $bar): float => (float) $bar['volume_ratio'], $recentBars);

        $averageNotionalLast3 = $recentNotional === [] ? 0.0 : array_sum($recentNotional) / count($recentNotional);
        $maxRvolLast3 = $recentRvol === [] ? 0.0 : max($recentRvol);

        $breakoutClose = (float) $breakoutBar['close'];
        $breakoutLow = (float) $breakoutBar['low'];
        $breakoutHigh = (float) $breakoutBar['high'];
        $breakoutVolume = (float) $breakoutBar['volume'];
        $breakoutVwap = (float) $breakoutBar['vwap'];
        $breakoutEma9 = (float) $breakoutBar['ema9'];
        $breakoutEma21 = (float) $breakoutBar['ema21'];
        $breakoutAtr = (float) $breakoutBar['atr'];
        $breakoutRvol = (float) $breakoutBar['volume_ratio'];
        $breakoutNotional = $breakoutClose * $breakoutVolume;
        $breakoutRange = max(1e-9, $breakoutHigh - $breakoutLow);
        $breakoutCloseLocation = ($breakoutClose - $breakoutLow) / $breakoutRange;
        $breakoutBodyRangeFraction = abs($breakoutClose - (float) $breakoutBar['open']) / $breakoutRange;
        $breakoutMovePct = $breakoutIndex > 0 && (float) $bars[$breakoutIndex - 1]['close'] > 0
            ? (($breakoutClose - (float) $bars[$breakoutIndex - 1]['close']) / (float) $bars[$breakoutIndex - 1]['close']) * 100.0
            : 0.0;
        $aboveVwapPct = $breakoutVwap > 0 ? (($breakoutClose - $breakoutVwap) / $breakoutVwap) * 100.0 : 999.0;
        $emaSpreadPct = $breakoutEma21 > 0 ? (($breakoutEma9 - $breakoutEma21) / $breakoutEma21) * 100.0 : 0.0;
        $extensionAboveOrbPct = $orbHigh > 0 ? (($breakoutClose - $orbHigh) / $orbHigh) * 100.0 : 999.0;
        $extensionAboveOrbAtr = $breakoutAtr > 0 ? max(0.0, ($breakoutClose - $orbHigh) / $breakoutAtr) : 999.0;
        $openingRangePct = (($orbHigh - $orbLow) / (($orbHigh + $orbLow) / 2.0)) * 100.0;

        $scores = $this->scoreMetrics([
            'orb_high' => $orbHigh,
            'orb_low' => $orbLow,
            'breakout_close' => $breakoutClose,
            'breakout_rvol' => $breakoutRvol,
            'breakout_close_location' => $breakoutCloseLocation,
            'breakout_body_range_fraction' => $breakoutBodyRangeFraction,
            'breakout_move_pct' => $breakoutMovePct,
            'above_vwap_pct' => $aboveVwapPct,
            'ema_spread_pct' => $emaSpreadPct,
            'average_notional_last3' => $averageNotionalLast3,
            'max_rvol_last3' => $maxRvolLast3,
            'opening_range_pct' => $openingRangePct,
            'last_close' => (float) $lastBar['close'],
        ]);

        return [
            'orb_high' => $orbHigh,
            'orb_low' => $orbLow,
            'opening_range_bars' => count($openingBars),
            'opening_range_pct' => $openingRangePct,
            'opening_range_volume' => array_sum(array_column($openingBars, 'volume')),
            'breakout_ts_est' => (string) $breakoutBar['ts'],
            'breakout_close' => $breakoutClose,
            'breakout_move_pct' => $breakoutMovePct,
            'breakout_rvol' => $breakoutRvol,
            'breakout_notional' => $breakoutNotional,
            'breakout_close_location' => $breakoutCloseLocation,
            'breakout_body_range_fraction' => $breakoutBodyRangeFraction,
            'last_close' => (float) $lastBar['close'],
            'vwap' => (float) $lastBar['vwap'],
            'above_vwap_pct' => (float) ($lastBar['vwap'] > 0 ? (((float) $lastBar['close'] - (float) $lastBar['vwap']) / (float) $lastBar['vwap']) * 100.0 : 999.0),
            'ema9' => (float) $lastBar['ema9'],
            'ema21' => (float) $lastBar['ema21'],
            'ema_spread_pct' => (float) $emaSpreadPct,
            'atr' => (float) $lastBar['atr'],
            'atr_pct' => (float) (((float) $lastBar['atr'] / max(1e-9, (float) $lastBar['close'])) * 100.0),
            'average_notional_last3' => $averageNotionalLast3,
            'max_rvol_last3' => $maxRvolLast3,
            'extension_above_orb_pct' => $extensionAboveOrbPct,
            'extension_above_orb_atr' => $extensionAboveOrbAtr,
            'has_breakout' => true,
            'breakout_score' => $scores['breakout_score'],
            'trend_score' => $scores['trend_score'],
            'activity_score' => $scores['activity_score'],
            'range_score' => $scores['range_score'],
            'score' => $scores['score'],
        ];
    }

    /**
     * @param  array<string, int|float|bool|string>  $metrics
     * @return array<string, float>
     */
    private function scoreMetrics(array $metrics): array
    {
        $breakoutRvol = (float) ($metrics['breakout_rvol'] ?? 0.0);
        $closeLocation = (float) ($metrics['breakout_close_location'] ?? 0.0);
        $bodyFraction = (float) ($metrics['breakout_body_range_fraction'] ?? 0.0);
        $breakoutMovePct = (float) ($metrics['breakout_move_pct'] ?? 0.0);
        $aboveVwapPct = (float) ($metrics['above_vwap_pct'] ?? 0.0);
        $emaSpreadPct = (float) ($metrics['ema_spread_pct'] ?? 0.0);
        $averageNotionalLast3 = (float) ($metrics['average_notional_last3'] ?? 0.0);
        $maxRvolLast3 = (float) ($metrics['max_rvol_last3'] ?? 0.0);
        $openingRangePct = (float) ($metrics['opening_range_pct'] ?? 0.0);

        $breakoutScore = 100.0 * (
            0.32 * $this->clamp(($breakoutRvol - 1.0) / 2.5)
            + 0.26 * $this->clamp(($closeLocation - 0.50) / 0.50)
            + 0.22 * $this->clamp(($bodyFraction - 0.20) / 0.50)
            + 0.20 * $this->clamp(($breakoutMovePct - 0.10) / 0.80)
        );

        $trendScore = 100.0 * (
            0.45 * $this->clamp(($emaSpreadPct - 0.00) / 0.60)
            + 0.35 * $this->clamp((max(0.0, 2.75 - $aboveVwapPct)) / 2.75)
            + 0.20 * $this->clamp(($openingRangePct - $this->minOpeningRangePct) / 2.5)
        );

        $activityScore = 100.0 * (
            0.60 * $this->clamp(($averageNotionalLast3 - $this->minAverageNotional5m) / max(1.0, $this->minAverageNotional5m * 2.0))
            + 0.40 * $this->clamp(($maxRvolLast3 - 1.0) / 2.0)
        );

        $rangeScore = 100.0 * (
            0.60 * $this->clamp((4.50 - $openingRangePct) / 4.50)
            + 0.40 * $this->clamp(($openingRangePct - $this->minOpeningRangePct) / 2.0)
        );

        $final = (
            0.40 * $breakoutScore
            + 0.30 * $trendScore
            + 0.20 * $activityScore
            + 0.10 * $rangeScore
        );

        return [
            'breakout_score' => round($breakoutScore, 2),
            'trend_score' => round($trendScore, 2),
            'activity_score' => round($activityScore, 2),
            'range_score' => round($rangeScore, 2),
            'score' => round($final, 2),
        ];
    }

    private function minuteOfDay(string $tsEst): int
    {
        $hh = (int) substr($tsEst, 11, 2);
        $mm = (int) substr($tsEst, 14, 2);

        return ($hh * 60) + $mm;
    }

    private function clamp(float $x, float $lo = 0.0, float $hi = 1.0): float
    {
        if ($x < $lo) {
            return $lo;
        }
        if ($x > $hi) {
            return $hi;
        }

        return $x;
    }
}
