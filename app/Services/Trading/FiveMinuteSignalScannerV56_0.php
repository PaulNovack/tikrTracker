<?php

namespace App\Services\Trading;

use App\Services\GainersLosersAnalysisService;
use App\Services\Market\BestPerformers5mService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Version 56.0 - Five-minute momentum acceleration scanner.
 *
 * This scanner deliberately does not require a completed flag/compression
 * pattern. It surfaces liquid symbols when one or more momentum conditions
 * are present:
 *
 * - a strong current five-minute bar;
 * - positive ten/twenty-minute price progress;
 * - acceleration versus the prior bar; or
 * - a break of a recent five-minute high.
 *
 * OneMinuteEntryFinderV56 performs the more precise entry timing.
 */
class FiveMinuteSignalScannerV56_0
{
    use HasPriceTables;

    private string $version = 'v56.0';

    private string $name = 'Five-Minute Momentum Acceleration';

    // All v56 scanner configuration is kept in this class.
    public bool $debug = true;

    public int $atrPeriod5m = 14;

    public int $rvolLookback5m = 20;

    public int $minimumBars = 5;

    public int $marketMoversLimit = 0;

    public int $scanCacheSeconds = 120;

    public int $universeCacheSeconds = 28800;

    public int $cacheLockSeconds = 45;

    public int $analysisLookbackMinutes = 90;

    public int $activeWindowMinutes = 10;

    public float $minAverageNotional5m = 50000.0;

    public float $minCurrentNotional5m = 25000.0;

    public float $minAtrPct5m = 0.12;

    public float $maxAtrPct5m = 3.25;

    public float $minRecentRvol5m = 0.85;

    public float $minCurrentRvol5m = 0.65;

    public float $minMove5mPct = 0.08;

    public float $minMove10mPct = 0.22;

    public float $minMove20mPct = 0.35;

    public float $strongMove10mPct = 0.55;

    public float $maxMove20mPct = 8.00;

    public float $minAccelerationPct = 0.04;

    public float $maxAboveVwapPct = 3.50;

    public float $minCloseLocation = 0.52;

    public float $minEmaSpreadPct = -0.05;

    public float $breakoutTolerancePct = 0.03;

    public int $recentHighLookbackBars = 6;

    public int $minimumMomentumConditions = 1;

    public float $minScannerScore = 34.0;

    // Compatibility arguments passed to scan() cannot make the class
    // materially stricter than these caps.
    public float $maxCallerMoveFloorPct = 0.50;

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
            'minimum_bars' => $this->minimumBars,
            'atr_period_5m' => $this->atrPeriod5m,
            'rvol_lookback_5m' => $this->rvolLookback5m,
            'market_movers_limit' => $this->marketMoversLimit,
            'scan_cache_seconds' => $this->scanCacheSeconds,
            'universe_cache_seconds' => $this->universeCacheSeconds,
            'analysis_lookback_minutes' => $this->analysisLookbackMinutes,
            'active_window_minutes' => $this->activeWindowMinutes,
            'min_average_notional_5m' => $this->minAverageNotional5m,
            'min_current_notional_5m' => $this->minCurrentNotional5m,
            'min_atr_pct_5m' => $this->minAtrPct5m,
            'max_atr_pct_5m' => $this->maxAtrPct5m,
            'min_recent_rvol_5m' => $this->minRecentRvol5m,
            'min_current_rvol_5m' => $this->minCurrentRvol5m,
            'min_move_5m_pct' => $this->minMove5mPct,
            'min_move_10m_pct' => $this->minMove10mPct,
            'min_move_20m_pct' => $this->minMove20mPct,
            'strong_move_10m_pct' => $this->strongMove10mPct,
            'max_move_20m_pct' => $this->maxMove20mPct,
            'min_acceleration_pct' => $this->minAccelerationPct,
            'max_above_vwap_pct' => $this->maxAboveVwapPct,
            'min_close_location' => $this->minCloseLocation,
            'min_ema_spread_pct' => $this->minEmaSpreadPct,
            'breakout_tolerance_pct' => $this->breakoutTolerancePct,
            'recent_high_lookback_bars' => $this->recentHighLookbackBars,
            'minimum_momentum_conditions' => $this->minimumMomentumConditions,
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
        $symbols = $this->loadUniverse($assetType, $skipCache);
        if ($symbols === []) {
            return [];
        }

