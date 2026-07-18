<?php

namespace App\Services\Trading;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class FadeDetectionService
{
    /**
     * Calculate fade detection features for a given symbol at a specific time.
     * Detects patterns like "spike then fade" where entry is on a weak bounce.
     *
     * @param  string  $entryTime  EST timestamp (Y-m-d H:i:s)
     * @param  int  $lookbackMinutes  How far back to look for the high (default 30)
     */
    public function calculateFadeFeatures(
        string $symbol,
        string $assetType,
        string $entryTime,
        int $lookbackMinutes = 30
    ): array {
        // Get 1-minute prices for the lookback period
        $startTime = date('Y-m-d H:i:s', strtotime($entryTime." -{$lookbackMinutes} minutes"));

        $prices = DB::table('one_minute_prices')
            ->where('symbol', $symbol)
            ->where('asset_type', $assetType)
            ->whereBetween('ts_est', [$startTime, $entryTime])
            ->orderBy('ts_est', 'asc')
            ->get(['ts_est', 'price', 'high', 'low', 'open', 'volume']);

        if ($prices->isEmpty()) {
            return [
                'pct_below_intraday_high' => null,
                'minutes_since_high' => null,
                'price_velocity_5min' => null,
                'price_velocity_10min' => null,
                'failed_rally_count' => null,
                'five_min_directional_changes' => null,
                'five_min_green_bar_pct' => null,
                'five_min_net_progress' => null,
                'consolidation_bars' => null,
                'breakout_volume_ratio' => null,
            ];
        }

        // Get 5-minute bars for pattern features
        $fiveMinStart = date('Y-m-d H:i:s', strtotime($entryTime.' -60 minutes'));
        $fiveMinBars = DB::table('five_minute_prices')
            ->where('symbol', $symbol)
            ->where('asset_type', $assetType)
            ->whereBetween('ts_est', [$fiveMinStart, $entryTime])
            ->orderBy('ts_est', 'asc')
            ->get(['ts_est', 'price', 'high', 'low', 'open', 'volume']);

        // Calculate 5-minute pattern features
        $fiveMinFeatures = $this->calculate5MinPatternFeatures($fiveMinBars, $prices);

        // Find the intraday high and when it occurred
        $highPrice = 0;
        $highTime = null;
        $entryPrice = null;

        foreach ($prices as $bar) {
            if ($bar->high > $highPrice) {
                $highPrice = $bar->high;
                $highTime = $bar->ts_est;
            }
            if ($bar->ts_est === $entryTime) {
                $entryPrice = $bar->price;
            }
        }

        // Calculate % below high
        $pctBelowHigh = null;
        if ($highPrice > 0 && $entryPrice !== null) {
            $pctBelowHigh = (($highPrice - $entryPrice) / $highPrice) * 100;
        }

        // Calculate minutes since high
        $minutesSinceHigh = null;
        if ($highTime !== null) {
            $minutesSinceHigh = (strtotime($entryTime) - strtotime($highTime)) / 60;
        }

        // Calculate price velocity (% change over last N minutes)
        $priceVelocity5min = $this->calculateVelocity($prices, $entryTime, 5);
        $priceVelocity10min = $this->calculateVelocity($prices, $entryTime, 10);

        // Count failed rallies (attempts to go up that failed)
        $failedRallyCount = $this->countFailedRallies($prices, 15);

        return array_merge([
            'pct_below_intraday_high' => $pctBelowHigh,
            'minutes_since_high' => $minutesSinceHigh,
            'price_velocity_5min' => $priceVelocity5min,
            'price_velocity_10min' => $priceVelocity10min,
            'failed_rally_count' => $failedRallyCount,
        ], $fiveMinFeatures);
    }

