<?php

namespace App\Services\Trading;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Oversold Bounce Strategy
 *
 * For choppy/down markets, look for:
 * 1. Stock down 2%+ from recent high
 * 2. Volume spike on selling
 * 3. Reversal signal (hammer, doji, etc)
 * 4. Quick bounce setup with tight stops
 *
 * Target: High win rate on short-term bounces
 */
class OversoldBounceFinder
{
    private $config;

    public function __construct()
    {
        $this->config = [
            'min_decline_pct' => 1.5,         // 1.5% decline from recent high
            'lookback_periods' => 15,         // Look back 15 minutes for high
            'min_volume_multiple' => 1.3,     // 1.3x volume on decline
            'min_price' => 10.0,              // Avoid low-priced stocks
            'max_price' => 300.0,             // Focus on mid-cap range
            'stop_loss_pct' => 0.8,           // Wider 0.8% stop (was 0.5%)
            'target_multiple' => 2.5,         // 2.5:1 risk/reward for quick bounce
            'reversal_threshold' => 0.3,      // 0.3% reversal from low
        ];
    }

    /**
     * Find oversold bounce opportunities
     */
    public function findOpportunities(Carbon $asOfTime, ?array $symbols = null): array
    {
        $opportunities = [];

        if ($symbols === null) {
            $symbols = $this->getActiveSymbols();
        }

        foreach ($symbols as $symbol) {
            $opportunity = $this->analyzeSymbol($symbol, $asOfTime);
            if ($opportunity) {
                $opportunities[] = $opportunity;
            }
        }

        // Sort by strength score
        usort($opportunities, function ($a, $b) {
            return $b['score'] <=> $a['score'];
        });

        return $opportunities;
    }

    /**
     * Analyze symbol for oversold bounce setup
     */
    private function analyzeSymbol(string $symbol, Carbon $asOfTime): ?array
    {
        // Get recent price data
        $prices = $this->getPriceData($symbol, $asOfTime, 25);

        if (count($prices) < 20) {
            return null;
        }

        $latest = $prices[0];

        // Basic filters
        if (! $this->passesBasicFilters($latest)) {
            return null;
        }

        // Check for decline from recent high
        $declineInfo = $this->detectDecline($prices);
        if (! $declineInfo) {
            return null;
        }

        // Check for volume on decline
        $volumeScore = $this->calculateVolumeScore($prices);
        if ($volumeScore < $this->config['min_volume_multiple']) {
            return null;
        }

        // Check for reversal signal
        $reversalScore = $this->calculateReversalScore($prices);
        if ($reversalScore < 2.0) {
            return null;
        }

        // Calculate overall score
        $score = $this->calculateOverallScore($declineInfo, $volumeScore, $reversalScore);

        if ($score < 3.0) {
            return null;
        }

        // Entry at current price (expecting bounce)
        $entry = (float) $latest['price'];
        $stop = $entry * (1 - $this->config['stop_loss_pct'] / 100);
        $riskAmount = $entry - $stop;
        $target1 = $entry + ($riskAmount * $this->config['target_multiple']);
        $target2 = $entry + ($riskAmount * $this->config['target_multiple'] * 1.5);
        $target3 = $entry + ($riskAmount * $this->config['target_multiple'] * 2.0);

        return [
            'symbol' => $symbol,
            'type' => 'oversold_bounce',
            'trigger_ts_est' => $asOfTime->format('Y-m-d H:i:s'),
            'entry_ts_est' => $asOfTime->format('Y-m-d H:i:s'),
            'entry' => $entry,
            'stop' => $stop,
            'targets' => [
                '1R' => $target1,
                '2R' => $target2,
                '3R' => $target3,
            ],
            'score' => $score,
            'decline_pct' => $declineInfo['decline_pct'],
            'volume_multiple' => $volumeScore,
            'reversal_score' => $reversalScore,
            'recent_high' => $declineInfo['recent_high'],
            'risk_pct' => $this->config['stop_loss_pct'],
            'risk_per_share' => $riskAmount,
            'notes' => sprintf(
                'Bounce setup: %.1f%% decline from $%.2f with %dx volume',
                $declineInfo['decline_pct'],
                $declineInfo['recent_high'],
                round($volumeScore, 1)
            ),
        ];
    }

