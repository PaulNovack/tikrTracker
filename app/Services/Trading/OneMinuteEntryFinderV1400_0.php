<?php

namespace App\Services\Trading;

use Illuminate\Support\Facades\DB;

/**
 * One-Minute Entry Finder for Tight Stops Clean Trend (V1400.0)
 *
 * Finds optimal 1-minute entry points for low-choppiness trend signals
 *
 * Entry Logic:
 * - CLEAN_CONTINUATION: Smooth continuation with volume and minimal pullback
 * - TIGHT_CONSOLIDATION_BREAK: Breakout from very tight consolidation pattern
 * - Focus: Entries suitable for 0.5-1% stop losses (tight risk management)
 */
class OneMinuteEntryFinderV1400_0
{
    use HasPriceTables;

    // ── Entry Finder Configuration (public so pipeline can read) ──
    public float $entryMinVolRatio;

    public float $entryMinTrendPct;

    public float $entryMaxDrawdownPct;

    public bool $requireAboveVwap;

    public bool $requireEma9AboveEma21;

    public float $maxAtrPct;

    public float $minEntryScore;

    public float $entryMinVolRatioFilter;

    public float $maxPctBelowIntradayHigh;

    public int $maxMinutesSinceHigh;

    public string $excludedEntryTypes;

    public string $preferredEntryTypes;

    /** @return array<string, mixed> */
    public function entryConfig(): array
    {
        return [
            'entry_min_vol_ratio' => $this->entryMinVolRatio,
            'entry_min_trend_pct' => $this->entryMinTrendPct,
            'entry_max_drawdown_pct' => $this->entryMaxDrawdownPct,
            'require_above_vwap' => $this->requireAboveVwap,
            'require_ema9_above_ema21' => $this->requireEma9AboveEma21,
            'max_atr_pct' => $this->maxAtrPct,
            'min_entry_score' => $this->minEntryScore,
            'entry_min_vol_ratio_filter' => $this->entryMinVolRatioFilter,
            'max_pct_below_intraday_high' => $this->maxPctBelowIntradayHigh,
            'max_minutes_since_high' => $this->maxMinutesSinceHigh,
            'excluded_entry_types' => $this->excludedEntryTypes,
            'preferred_entry_types' => $this->preferredEntryTypes,
        ];
    }

    public function __construct()
    {
        $this->entryMinVolRatio = (float) env('TRADING_V1400_ENTRY_MIN_VOL_RATIO', 1.5);
        $this->entryMinTrendPct = (float) env('TRADING_V1400_ENTRY_MIN_TREND_PCT', 0.25);
        $this->entryMaxDrawdownPct = (float) env('TRADING_V1400_ENTRY_MAX_DRAWDOWN_PCT', 0.50);
        $this->requireAboveVwap = (bool) env('TRADING_V1400_REQUIRE_ABOVE_VWAP', true);
        $this->requireEma9AboveEma21 = (bool) env('TRADING_V1400_REQUIRE_EMA9_ABOVE_EMA21', true);
        $this->maxAtrPct = (float) env('TRADING_V1400_MAX_ATR_PCT', 0.20);
        $this->minEntryScore = (float) env('TRADING_V1400_MIN_ENTRY_SCORE', 85);
        $this->entryMinVolRatioFilter = (float) env('TRADING_V1400_ENTRY_MIN_VOL_RATIO_FILTER', 2.0);
        $this->maxPctBelowIntradayHigh = (float) env('TRADING_V1400_MAX_PCT_BELOW_INTRADAY_HIGH', 0.15);
        $this->maxMinutesSinceHigh = (int) env('TRADING_V1400_MAX_MINUTES_SINCE_HIGH', 5);
        $this->excludedEntryTypes = env('TRADING_V1400_EXCLUDED_ENTRY_TYPES', 'MICRO_PULLBACK_HOLD');
        $this->preferredEntryTypes = env('TRADING_V1400_PREFERRED_ENTRY_TYPES', 'TIGHT_CONSOLIDATION_BREAK');
    }

