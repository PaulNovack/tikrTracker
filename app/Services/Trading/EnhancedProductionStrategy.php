<?php

namespace App\Services\Trading;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Enhanced Production Strategy
 *
 * Based on ProductionBounceStrategy but with smarter filtering:
 * - Keep reasonable volume requirements
 * - Add better quality filters to improve win rate
 * - Target 8-15 high-quality setups per day
 * - Focus on positive expected value
 */
class EnhancedProductionStrategy
{
    private $config;

    public function __construct()
    {
        $this->config = [
            // Setup criteria (improved from production)
            'min_decline_pct' => 2.0,          // Meaningful decline
            'max_decline_pct' => 5.0,          // Avoid freefall
            'min_reversal_pct' => 0.4,         // Decent bounce
            'min_volume_ratio' => 1.5,         // Volume confirmation

            // Enhanced quality thresholds
            'min_price' => 12.0,               // Include penny stocks
            'max_price' => 200.0,              // Wider range
            'max_rsi_oversold' => 42,          // Slightly more strict RSI
            'min_rsi_improvement' => 2.0,      // RSI must be improving
            'min_trend_strength' => 0.05,      // Very basic trend req

            // Enhanced risk management
            'stop_loss_pct' => 1.3,            // Slightly tighter stops
            'target_multiple' => 2.2,          // Better R/R
            'min_quality_score' => 14.0,       // Higher quality bar
            'max_daily_signals' => 15,         // Reasonable number

            // New quality filters
            'max_recent_volatility' => 12.0,   // Avoid too volatile
            'min_price_stability' => 0.7,      // Price pattern stability
            'require_volume_surge' => true,    // Must have volume spike
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

        // Sort by score and return best ones
        usort($opportunities, function ($a, $b) {
            return $b['score'] <=> $a['score'];
        });

        return array_slice($opportunities, 0, $this->config['max_daily_signals']);
    }

    private function analyzeSymbol(string $symbol, Carbon $asOfTime): ?array
    {
        $prices = $this->getPriceData($symbol, $asOfTime, 60);

        if (count($prices) < 40) {
            return null;
        }

        $latest = $prices[0];

        // Basic filters
        if (! $this->passesBasicFilters($latest)) {
            return null;
        }

        // Enhanced decline check
        $decline = $this->checkEnhancedDecline($prices);
        if (! $decline) {
            return null;
        }

        // Enhanced bounce check
        $bounce = $this->checkEnhancedBounce($prices);
        if (! $bounce) {
            return null;
        }

        // Enhanced volume check
        $volume = $this->checkEnhancedVolume($prices);
        if (! $volume) {
            return null;
        }

        // Enhanced RSI check
        $rsi = $this->checkEnhancedRSI($prices);
        if (! $rsi) {
            return null;
        }

        // NEW: Volatility check
        $volatility = $this->checkVolatility($prices);
        if (! $volatility) {
            return null;
        }

        // NEW: Price stability check
        $stability = $this->checkPriceStability($prices);
        if (! $stability) {
            return null;
        }

        // Calculate levels
        $entry = (float) $latest->price;
        $stop = $entry * (1 - $this->config['stop_loss_pct'] / 100);
        $riskAmount = $entry - $stop;
        $target1 = $entry + ($riskAmount * $this->config['target_multiple']);
        $target2 = $entry + ($riskAmount * $this->config['target_multiple'] * 1.4);

        // Enhanced scoring
        $score = $this->calculateEnhancedScore($decline, $bounce, $volume, $rsi, $volatility, $stability);

        if ($score < $this->config['min_quality_score']) {
            return null;
        }

        return [
            'symbol' => $symbol,
            'type' => 'enhanced_production',
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
            'rsi' => $rsi['current'],
            'rsi_improvement' => $rsi['improvement'],
            'volatility_score' => $volatility['score'],
            'stability_score' => $stability['score'],
            'notes' => sprintf(
                'Enhanced setup: %.1f%% decline → %.1f%% bounce, %.1fx vol, RSI %.1f (+%.1f), Vol:%.1f, Stab:%.2f',
                $decline['decline_pct'],
                $bounce['bounce_pct'],
                $volume['ratio'],
                $rsi['current'],
                $rsi['improvement'],
                $volatility['score'],
                $stability['score']
            ),
        ];
    }

    private function passesBasicFilters($latest): bool
    {
        $price = (float) $latest->price;
        $volume = (int) $latest->volume;

        return $price >= $this->config['min_price'] &&
               $price <= $this->config['max_price'] &&
               $volume >= 1000;
    }

    private function checkEnhancedDecline(array $prices): ?array
    {
        $currentPrice = (float) $prices[0]->price;

        // Look for recent high in last 20 bars
        $lookback = array_slice($prices, 1, 20);
        if (empty($lookback)) {
            return null;
        }

        $recentHigh = max(array_map(fn ($bar) => (float) $bar->high, $lookback));
        $declinePct = (($recentHigh - $currentPrice) / $recentHigh) * 100;

        if ($declinePct < $this->config['min_decline_pct'] ||
            $declinePct > $this->config['max_decline_pct']) {
            return null;
        }

        // NEW: Check decline quality - should not be too erratic
        $declineQuality = $this->calculateDeclineQuality($prices, $recentHigh, $currentPrice);
        if ($declineQuality < 0.6) {  // 60% quality threshold
            return null;
        }

        return [
            'decline_pct' => $declinePct,
            'recent_high' => $recentHigh,
            'quality' => $declineQuality,
        ];
    }

    private function checkEnhancedBounce(array $prices): ?array
    {
        $currentPrice = (float) $prices[0]->price;

        // Find recent low in last 8 bars
        $recentBars = array_slice($prices, 0, 8);
        $recentLow = min(array_map(fn ($bar) => (float) $bar->low, $recentBars));

        $bouncePct = (($currentPrice - $recentLow) / $recentLow) * 100;

        if ($bouncePct < $this->config['min_reversal_pct']) {
            return null;
        }

        // NEW: Check bounce momentum - should show strength
        $bounceStrength = $this->calculateBounceStrength($prices);
        if ($bounceStrength < 0.5) {  // 50% strength threshold
            return null;
        }

        return [
            'bounce_pct' => $bouncePct,
            'recent_low' => $recentLow,
            'strength' => $bounceStrength,
        ];
    }

    private function checkEnhancedVolume(array $prices): ?array
    {
        $currentVolume = (int) $prices[0]->volume;

        // Compare to recent average (last 15 bars)
        $recentVolumes = array_slice($prices, 1, 15);
        if (count($recentVolumes) < 10) {
            return null;
        }

        $avgVolume = array_sum(array_map(fn ($bar) => (int) $bar->volume, $recentVolumes)) / count($recentVolumes);

        if ($avgVolume <= 0) {
            return null;
        }

        $volumeRatio = $currentVolume / $avgVolume;

        if ($volumeRatio < $this->config['min_volume_ratio']) {
            return null;
        }

        // NEW: Check volume pattern - should be surging on bounce
        if ($this->config['require_volume_surge']) {
            $volumeSurge = $this->checkVolumeSurge($prices);
            if (! $volumeSurge) {
                return null;
            }
        }

        return [
            'ratio' => $volumeRatio,
            'current' => $currentVolume,
            'average' => $avgVolume,
        ];
    }

    private function checkEnhancedRSI(array $prices): ?array
    {
        $currentRSI = $this->calculateRSI($prices);

        // Must be oversold or recently oversold
        if ($currentRSI > $this->config['max_rsi_oversold']) {
            return null;
        }

        // NEW: Check RSI improvement trend
        $rsiHistory = $this->calculateRSIHistory($prices, 5);
        if (count($rsiHistory) < 3) {
            return null;
        }

        $rsiImprovement = $currentRSI - min($rsiHistory);

        if ($rsiImprovement < $this->config['min_rsi_improvement']) {
            return null;
        }

        return [
            'current' => $currentRSI,
            'improvement' => $rsiImprovement,
        ];
    }

    private function checkVolatility(array $prices): ?array
    {
        // Check recent volatility - avoid too volatile stocks
        $recentBars = array_slice($prices, 0, 10);
        if (count($recentBars) < 5) {
            return null;
        }

        $priceRanges = [];
        foreach ($recentBars as $bar) {
            $range = ((float) $bar->high - (float) $bar->low) / (float) $bar->price * 100;
            $priceRanges[] = $range;
        }

        $avgVolatility = array_sum($priceRanges) / count($priceRanges);

        if ($avgVolatility > $this->config['max_recent_volatility']) {
            return null;
        }

        // Higher score for lower volatility (more stable)
        $volatilityScore = max(0, 10 - $avgVolatility);

        return [
            'avg_volatility' => $avgVolatility,
            'score' => $volatilityScore,
        ];
    }

    private function checkPriceStability(array $prices): ?array
    {
        // Check price pattern stability
        $recentBars = array_slice($prices, 0, 8);
        if (count($recentBars) < 5) {
            return null;
        }

        // Calculate price trend consistency
        $trendChanges = 0;
        $lastDirection = null;

        for ($i = 0; $i < count($recentBars) - 1; $i++) {
            $current = (float) $recentBars[$i]->price;
            $next = (float) $recentBars[$i + 1]->price;

            $direction = $current > $next ? 'up' : 'down';

            if ($lastDirection && $direction !== $lastDirection) {
                $trendChanges++;
            }

            $lastDirection = $direction;
        }

        $stabilityScore = max(0, 1 - ($trendChanges / (count($recentBars) - 1)));

        if ($stabilityScore < $this->config['min_price_stability']) {
            return null;
        }

        return [
            'trend_changes' => $trendChanges,
            'score' => $stabilityScore,
        ];
    }

    private function calculateEnhancedScore($decline, $bounce, $volume, $rsi, $volatility, $stability): float
    {
        $score = 0;

        // Base production scoring
        $score += min(6, $decline['decline_pct'] * 1.5);
        $score += min(4, $bounce['bounce_pct'] * 8);
        $score += min(6, ($volume['ratio'] - 1) * 4);
        $score += min(3, $rsi['improvement'] * 1.5);

        // NEW: Enhanced quality scoring
        $score += min(3, $volatility['score'] * 0.3);      // Reward stability
        $score += min(4, $stability['score'] * 4);         // Reward consistent patterns
        $score += min(2, $decline['quality'] * 3);         // Reward clean declines
        $score += min(2, $bounce['strength'] * 4);         // Reward strong bounces

        return round($score, 1);
    }

    // Helper calculation methods
    private function calculateDeclineQuality(array $prices, float $high, float $current): float
    {
        // Quality = how smooth/consistent the decline was
        $declineRange = array_slice($prices, 0, 10);
        $erraticMoves = 0;

        for ($i = 0; $i < count($declineRange) - 1; $i++) {
            $curr = (float) $declineRange[$i]->price;
            $next = (float) $declineRange[$i + 1]->price;

            // Check for erratic moves against decline trend
            if ($curr < $next && ($next - $curr) / $curr > 0.015) { // 1.5% up move during decline
                $erraticMoves++;
            }
        }

        return max(0, 1 - ($erraticMoves * 0.2));
    }

    private function calculateBounceStrength(array $prices): float
    {
        // Strength = volume and price action on bounce bars
        $bounceBars = array_slice($prices, 0, 5);
        $strength = 0;

        for ($i = 0; $i < count($bounceBars) - 1; $i++) {
            $curr = $bounceBars[$i];
            $next = $bounceBars[$i + 1];

            $priceGain = (float) $curr->price > (float) $next->price;
            $volumeIncrease = (int) $curr->volume > (int) $next->volume;

            if ($priceGain && $volumeIncrease) {
                $strength += 0.3;
            } elseif ($priceGain) {
                $strength += 0.1;
            }
        }

        return min(1.0, $strength);
    }

    private function checkVolumeSurge(array $prices): bool
    {
        // Check if volume is surging on recent bars
        $currentVol = (int) $prices[0]->volume;
        $recentVols = array_slice(array_map(fn ($p) => (int) $p->volume, $prices), 1, 5);

        if (empty($recentVols)) {
            return false;
        }

        $avgRecentVol = array_sum($recentVols) / count($recentVols);

        return $currentVol > ($avgRecentVol * 1.3); // 30% volume surge
    }

    private function calculateRSI(array $prices): float
    {
        $period = 14;
        if (count($prices) < $period + 2) {
            return 35; // Assume oversold if not enough data
        }

        $gains = [];
        $losses = [];

        for ($i = 1; $i <= $period; $i++) {
            if (! isset($prices[$i - 1]) || ! isset($prices[$i])) {
                continue;
            }

            $change = (float) $prices[$i - 1]->price - (float) $prices[$i]->price;
            $gains[] = max($change, 0);
            $losses[] = max(-$change, 0);
        }

        if (empty($gains) || empty($losses)) {
            return 35;
        }

        $avgGain = array_sum($gains) / count($gains);
        $avgLoss = array_sum($losses) / count($losses);

        if ($avgLoss == 0) {
            return 100;
        }
        if ($avgGain == 0) {
            return 0;
        }

        $rs = $avgGain / $avgLoss;

        return 100 - (100 / (1 + $rs));
    }

    private function calculateRSIHistory(array $prices, int $periods): array
    {
        $history = [];

        for ($i = 0; $i < min($periods, count($prices) - 15); $i++) {
            $subset = array_slice($prices, $i, 16);
            $history[] = $this->calculateRSI($subset);
        }

        return $history;
    }

    // Standard helper methods
    private function getActiveSymbols(): array
    {
        return DB::table('asset_info')
            ->where('asset_type', 'stock')
            ->whereNull('deleted_at')
            ->where('1_min', 1)      // Only volatile stocks
            ->where('over_1mil', 1)  // Only liquid stocks
            ->pluck('symbol')
            ->take(800)              // Good-sized universe
            ->toArray();
    }

    private function getPriceData(string $symbol, Carbon $asOfTime, int $limit): array
    {
        return DB::table('five_minute_prices')
            ->where('symbol', $symbol)
            ->where('asset_type', 'stock')
            ->where('ts_est', '<=', $asOfTime)
            ->orderBy('ts_est', 'desc')
            ->limit($limit)
            ->get(['price', 'open', 'high', 'low', 'volume', 'ts_est'])
            ->toArray();
    }
}