    /**
     * Basic filters
     */
    private function passesBasicFilters(array $latest): bool
    {
        $price = (float) $latest['price'];

        if ($price < $this->config['min_price'] || $price > $this->config['max_price']) {
            return false;
        }

        if ((int) $latest['volume'] < 1000) {
            return false;
        }

        return true;
    }

    /**
     * Detect decline from recent high
     */
    private function detectDecline(array $prices): ?array
    {
        $currentPrice = (float) $prices[0]['price'];

        // Look at previous bars to find recent high
        $lookbackPrices = array_slice($prices, 1, $this->config['lookback_periods']);

        if (count($lookbackPrices) < 10) {
            return null;
        }

        // Find highest high in lookback period
        $maxHigh = max(array_map(fn ($bar) => (float) $bar['high'], $lookbackPrices));

        // Calculate decline percentage
        $declinePct = (($maxHigh - $currentPrice) / $maxHigh) * 100;

        if ($declinePct < $this->config['min_decline_pct']) {
            return null;
        }

        return [
            'recent_high' => $maxHigh,
            'decline_pct' => $declinePct,
            'current_price' => $currentPrice,
        ];
    }

    /**
     * Calculate volume score
     */
    private function calculateVolumeScore(array $prices): float
    {
        $recentVolumes = array_slice($prices, 0, 3); // Last 3 bars
        $avgRecentVolume = array_sum(array_map(fn ($bar) => (int) $bar['volume'], $recentVolumes)) / 3;

        if ($avgRecentVolume === 0) {
            return 0;
        }

        // Compare to average of previous 10 bars
        $lookbackVolumes = array_slice($prices, 3, 10);
        $avgLookbackVolume = array_sum(array_map(fn ($bar) => (int) $bar['volume'], $lookbackVolumes)) / count($lookbackVolumes);

        if ($avgLookbackVolume === 0) {
            return 0;
        }

        return $avgRecentVolume / $avgLookbackVolume;
    }

    /**
     * Calculate reversal score based on price action
     */
    private function calculateReversalScore(array $prices): float
    {
        if (count($prices) < 5) {
            return 0;
        }

        $latest = $prices[0];
        $current = (float) $latest['price'];
        $high = (float) $latest['high'];
        $low = (float) $latest['low'];

        // Look for reversal patterns
        $score = 0;

        // Current bar bouncing from low
        if ($low > 0) {
            $bounceFromLow = (($current - $low) / $low) * 100;
            if ($bounceFromLow > $this->config['reversal_threshold']) {
                $score += $bounceFromLow * 2;
            }
        }

        // Recent bars showing support
        $recentLows = array_slice(array_map(fn ($bar) => (float) $bar['low'], $prices), 0, 3);
        $minRecentLow = min($recentLows);

        if ($current > $minRecentLow) {
            $score += 1.0;
        }

        // Volume confirmation on bounce
        $currentVolume = (int) $latest['volume'];
        $prevVolume = (int) $prices[1]['volume'];

        if ($currentVolume > $prevVolume) {
            $score += 0.5;
        }

        return $score;
    }

    /**
     * Calculate overall score
     */
    private function calculateOverallScore(array $declineInfo, float $volumeScore, float $reversalScore): float
    {
        $score = 0;

        // Decline magnitude (higher decline = higher potential bounce)
        $score += $declineInfo['decline_pct'] * 0.5;

        // Volume score
        $score += ($volumeScore - 1) * 2.0;

        // Reversal strength
        $score += $reversalScore;

        return max(0, $score);
    }

    /**
     * Get active symbols
     */
    private function getActiveSymbols(): array
    {
        return DB::table('asset_info')
            ->where('1_min', 1)
            ->whereNull('deleted_at')
            ->pluck('symbol')
            ->toArray();
    }

    /**
     * Get price data
     */
    private function getPriceData(string $symbol, Carbon $asOfTime, int $minutes): array
    {
        $startTime = $asOfTime->copy()->subMinutes($minutes);

        $results = DB::table('one_minute_prices')
            ->where('symbol', $symbol)
            ->where('ts_est', '<=', $asOfTime)
            ->where('ts_est', '>=', $startTime)
            ->orderBy('ts_est', 'desc')
            ->get(['price', 'high', 'low', 'volume', 'ts_est']);

        return $results->map(function ($item) {
            return [
                'price' => $item->price,
                'high' => $item->high,
                'low' => $item->low,
                'volume' => $item->volume,
                'ts_est' => $item->ts_est,
            ];
        })->toArray();
    }
}