    public function findBestLong(
        string $symbol,
        string $signalTsEst,
        string $asOfTsEst,
        int $beforeMinutes = 5,
        int $afterMinutes = 15,
        int $volLookback = 20,
        int $pivotLookback = 15,
        string $fillMethod = 'next_open'
    ): ?array {
        // Load config
        $entryMinVolRatio = $this->entryMinVolRatio;
        $entryMinTrendPct = $this->entryMinTrendPct;
        $entryMaxDrawdownPct = $this->entryMaxDrawdownPct;

        // Quality filters
        $requireAboveVwap = $this->requireAboveVwap;
        $requireEma9AboveEma21 = $this->requireEma9AboveEma21;
        $maxAtrPct = $this->maxAtrPct;
        $minEntryScore = $this->minEntryScore;
        $minVolRatioFilter = $this->entryMinVolRatioFilter;
        $maxPctBelowIntradayHigh = $this->maxPctBelowIntradayHigh;
        $maxMinutesSinceHigh = $this->maxMinutesSinceHigh;

        $excludedEntryTypes = $this->excludedEntryTypes;
        $excludedTypes = is_string($excludedEntryTypes) ? array_map('trim', explode(',', $excludedEntryTypes)) : [];

        $preferredEntryTypes = $this->preferredEntryTypes;
        $preferredTypes = is_string($preferredEntryTypes) ? array_map('trim', explode(',', $preferredEntryTypes)) : [];

        // Get the signal bar to establish context
        $signalBar = DB::selectOne(
            'SELECT price, high, low, open, volume
             FROM five_minute_prices
             WHERE symbol = ? AND ts_est = ?',
            [$symbol, $signalTsEst]
        );

        if (! $signalBar) {
            return null;
        }

        $signalHigh = (float) $signalBar->high;
        $signalLow = (float) $signalBar->low;
        $signalClose = (float) $signalBar->price;

        // Calculate simple ATR approximation from recent bars if needed
        $atrApprox = $this->estimateATR($symbol, $signalTsEst);
        $atr = $atrApprox;
        $atrPct = $atr ? ($atr / $signalClose) * 100 : null;

        // Get 1-minute bars after the signal
        $searchStart = $signalTsEst;
        // Fix look-ahead bias: only use data available at asOfTsEst, not future data
        // In live mode with rolling window, asOfTsEst moves forward naturally
        $searchEnd = $asOfTsEst;

        $bars = $this->dbSelect(
            'SELECT ts_est, price, open, high, low, volume, vwap, above_vwap, atr_pct, ema9, ema21, ema9_above_ema21
             FROM one_minute_prices
             WHERE symbol = ? 
               AND trading_date_est = DATE(?)
               AND ts_est > ?
               AND ts_est <= ?
             ORDER BY ts_est ASC
             LIMIT 20',
            [$symbol, $signalTsEst, $searchStart, $searchEnd]
        );

        if (empty($bars)) {
            return null;
        }

        // Calculate average volume
        $avgVol = $this->getAvgVolume($symbol, $signalTsEst, $volLookback);

        // Look for clean continuation entries with minimal risk
        $entry = $this->findCleanContinuationEntry(
            $bars,
            $signalHigh,
            $signalLow,
            $signalClose,
            $avgVol,
            $atr,
            $entryMinVolRatio,
            $entryMinTrendPct,
            $entryMaxDrawdownPct,
            $requireAboveVwap,
            $requireEma9AboveEma21,
            $maxAtrPct,
            $minEntryScore,
            $minVolRatioFilter,
            $excludedTypes,
            $preferredTypes
        );

        if (! $entry) {
            return null;
        }

        // Calculate tight stop loss
        // For tight stop strategy: use ATR-based or 0.5-1% below entry
        $atrBasedStop = $atr ? $entry['price'] - ($atr * 1.5) : null;
        $pctBasedStop = $entry['price'] * 0.995; // 0.5% below entry

        // Use the tighter of the two, but not below signal low
        $stopPrice = max(
            $atrBasedStop ?? $pctBasedStop,
            $pctBasedStop,
            $signalLow - ($atr ?? 0.05)
        );

        $riskPerShare = $entry['price'] - $stopPrice;
        $riskPct = ($riskPerShare / $entry['price']) * 100;

        // For tight stops, use ATR-based trailing (tighter than usual)
        $suggestedTrailingStop = $atr ? $atr * 1.5 : $entry['price'] * 0.008;
        $suggestedTrailingStopPct = ($suggestedTrailingStop / $entry['price']) * 100;

        $bestEntry = [
            'type' => $entry['type'],
            'entry_ts_est' => $entry['ts_est'],
            'entry' => $entry['price'],
            'stop' => $stopPrice,
            'risk_pct' => round($riskPct, 2),
            'risk_per_share' => round($riskPerShare, 4),
            'score' => $entry['score'],
            'vol_ratio' => $entry['vol_ratio'],
            'atr' => $atr,
            'atr_pct' => $atrPct,
            'suggested_trailing_stop' => round($suggestedTrailingStop, 4),
            'suggested_trailing_stop_pct' => round($suggestedTrailingStopPct, 2),
            'trend_smoothness' => $entry['trend_smoothness'] ?? null,
        ];

        return [
            'ok' => true,
            'best_entry' => $bestEntry,
        ];
    }