    /**
     * Calculate 5-minute pattern features for ML scoring.
     * Analyzes consolidation, breakout, and trend characteristics.
     *
     * @param  Collection  $fiveMinBars  5-minute bars (60 minutes before entry)
     * @param  Collection  $oneMinBars  1-minute bars (for volume reference)
     */
    private function calculate5MinPatternFeatures(Collection $fiveMinBars, Collection $oneMinBars): array
    {
        if ($fiveMinBars->isEmpty()) {
            return [
                'five_min_directional_changes' => null,
                'five_min_green_bar_pct' => null,
                'five_min_net_progress' => null,
                'consolidation_bars' => null,
                'breakout_volume_ratio' => null,
            ];
        }

        // 1. Directional changes (choppiness indicator)
        // Count how many times price direction reverses
        $directionChanges = 0;
        $prevDirection = null;
        for ($i = 1; $i < count($fiveMinBars); $i++) {
            $prev = $fiveMinBars[$i - 1];
            $current = $fiveMinBars[$i];
            $direction = $current->price > $prev->price ? 'up' : 'down';
            if ($prevDirection !== null && $direction !== $prevDirection) {
                $directionChanges++;
            }
            $prevDirection = $direction;
        }

        // 2. Green bar percentage (trend strength)
        // What % of bars close higher than they opened
        $greenBars = 0;
        foreach ($fiveMinBars as $bar) {
            if ($bar->price >= $bar->open) {
                $greenBars++;
            }
        }
        $greenBarPct = count($fiveMinBars) > 0 ? ($greenBars / count($fiveMinBars)) * 100 : null;
        if ($greenBarPct !== null) {
            // Column five_min_green_bar_pct is decimal(4,1), cap hard upper bound.
            $greenBarPct = min(99.9, max(0.0, $greenBarPct));
        }

        // 3. Net progress (directional efficiency)
        // Sum of all (close - open) divided by total range
        $netProgress = 0;
        $totalRange = 0;
        foreach ($fiveMinBars as $bar) {
            $netProgress += ($bar->price - $bar->open);
            $totalRange += ($bar->high - $bar->low);
        }
        // Column five_min_net_progress is decimal(4,3), so keep this as ratio (not percent).
        $netProgressRatio = $totalRange > 0 ? ($netProgress / $totalRange) : null;
        if ($netProgressRatio !== null) {
            $netProgressRatio = min(9.999, max(-9.999, $netProgressRatio));
        }

        // 4. Consolidation bars (pattern tightness)
        // Count bars with range < 50% of average range (tight consolidation)
        $ranges = $fiveMinBars->map(fn ($bar) => $bar->high - $bar->low);
        $avgRange = $ranges->avg();
        $consolidationBars = 0;
        if ($avgRange > 0) {
            foreach ($fiveMinBars as $bar) {
                $barRange = $bar->high - $bar->low;
                if ($barRange < ($avgRange * 0.5)) {
                    $consolidationBars++;
                }
            }
        }

        // 5. Breakout volume ratio (volume spike at breakout)
        // Compare last bar volume to average volume of previous bars
        $breakoutVolRatio = null;
        if (count($fiveMinBars) >= 2) {
            $lastBar = $fiveMinBars->last();
            $prevBars = $fiveMinBars->slice(0, -1);
            $avgVolume = $prevBars->avg('volume');
            if ($avgVolume > 0 && $lastBar->volume > 0) {
                $breakoutVolRatio = $lastBar->volume / $avgVolume;
            }
        }

        return [
            'five_min_directional_changes' => $directionChanges,
            'five_min_green_bar_pct' => $greenBarPct !== null ? round($greenBarPct, 1) : null,
            'five_min_net_progress' => $netProgressRatio !== null ? round($netProgressRatio, 3) : null,
            'consolidation_bars' => $consolidationBars,
            'breakout_volume_ratio' => $breakoutVolRatio !== null ? round($breakoutVolRatio, 4) : null,
        ];
    }

    /**
     * Calculate price velocity (% change) over the last N minutes.
     */
    private function calculateVelocity(Collection $prices, string $entryTime, int $minutes): ?float
    {
        $startTime = date('Y-m-d H:i:s', strtotime($entryTime." -{$minutes} minutes"));

        $priceAtStart = null;
        $priceAtEnd = null;

        foreach ($prices as $bar) {
            if ($bar->ts_est >= $startTime && $priceAtStart === null) {
                $priceAtStart = $bar->price;
            }
            if ($bar->ts_est === $entryTime) {
                $priceAtEnd = $bar->price;
            }
        }

        if ($priceAtStart === null || $priceAtEnd === null || $priceAtStart == 0) {
            return null;
        }

        return (($priceAtEnd - $priceAtStart) / $priceAtStart) * 100;
    }

    /**
     * Count failed rally attempts in the last N minutes.
     * A failed rally is when price tries to go up but fails to sustain.
     * Pattern: bar goes up, next bar(s) go down below the starting point.
     */
    private function countFailedRallies(Collection $prices, int $lookbackMinutes): int
    {
        if ($prices->count() < 3) {
            return 0;
        }

        // Take last N bars
        $recentBars = $prices->slice(-$lookbackMinutes)->values();
        $failedRallies = 0;

        for ($i = 0; $i < $recentBars->count() - 2; $i++) {
            $current = $recentBars[$i];
            $next = $recentBars[$i + 1];
            $afterNext = $recentBars[$i + 2];

            // Check if next bar went up
            $wentUp = $next->price > $current->price;

            // Check if it then failed (subsequent bars below starting point)
            $thenFailed = $afterNext->price < $current->price;

            if ($wentUp && $thenFailed) {
                $failedRallies++;
            }
        }

        return $failedRallies;
    }
}
