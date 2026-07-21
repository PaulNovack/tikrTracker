<?php

namespace App\Services\Trading;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Profitable Pattern Strategy
 *
 * Focus on QUALITY over quantity:
 * - Ultra-selective criteria based on winning patterns
 * - Higher win rates with better risk/reward
 * - 1-5 high-quality setups per day maximum
 * - Positive expected value
 */
class ProfitablePatternStrategy
{
    private $config;

    public function __construct()
    {
        $this->config = [
            // Balanced setup criteria (less restrictive than ultra, more than production)
            'min_decline_pct' => 2.0,          // Meaningful decline required
            'max_decline_pct' => 5.5,          // Avoid freefall stocks
            'min_reversal_pct' => 0.6,         // Decent bounce required
            'min_volume_ratio' => 1.8,         // Good volume confirmation

            // Quality thresholds (balanced)
            'min_price' => 12.0,               // Include more stocks
            'max_price' => 200.0,              // Wider range
            'max_rsi_oversold' => 40,          // Slightly less strict RSI
            'min_rsi_bounce' => 35,            // RSI recovery required
            'min_trend_strength' => 0.15,      // Modest trend requirement

            // Balanced risk management
            'stop_loss_pct' => 1.5,            // Reasonable stops
            'target_multiple' => 2.0,          // Conservative R/R
            'min_quality_score' => 15.0,       // Reasonable quality bar
            'max_daily_signals' => 8,          // Quality focused but not too restrictive

            // Pattern-specific filters (relaxed)
            'min_consolidation_bars' => 2,     // Price stabilization
            'max_volatility_pct' => 10.0,      // Allow reasonable swings
            'min_relative_strength' => 0.2,    // Basic sector strength
        ];
    }

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

        // Sort by score and return only the best ones
        usort($opportunities, function ($a, $b) {
            return $b['score'] <=> $a['score'];
        });