    private function findCleanContinuationEntry(
        array $bars,
        float $signalHigh,
        float $signalLow,
        float $signalClose,
        float $avgVol,
        ?float $atr,
        float $minVolRatio,
        float $minTrendPct,
        float $maxDrawdownPct,
        bool $requireAboveVwap,
        bool $requireEma9AboveEma21,
        float $maxAtrPct,
        float $minEntryScore,
        float $minVolRatioFilter,
        array $excludedTypes,
        array $preferredTypes
    ): ?array {
        $bestEntry = null;
        $highestScore = 0;

        // Track high water mark for drawdown calculation
        $highWaterMark = $signalClose;

        foreach ($bars as $i => $bar) {
            $barPrice = (float) $bar->price;
            $barHigh = (float) $bar->high;
            $barLow = (float) $bar->low;
            $barOpen = (float) $bar->open;
            $barVol = (float) $bar->volume;
            $volRatio = $avgVol > 0 ? $barVol / $avgVol : 1.0;

            // Apply quality filters
            $barAboveVwap = isset($bar->above_vwap) ? (bool) $bar->above_vwap : true;
            $barAtrPct = isset($bar->atr_pct) ? (float) $bar->atr_pct : 0.0;
            $barEma9AboveEma21 = isset($bar->ema9_above_ema21) ? (bool) $bar->ema9_above_ema21 : true;

            // Skip if fails VWAP filter
            if ($requireAboveVwap && ! $barAboveVwap) {
                continue;
            }

            // Skip if fails EMA9 above EMA21 filter
            if ($requireEma9AboveEma21 && ! $barEma9AboveEma21) {
                continue;
            }

            // Skip if fails ATR filter (too volatile)
            if ($maxAtrPct > 0 && $barAtrPct > $maxAtrPct) {
                continue;
            }

            // Skip if fails minimum volume ratio filter
            if ($minVolRatioFilter > 0 && $volRatio < $minVolRatioFilter) {
                continue;
            }

            // Update high water mark
            $highWaterMark = max($highWaterMark, $barHigh);

            // Calculate drawdown from high water mark
            $drawdownPct = (($highWaterMark - $barLow) / $highWaterMark) * 100;

            // Calculate trend from signal close
            $trendPct = (($barPrice - $signalClose) / $signalClose) * 100;

            // Pattern 1: Clean continuation - smooth move with volume
            // Look for: price continuing upward, good volume, minimal drawdown
            if ($trendPct >= $minTrendPct && $volRatio >= $minVolRatio && $drawdownPct <= $maxDrawdownPct) {
                // Calculate trend smoothness (lower drawdown relative to gain = smoother)
                $trendSmoothness = $trendPct > 0 ? $trendPct / max($drawdownPct, 0.10) : 0;

                $score = 85 + min(15, $trendSmoothness * 2); // Bonus for smooth trends

                // Apply min score filter
                if ($score < $minEntryScore) {
                    continue;
                }

                // Skip if entry type is excluded
                if (in_array('CLEAN_CONTINUATION', $excludedTypes)) {
                    continue;
                }

                if ($score > $highestScore) {
                    $bestEntry = [
                        'type' => 'CLEAN_CONTINUATION',
                        'ts_est' => $bar->ts_est,
                        'price' => $barPrice,
                        'score' => $score,
                        'vol_ratio' => round($volRatio, 2),
                        'trend_smoothness' => round($trendSmoothness, 2),
                    ];
                    $highestScore = $score;
                }
            }

            // Pattern 2: Tight consolidation breakout (PREFERRED PATTERN - 39.2% WR)
            // Look for: very narrow range followed by volume breakout
            if ($i >= 3) {
                $recentBars = array_slice($bars, max(0, $i - 3), 3);
                $recentHighs = array_map(fn ($b) => (float) $b->high, $recentBars);
                $recentLows = array_map(fn ($b) => (float) $b->low, $recentBars);

                $consolidationHigh = max($recentHighs);
                $consolidationLow = min($recentLows);
                $consolidationRange = (($consolidationHigh - $consolidationLow) / $consolidationLow) * 100;

                // Very tight range (< 0.3%) breaking out with volume
                if ($consolidationRange <= 0.30 && $barPrice > $consolidationHigh && $volRatio >= $minVolRatio * 1.2) {
                    // Boost score if this is a preferred entry type
                    $scoreBonus = in_array('TIGHT_CONSOLIDATION_BREAK', $preferredTypes) ? 5 : 0;
                    $score = 80 + ($volRatio >= 2.0 ? 10 : 0) + $scoreBonus;

                    // Apply min score filter
                    if ($score < $minEntryScore) {
                        continue;
                    }

                    // Skip if entry type is excluded
                    if (in_array('TIGHT_CONSOLIDATION_BREAK', $excludedTypes)) {
                        continue;
                    }

                    if ($score > $highestScore) {
                        $bestEntry = [
                            'type' => 'TIGHT_CONSOLIDATION_BREAK',
                            'ts_est' => $bar->ts_est,
                            'price' => $consolidationHigh + 0.01,
                            'score' => $score,
                            'vol_ratio' => round($volRatio, 2),
                            'consolidation_range_pct' => round($consolidationRange, 3),
                        ];
                        $highestScore = $score;
                    }
                }
            }

            // Pattern 3: Micro-pullback entry
            // Look for: very shallow pullback (< 0.25%) that holds, then continuation
            if ($i > 0 && $trendPct >= 0) {
                $prevBar = $bars[$i - 1];
                $prevPrice = (float) $prevBar->price;

                $pullbackPct = (($prevPrice - $barLow) / $prevPrice) * 100;

                // Micro pullback that holds above signal close
                if ($pullbackPct <= 0.25 && $barLow > $signalClose && $barPrice > $prevPrice && $volRatio >= $minVolRatio) {
                    $score = 75 + ($volRatio >= 2.0 ? 10 : 0);

                    // Apply min score filter
                    if ($score < $minEntryScore) {
                        continue;
                    }

                    // Skip if entry type is excluded (default excludes MICRO_PULLBACK_HOLD)
                    if (in_array('MICRO_PULLBACK_HOLD', $excludedTypes)) {
                        continue;
                    }

                    if ($score > $highestScore) {
                        $bestEntry = [
                            'type' => 'MICRO_PULLBACK_HOLD',
                            'ts_est' => $bar->ts_est,
                            'price' => $barPrice,
                            'score' => $score,
                            'vol_ratio' => round($volRatio, 2),
                            'pullback_pct' => round($pullbackPct, 3),
                        ];
                        $highestScore = $score;
                    }
                }
            }
        }

        return $bestEntry;
    }

    private function getAvgVolume(string $symbol, string $asOfTsEst, int $lookback): float
    {
        $result = DB::selectOne(
            'SELECT AVG(volume) as avg_vol
             FROM one_minute_prices
             WHERE symbol = ?
               AND trading_date_est = DATE(?)
               AND ts_est < ?
             ORDER BY ts_est DESC
             LIMIT ?',
            [$symbol, $asOfTsEst, $asOfTsEst, $lookback]
        );

        return $result && $result->avg_vol ? (float) $result->avg_vol : 1000.0;
    }

    /**
     * Estimate ATR from recent 5-minute bars
     * Simple approximation: average of (high - low) over last 14 bars
     */
    private function estimateATR(string $symbol, string $asOfTsEst, int $lookback = 14): float
    {
        $result = DB::selectOne(
            'SELECT AVG(high - low) as avg_range
             FROM five_minute_prices
             WHERE symbol = ?
               AND trading_date_est = DATE(?)
               AND ts_est <= ?
             ORDER BY ts_est DESC
             LIMIT ?',
            [$symbol, $asOfTsEst, $asOfTsEst, $lookback]
        );

        return $result && $result->avg_range ? (float) $result->avg_range : 0.10;
    }
}
