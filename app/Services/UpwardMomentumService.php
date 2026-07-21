<?php

namespace App\Services;

use App\Support\EstTimezoneHelper;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class UpwardMomentumService
{
    // Configuration constants
    private const DEFAULT_LOOKBACK_MINUTES = 15;

    private const DEFAULT_MIN_MOVE_PCT = 0.75;

    private const NOISE_MULTIPLIER = 1.5;

    private const MIN_BARS = 5;

    private const MIN_PRICE = 1.00;

    private const MIN_VOLUME_SUM = 10000;

    private const MAX_DISTANCE_FROM_HIGH_PCT = 0.25;

    /**
     * Scan for symbols showing upward momentum
     *
     * @param  string|null  $datetime  EST datetime string (YYYY-MM-DD HH:MM:SS) or null for latest
     * @param  string  $assetType  'stock' or 'crypto'
     * @param  int  $lookbackMinutes  Minutes to look back
     * @param  float  $minMovePct  Minimum percentage move required
     * @param  float  $noiseMultiplier  Move must be >= noiseMultiplier * noisePct
     * @param  int  $minBars  Minimum number of 1-minute candles in window
     * @param  float  $minPrice  Ignore symbols below this price
     * @param  int  $minVolumeSum  Minimum total volume in window
     * @param  float  $maxDistanceFromHighPct  Last price must be within this % of window high
     */
    public function scanUpwardMomentum(
        ?string $datetime = null,
        string $assetType = 'stock',
        int $lookbackMinutes = self::DEFAULT_LOOKBACK_MINUTES,
        float $minMovePct = self::DEFAULT_MIN_MOVE_PCT,
        float $noiseMultiplier = self::NOISE_MULTIPLIER,
        int $minBars = self::MIN_BARS,
        float $minPrice = self::MIN_PRICE,
        int $minVolumeSum = self::MIN_VOLUME_SUM,
        float $maxDistanceFromHighPct = self::MAX_DISTANCE_FROM_HIGH_PCT
    ): array {
        // Determine reference time
        $latestTsEst = $this->determineReferenceTime($datetime, $assetType);

        if (! $latestTsEst) {
            return [
                'candidates' => [],
                'metadata' => [
                    'error' => 'No data found for asset type: '.$assetType,
                    'reference_time' => null,
                    'window_start' => null,
                    'window_end' => null,
                ],
            ];
        }

        // Calculate window
        $latestDt = EstTimezoneHelper::parseEstTimestamp($latestTsEst);
        $windowStart = $latestDt->copy()->subMinutes($lookbackMinutes);

        $windowStartStr = $windowStart->format('Y-m-d H:i:s');
        $windowEndStr = $latestDt->format('Y-m-d H:i:s');

        // Get raw data
        $rawData = $this->getRawDataPerSymbol($windowStartStr, $windowEndStr, $assetType, $minBars);

        // Process candidates
        $candidates = $this->processCandidates($rawData, $minMovePct, $latestDt, $lookbackMinutes, $noiseMultiplier, $minBars, $minPrice, $minVolumeSum, $maxDistanceFromHighPct);

        // Sort by timestamp DESC, then move_pct DESC
        $this->sortCandidates($candidates);

        return [
            'candidates' => $candidates,
            'metadata' => [
                'reference_time' => $latestTsEst,
                'window_start' => $windowStartStr,
                'window_end' => $windowEndStr,
                'lookback_minutes' => $lookbackMinutes,
                'min_move_pct' => $minMovePct,
                'asset_type' => $assetType,
                'filters' => [
                    'noise_multiplier' => $noiseMultiplier,
                    'min_bars' => $minBars,
                    'min_price' => $minPrice,
                    'min_volume_sum' => $minVolumeSum,
                    'max_distance_from_high_pct' => $maxDistanceFromHighPct,
                ],
            ],
        ];
    }

    /**
     * Determine reference time (EST)
     */
    private function determineReferenceTime(?string $datetime, string $assetType): ?string
    {
        if ($datetime !== null) {
            try {
                $dt = EstTimezoneHelper::parseEstTimestamp($datetime);

                return $dt->format('Y-m-d H:i:s');
            } catch (\Exception $e) {
                return null;
            }
        }

        // Get latest timestamp from database
        $result = DB::table('one_minute_prices')
            ->where('asset_type', $assetType)
            ->max('ts_est');

        return $result;
    }

    /**
     * Get aggregated data per symbol for the time window
     */
    private function getRawDataPerSymbol(string $windowStart, string $windowEnd, string $assetType, int $minBars): array
    {
        return DB::select("
            SELECT
                omp.symbol,
                omp.asset_type,
                ai.id as asset_id,
                MIN(omp.ts_est) AS first_ts_est,
                MAX(omp.ts_est) AS last_ts_est,
                SUBSTRING_INDEX(GROUP_CONCAT(omp.price ORDER BY omp.ts_est ASC), ',', 1) AS first_price,
                SUBSTRING_INDEX(GROUP_CONCAT(omp.price ORDER BY omp.ts_est DESC), ',', 1) AS last_price,
                MAX(omp.high) AS window_high,
                MIN(omp.low)  AS window_low,
                COUNT(*)  AS bars,
                STDDEV_POP(omp.price) AS price_stddev,
                SUM(omp.volume) AS volume_sum
            FROM one_minute_prices omp
            JOIN asset_info ai ON omp.symbol = ai.symbol AND omp.asset_type = ai.asset_type
            WHERE omp.ts_est BETWEEN ? AND ?
              AND omp.asset_type = ?
              AND ai.deleted_at IS NULL
            GROUP BY omp.symbol, omp.asset_type, ai.id
            HAVING bars >= ?
        ", [$windowStart, $windowEnd, $assetType, $minBars]);
    }

    /**
     * Process raw data into filtered candidates
     */
    private function processCandidates(
        array $rawData,
        float $minMovePct,
        Carbon $latestDt,
        int $lookbackMinutes,
        float $noiseMultiplier,
        int $minBars,
        float $minPrice,
        int $minVolumeSum,
        float $maxDistanceFromHighPct
    ): array {
        $candidates = [];

        foreach ($rawData as $row) {
            $symbol = $row->symbol;
            $firstPrice = (float) $row->first_price;
            $lastPrice = (float) $row->last_price;
            $windowHigh = (float) $row->window_high;
            $windowLow = (float) $row->window_low;
            $bars = (int) $row->bars;
            $volumeSum = (int) $row->volume_sum;
            $stdDev = $row->price_stddev !== null ? (float) $row->price_stddev : 0.0;
            $lastTsStr = $row->last_ts_est;

            // Basic validation
            if ($firstPrice <= 0.0 || $lastPrice <= 0.0) {
                continue;
            }
            if ($lastPrice < $minPrice) {
                continue;
            }
            if ($volumeSum < $minVolumeSum) {
                continue;
            }

            // Calculate move and noise percentages
            $movePct = (($lastPrice - $firstPrice) / $firstPrice) * 100.0;
            $noisePct = $stdDev > 0.0 ? ($stdDev / $firstPrice) * 100.0 : 0.0;

            // Apply movement filter
            if ($movePct < $minMovePct) {
                continue;
            }

            // Apply noise filter
            if ($noisePct > 0.0 && $movePct < $noiseMultiplier * $noisePct) {
                continue;
            }

            // Check if still near window high
            $distanceFromHighPct = 0.0;
            if ($windowHigh > 0.0 && $lastPrice < $windowHigh) {
                $distanceFromHighPct = ($windowHigh - $lastPrice) / $windowHigh * 100.0;
                if ($distanceFromHighPct > $maxDistanceFromHighPct) {
                    continue;
                }
            }

            // Calculate age in minutes
            $lastTs = EstTimezoneHelper::parseEstTimestamp($lastTsStr);
            $ageMinutes = $latestDt->diffInMinutes($lastTs, false);

            // Safety check: last bar should be within lookback window
            if ($ageMinutes < 0 || $ageMinutes > $lookbackMinutes + 0.01) {
                continue;
            }

            $candidates[] = [
                'symbol' => $symbol,
                'asset_id' => $row->asset_id,
                'last_price' => $lastPrice,
                'move_pct' => round($movePct, 2),
                'noise_pct' => round($noisePct, 2),
                'bars' => $bars,
                'volume_sum' => $volumeSum,
                'distance_from_high' => round($distanceFromHighPct, 3),
                'age_minutes' => round($ageMinutes, 2),
                'last_ts_est' => $lastTsStr,
                'window_high' => $windowHigh,
                'window_low' => $windowLow,
                'first_price' => $firstPrice,
            ];
        }

        return $candidates;
    }

    /**
     * Sort candidates by timestamp DESC, then move_pct DESC
     */
    private function sortCandidates(array &$candidates): void
    {
        usort($candidates, function (array $a, array $b): int {
            // Sort by last_ts_est DESC, then by move_pct DESC
            $cmpTs = strcmp($b['last_ts_est'], $a['last_ts_est']);
            if ($cmpTs !== 0) {
                return $cmpTs;
            }

            return $b['move_pct'] <=> $a['move_pct'];
        });
    }
}