        $asOfEpoch = strtotime($asOfTsEst);
        if ($asOfEpoch === false) {
            return [];
        }

        $effectiveMove20mFloor = max(
            $this->minMove20mPct,
            min(max(0.0, $minMovePct), $this->maxCallerMoveFloorPct)
        );
        $effectiveRecentRvolFloor = max(
            $this->minRecentRvol5m,
            min(max(0.0, $volMult), $this->maxCallerRvolFloor)
        );

        $tradeDate = substr($asOfTsEst, 0, 10);
        $marketOpen = $tradeDate.' 09:30:00';
        $lookbackMinutes = max($lookbackMinutes, $this->analysisLookbackMinutes);
        $table = $this->fiveMinuteTable;
        $bucketTs = date('Y-m-d H:i', intdiv($asOfEpoch, 300) * 300);
        $cacheKey = "scan_v56:{$table}:{$assetType}:{$bucketTs}:{$lookbackMinutes}";

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
            'bad_data' => 0,
            'trend' => 0,
            'liquidity' => 0,
            'atr' => 0,
            'activity' => 0,
            'momentum' => 0,
            'extended' => 0,
            'candle_quality' => 0,
            'score' => 0,
            'passed' => 0,
        ];

        $out = [];
        $maxSignalAgeSeconds = max(1, $this->activeWindowMinutes) * 60;

        foreach ($grouped as $symbol => $bars) {
            if (count($bars) < max(4, $this->minimumBars)) {
                $drops['not_enough_bars']++;

                continue;
            }

            $metrics = $this->calculateMetrics(
                $bars,
                max(5, $this->atrPeriod5m),
                max(5, $this->rvolLookback5m)
            );
            if ($metrics === null) {
                $drops['bad_data']++;

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

            $strongEarlyMomentum = (float) $metrics['move_10m_pct'] >= $this->strongMove10mPct;
            $trendOk =
                (bool) $metrics['above_vwap']
                && (
                    (float) $metrics['ema_spread_pct'] >= $this->minEmaSpreadPct
                    || $strongEarlyMomentum
                    || (bool) $metrics['recent_high_break']
                );
            if (! $trendOk) {
                $drops['trend']++;

                continue;
            }

            if (
                (float) $metrics['average_notional_last2'] < $this->minAverageNotional5m
                || (float) $metrics['current_notional'] < $this->minCurrentNotional5m
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

            $activityOk =
                (float) $metrics['max_rvol_last3'] >= $effectiveRecentRvolFloor
                || (
                    (bool) $metrics['recent_high_break']
                    && (float) $metrics['current_rvol'] >= $this->minCurrentRvol5m
                );
            if (! $activityOk) {
                $drops['activity']++;

                continue;
            }

            $momentumConditions = 0;
            if ((float) $metrics['move_5m_pct'] >= $this->minMove5mPct) {
                $momentumConditions++;
            }
            if ((float) $metrics['move_10m_pct'] >= $this->minMove10mPct) {
                $momentumConditions++;
            }
            if ((float) $metrics['move_20m_pct'] >= $effectiveMove20mFloor) {
                $momentumConditions++;
            }
            if ((float) $metrics['acceleration_pct'] >= $this->minAccelerationPct) {
                $momentumConditions++;
            }
            if ((bool) $metrics['recent_high_break']) {
                $momentumConditions++;
            }

            if (
                $momentumConditions < max(1, $this->minimumMomentumConditions)
                || (float) $metrics['move_20m_pct'] > $this->maxMove20mPct
            ) {
                $drops['momentum']++;

                continue;
            }

            if ((float) $metrics['above_vwap_pct'] > $this->maxAboveVwapPct) {
                $drops['extended']++;

                continue;
            }

            if (
                (float) $metrics['close_location'] < $this->minCloseLocation
                && ! (bool) $metrics['recent_high_break']
            ) {
                $drops['candle_quality']++;

                continue;
            }

            $scores = $this->scoreMetrics($metrics, $momentumConditions);
            if ((float) $scores['score'] < $this->minScannerScore) {
                $drops['score']++;

                continue;
            }

            $drops['passed']++;
            $out[] = [
                'symbol' => $symbol,
                'asset_type' => $assetType,
                'signal_type' => 'MOMENTUM_ACCELERATION_SETUP_5M_V56',
                'signal_ts_est' => $metrics['signal_ts_est'],
                'score' => $scores['score'],
                'atr' => round((float) $metrics['atr'], 6),
                'atr_pct' => round((float) $metrics['atr_pct'], 4),
                'meta' => [
                    'pattern' => 'MOMENTUM_ACCELERATION_CONTINUATION',
                    'version' => $this->version,
                    'current_price' => round((float) $metrics['last_close'], 6),
                    'session_vwap' => round((float) $metrics['vwap'], 6),
                    'above_vwap_pct' => round((float) $metrics['above_vwap_pct'], 4),
                    'ema9' => round((float) $metrics['ema9'], 6),
                    'ema21' => round((float) $metrics['ema21'], 6),
                    'ema_spread_pct' => round((float) $metrics['ema_spread_pct'], 4),
                    'move_5m_pct' => round((float) $metrics['move_5m_pct'], 4),
                    'move_10m_pct' => round((float) $metrics['move_10m_pct'], 4),
                    'move_20m_pct' => round((float) $metrics['move_20m_pct'], 4),
                    'prior_move_5m_pct' => round((float) $metrics['prior_move_5m_pct'], 4),
                    'acceleration_pct' => round((float) $metrics['acceleration_pct'], 4),
                    'current_rvol' => round((float) $metrics['current_rvol'], 4),
                    'max_rvol_last3' => round((float) $metrics['max_rvol_last3'], 4),
                    'current_notional' => round((float) $metrics['current_notional'], 2),
                    'average_notional_last2' => round((float) $metrics['average_notional_last2'], 2),
                    'close_location' => round((float) $metrics['close_location'], 4),
                    'recent_high_break' => (bool) $metrics['recent_high_break'],
                    'recent_high_before' => round((float) $metrics['recent_high_before'], 6),
                    'green_bars_last4' => (int) $metrics['green_bars_last4'],
                    'higher_closes_last4' => (int) $metrics['higher_closes_last4'],
                    'momentum_conditions' => $momentumConditions,
                    'momentum_score' => $scores['momentum_score'],
                    'trend_score' => $scores['trend_score'],
                    'activity_score' => $scores['activity_score'],
                    'quality_score' => $scores['quality_score'],
                    'signal_age_seconds' => $signalAgeSeconds,
                    'universe_size' => count($symbols),
                ],
            ];
        }

        usort($out, static fn (array $a, array $b): int => ($b['score'] ?? 0) <=> ($a['score'] ?? 0));
        $out = array_slice($out, 0, max(1, $limit));

        if ($this->debug) {
            Log::info('[ScannerV56] debug counters', array_merge($drops, [
                'as_of_ts_est' => $asOfTsEst,
                'asset_type' => $assetType,
                'effective_move_20m_floor' => $effectiveMove20mFloor,
                'effective_recent_rvol_floor' => $effectiveRecentRvolFloor,
                'returned' => count($out),
                'pid' => getmypid(),
            ]));
        }

        return $out;
    }

    /** @return array<int, string> */
    private function loadUniverse(string $assetType, bool $skipCache): array
    {
        $cacheKey = "scan_v56:universe_symbols:{$assetType}";
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
     * @return array<string, float|int|bool|string>|null
     */
    private function calculateMetrics(array $bars, int $atrPeriod, int $rvolLookback): ?array
    {
        $count = count($bars);
        if ($count < max(4, $this->minimumBars)) {
            return null;
        }

        $ema9 = null;
        $ema21 = null;
        $trueRanges = [];
        $volumes = [];
        $rvols = [];
        $notionals = [];
        $cumPv = 0.0;
        $cumVolume = 0.0;
        $previousClose = null;
        $k9 = 2.0 / 10.0;
        $k21 = 2.0 / 22.0;

        foreach ($bars as $bar) {
            $open = (float) $bar->open;
            $high = (float) $bar->high;
            $low = (float) $bar->low;
            $close = (float) $bar->close;
            $volume = max(0.0, (float) $bar->volume);

            if (
                $open <= 0
                || $high <= 0
                || $low <= 0
                || $close <= 0
                || $high < $low
                || $high < max($open, $close)
                || $low > min($open, $close)
            ) {
                return null;
            }

            $typical = ($high + $low + $close) / 3.0;
            $cumPv += $typical * $volume;
            $cumVolume += $volume;

            $ema9 = $ema9 === null ? $close : (($close * $k9) + ($ema9 * (1.0 - $k9)));
            $ema21 = $ema21 === null ? $close : (($close * $k21) + ($ema21 * (1.0 - $k21)));

            $tr = $previousClose === null
                ? $high - $low
                : max($high - $low, abs($high - $previousClose), abs($low - $previousClose));
            $trueRanges[] = max(0.0, $tr);
            $previousClose = $close;

            $priorVolumes = array_slice($volumes, -min($rvolLookback, count($volumes)));
            $avgPriorVolume = $priorVolumes === [] ? 0.0 : array_sum($priorVolumes) / count($priorVolumes);
            $rvols[] = $avgPriorVolume > 0 ? $volume / $avgPriorVolume : 0.0;
            $volumes[] = $volume;
            $notionals[] = $close * $volume;
        }

        $last = $bars[$count - 1];
        $lastClose = (float) $last->close;
        $lastOpen = (float) $last->open;
        $lastHigh = (float) $last->high;
        $lastLow = (float) $last->low;
        $vwap = $cumVolume > 0 ? $cumPv / $cumVolume : 0.0;
        if ($vwap <= 0 || $ema9 === null || $ema21 === null) {
            return null;
        }

        $atrSlice = array_slice($trueRanges, -min($atrPeriod, count($trueRanges)));
        $atr = $atrSlice === [] ? 0.0 : array_sum($atrSlice) / count($atrSlice);
        if ($atr <= 0) {
            return null;
        }

        $closeAt = static function (array $source, int $index): float {
            $index = max(0, min(count($source) - 1, $index));

            return (float) $source[$index]->close;
        };

        $previousCloseValue = $closeAt($bars, $count - 2);
        $twoBarsBackClose = $closeAt($bars, $count - 3);
        $fourBarsBackClose = $closeAt($bars, $count - 5);

        $move5mPct = $previousCloseValue > 0
            ? (($lastClose - $previousCloseValue) / $previousCloseValue) * 100.0
            : 0.0;
        $priorMove5mPct = $twoBarsBackClose > 0
            ? (($previousCloseValue - $twoBarsBackClose) / $twoBarsBackClose) * 100.0
            : 0.0;
        $move10mPct = $twoBarsBackClose > 0
            ? (($lastClose - $twoBarsBackClose) / $twoBarsBackClose) * 100.0
            : 0.0;
        $move20mPct = $fourBarsBackClose > 0
            ? (($lastClose - $fourBarsBackClose) / $fourBarsBackClose) * 100.0
            : 0.0;
        $accelerationPct = $move5mPct - $priorMove5mPct;

        $range = max(1e-9, $lastHigh - $lastLow);
        $closeLocation = ($lastClose - $lastLow) / $range;

        $recentHighStart = max(0, $count - 1 - max(2, $this->recentHighLookbackBars));
        $recentHighBefore = 0.0;
        for ($i = $recentHighStart; $i <= $count - 2; $i++) {
            $recentHighBefore = max($recentHighBefore, (float) $bars[$i]->high);
        }
        $recentHighBreak = $recentHighBefore > 0
            && $lastClose >= ($recentHighBefore * (1.0 - ($this->breakoutTolerancePct / 100.0)));

        $greenBars = 0;
        $higherCloses = 0;
        $recentStart = max(0, $count - 4);
        for ($i = $recentStart; $i < $count; $i++) {
            if ((float) $bars[$i]->close >= (float) $bars[$i]->open) {
                $greenBars++;
            }
            if ($i > $recentStart && (float) $bars[$i]->close > (float) $bars[$i - 1]->close) {
                $higherCloses++;
            }
        }

        $recentRvols = array_slice($rvols, -3);
        $recentNotionals = array_slice($notionals, -2);

        return [
            'signal_ts_est' => (string) $last->ts_est,
            'last_close' => $lastClose,
            'last_open' => $lastOpen,
            'vwap' => $vwap,
            'above_vwap' => $lastClose > $vwap,
            'above_vwap_pct' => (($lastClose - $vwap) / $vwap) * 100.0,
            'ema9' => $ema9,
            'ema21' => $ema21,
            'ema_spread_pct' => (($ema9 - $ema21) / $ema21) * 100.0,
            'atr' => $atr,
            'atr_pct' => ($atr / $lastClose) * 100.0,
            'move_5m_pct' => $move5mPct,
            'prior_move_5m_pct' => $priorMove5mPct,
            'move_10m_pct' => $move10mPct,
            'move_20m_pct' => $move20mPct,
            'acceleration_pct' => $accelerationPct,
            'current_rvol' => (float) ($rvols[count($rvols) - 1] ?? 0.0),
            'max_rvol_last3' => $recentRvols === [] ? 0.0 : max($recentRvols),
            'current_notional' => (float) ($notionals[count($notionals) - 1] ?? 0.0),
            'average_notional_last2' => $recentNotionals === []
                ? 0.0
                : array_sum($recentNotionals) / count($recentNotionals),
            'close_location' => $this->clamp($closeLocation),
            'recent_high_before' => $recentHighBefore,
            'recent_high_break' => $recentHighBreak,
            'green_bars_last4' => $greenBars,
            'higher_closes_last4' => $higherCloses,
        ];
    }

    /**
     * @param  array<string, float|int|bool|string>  $m
     * @return array<string, float>
     */
    private function scoreMetrics(array $m, int $momentumConditions): array
    {
        $move5Score = $this->clamp(((float) $m['move_5m_pct'] - 0.02) / 0.90);
        $move10Score = $this->clamp(((float) $m['move_10m_pct'] - 0.10) / 1.60);
        $move20Score = $this->clamp(((float) $m['move_20m_pct'] - 0.20) / 3.00);
        $accelerationScore = $this->clamp(((float) $m['acceleration_pct'] + 0.05) / 0.80);
        $breakoutScore = (bool) $m['recent_high_break'] ? 1.0 : 0.0;
        $conditionScore = $this->clamp($momentumConditions / 4.0);

        $momentumScore = 100.0 * (
            (0.22 * $move5Score)
            + (0.24 * $move10Score)
            + (0.18 * $move20Score)
            + (0.16 * $accelerationScore)
            + (0.12 * $breakoutScore)
            + (0.08 * $conditionScore)
        );

        $emaScore = $this->clamp(((float) $m['ema_spread_pct'] + 0.05) / 0.45);
        $vwapScore = 1.0 - $this->clamp((float) $m['above_vwap_pct'] / max(0.01, $this->maxAboveVwapPct));
        $higherCloseScore = $this->clamp((float) $m['higher_closes_last4'] / 3.0);
        $trendScore = 100.0 * ((0.40 * $emaScore) + (0.25 * $vwapScore) + (0.35 * $higherCloseScore));

        $rvolScore = $this->clamp(((float) $m['max_rvol_last3'] - 0.65) / 2.00);
        $atrScore = 1.0 - $this->clamp(abs((float) $m['atr_pct'] - 0.75) / 1.75);
        $activityScore = 100.0 * ((0.65 * $rvolScore) + (0.35 * $atrScore));

        $closeScore = $this->clamp(((float) $m['close_location'] - 0.35) / 0.60);
        $greenScore = $this->clamp((float) $m['green_bars_last4'] / 4.0);
        $qualityScore = 100.0 * ((0.65 * $closeScore) + (0.35 * $greenScore));

        $score =
            (0.46 * $momentumScore)
            + (0.22 * $trendScore)
            + (0.18 * $activityScore)
            + (0.14 * $qualityScore);

        return [
            'score' => round($this->clamp($score, 0.0, 100.0), 3),
            'momentum_score' => round($momentumScore, 3),
            'trend_score' => round($trendScore, 3),
            'activity_score' => round($activityScore, 3),
            'quality_score' => round($qualityScore, 3),
        ];
    }

    private function clamp(float $value, float $min = 0.0, float $max = 1.0): float
    {
        return max($min, min($max, $value));
    }
}
