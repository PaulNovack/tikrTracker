<?php

namespace App\Services;

use App\Support\EstTimezoneHelper;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class ConfirmedMomentumService
{
    public function scanConfirmedMomentum(
        Carbon $time,
        string $assetType = 'stocks',
        int $lookbackMinutes = 30,
        float $minMovePct = 0.5,
        float $noiseMultiplier = 3.0,
        float $maxDistanceFromHighPct = 2.0,
        float $minPrice = 0.0,
        int $minVolumeSum1m = 0,
        int $minBars1m = 5,
        float $strongBodyMinPct = 0.3,
        int $fiveMinBarsCount = 5,
        float $fiveMinRangeFactor = 5.0,
        int $minBars5m = 3
    ): array {
        // Convert to EST timezone
        $latestDt = $time->setTimezone('America/New_York');
        $windowStart = $latestDt->copy()->subMinutes($lookbackMinutes);

        // ---------------------- STEP 1: 1-MINUTE MOMENTUM SCAN --------------
        $rawCandidates = $this->scanOneMinuteMomentum(
            $latestDt,
            $windowStart,
            $assetType,
            $minMovePct,
            $noiseMultiplier,
            $maxDistanceFromHighPct,
            $minPrice,
            $minVolumeSum1m,
            $minBars1m,
            $lookbackMinutes
        );

        // If no 1m momentum candidates, return empty
        if (empty($rawCandidates)) {
            return [
                'candidates' => [],
                'metadata' => [
                    'reference_time_est' => $latestDt->format('Y-m-d H:i:s'),
                    'window_1m_start' => $windowStart->format('Y-m-d H:i:s'),
                    'window_1m_end' => $latestDt->format('Y-m-d H:i:s'),
                    'lookback_minutes' => $lookbackMinutes,
                    'asset_type' => $assetType,
                    'message' => 'No 1-minute momentum candidates found.',
                ],
            ];
        }

        // ---------------------- STEP 2: 5-MINUTE CONFIRMATION --------------
        $symbols = array_keys($rawCandidates);
        $fiveData = $this->getFiveMinuteData(
            $symbols,
            $assetType,
            $latestDt,
            $fiveMinBarsCount,
            $fiveMinRangeFactor,
            $minBars5m
        );

        // ---------------------- STEP 3: STRONG BREAKOUT FILTER --------------
        $candidates = $this->filterStrongBreakouts(
            $rawCandidates,
            $fiveData,
            $strongBodyMinPct
        );

        // Sort by most recent and strongest
        $candidates = $this->sortCandidates($candidates);

        return [
            'candidates' => $candidates,
            'metadata' => [
                'reference_time_est' => $latestDt->format('Y-m-d H:i:s'),
                'window_1m_start' => $windowStart->format('Y-m-d H:i:s'),
                'window_1m_end' => $latestDt->format('Y-m-d H:i:s'),
                'window_5m_start' => $latestDt->copy()->subMinutes($fiveMinBarsCount * $fiveMinRangeFactor)->format('Y-m-d H:i:s'),
                'lookback_minutes' => $lookbackMinutes,
                'five_min_range_minutes' => $fiveMinBarsCount * $fiveMinRangeFactor,
                'asset_type' => $assetType,
                'filters' => [
                    'min_move_pct' => $minMovePct,
                    'noise_multiplier' => $noiseMultiplier,
                    'max_distance_from_high_pct' => $maxDistanceFromHighPct,
                    'strong_body_min_pct' => $strongBodyMinPct,
                    'min_bars_5m' => $minBars5m,
                ],
            ],
        ];
    }

    private function scanOneMinuteMomentum(
        Carbon $latestDt,
        Carbon $windowStart,
        string $assetType,
        float $minMovePct,
        float $noiseMultiplier,
        float $maxDistanceFromHighPct,
        float $minPrice,
        int $minVolumeSum1m,
        int $minBars1m,
        int $lookbackMinutes
    ): array {
        $results = DB::select("
            SELECT 
                symbol,
                SUBSTRING_INDEX(GROUP_CONCAT(price ORDER BY ts_est ASC), ',', 1) AS first_price,
                SUBSTRING_INDEX(GROUP_CONCAT(price ORDER BY ts_est DESC), ',', 1) AS last_price,
                MAX(high) AS window_high,
                MIN(low) AS window_low,
                SUBSTRING_INDEX(GROUP_CONCAT(ts_est ORDER BY ts_est DESC), ',', 1) AS last_ts_est,
                COUNT(*) AS bars,
                STDDEV_POP(price) AS price_stddev,
                SUM(volume) AS volume_sum
            FROM one_minute_prices 
            WHERE asset_type = ?
              AND ts_est BETWEEN ? AND ?
            GROUP BY symbol, asset_type
            HAVING bars >= ?
        ", [
            $assetType,
            $windowStart->format('Y-m-d H:i:s'),
            $latestDt->format('Y-m-d H:i:s'),
            $minBars1m,
        ]);

        $rawCandidates = [];

        foreach ($results as $row) {
            $symbol = $row->symbol;
            $firstPrice = (float) $row->first_price;
            $lastPrice = (float) $row->last_price;
            $windowHigh = (float) $row->window_high;
            $priceStddev = (float) $row->price_stddev;
            $lastTsStr = $row->last_ts_est;
            $bars = (int) $row->bars;
            $volumeSum = (int) $row->volume_sum;

            // Basic validation
            if ($firstPrice <= 0.0 || $lastPrice <= 0.0) {
                continue;
            }
            if ($lastPrice < $minPrice) {
                continue;
            }

            // Calculate move and noise percentages using PHP script method
            $movePct = (($lastPrice - $firstPrice) / $firstPrice) * 100.0;
            $noisePct = $priceStddev > 0.0 ? ($priceStddev / $firstPrice) * 100.0 : 0.0;

            // Apply filters
            if ($movePct < $minMovePct) {
                continue;
            }
            if ($noisePct > 0.0 && $movePct < $noiseMultiplier * $noisePct) {
                continue;
            }
            if ($volumeSum < $minVolumeSum1m) {
                continue;
            }

            // Distance from high filter
            $distanceFromHighPct = 0.0;
            if ($windowHigh > 0.0 && $lastPrice < $windowHigh) {
                $distanceFromHighPct = ($windowHigh - $lastPrice) / $windowHigh * 100.0;
                if ($distanceFromHighPct > $maxDistanceFromHighPct) {
                    continue;
                }
            }

            // Age check
            $lastTs = EstTimezoneHelper::parseEstTimestamp($lastTsStr);
            $ageMinutes = $latestDt->diffInMinutes($lastTs);

            if ($ageMinutes > $lookbackMinutes) {
                continue;
            }

            $rawCandidates[$symbol] = [
                'symbol' => $symbol,
                'last_price' => $lastPrice,
                'move_pct' => $movePct,
                'noise_pct' => $noisePct,
                'bars_1m' => $bars,
                'volume_sum_1m' => $volumeSum,
                'distance_from_high' => $distanceFromHighPct,
                'age_minutes' => $ageMinutes,
                'last_ts_est_1m' => $lastTsStr,
            ];
        }

        return $rawCandidates;
    }

    private function getFiveMinuteData(
        array $symbols,
        string $assetType,
        Carbon $latestDt,
        int $fiveMinBarsCount,
        float $fiveMinRangeFactor,
        int $minBars5m
    ): array {
        if (empty($symbols)) {
            return [];
        }

        $fiveRangeMinutes = $fiveMinBarsCount * $fiveMinRangeFactor;
        $fiveStart = $latestDt->copy()->subMinutes($fiveRangeMinutes);

        // Build placeholders for symbols
        $symbolPlaceholders = str_repeat('?,', count($symbols) - 1).'?';
        $params = array_merge(
            [$assetType, $fiveStart->format('Y-m-d H:i:s'), $latestDt->format('Y-m-d H:i:s')],
            $symbols,
            [$minBars5m]
        );

        $results = DB::select("
            SELECT
                symbol,
                MIN(ts_est) AS first5_ts_est,
                MAX(ts_est) AS last5_ts_est,
                MAX(high) AS recent5_high,
                SUBSTRING_INDEX(GROUP_CONCAT(open ORDER BY ts_est DESC), ',', 1) AS last5_open,
                SUBSTRING_INDEX(GROUP_CONCAT(price ORDER BY ts_est DESC), ',', 1) AS last5_close,
                COUNT(*) AS bars5
            FROM five_minute_prices
            WHERE asset_type = ?
              AND ts_est BETWEEN ? AND ?
              AND symbol IN ($symbolPlaceholders)
            GROUP BY symbol, asset_type
            HAVING bars5 >= ?
        ", $params);

        $fiveData = [];

        foreach ($results as $row) {
            $symbol = $row->symbol;
            $recent5High = (float) $row->recent5_high;
            $last5Open = (float) $row->last5_open;
            $last5Close = (float) $row->last5_close;
            $bars5 = (int) $row->bars5;
            $last5TsStr = $row->last5_ts_est;

            if ($bars5 < $minBars5m || $recent5High <= 0.0 || $last5Open <= 0.0) {
                continue;
            }

            $bodyPct = (($last5Close - $last5Open) / $last5Open) * 100.0;

            $fiveData[$symbol] = [
                'recent5_high' => $recent5High,
                'last5_open' => $last5Open,
                'last5_close' => $last5Close,
                'bars5' => $bars5,
                'body_pct_5m' => $bodyPct,
                'last_ts_est_5m' => $last5TsStr,
            ];
        }

        return $fiveData;
    }

    private function filterStrongBreakouts(
        array $rawCandidates,
        array $fiveData,
        float $strongBodyMinPct
    ): array {
        $candidates = [];

        foreach ($rawCandidates as $symbol => $one) {
            if (! isset($fiveData[$symbol])) {
                continue;
            }

            $five = $fiveData[$symbol];
            $recent5High = $five['recent5_high'];
            $last5Open = $five['last5_open'];
            $last5Close = $five['last5_close'];
            $bodyPct = $five['body_pct_5m'];

            // Require bullish and strong body on last 5m candle
            if ($last5Close <= $last5Open || $bodyPct < $strongBodyMinPct) {
                continue;
            }

            // Require breakout: last 1m price above recent 5m high
            if ($one['last_price'] <= $recent5High) {
                continue;
            }

            $candidates[] = array_merge($one, [
                'recent5_high' => $recent5High,
                'last5_open' => $last5Open,
                'last5_close' => $last5Close,
                'bars_5m' => $five['bars5'],
                'body_pct_5m' => $bodyPct,
                'last_ts_est_5m' => $five['last_ts_est_5m'],
            ]);
        }

        return $candidates;
    }

    private function sortCandidates(array $candidates): array
    {
        usort($candidates, function ($a, $b) {
            // Sort by last 1m timestamp desc, then by 1m move desc
            $cmpTs = strcmp($b['last_ts_est_1m'], $a['last_ts_est_1m']);
            if ($cmpTs !== 0) {
                return $cmpTs;
            }

            return $b['move_pct'] <=> $a['move_pct'];
        });

        return $candidates;
    }
}
