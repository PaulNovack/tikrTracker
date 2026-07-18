<?php

namespace App\Services\Trading;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class PricePatternAnalyzer
{
    /**
     * Fetch historical price snapshots at specified intervals
     * Returns price ratios normalized to entry price (entry = 1.0)
     *
     * @param  string  $signalTs  Signal timestamp (EST)
     * @param  float  $entryPrice  Price at entry
     * @param  array  $intervalsMinutes  Array of minutes back (e.g., [100, 60, 30, 10])
     * @return array Keyed by minutes back, values are price ratios (null if no data)
     */
    public function getHistoricalSnapshots(
        string $symbol,
        string $assetType,
        string $signalTs,
        float $entryPrice,
        array $intervalsMinutes = [100, 90, 80, 70, 60, 50, 40, 30, 20, 10]
    ): array {
        $snapshots = [];

        foreach ($intervalsMinutes as $minutes) {
            $ts = Carbon::parse($signalTs, 'America/New_York')->subMinutes($minutes);

            $bar = DB::table('five_minute_prices')
                ->where('symbol', $symbol)
                ->where('asset_type', $assetType)
                ->where('ts_est', '<=', $ts)
                ->orderBy('ts_est', 'desc')
                ->first();

            $snapshots[$minutes] = $bar ? ($bar->price / $entryPrice) : null;
        }

        return $snapshots;
    }

    /**
     * Check for pump exhaustion pattern (spike in 100-50m window then pullback)
     *
     * @param  array  $snapshots  Historical price ratios keyed by minutes back
     * @param  float  $threshold  Spike threshold (e.g., 1.020 = 2% above entry)
     * @return bool True if pump exhaustion detected
     */
    public function hasPumpExhaustion(array $snapshots, float $threshold = 1.020): bool
    {
        // Check 100-50m window for spikes above threshold
        $window = [];
        foreach ([100, 90, 80, 70, 60, 50] as $minutes) {
            if (isset($snapshots[$minutes]) && $snapshots[$minutes] !== null) {
                $window[] = $snapshots[$minutes];
            }
        }

        if (empty($window)) {
            return false;
        }

        return max($window) > $threshold;
    }

    /**
     * Check for inverted V pattern (pump then dump)
     *
     * @param  array  $snapshots  Historical price ratios keyed by minutes back
     * @return bool True if inverted V detected
     */
    public function hasInvertedV(array $snapshots): bool
    {
        $p100 = $snapshots[100] ?? null;
        $p60 = $snapshots[60] ?? null;
        $p30 = $snapshots[30] ?? null;
        $p10 = $snapshots[10] ?? null;

        if ($p100 === null || $p60 === null || $p30 === null || $p10 === null) {
            return false;
        }

        // Spiked 100→60, then faded 60→30→10
        return ($p60 > $p100) && ($p30 < $p60) && ($p10 < $p30);
    }

    /**
     * Check for V-shaped recovery pattern (decline then recovery)
     *
     * @param  array  $snapshots  Historical price ratios keyed by minutes back
     * @return bool True if V-pattern detected
     */
    public function hasVPattern(array $snapshots): bool
    {
        $p100 = $snapshots[100] ?? null;
        $p60 = $snapshots[60] ?? null;
        $p30 = $snapshots[30] ?? null;
        $p10 = $snapshots[10] ?? null;

        if ($p100 === null || $p60 === null || $p30 === null || $p10 === null) {
            return false;
        }

        // Declined 100→60, then recovered 60→30→10
        return ($p60 < $p100) && ($p30 > $p60) && ($p10 > $p30);
    }

    /**
     * Check for continuous decline (bear trend)
     *
     * @param  array  $snapshots  Historical price ratios keyed by minutes back
     * @return bool True if continuous decline detected
     */
    public function hasContinuousDecline(array $snapshots): bool
    {
        $p100 = $snapshots[100] ?? null;
        $p60 = $snapshots[60] ?? null;
        $p30 = $snapshots[30] ?? null;
        $p10 = $snapshots[10] ?? null;

        if ($p100 === null || $p60 === null || $p30 === null || $p10 === null) {
            return false;
        }

        // Continuous downtrend 100→60→30→10
        return ($p100 > $p60) && ($p60 > $p30) && ($p30 > $p10);
    }
}
