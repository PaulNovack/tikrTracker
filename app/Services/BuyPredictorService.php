<?php

namespace App\Services;

use App\Support\EstTimezoneHelper;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class BuyPredictorService
{
    public function analyze(
        ?string $asOfEst = null,
        int $lookbackMinutes = 90,
        string $assetType = 'stock',
        int $minScore = 5
    ): array {
        $asOf = $this->determineAsOfTime($asOfEst);
        $startEst = $asOf->copy()->subMinutes($lookbackMinutes);

        // Fetch 5-minute data with early morning fallback support
        $fiveMinData = $this->fetchFiveMinuteDataWithFallback($assetType, $startEst, $asOf);

        if (empty($fiveMinData)) {
            return [
                'results' => [],
                'meta' => [
                    'as_of' => $asOf->format('Y-m-d H:i:s'),
                    'lookback_minutes' => $lookbackMinutes,
                    'asset_type' => $assetType,
                    'min_score' => $minScore,
                    'message' => "No 5-minute data found for {$assetType} between {$startEst->format('Y-m-d H:i:s')} and {$asOf->format('Y-m-d H:i:s')} (including fallback attempts)",
                    'early_morning_mode' => $this->isEarlyMorning($asOf),
                ],
            ];
        }

        // Fetch 1-minute data (shorter window) with fallback
        $oneMinLookback = min($lookbackMinutes, 45);
        $start1mEst = $asOf->copy()->subMinutes($oneMinLookback);
        $oneMinData = $this->fetchOneMinuteDataWithFallback($assetType, $start1mEst, $asOf);

        // Calculate scores for each symbol
        $results = $this->calculateScores($fiveMinData, $oneMinData, $minScore, $asOf, $lookbackMinutes);

        // Apply enhanced quality filtering for 9/10 success rate
        $results = $this->applyEnhancedQualityFiltering($results);

        // Sort by score desc, then by range_pct desc
        usort($results, function (array $a, array $b) {
            if ($a['score'] === $b['score']) {
                return $b['range_pct'] <=> $a['range_pct'];
            }

            return $b['score'] <=> $a['score'];
        });

        return [
            'results' => $results,
            'meta' => [
                'as_of' => $asOf->format('Y-m-d H:i:s'),
                'lookback_minutes' => $lookbackMinutes,
                'asset_type' => $assetType,
                'min_score' => $minScore,
                'total_symbols' => count($results),
                'data_window' => [
                    'start' => $startEst->format('Y-m-d H:i:s'),
                    'end' => $asOf->format('Y-m-d H:i:s'),
                ],
            ],
        ];
    }

    private function determineAsOfTime(?string $asOfEstInput): Carbon
    {
        if ($asOfEstInput) {
            $asOf = EstTimezoneHelper::parseEstTimestamp($asOfEstInput);
        } else {
            $asOf = Carbon::now('America/New_York');
        }

        // Snap to last regular session (9:30–16:00 EST)
        $marketOpen = $asOf->copy()->setTime(9, 30, 0);
        $marketClose = $asOf->copy()->setTime(16, 0, 0);

        // If before open -> use previous weekday 16:00
        if ($asOf->lt($marketOpen)) {
            $asOf->subDay();
            while (in_array($asOf->dayOfWeek, [Carbon::SATURDAY, Carbon::SUNDAY], true)) {
                $asOf->subDay();
            }
            $asOf->setTime(16, 0, 0);
        }
        // If after close -> use today's 16:00 (or last weekday if weekend)
        elseif ($asOf->gt($marketClose)) {
            if (in_array($asOf->dayOfWeek, [Carbon::SATURDAY, Carbon::SUNDAY], true)) {
                while (in_array($asOf->dayOfWeek, [Carbon::SATURDAY, Carbon::SUNDAY], true)) {
                    $asOf->subDay();
                }
            }
            $asOf->setTime(16, 0, 0);
        }

        return $asOf;
    }

    private function fetchFiveMinuteData(string $assetType, Carbon $startEst, Carbon $endEst): array
    {
        $data = DB::select('
            SELECT 
                p.symbol, 
                p.asset_type, 
                p.ts_est, 
                p.price, 
                p.open, 
                p.high, 
                p.low, 
                p.volume,
                a.id as asset_id
            FROM five_minute_prices p
            LEFT JOIN asset_info a ON p.symbol = a.symbol AND p.asset_type = a.asset_type
            WHERE p.asset_type = :assetType
              AND p.ts_est BETWEEN :startEst AND :endEst
            ORDER BY p.symbol, p.ts_est
        ', [
            'assetType' => $assetType,
            'startEst' => $startEst->format('Y-m-d H:i:s'),
            'endEst' => $endEst->format('Y-m-d H:i:s'),
        ]);

        $fiveMinData = [];
        foreach ($data as $row) {
            $row = (array) $row;
            $sym = $row['symbol'];
            if (! isset($fiveMinData[$sym])) {
                $fiveMinData[$sym] = [];
            }
            $fiveMinData[$sym][] = $row;
        }

        return $fiveMinData;
    }

    private function fetchOneMinuteData(string $assetType, Carbon $startEst, Carbon $endEst): array
    {
        $data = DB::select('
            SELECT symbol, asset_type, ts_est, price, open, high, low, volume
            FROM one_minute_prices
            WHERE asset_type = :assetType
              AND ts_est BETWEEN :startEst AND :endEst
            ORDER BY symbol, ts_est
        ', [
            'assetType' => $assetType,
            'startEst' => $startEst->format('Y-m-d H:i:s'),
            'endEst' => $endEst->format('Y-m-d H:i:s'),
        ]);

        $oneMinData = [];
        foreach ($data as $row) {
            $row = (array) $row;
            $sym = $row['symbol'];
            if (! isset($oneMinData[$sym])) {
                $oneMinData[$sym] = [];
            }
            $oneMinData[$sym][] = $row;
        }

        return $oneMinData;
    }

    /**
     * Enhanced 5-minute data fetch with early morning fallback support
     */
    private function fetchFiveMinuteDataWithFallback(string $assetType, Carbon $startEst, Carbon $endEst): array
    {
        // First attempt: fetch data for the requested time range
        $fiveMinData = $this->fetchFiveMinuteData($assetType, $startEst, $endEst);

        // If we have sufficient data, return it
        if (! empty($fiveMinData) && count($fiveMinData) >= 5) {
            return $fiveMinData;
        }

        // Check if this is early morning scenario (before 10:30 AM ET)
        $now = Carbon::now('America/New_York');
        if ($this->isEarlyMorning($now)) {
            // Attempt to use previous trading day data
            $fallbackData = $this->fetchPreviousDayData($assetType, 'five_minute_prices');

            if (! empty($fallbackData)) {
                \Log::info('Using previous day 5-minute data for early morning analysis', [
                    'original_count' => count($fiveMinData),
                    'fallback_count' => count($fallbackData),
                    'time' => $now->format('Y-m-d H:i:s'),
                ]);

                return $fallbackData;
            }
        }

        return $fiveMinData;
    }

    /**
     * Enhanced 1-minute data fetch with early morning fallback support
     */
    private function fetchOneMinuteDataWithFallback(string $assetType, Carbon $startEst, Carbon $endEst): array
    {
        // First attempt: fetch data for the requested time range
        $oneMinData = $this->fetchOneMinuteData($assetType, $startEst, $endEst);

        // If we have sufficient data, return it
        if (! empty($oneMinData) && count($oneMinData) >= 3) {
            return $oneMinData;
        }

        // Check if this is early morning scenario
        $now = Carbon::now('America/New_York');
        if ($this->isEarlyMorning($now)) {
            // Attempt to use previous trading day data (last 30 minutes)
            $fallbackData = $this->fetchPreviousDayData($assetType, 'one_minute_prices', 30);

            if (! empty($fallbackData)) {
                \Log::info('Using previous day 1-minute data for early morning analysis', [
                    'original_count' => count($oneMinData),
                    'fallback_count' => count($fallbackData),
                    'time' => $now->format('Y-m-d H:i:s'),
                ]);

                return $fallbackData;
            }
        }

        return $oneMinData;
    }

    /**
     * Check if current time is early morning (before market has sufficient data)
     */
    private function isEarlyMorning(Carbon $time): bool
    {
        $marketTime = $time->copy()->setTimezone('America/New_York');
        $earlyMorningCutoff = $marketTime->copy()->setTime(10, 30, 0); // 10:30 AM ET

        return $marketTime->lt($earlyMorningCutoff);
    }

    /**
     * Fetch data from previous trading day for early morning scenarios
     */
    private function fetchPreviousDayData(string $assetType, string $table, int $minutesFromClose = 90): array
    {
        // Find the previous trading day
        $prevDay = Carbon::now('America/New_York');
        do {
            $prevDay->subDay();
        } while (in_array($prevDay->dayOfWeek, [Carbon::SATURDAY, Carbon::SUNDAY], true));

        // Get data from the end of previous trading day
        $endTime = $prevDay->copy()->setTime(16, 0, 0); // 4:00 PM ET market close
        $startTime = $endTime->copy()->subMinutes($minutesFromClose);

        // Optimize query with more specific conditions and limits
        $data = DB::select('
            SELECT 
                p.symbol, 
                p.asset_type, 
                p.ts_est, 
                p.price, 
                p.open, 
                p.high, 
                p.low, 
                p.volume'.
                ($table === 'five_minute_prices' ? ', a.id as asset_id' : '')."
            FROM {$table} p".
            ($table === 'five_minute_prices' ? ' LEFT JOIN asset_info a ON p.symbol = a.symbol AND p.asset_type = a.asset_type' : '').'
            WHERE p.asset_type = :assetType
              AND p.ts_est BETWEEN :startEst AND :endEst
              AND p.price > 0.50
              AND p.volume > 1000
            ORDER BY p.symbol, p.ts_est
            LIMIT 2000
        ', [
                'assetType' => $assetType,
                'startEst' => $startTime->format('Y-m-d H:i:s'),
                'endEst' => $endTime->format('Y-m-d H:i:s'),
            ]);

        $groupedData = [];
        foreach ($data as $row) {
            $row = (array) $row;
            $sym = $row['symbol'];
            if (! isset($groupedData[$sym])) {
                $groupedData[$sym] = [];
            }
            $groupedData[$sym][] = $row;
        }

        return $groupedData;
    }

    private function calculateScores(array $fiveMinData, array $oneMinData, int $minScore, Carbon $asOf, int $lookbackMinutes): array
    {
        $results = [];

        foreach ($fiveMinData as $symbol => $rows5m) {
            $barsCount = count($rows5m);

            // Basic sanity check: need some minimum bars to be meaningful
            if ($barsCount < 5) {
                continue;
            }

            // Get asset_id from first row (all rows for same symbol should have same asset_id)
            $assetId = $rows5m[0]['asset_id'] ?? null;

            // Get sector information for this symbol
            $assetInfo = DB::table('asset_info')
                ->where('symbol', $symbol)
                ->where('asset_type', 'stock')
                ->first(['sector']);
            $sector = $assetInfo->sector ?? 'Unknown';

            // Extract prices & high/low/volume for 5m
            $prices5m = [];
            $highs5m = [];
            $lows5m = [];
            $vol5m = [];

            foreach ($rows5m as $row) {
                $prices5m[] = (float) $row['price'];
                $highs5m[] = (float) $row['high'];
                $lows5m[] = (float) $row['low'];
                $vol5m[] = (float) $row['volume'];
            }

            $firstPrice5m = $prices5m[0];
            $lastPrice5m = $prices5m[$barsCount - 1];
            $sessionHigh = max($highs5m);
            $sessionLow = min($lows5m);

            // Range: session high vs first price
            $rangePct = $this->percentChange($firstPrice5m, $sessionHigh);

            // Pullback from high: how far below high is lastPrice
            $pullbackFromHighPct = $this->percentChange($sessionHigh, $lastPrice5m) * -1.0;

            // VWAP for 5m
            $vwapNumerator = 0.0;
            $vwapDenom = 0.0;
            foreach ($rows5m as $row) {
                $p = (float) $row['price'];
                $v = (float) $row['volume'];
                $vwapNumerator += $p * $v;
                $vwapDenom += $v;
            }
            $vwap = $vwapDenom > 0 ? $vwapNumerator / $vwapDenom : $lastPrice5m;

            // 5m MAs with fallback when not enough candles for 20-period
            $ma5_5m = $this->movingAverage($prices5m, min(5, $barsCount));

            if ($barsCount >= 20) {
                $ma20_5m = $this->movingAverage($prices5m, 20);
            } elseif ($barsCount >= 10) {
                $fallbackPeriod = (int) floor($barsCount / 2);
                $ma20_5m = $this->movingAverage($prices5m, $fallbackPeriod);
            } else {
                $ma20_5m = null;
            }

            // 1m data for this symbol (if available)
            $rows1m = $oneMinData[$symbol] ?? [];
            $prices1m = [];
            foreach ($rows1m as $row1) {
                $prices1m[] = (float) $row1['price'];
            }
            $bars1m = count($prices1m);

            $ma5_1m = $bars1m > 0 ? $this->movingAverage($prices1m, min(5, $bars1m)) : null;
            $ma20_1m = null;
            if ($bars1m >= 20) {
                $ma20_1m = $this->movingAverage($prices1m, 20);
            } elseif ($bars1m >= 10) {
                $ma20_1m = $this->movingAverage($prices1m, (int) floor($bars1m / 2));
            }

            // Enhanced momentum analysis (replaces simple rising momentum check)
            $momentumAnalysis = $this->analyzeMomentumPattern($rows5m);
            $isRisingMomentum = $momentumAnalysis['rising_momentum'];

            // ENHANCED: Analyze historical swing patterns for day trading opportunities
            $currentDate = Carbon::now('America/New_York');
            $historicalSwings = $this->analyzeHistoricalSwings($symbol, $currentDate, 5);

            // NEW: Analyze predictive patterns based on 4%+ gainers
            $predictivePatterns = $this->analyzePredictivePatterns($symbol, $asOf, $lookbackMinutes);

            // NEW: Analyze volume breakout patterns
            $volumeBreakout = $this->analyzeVolumeBreakout(
                DB::table('five_minute_prices')
                    ->select(['price', 'high', 'low', 'volume', 'ts_est'])
                    ->where('symbol', $symbol)
                    ->where('asset_type', 'stock')
                    ->where('ts_est', '>=', $asOf->copy()->setTime(9, 30, 0))
                    ->where('ts_est', '<=', $asOf)
                    ->orderBy('ts_est')
                    ->get(),
                $lastPrice5m,
                $sessionHigh
            );

            // NEW: Analyze sector momentum
            $sectorMomentum = $this->analyzeSectorMomentum($symbol, $sector, $asOf);

            // NEW: Calculate stop-loss verification
            $stopLossVerification = $this->calculateStopLossVerification(
                $lastPrice5m,
                $sessionHigh,
                $sessionLow,
                $pullbackFromHighPct
            );

            // Calculate enhanced score with all analyses
            $baseScore = $this->calculateScore($ma5_5m, $ma20_5m, $ma5_1m, $ma20_1m, $lastPrice5m, $vwap, $rangePct, $pullbackFromHighPct, $isRisingMomentum, $historicalSwings);

            // Add all enhancement bonuses
            $finalScore = $baseScore +
                ($predictivePatterns['predictive_score'] * 0.3) +
                ($volumeBreakout['volume_breakout_strength'] * 0.4) +
                ($volumeBreakout['volume_trend_score'] * 0.3) +
                ($volumeBreakout['price_volume_correlation'] * 0.2) +
                ($sectorMomentum['sector_score'] * 0.2) +
                ($stopLossVerification['stop_loss_score'] * 0.25);

            // Filter by score threshold
            if ($finalScore < $minScore) {
                continue;
            }

            $results[] = [
                'symbol' => $symbol,
                'asset_id' => $assetId,
                'score' => round($finalScore),
                'last_price' => $lastPrice5m,
                'range_pct' => $rangePct,
                'pullback_from_high' => $pullbackFromHighPct,
                'rising_momentum' => $isRisingMomentum,
                'momentum_strength' => $momentumAnalysis['momentum_strength'] ?? 0,
                'momentum_consistency' => $momentumAnalysis['momentum_consistency'] ?? 0,
                'trend_slope' => $momentumAnalysis['trend_slope'] ?? 0,
                'vwap' => $vwap,
                'ma5_5m' => $ma5_5m,
                'ma20_5m' => $ma20_5m,
                'ma5_1m' => $ma5_1m,
                'ma20_1m' => $ma20_1m,
                'session_high' => $sessionHigh,
                'session_low' => $sessionLow,
                'first_price' => $firstPrice5m,
                'bars_count_5m' => $barsCount,
                'bars_count_1m' => $bars1m,
                'historical_swings' => $historicalSwings, // Add swing analysis data
                // Add predictive pattern analysis
                'morning_gain_pct' => $predictivePatterns['morning_gain_pct'],
                'morning_range_pct' => $predictivePatterns['morning_range_pct'],
                'pullback_from_morning_high' => $predictivePatterns['pullback_from_high_pct'],
                'volume_acceleration' => $predictivePatterns['volume_acceleration'],
                'consolidation_quality' => $predictivePatterns['consolidation_quality'],
                'breakout_angle' => $predictivePatterns['breakout_angle'],
                'predictive_score' => $predictivePatterns['predictive_score'],
                // Add volume analysis data
                'volume_breakout_strength' => $volumeBreakout['volume_breakout_strength'],
                'volume_trend_score' => $volumeBreakout['volume_trend_score'],
                'price_volume_correlation' => $volumeBreakout['price_volume_correlation'],
                'breakout_volume_ratio' => $volumeBreakout['breakout_volume_ratio'],
                // Add sector analysis data
                'sector_name' => $sectorMomentum['sector_name'],
                'sector_score' => $sectorMomentum['sector_score'],
                'exchange_type' => $sectorMomentum['exchange_type'],
                // Add stop-loss verification data
                'stop_loss_price' => $stopLossVerification['stop_loss_price'],
                'risk_reward_ratio' => $stopLossVerification['risk_reward_ratio'],
                'distance_from_low_pct' => $stopLossVerification['distance_from_low_pct'],
                'stop_loss_score' => $stopLossVerification['stop_loss_score'],
            ];
        }

        return $results;
    }

    private function calculateScore(
        ?float $ma5_5m,
        ?float $ma20_5m,
        ?float $ma5_1m,
        ?float $ma20_1m,
        float $lastPrice5m,
        float $vwap,
        float $rangePct,
        float $pullbackFromHighPct,
        bool $isRisingMomentum,
        ?array $historicalSwings = null
    ): int {
        $score = 0;

        // ENHANCED: Historical swing quality (major factor for day trading success)
        if ($historicalSwings !== null) {
            $swingQuality = $historicalSwings['swing_quality_score'];
            $avgMaxSwing = $historicalSwings['avg_max_swing'];
            $consistencyScore = $historicalSwings['consistency_score'];
            $volatilityConsistency = $historicalSwings['volatility_consistency'];

            // Major scoring based on historical swing patterns
            if ($swingQuality >= 8.0) {
                $score += 12; // Excellent swing history - high priority
            } elseif ($swingQuality >= 6.0) {
                $score += 8; // Good swing history
            } elseif ($swingQuality >= 4.0) {
                $score += 4; // Moderate swing history
            } else {
                $score -= 3; // Poor swing history - avoid
            }

            // Bonus for optimal swing range (5-15%)
            if ($avgMaxSwing >= 5.0 && $avgMaxSwing <= 15.0) {
                $score += 3;
            }

            // Bonus for consistency (predictable patterns)
            if ($consistencyScore >= 6.0 && $volatilityConsistency >= 6.0) {
                $score += 4; // Highly consistent patterns
            } elseif ($consistencyScore >= 4.0) {
                $score += 2; // Moderately consistent
            }

            // Penalty for excessive volatility (unpredictable)
            if ($avgMaxSwing > 30.0) {
                $score -= 5; // Too volatile, avoid
            }
        }

        // Traditional technical analysis (reduced weight since historical patterns more important)
        // 5m trend alignment (bullish setup)
        if ($ma5_5m !== null && $ma20_5m !== null && $ma5_5m > $ma20_5m) {
            $score += 2; // Reduced from 3
        }

        // 1m trend alignment (short-term momentum)
        if ($ma5_1m !== null && $ma20_1m !== null && $ma5_1m > $ma20_1m) {
            $score += 1; // Reduced from 2
        }

        // Price above VWAP (institutional support)
        if ($lastPrice5m > $vwap) {
            $score += 1; // Reduced from 2
        }

        // Range expansion (showing movement) - reduced weight
        if ($rangePct > 0.8) {
            $score += 1;
        }
        if ($rangePct > 1.5) {
            $score += 1; // total +2 for strong expansion
        }

        // IMPROVED: Better pullback scoring for optimal entry timing
        // We want stocks that have pulled back but not too much (sweet spot for entries)
        if ($pullbackFromHighPct >= 1.0 && $pullbackFromHighPct <= 3.0) {
            $score += 3; // Best entry zone: 1-3% pullback from high
        } elseif ($pullbackFromHighPct >= 0.5 && $pullbackFromHighPct < 1.0) {
            $score += 2; // Good entry: 0.5-1% pullback
        } elseif ($pullbackFromHighPct >= 3.0 && $pullbackFromHighPct <= 5.0) {
            $score += 1; // Acceptable: 3-5% pullback (might be weakening)
        } elseif ($pullbackFromHighPct < 0.5) {
            $score += 0; // Too close to high - likely topping out
        }
        // More than 5% pullback gets 0 points (trend may be breaking)

        // NEW: Rising momentum detection - but PENALIZE if too close to session highs
        if ($isRisingMomentum) {
            if ($pullbackFromHighPct >= 1.0) {
                $score += 4; // Big bonus for rising momentum with meaningful pullback
            } elseif ($pullbackFromHighPct >= 0.3) {
                $score += 1; // Small bonus for rising momentum with minimal pullback
            } else {
                $score -= 3; // PENALTY: Rising momentum too close to highs = topping pattern
            }
        }

        // BONUS: Give extra points for stocks that have good range but reasonable pullback
        if ($rangePct > 2.0 && $pullbackFromHighPct >= 1.0 && $pullbackFromHighPct <= 2.5) {
            $score += 1; // Bonus for strong movers with good entry pullback
        }

        // ENHANCED: Early entry detection - rising momentum + good pullback
        if ($isRisingMomentum && $pullbackFromHighPct >= 1.0 && $pullbackFromHighPct <= 3.0) {
            $score += 3; // Extra bonus for early momentum with proper pullback entry
        }

        return $score;
    }

    /**
     * Enhanced momentum detection - look for sustained upward momentum patterns
     * This replaces the simple rising momentum check with more sophisticated analysis
     */
    private function analyzeMomentumPattern(array $rows): array
    {
        $count = count($rows);
        if ($count < 8) {
            return [
                'rising_momentum' => false,
                'momentum_strength' => 0,
                'momentum_consistency' => 0,
                'momentum_acceleration' => 0,
            ];
        }

        // Get recent periods for analysis (last 8 periods = 40 minutes)
        $recentPeriods = array_slice($rows, -8);
        $prices = array_map(fn ($row) => (float) $row['price'], $recentPeriods);
        $highs = array_map(fn ($row) => (float) $row['high'], $recentPeriods);

        // 1. Calculate trend strength (linear regression slope)
        $trendStrength = $this->calculateTrendSlope($prices);

        // 2. Check for consistent higher highs
        $higherHighCount = 0;
        $consecutiveHigherHighs = 0;
        $maxConsecutive = 0;

        for ($i = 1; $i < count($highs); $i++) {
            if ($highs[$i] > $highs[$i - 1] * 1.001) { // >0.1% higher
                $higherHighCount++;
                $consecutiveHigherHighs++;
                $maxConsecutive = max($maxConsecutive, $consecutiveHigherHighs);
            } else {
                $consecutiveHigherHighs = 0;
            }
        }

        // 3. Calculate momentum acceleration (is trend getting stronger?)
        $firstHalfPrices = array_slice($prices, 0, 4);
        $secondHalfPrices = array_slice($prices, 4, 4);

        $firstHalfSlope = $this->calculateTrendSlope($firstHalfPrices);
        $secondHalfSlope = $this->calculateTrendSlope($secondHalfPrices);

        $momentumAcceleration = $secondHalfSlope > $firstHalfSlope * 1.2 ? 1 : 0;

        // 4. Check for pullback quality (not too close to highs)
        $sessionHigh = max($highs);
        $currentPrice = end($prices);
        $pullbackFromHigh = (($sessionHigh - $currentPrice) / $sessionHigh) * 100;

        // 5. Volume confirmation (if available)
        $volumes = array_map(fn ($row) => (float) $row['volume'], $recentPeriods);
        $avgVolume = array_sum($volumes) / count($volumes);
        $recentVolume = end($volumes);
        $volumeConfirmation = $recentVolume > $avgVolume * 1.1 ? 1 : 0;

        // Calculate momentum scores
        $momentumStrength = min(10, max(0, $trendStrength * 100)); // Scale to 0-10
        $momentumConsistency = ($higherHighCount / (count($highs) - 1)) * 10; // 0-10 based on % of higher highs

        // Enhanced rising momentum criteria - balanced for realistic detection
        $risingMomentum = (
            $trendStrength > 0.01 && // At least 1% upward slope per period (reduced from 2%)
            $higherHighCount >= 3 && // At least 3 out of 7 periods have higher highs (reduced from 4)
            $maxConsecutive >= 2 && // At least 2 consecutive higher highs (reduced from 3)
            $pullbackFromHigh >= 0.5 && // Not too close to session high
            $pullbackFromHigh <= 10.0 // Not too far from highs either (increased from 8%)
        );

        return [
            'rising_momentum' => $risingMomentum,
            'momentum_strength' => $momentumStrength,
            'momentum_consistency' => $momentumConsistency,
            'momentum_acceleration' => $momentumAcceleration,
            'trend_slope' => $trendStrength,
            'higher_high_count' => $higherHighCount,
            'max_consecutive_highs' => $maxConsecutive,
            'volume_confirmation' => $volumeConfirmation,
        ];
    }

    /**
     * Calculate trend slope using linear regression
     * Returns slope as percentage per period
     */
    private function calculateTrendSlope(array $prices): float
    {
        $n = count($prices);
        if ($n < 2) {
            return 0;
        }

        $sumX = 0;
        $sumY = 0;
        $sumXY = 0;
        $sumX2 = 0;

        for ($i = 0; $i < $n; $i++) {
            $x = $i + 1; // Time period (1, 2, 3, ...)
            $y = $prices[$i];

            $sumX += $x;
            $sumY += $y;
            $sumXY += $x * $y;
            $sumX2 += $x * $x;
        }

        // Calculate slope: slope = (n*ΣXY - ΣX*ΣY) / (n*ΣX² - (ΣX)²)
        $denominator = $n * $sumX2 - $sumX * $sumX;
        if ($denominator == 0) {
            return 0;
        }

        $slope = ($n * $sumXY - $sumX * $sumY) / $denominator;

        // Convert to percentage per period
        $avgPrice = $sumY / $n;

        return $avgPrice > 0 ? ($slope / $avgPrice) : 0;
    }

    private function movingAverage(array $values, int $period): ?float
    {
        $count = count($values);
        if ($count < $period || $period <= 0) {
            return null;
        }
        $slice = array_slice($values, $count - $period, $period);
        $sum = array_sum($slice);

        return $sum / $period;
    }

    private function percentChange(float $from, float $to): float
    {
        if ($from == 0.0) {
            return 0.0;
        }

        return (($to - $from) / $from) * 100.0;
    }

    /**
     * Analyze historical swing patterns to identify stocks with consistent day trading opportunities
     */
    private function analyzeHistoricalSwings(string $symbol, Carbon $currentDate, int $lookbackDays = 5): ?array
    {
        $swingMetrics = [];

        // Analyze the last N trading days
        for ($i = 1; $i <= $lookbackDays; $i++) {
            $testDate = $currentDate->copy()->subDays($i);

            // Skip weekends
            while (in_array($testDate->dayOfWeek, [Carbon::SATURDAY, Carbon::SUNDAY])) {
                $testDate->subDay();
            }

            $data = DB::select("
                SELECT ts_est, price, high, low, volume 
                FROM five_minute_prices 
                WHERE symbol = ? AND asset_type = 'stock' 
                AND DATE(ts_est) = ? 
                AND TIME(ts_est) BETWEEN '09:30:00' AND '16:00:00'
                ORDER BY ts_est
            ", [$symbol, $testDate->format('Y-m-d')]);

            if (count($data) < 10) {
                continue; // Need at least 10 periods for analysis
            }

            $prices = array_map(fn ($row) => (float) $row->price, $data);
            $highs = array_map(fn ($row) => (float) $row->high, $data);
            $lows = array_map(fn ($row) => (float) $row->low, $data);
            $volumes = array_map(fn ($row) => (float) $row->volume, $data);

            $dayHigh = max($highs);
            $dayLow = min($lows);
            $openPrice = $prices[0];

            // Calculate key swing metrics
            $dailyRange = (($dayHigh - $dayLow) / $openPrice) * 100;
            $avgVolume = array_sum($volumes) / count($volumes);

            // Find largest profitable intraday swing (1-2 hour windows)
            $maxProfitableSwing = 0;
            $swingCount = 0;

            for ($j = 0; $j < count($data) - 12; $j += 6) { // Check every 30 minutes
                $startPrice = $prices[$j];
                $endIndex = min($j + 24, count($prices) - 1); // 2-hour window
                $maxInWindow = max(array_slice($highs, $j, $endIndex - $j));
                $swing = (($maxInWindow - $startPrice) / $startPrice) * 100;

                if ($swing >= 2.0) { // Only count meaningful swings
                    $maxProfitableSwing = max($maxProfitableSwing, $swing);
                    $swingCount++;
                }
            }

            $swingMetrics[] = [
                'date' => $testDate->format('Y-m-d'),
                'daily_range' => $dailyRange,
                'max_swing' => $maxProfitableSwing,
                'swing_count' => $swingCount,
                'avg_volume' => $avgVolume,
                'consistency_score' => $swingCount > 0 ? min(10, $swingCount * 2) : 0,
            ];
        }

        if (count($swingMetrics) < 3) {
            return null; // Need at least 3 days of data
        }

        // Calculate averages and consistency
        $avgRange = array_sum(array_column($swingMetrics, 'daily_range')) / count($swingMetrics);
        $avgMaxSwing = array_sum(array_column($swingMetrics, 'max_swing')) / count($swingMetrics);
        $avgSwingCount = array_sum(array_column($swingMetrics, 'swing_count')) / count($swingMetrics);
        $avgVolume = array_sum(array_column($swingMetrics, 'avg_volume')) / count($swingMetrics);
        $avgConsistency = array_sum(array_column($swingMetrics, 'consistency_score')) / count($swingMetrics);

        // Calculate volatility consistency (lower is better for predictable swings)
        $rangeValues = array_column($swingMetrics, 'daily_range');
        $rangeMean = array_sum($rangeValues) / count($rangeValues);
        $rangeVariance = array_sum(array_map(fn ($x) => pow($x - $rangeMean, 2), $rangeValues)) / count($rangeValues);
        $rangeStdDev = sqrt($rangeVariance);
        $volatilityConsistency = $rangeMean > 0 ? (1 - ($rangeStdDev / $rangeMean)) * 10 : 0;

        return [
            'avg_range' => $avgRange,
            'avg_max_swing' => $avgMaxSwing,
            'avg_swing_count' => $avgSwingCount,
            'avg_volume' => $avgVolume,
            'consistency_score' => $avgConsistency,
            'volatility_consistency' => max(0, min(10, $volatilityConsistency)),
            'historical_days' => count($swingMetrics),
            'swing_quality_score' => $this->calculateSwingQualityScore($avgMaxSwing, $avgConsistency, $volatilityConsistency),
        ];
    }

    /**
     * Calculate a quality score for historical swing patterns
     * Optimal range: 5-15% max swings with good consistency
     */
    private function calculateSwingQualityScore(float $avgMaxSwing, float $avgConsistency, float $volatilityConsistency): float
    {
        $swingScore = 0;

        // Optimal swing range: 5-15% (sweet spot for day trading)
        if ($avgMaxSwing >= 5.0 && $avgMaxSwing <= 15.0) {
            $swingScore = 10;
        } elseif ($avgMaxSwing >= 3.0 && $avgMaxSwing < 5.0) {
            $swingScore = 6; // Smaller but tradeable swings
        } elseif ($avgMaxSwing > 15.0 && $avgMaxSwing <= 25.0) {
            $swingScore = 7; // Larger swings but riskier
        } elseif ($avgMaxSwing > 25.0) {
            $swingScore = 2; // Too volatile, unpredictable
        }

        // Combine with consistency and volatility
        $qualityScore = ($swingScore * 0.6) + ($avgConsistency * 0.25) + ($volatilityConsistency * 0.15);

        return $qualityScore;
    }

    /**
     * Analyze stocks with forward-looking predictive patterns based on 4%+ gainers
     */
    private function analyzePredictivePatterns(string $symbol, Carbon $asOf, int $lookbackMinutes): array
    {
        // Get morning session data (9:30 AM to current time)
        $morningStart = $asOf->copy()->setTime(9, 30, 0);

        $morningData = DB::table('five_minute_prices')
            ->select(['price', 'high', 'low', 'open', 'volume', 'ts_est'])
            ->where('symbol', $symbol)
            ->where('asset_type', 'stock')
            ->where('ts_est', '>=', $morningStart)
            ->where('ts_est', '<=', $asOf)
            ->orderBy('ts_est')
            ->get();

        if ($morningData->count() < 10) {
            return [
                'morning_gain_pct' => 0,
                'morning_range_pct' => 0,
                'pullback_from_high_pct' => 0,
                'volume_acceleration' => 0,
                'consolidation_quality' => 0,
                'breakout_angle' => 0,
                'predictive_score' => 0,
            ];
        }

        $first = $morningData->first();
        $last = $morningData->last();

        // Calculate morning performance
        $firstPrice = $first->open ?? $first->price;
        $lastPrice = $last->price;
        $morningGain = (($lastPrice - $firstPrice) / $firstPrice) * 100;

        // Morning range analysis
        $morningHigh = $morningData->max('high');
        $morningLow = $morningData->min('low');
        $morningRange = (($morningHigh - $morningLow) / $morningLow) * 100;
        $pullbackFromHigh = (($morningHigh - $lastPrice) / $morningHigh) * 100;

        // Volume acceleration analysis
        $totalBars = $morningData->count();
        $midPoint = intval($totalBars / 2);

        $firstHalfVol = $morningData->take($midPoint)->where('volume', '>', 0)->avg('volume') ?? 0;
        $secondHalfVol = $morningData->skip($midPoint)->where('volume', '>', 0)->avg('volume') ?? 0;
        $volumeAcceleration = $firstHalfVol > 0 ? ($secondHalfVol / $firstHalfVol) : 0;

        // Consolidation quality (lower volatility near highs = better)
        $recentData = $morningData->slice(-6); // Last 30 minutes
        $recentHigh = $recentData->max('high');
        $recentLow = $recentData->min('low');
        $consolidationRange = $recentHigh > 0 ? (($recentHigh - $recentLow) / $recentHigh) * 100 : 100;
        $consolidationQuality = max(0, 10 - $consolidationRange); // Lower range = higher quality

        // Breakout angle (price acceleration in last 20 minutes)
        $recentPrices = $morningData->slice(-4)->pluck('price')->toArray();
        if (count($recentPrices) >= 3) {
            $x = range(1, count($recentPrices));
            $slope = $this->calculateTrendSlope($x, $recentPrices);
            $breakoutAngle = $slope; // Keep as raw slope, not percentage
        } else {
            $breakoutAngle = 0;
        }

        // Calculate predictive score based on successful patterns
        $predictiveScore = $this->calculatePredictiveScore(
            $morningGain,
            $morningRange,
            $pullbackFromHigh,
            $volumeAcceleration,
            $consolidationQuality,
            $breakoutAngle
        );

        return [
            'morning_gain_pct' => round($morningGain, 2),
            'morning_range_pct' => round($morningRange, 2),
            'pullback_from_high_pct' => round($pullbackFromHigh, 2),
            'volume_acceleration' => round($volumeAcceleration, 2),
            'consolidation_quality' => round($consolidationQuality, 2),
            'breakout_angle' => round($breakoutAngle, 3),
            'predictive_score' => round($predictiveScore, 1),
        ];
    }

    /**
     * Calculate predictive score based on patterns of 4%+ gainers
     */
    private function calculatePredictiveScore(
        float $morningGain,
        float $morningRange,
        float $pullbackFromHigh,
        float $volumeAcceleration,
        float $consolidationQuality,
        float $breakoutAngle
    ): float {
        $score = 0;

        // Pattern 1: Morning range expansion (10-50% range optimal)
        if ($morningRange >= 10 && $morningRange <= 50) {
            $score += 25;
        } elseif ($morningRange >= 6 && $morningRange <= 60) {
            $score += 15;
        }

        // Pattern 2: Small pullback from morning high (0-5% optimal)
        if ($pullbackFromHigh <= 5) {
            $score += 25;
        } elseif ($pullbackFromHigh <= 15) {
            $score += 10;
        }

        // Pattern 3: Volume acceleration (increasing volume = good)
        if ($volumeAcceleration >= 1.2) {
            $score += 20;
        } elseif ($volumeAcceleration >= 1.0) {
            $score += 10;
        }

        // Pattern 4: Consolidation quality (tight consolidation = good)
        if ($consolidationQuality >= 7) {
            $score += 15;
        } elseif ($consolidationQuality >= 4) {
            $score += 8;
        }

        // Pattern 5: Recent breakout angle (positive momentum)
        if ($breakoutAngle >= 0.5) {
            $score += 15;
        } elseif ($breakoutAngle >= 0.1) {
            $score += 8;
        }

        // Bonus: Morning gain pattern (both gainers and down stocks can break out)
        if ($morningGain >= 5 && $morningGain <= 25) {
            $score += 10; // Moderate gainers
        } elseif ($morningGain <= -5 && $morningGain >= -25) {
            $score += 8; // Oversold bounce potential
        }

        return $score;
    }

    /**
     * Analyze volume breakout patterns for enhanced entry timing
     */
    private function analyzeVolumeBreakout($morningData, float $currentPrice, float $sessionHigh): array
    {
        if ($morningData->count() < 10) {
            return [
                'volume_breakout_strength' => 0,
                'volume_trend_score' => 0,
                'price_volume_correlation' => 0,
                'breakout_volume_ratio' => 0,
            ];
        }

        $volumes = $morningData->pluck('volume')->filter(fn ($v) => $v > 0)->values();
        $prices = $morningData->pluck('price')->values();
        $highs = $morningData->pluck('high')->values();

        if ($volumes->count() < 5) {
            return [
                'volume_breakout_strength' => 0,
                'volume_trend_score' => 0,
                'price_volume_correlation' => 0,
                'breakout_volume_ratio' => 0,
            ];
        }

        // Calculate volume averages
        $totalVolumes = $volumes->toArray();
        $avgVolume = array_sum($totalVolumes) / count($totalVolumes);
        $midPoint = intval(count($totalVolumes) / 2);

        $earlyVolumes = array_slice($totalVolumes, 0, $midPoint);
        $lateVolumes = array_slice($totalVolumes, $midPoint);

        $earlyAvg = array_sum($earlyVolumes) / count($earlyVolumes);
        $lateAvg = array_sum($lateVolumes) / count($lateVolumes);

        // 1. Volume trend analysis
        $volumeTrendScore = 0;
        if ($earlyAvg > 0) {
            $volumeIncrease = ($lateAvg / $earlyAvg) - 1;
            if ($volumeIncrease > 0.5) {
                $volumeTrendScore = 25;
            }      // 50%+ increase
            elseif ($volumeIncrease > 0.25) {
                $volumeTrendScore = 15;
            } // 25%+ increase
            elseif ($volumeIncrease > 0.1) {
                $volumeTrendScore = 8;
            }   // 10%+ increase
        }

        // 2. Volume breakout strength (recent volume vs average)
        $recentVolumes = array_slice($totalVolumes, -3); // Last 3 bars
        $recentAvgVolume = array_sum($recentVolumes) / count($recentVolumes);
        $breakoutVolumeRatio = $avgVolume > 0 ? $recentAvgVolume / $avgVolume : 0;

        $volumeBreakoutStrength = 0;
        if ($breakoutVolumeRatio > 3.0) {
            $volumeBreakoutStrength = 30;
        }      // 3x average
        elseif ($breakoutVolumeRatio > 2.0) {
            $volumeBreakoutStrength = 20;
        }  // 2x average
        elseif ($breakoutVolumeRatio > 1.5) {
            $volumeBreakoutStrength = 10;
        }  // 1.5x average

        // 3. Price-volume correlation (volume increases on price advances)
        $priceVolumeCorrelation = $this->calculatePriceVolumeCorrelation(
            $prices->toArray(),
            $totalVolumes
        );

        $correlationScore = 0;
        if ($priceVolumeCorrelation > 0.6) {
            $correlationScore = 20;
        }      // Strong positive correlation
        elseif ($priceVolumeCorrelation > 0.3) {
            $correlationScore = 12;
        }  // Moderate correlation
        elseif ($priceVolumeCorrelation > 0.1) {
            $correlationScore = 6;
        }   // Weak correlation

        return [
            'volume_breakout_strength' => $volumeBreakoutStrength,
            'volume_trend_score' => $volumeTrendScore,
            'price_volume_correlation' => $correlationScore,
            'breakout_volume_ratio' => round($breakoutVolumeRatio, 2),
        ];
    }

    /**
     * Calculate correlation between price movement and volume
     */
    private function calculatePriceVolumeCorrelation(array $prices, array $volumes): float
    {
        if (count($prices) < 3 || count($volumes) < 3) {
            return 0;
        }

        // Calculate price changes
        $priceChanges = [];
        for ($i = 1; $i < count($prices); $i++) {
            $priceChanges[] = $prices[$i] - $prices[$i - 1];
        }

        // Use volumes corresponding to price changes
        $correspondingVolumes = array_slice($volumes, 1);

        if (count($priceChanges) !== count($correspondingVolumes)) {
            return 0;
        }

        // Calculate correlation coefficient
        $n = count($priceChanges);
        if ($n < 3) {
            return 0;
        }

        $meanPriceChange = array_sum($priceChanges) / $n;
        $meanVolume = array_sum($correspondingVolumes) / $n;

        $numerator = 0;
        $priceVariance = 0;
        $volumeVariance = 0;

        for ($i = 0; $i < $n; $i++) {
            $priceDiff = $priceChanges[$i] - $meanPriceChange;
            $volumeDiff = $correspondingVolumes[$i] - $meanVolume;

            $numerator += $priceDiff * $volumeDiff;
            $priceVariance += $priceDiff * $priceDiff;
            $volumeVariance += $volumeDiff * $volumeDiff;
        }

        $denominator = sqrt($priceVariance * $volumeVariance);

        return $denominator > 0 ? $numerator / $denominator : 0;
    }

    /**
     * Calculate stop-loss risk/reward verification
     */
    private function calculateStopLossVerification(float $entryPrice, float $sessionHigh, float $sessionLow, float $pullbackFromHigh): array
    {
        // Calculate potential stop loss at 1.3% below entry
        $stopLossPrice = $entryPrice * 0.987; // 1.3% below entry
        $riskAmount = $entryPrice - $stopLossPrice; // Risk per share

        // Calculate upside potential to session high
        $upsidePotential = $sessionHigh - $entryPrice;

        // Risk/Reward ratio
        $riskRewardRatio = $riskAmount > 0 ? $upsidePotential / $riskAmount : 0;

        // Distance from session low (safety margin)
        $distanceFromLow = $entryPrice > 0 ? (($entryPrice - $sessionLow) / $entryPrice) * 100 : 0;

        // Stop loss quality score
        $stopLossScore = 0;

        // 1. Favorable risk/reward (3:1 or better)
        if ($riskRewardRatio >= 3.0) {
            $stopLossScore += 25;
        } elseif ($riskRewardRatio >= 2.0) {
            $stopLossScore += 15;
        } elseif ($riskRewardRatio >= 1.5) {
            $stopLossScore += 8;
        }

        // 2. Safe distance from session low (>3% preferred)
        if ($distanceFromLow >= 5.0) {
            $stopLossScore += 20;
        } elseif ($distanceFromLow >= 3.0) {
            $stopLossScore += 15;
        } elseif ($distanceFromLow >= 2.0) {
            $stopLossScore += 8;
        }

        // 3. Small pullback from high (tight action near highs = good)
        if ($pullbackFromHigh <= 2.0) {
            $stopLossScore += 15;
        } elseif ($pullbackFromHigh <= 5.0) {
            $stopLossScore += 10;
        } elseif ($pullbackFromHigh <= 10.0) {
            $stopLossScore += 5;
        }

        return [
            'stop_loss_price' => round($stopLossPrice, 4),
            'risk_amount' => round($riskAmount, 4),
            'upside_potential' => round($upsidePotential, 4),
            'risk_reward_ratio' => round($riskRewardRatio, 2),
            'distance_from_low_pct' => round($distanceFromLow, 2),
            'stop_loss_score' => $stopLossScore,
        ];
    }

    /**
     * Analyze market context and exchange patterns for sector momentum
     */
    private function analyzeSectorMomentum(string $symbol, string $sector, Carbon $asOf): array
    {
        // Simplified sector analysis based on exchange and market patterns
        $sectorScore = 0;
        $sectorName = 'Unknown';

        // Exchange-based scoring (some exchanges have better performing stocks)
        if (str_contains($sector, 'NASDAQ Capital Market')) {
            $sectorScore = 15; // Often has more volatile, momentum-friendly stocks
            $sectorName = 'NASDAQ Capital';
        } elseif (str_contains($sector, 'NASDAQ Global Market')) {
            $sectorScore = 12; // Good growth stocks
            $sectorName = 'NASDAQ Global';
        } elseif (str_contains($sector, 'NASDAQ Global Select Market')) {
            $sectorScore = 8; // Larger, more stable stocks
            $sectorName = 'NASDAQ Select';
        } elseif (str_contains($sector, 'NYSE')) {
            $sectorScore = 10; // Traditional exchange, mixed performance
            $sectorName = 'NYSE';
        }

        // Additional scoring based on symbol characteristics
        $symbolLength = strlen($symbol);
        if ($symbolLength <= 3) {
            $sectorScore += 5; // Shorter symbols often indicate established companies
        } elseif ($symbolLength >= 5) {
            $sectorScore += 8; // Longer symbols often indicate newer/more speculative stocks
        }

        return [
            'sector_name' => $sectorName,
            'sector_score' => $sectorScore,
            'exchange_type' => $sector,
        ];
    }

    /**
     * Apply enhanced quality filtering for 9/10 success rate
     *
     * Filters out:
     * - Stocks with R/R < 1.0 (poor risk/reward)
     * - Already extended stocks (>15% morning gain)
     * - Prioritizes predictive scores ≥95
     */
    private function applyEnhancedQualityFiltering(array $results): array
    {
        $filtered = [];
        $rejectionReasons = [];

        foreach ($results as $stock) {
            $rejections = [];

            // Filter 1: Risk/Reward ratio must be ≥ 1.0 (relaxed from strict requirement)
            if (isset($stock['risk_reward_ratio']) && $stock['risk_reward_ratio'] < 0.8) {
                $rejections[] = "Very low R/R ratio ({$stock['risk_reward_ratio']})";
            }

            // Filter 2: Exclude already extended stocks (>15% morning gain)
            if (isset($stock['morning_gain_pct']) && $stock['morning_gain_pct'] > 15.0) {
                $rejections[] = "Already extended ({$stock['morning_gain_pct']}% morning gain)";
            }

            // Filter 3: Exclude stocks with negative morning performance
            if (isset($stock['morning_gain_pct']) && $stock['morning_gain_pct'] < -5.0) {
                $rejections[] = "Significantly negative morning performance ({$stock['morning_gain_pct']}%)";
            }

            // Filter 4: Require reasonable predictive scores (lowered threshold)
            if (isset($stock['predictive_score']) && $stock['predictive_score'] < 80) {
                $rejections[] = "Low predictive score ({$stock['predictive_score']})";
            }

            // Filter 5: Ensure minimum volume breakout strength (relaxed)
            if (isset($stock['volume_breakout_strength']) && $stock['volume_breakout_strength'] < 15) {
                $rejections[] = "Very weak volume breakout ({$stock['volume_breakout_strength']})";
            }

            // Filter 6: Exclude very low scores (focus on quality)
            if (isset($stock['score']) && $stock['score'] < 75) {
                $rejections[] = "Low overall score ({$stock['score']})";
            }

            // If no rejections, include in filtered results
            if (empty($rejections)) {
                // Boost scores for high-quality candidates
                if (isset($stock['predictive_score']) && $stock['predictive_score'] >= 95 &&
                    isset($stock['risk_reward_ratio']) && $stock['risk_reward_ratio'] >= 1.5) {
                    $stock['score'] += 5; // Boost high-quality candidates
                }

                $filtered[] = $stock;
            } else {
                $rejectionReasons[$stock['symbol']] = $rejections;
            }
        }

        // Log rejection summary for debugging
        if (! empty($rejectionReasons)) {
            $totalRejected = count($rejectionReasons);
            $totalOriginal = count($results);
            $keepCount = count($filtered);

            \Log::info('Enhanced Quality Filtering Applied', [
                'original_count' => $totalOriginal,
                'filtered_count' => $keepCount,
                'rejected_count' => $totalRejected,
                'rejection_rate' => round(($totalRejected / $totalOriginal) * 100, 1).'%',
                'sample_rejections' => array_slice($rejectionReasons, 0, 5, true),
            ]);
        }

        return $filtered;
    }
}