        return array_slice($opportunities, 0, $this->config['max_daily_signals']);
    }

    private function analyzeSymbol(string $symbol, Carbon $asOfTime): ?array
    {
        $prices = $this->getPriceData($symbol, $asOfTime, 80);

        if (count($prices) < 60) {
            return null;
        }

        $latest = $prices[0];

        // Basic filters
        if (! $this->passesBasicFilters($latest)) {
            return null;
        }

        // Check for high-quality decline setup
        $decline = $this->checkHighQualityDecline($prices);
        if (! $decline) {
            return null;
        }

        // Check for strong bounce signal
        $bounce = $this->checkStrongBounce($prices);
        if (! $bounce) {
            return null;
        }

        // Volume confirmation required
        $volume = $this->checkVolumeConfirmation($prices);
        if (! $volume) {
            return null;
        }

        // RSI pattern check
        $rsi = $this->checkRSIPattern($prices);
        if (! $rsi) {
            return null;
        }

        // Price consolidation check
        $consolidation = $this->checkConsolidation($prices);
        if (! $consolidation) {
            return null;
        }

        // Trend context
        $trend = $this->checkTrendContext($prices);
        if (! $trend) {
            return null;
        }

        // Calculate levels
        $entry = (float) $latest->price;
        $stop = $entry * (1 - $this->config['stop_loss_pct'] / 100);
        $riskAmount = $entry - $stop;
        $target1 = $entry + ($riskAmount * $this->config['target_multiple']);
        $target2 = $entry + ($riskAmount * $this->config['target_multiple'] * 1.5);

        // Score the setup - must be high quality
        $score = $this->calculateQualityScore($decline, $bounce, $volume, $rsi, $consolidation, $trend);

        if ($score < $this->config['min_quality_score']) {
            return null;
        }

        return [
            'symbol' => $symbol,
            'type' => 'profitable_pattern',
            'trigger_ts_est' => $asOfTime->format('Y-m-d H:i:s'),
            'entry_ts_est' => $asOfTime->format('Y-m-d H:i:s'),
            'entry' => $entry,
            'stop' => $stop,
            'targets' => [
                '1R' => $target1,
                '2R' => $target2,
            ],
            'score' => $score,
            'risk_pct' => $this->config['stop_loss_pct'],
            'decline_pct' => $decline['decline_pct'],
            'bounce_pct' => $bounce['bounce_pct'],
            'volume_ratio' => $volume['ratio'],
            'rsi_current' => $rsi['current'],
            'rsi_low' => $rsi['low'],
            'consolidation_bars' => $consolidation['bars'],
            'trend_strength' => $trend['strength'],
            'notes' => sprintf(
                'High-quality setup: %.1f%% decline → %.1f%% bounce, %.1fx vol, RSI %.1f→%.1f, %d-bar consolidation',
                $decline['decline_pct'],
                $bounce['bounce_pct'],
                $volume['ratio'],
                $rsi['low'],
                $rsi['current'],
                $consolidation['bars']
            ),
        ];
    }

    private function passesBasicFilters($latest): bool
    {
        $price = (float) $latest->price;
        $volume = (int) $latest->volume;

        return $price >= $this->config['min_price'] &&
               $price <= $this->config['max_price'] &&
               $volume >= 1000;  // Lower minimum volume for 5-minute bars
    }

    private function checkHighQualityDecline(array $prices): ?array
    {
        $currentPrice = (float) $prices[0]->price;

        // Look for recent high in last 15 bars
        $lookback = array_slice($prices, 1, 15);
        if (empty($lookback)) {
            return null;
        }

        $recentHigh = max(array_map(fn ($bar) => (float) $bar->high, $lookback));
        $declinePct = (($recentHigh - $currentPrice) / $recentHigh) * 100;

        // Must be meaningful decline but not freefall
        if ($declinePct < $this->config['min_decline_pct'] ||
            $declinePct > $this->config['max_decline_pct']) {
            return null;
        }

        // Check decline wasn't too fast (avoid panics)
        $declineSpeed = $this->calculateDeclineSpeed($prices, $recentHigh);
        if ($declineSpeed > 2.0) {  // Max 2% per bar decline
            return null;
        }

        return [
            'decline_pct' => $declinePct,
            'recent_high' => $recentHigh,
            'decline_speed' => $declineSpeed,
        ];
    }

    private function checkStrongBounce(array $prices): ?array
    {
        $currentPrice = (float) $prices[0]->price;

        // Find recent low in last 8 bars
        $recentBars = array_slice($prices, 0, 8);
        $recentLow = min(array_map(fn ($bar) => (float) $bar->low, $recentBars));

        $bouncePct = (($currentPrice - $recentLow) / $recentLow) * 100;

        // Must have strong bounce
        if ($bouncePct < $this->config['min_reversal_pct']) {
            return null;
        }

        // Check bounce quality - should be on increasing volume
        $bounceQuality = $this->calculateBounceQuality($prices);
        if ($bounceQuality < 0.4) {  // 40% quality threshold (less strict)
            return null;
        }

        return [
            'bounce_pct' => $bouncePct,
            'recent_low' => $recentLow,
            'bounce_quality' => $bounceQuality,
        ];
    }

    private function checkVolumeConfirmation(array $prices): ?array
    {
        $currentVolume = (int) $prices[0]->volume;

        // Average volume over last 20 bars
        $recentBars = array_slice($prices, 1, 20);
        if (count($recentBars) < 10) {
            return null;
        }

        $avgVolume = array_sum(array_map(fn ($bar) => (int) $bar->volume, $recentBars)) / count($recentBars);

        if ($avgVolume <= 0) {
            return null;
        }

        $volumeRatio = $currentVolume / $avgVolume;

        // Must have strong volume confirmation
        if ($volumeRatio < $this->config['min_volume_ratio']) {
            return null;
        }

        return [
            'ratio' => $volumeRatio,
            'current' => $currentVolume,
            'average' => $avgVolume,
        ];
    }

    private function checkRSIPattern(array $prices): ?array
    {
        $rsi14 = $this->calculateRSI($prices);

        // More flexible RSI check - either recovering from oversold OR currently oversold
        $rsiHistory = $this->calculateRSIHistory($prices, 10);
        $rsiLow = min($rsiHistory);

        // Must have been oversold recently OR be oversold now
        if ($rsiLow > $this->config['max_rsi_oversold'] && $rsi14 > $this->config['max_rsi_oversold']) {
            return null;
        }

        // If recovering, should show some improvement
        if ($rsi14 > $this->config['max_rsi_oversold']) {
            $rsiImprovement = $rsi14 - $rsiLow;
            if ($rsiImprovement < 3.0) {  // At least 3-point improvement
                return null;
            }
        } else {
            // Currently oversold is okay
            $rsiImprovement = 0;
        }

        return [
            'current' => $rsi14,
            'low' => $rsiLow,
            'improvement' => $rsiImprovement,
        ];
    }

    private function checkConsolidation(array $prices): ?array
    {
        // Look for price consolidation after decline
        $recentBars = array_slice($prices, 0, min(8, count($prices)));

        if (count($recentBars) < $this->config['min_consolidation_bars']) {
            return null;
        }

        $priceRange = $this->calculatePriceRange($recentBars);
        $avgPrice = array_sum(array_map(fn ($bar) => (float) $bar->close, $recentBars)) / count($recentBars);

        $volatilityPct = ($priceRange / $avgPrice) * 100;

        // Volatility should be reasonable (not too wild)
        if ($volatilityPct > $this->config['max_volatility_pct']) {
            return null;
        }

        return [
            'bars' => count($recentBars),
            'volatility_pct' => $volatilityPct,
            'price_range' => $priceRange,
        ];
    }

    private function checkTrendContext(array $prices): ?array
    {
        // Check longer-term trend context
        if (count($prices) < 40) {
            return null;
        }

        $ma20 = $this->calculateSMA($prices, 20);
        $ma50 = $this->calculateSMA($prices, 50);

        $currentPrice = (float) $prices[0]->price;

        // Trend strength calculation
        $trendStrength = ($ma20 - $ma50) / $ma50;

        if (abs($trendStrength) < $this->config['min_trend_strength']) {
            return null;
        }

        return [
            'strength' => $trendStrength,
            'ma20' => $ma20,
            'ma50' => $ma50,
            'direction' => $trendStrength > 0 ? 'up' : 'down',
        ];
    }

    private function calculateQualityScore($decline, $bounce, $volume, $rsi, $consolidation, $trend): float
    {
        $score = 0;

        // Decline quality (0-5 points)
        $score += min(5, $decline['decline_pct'] * 1.2);

        // Bounce strength (0-5 points)
        $score += min(5, $bounce['bounce_pct'] * 2.5);

        // Volume confirmation (0-8 points)
        $score += min(8, ($volume['ratio'] - 1) * 3);

        // RSI pattern (0-4 points)
        $score += min(4, $rsi['improvement'] * 0.4);

        // Consolidation quality (0-3 points)
        $consolidationScore = max(0, 3 - ($consolidation['volatility_pct'] * 0.3));
        $score += $consolidationScore;

        // Trend context (0-3 points)
        $score += min(3, abs($trend['strength']) * 6);

        return round($score, 1);
    }

    // Helper methods
    private function getActiveSymbols(): array
    {
        return DB::table('asset_info')
            ->where('asset_type', 'stock')
            ->whereNull('deleted_at')
            ->where('1_min', 1)      // Only volatile stocks
            ->where('over_1mil', 1)  // Only liquid stocks
            ->pluck('symbol')
            ->take(500)              // Smaller set for quality focus
            ->toArray();
    }

    private function getPriceData(string $symbol, Carbon $asOfTime, int $bars): array
    {
        return DB::table('five_minute_prices')
            ->where('symbol', $symbol)
            ->where('asset_type', 'stock')
            ->where('ts_est', '<=', $asOfTime)
            ->orderBy('ts_est', 'desc')
            ->limit($bars)
            ->get(['price', 'open', 'high', 'low', 'volume', 'ts_est as close'])
            ->map(function ($item) {
                $item->close = $item->price; // Add close field

                return $item;
            })
            ->toArray();
    }

    private function calculateRSI(array $prices, int $period = 14): float
    {
        if (count($prices) < $period + 1) {
            return 50.0;
        }

        $gains = [];
        $losses = [];

        for ($i = 0; $i < $period; $i++) {
            if (! isset($prices[$i + 1])) {
                break;
            }

            $change = (float) $prices[$i]->close - (float) $prices[$i + 1]->close;

            if ($change > 0) {
                $gains[] = $change;
                $losses[] = 0;
            } else {
                $gains[] = 0;
                $losses[] = abs($change);
            }
        }

        if (empty($gains)) {
            return 50.0;
        }

        $avgGain = array_sum($gains) / count($gains);
        $avgLoss = array_sum($losses) / count($losses);

        if ($avgLoss == 0) {
            return 100.0;
        }

        $rs = $avgGain / $avgLoss;

        return 100 - (100 / (1 + $rs));
    }

    private function calculateRSIHistory(array $prices, int $bars): array
    {
        $rsiHistory = [];
        for ($i = 0; $i < min($bars, count($prices) - 14); $i++) {
            $subset = array_slice($prices, $i, 15);
            $rsiHistory[] = $this->calculateRSI($subset);
        }

        return $rsiHistory;
    }

    private function calculateSMA(array $prices, int $period): float
    {
        if (count($prices) < $period) {
            return 0.0;
        }

        $sum = 0;
        for ($i = 0; $i < $period; $i++) {
            $sum += (float) $prices[$i]->close;
        }

        return $sum / $period;
    }

    private function calculateDeclineSpeed(array $prices, float $high): float
    {
        $currentPrice = (float) $prices[0]->price;
        $declinePct = (($high - $currentPrice) / $high) * 100;

        // Find how many bars since high
        $barsSinceHigh = 1;
        foreach (array_slice($prices, 1, 10) as $bar) {
            if ((float) $bar->high >= $high * 0.99) {
                break;
            }
            $barsSinceHigh++;
        }

        return $barsSinceHigh > 0 ? $declinePct / $barsSinceHigh : $declinePct;
    }

    private function calculateBounceQuality(array $prices): float
    {
        if (count($prices) < 5) {
            return 0.0;
        }

        $recentBars = array_slice($prices, 0, 5);
        $volumeIncrease = 0;
        $priceProgress = 0;

        for ($i = 0; $i < count($recentBars) - 1; $i++) {
            $current = $recentBars[$i];
            $previous = $recentBars[$i + 1];

            if ((int) $current->volume > (int) $previous->volume) {
                $volumeIncrease += 0.2;
            }

            if ((float) $current->close > (float) $previous->close) {
                $priceProgress += 0.2;
            }
        }

        return min(1.0, $volumeIncrease + $priceProgress);
    }

    private function calculatePriceRange(array $bars): float
    {
        if (empty($bars)) {
            return 0.0;
        }

        $highs = array_map(fn ($bar) => (float) $bar->high, $bars);
        $lows = array_map(fn ($bar) => (float) $bar->low, $bars);

        return max($highs) - min($lows);
    }
}
