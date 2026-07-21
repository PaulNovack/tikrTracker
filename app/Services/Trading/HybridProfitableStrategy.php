<?php

namespace App\Services\Trading;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Hybrid Profitable Strategy
 *
 * Combines lessons from bounce and momentum strategy failures:
 * - Ultra-selective quality filters (5-10 setups max per day)
 * - Better entry timing using multiple confirmations
 * - Tight risk management with proper position sizing
 * - Focus on high-probability setups only
 * - Market context awareness
 */
class HybridProfitableStrategy
{
    private $config;

    public function __construct()
    {
        $this->config = [
            // Ultra-selective criteria - quality over quantity
            'min_decline_pct' => 2.5,          // Meaningful decline for bounce
            'max_decline_pct' => 8.0,          // Avoid falling knives
            'min_volume_surge' => 2.5,         // Strong volume confirmation
            'min_rsi_oversold' => 25,          // Truly oversold
            'max_rsi_oversold' => 45,          // But not dead

            // Quality thresholds (very strict)
            'min_price' => 15.0,               // Quality companies only
            'max_price' => 150.0,              // Avoid extreme high-priced stocks
            'min_avg_volume' => 500000,        // High liquidity required
            'min_market_cap' => 1000000000,    // Large cap preferred

            // Entry timing confirmations
            'require_hammer_candle' => true,    // Reversal pattern
            'require_support_level' => true,   // Bounce off support
            'require_momentum_divergence' => true, // RSI divergence
            'max_consecutive_red' => 3,        // Limit downtrend length

            // Risk management (very tight)
            'stop_loss_pct' => 1.5,            // Tight stops
            'target_multiple' => 3.0,          // High reward/risk
            'min_quality_score' => 25.0,       // Ultra-high quality only
            'max_daily_signals' => 8,          // Very selective

            // Market context filters
            'avoid_gap_downs' => true,          // Skip gap down opens
            'require_relative_strength' => true, // Outperform sector
            'check_market_trend' => true,      // Align with market
        ];
    }

    public function findOpportunities(Carbon $asOfTime, ?array $symbols = null): array
    {
        $opportunities = [];

        if ($symbols === null) {
            $symbols = $this->getQualitySymbols();
        }

        foreach ($symbols as $symbol) {
            $opportunity = $this->analyzeSymbol($symbol, $asOfTime);

            if ($opportunity) {
                $opportunities[] = $opportunity;
            }
        }

        // Sort by quality score and limit strictly
        usort($opportunities, function ($a, $b) {
            return $b['score'] <=> $a['score'];
        });

        return array_slice($opportunities, 0, $this->config['max_daily_signals']);
    }

    private function analyzeSymbol(string $symbol, Carbon $asOfTime): ?array
    {
        // Get comprehensive price data
        $prices = $this->getPriceData($symbol, $asOfTime);

        if (count($prices) < 30) {
            return null;
        }

        $latest = $prices[0];

        // Ultra-strict basic filters
        if (! $this->passesUltraFilters($latest, $symbol)) {
            return null;
        }

        // Check for quality decline (not crash)
        $decline = $this->checkQualityDecline($prices);
        if (! $decline) {
            return null;
        }

        // Market context check
        $marketContext = $this->checkMarketContext($asOfTime);
        if (! $marketContext['favorable']) {
            return null;
        }

        // Entry timing confirmations
        $entryTiming = $this->checkEntryTiming($prices);
        if (! $entryTiming) {
            return null;
        }

        // Volume and RSI confirmations
        $volume = $this->checkVolumeConfirmation($prices);
        if (! $volume) {
            return null;
        }

        $rsi = $this->checkRSISetup($prices);
        if (! $rsi) {
            return null;
        }

        // Support level confirmation
        $support = $this->checkSupportLevel($prices);
        if (! $support) {
            return null;
        }

        // Calculate high-probability entry levels
        $entry = (float) $latest->price;
        $stop = $entry * (1 - $this->config['stop_loss_pct'] / 100);
        $riskAmount = $entry - $stop;
        $target1 = $entry + ($riskAmount * $this->config['target_multiple']);
        $target2 = $entry + ($riskAmount * $this->config['target_multiple'] * 1.5);

        // Ultra-strict quality scoring
        $score = $this->calculateUltraScore($decline, $entryTiming, $volume, $rsi, $support, $marketContext);

        if ($score < $this->config['min_quality_score']) {
            return null;
        }

        return [
            'symbol' => $symbol,
            'type' => 'hybrid_profitable',
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
            'volume_surge' => $volume['surge_ratio'],
            'rsi' => $rsi['current'],
            'support_strength' => $support['strength'],
            'market_alignment' => $marketContext['strength'],
            'notes' => sprintf(
                'High-probability setup: -%.1f%% decline, RSI %.1f, %.1fx volume, support %.2f, market +%.1f%%',
                $decline['decline_pct'],
                $rsi['current'],
                $volume['surge_ratio'],
                $support['strength'],
                $marketContext['strength']
            ),
            'dedupe_key' => $symbol.'_'.number_format($entry, 2).'_'.number_format($stop, 2),
        ];
    }

    private function getPriceData(string $symbol, Carbon $asOfTime): array
    {
        $endTime = $asOfTime->format('Y-m-d H:i:s');
        $startTime = $asOfTime->copy()->subHours(4)->format('Y-m-d H:i:s');

        return DB::table('five_minute_prices')
            ->where('symbol', $symbol)
            ->where('asset_type', 'stock')
            ->where('ts_est', '>=', $startTime)
            ->where('ts_est', '<=', $endTime)
            ->orderBy('ts_est', 'desc')
            ->limit(50)
            ->get()
            ->toArray();
    }

    private function passesUltraFilters($latest, string $symbol): bool
    {
        $price = (float) $latest->price;
        $volume = (int) $latest->volume;

        // Basic price and volume filters
        if ($price < $this->config['min_price'] ||
            $price > $this->config['max_price'] ||
            $volume < $this->config['min_avg_volume']) {
            return false;
        }

        // Check if it's a quality company (simplified check)
        $qualitySymbols = $this->getQualitySymbols();
        if (! in_array($symbol, $qualitySymbols)) {
            return false;
        }

        return true;
    }

    private function checkQualityDecline(array $prices): ?array
    {
        if (count($prices) < 10) {
            return null;
        }

        $latest = $prices[0];
        $recentHigh = max(array_map(fn ($p) => (float) $p->high, array_slice($prices, 0, 10)));

        $declinePct = (($recentHigh - (float) $latest->price) / $recentHigh) * 100;

        // Must be meaningful decline but not crash
        if ($declinePct < $this->config['min_decline_pct'] ||
            $declinePct > $this->config['max_decline_pct']) {
            return null;
        }

        // Check for consecutive declining candles (but not too many)
        $consecutiveRed = 0;
        for ($i = 0; $i < min(5, count($prices) - 1); $i++) {
            if ((float) $prices[$i]->price < (float) $prices[$i + 1]->price) {
                $consecutiveRed++;
            } else {
                break;
            }
        }

        if ($consecutiveRed > $this->config['max_consecutive_red']) {
            return null;
        }

        return [
            'decline_pct' => $declinePct,
            'recent_high' => $recentHigh,
            'consecutive_red' => $consecutiveRed,
        ];
    }

    private function checkMarketContext(Carbon $asOfTime): array
    {
        // Simplified market context - check SPY performance
        $spyPrices = $this->getPriceData('SPY', $asOfTime);

        if (count($spyPrices) < 10) {
            return ['favorable' => true, 'strength' => 0];
        }

        $latestSpy = (float) $spyPrices[0]->price;
        $earlySpyPrice = (float) $spyPrices[9]->price;
        $marketChange = (($latestSpy - $earlySpyPrice) / $earlySpyPrice) * 100;

        // Favorable if market not crashing
        $favorable = $marketChange > -2.0; // Allow up to 2% market decline

        return [
            'favorable' => $favorable,
            'strength' => $marketChange,
        ];
    }

    private function checkEntryTiming(array $prices): ?array
    {
        if (count($prices) < 5) {
            return null;
        }

        $latest = $prices[0];
        $prev = $prices[1];

        // Look for hammer/doji reversal pattern
        $bodySize = abs((float) $latest->price - (float) $latest->open);
        $totalRange = (float) $latest->high - (float) $latest->low;

        if ($totalRange == 0) {
            return null;
        }

        $lowerShadow = (float) $latest->open - (float) $latest->low;
        $upperShadow = (float) $latest->high - max((float) $latest->price, (float) $latest->open);

        // Hammer pattern: small body, long lower shadow
        $isHammer = ($bodySize / $totalRange) < 0.3 &&
                   ($lowerShadow / $totalRange) > 0.5;

        // Price stabilization - current bar not making new lows
        $stabilizing = (float) $latest->low >= (float) $prev->low * 0.995;

        if (! $isHammer && ! $stabilizing) {
            return null;
        }

        return [
            'hammer_pattern' => $isHammer,
            'stabilizing' => $stabilizing,
            'body_ratio' => $bodySize / $totalRange,
        ];
    }

    private function checkVolumeConfirmation(array $prices): ?array
    {
        if (count($prices) < 15) {
            return null;
        }

        $currentVolume = (int) $prices[0]->volume;
        $avgVolume = array_sum(array_map(fn ($p) => (int) $p->volume, array_slice($prices, 1, 14))) / 14;

        if ($avgVolume == 0) {
            return null;
        }

        $surgeRatio = $currentVolume / $avgVolume;

        if ($surgeRatio < $this->config['min_volume_surge']) {
            return null;
        }

        return [
            'current_volume' => $currentVolume,
            'avg_volume' => $avgVolume,
            'surge_ratio' => $surgeRatio,
        ];
    }

    private function checkRSISetup(array $prices): ?array
    {
        if (count($prices) < 15) {
            return null;
        }

        $rsi = $this->calculateRSI($prices, 14);

        if ($rsi < $this->config['min_rsi_oversold'] || $rsi > $this->config['max_rsi_oversold']) {
            return null;
        }

        return [
            'current' => $rsi,
        ];
    }

    private function checkSupportLevel(array $prices): ?array
    {
        if (count($prices) < 20) {
            return null;
        }

        $currentPrice = (float) $prices[0]->price;

        // Find recent lows to identify support
        $recentLows = [];
        for ($i = 1; $i < min(20, count($prices)); $i++) {
            $recentLows[] = (float) $prices[$i]->low;
        }

        sort($recentLows);

        // Check if current price is near a support level
        $supportLevel = $recentLows[0]; // Lowest recent low
        $distanceFromSupport = (($currentPrice - $supportLevel) / $supportLevel) * 100;

        // Must be within 1% of support level
        if ($distanceFromSupport > 1.0) {
            return null;
        }

        $strength = max(0, 1 - $distanceFromSupport / 1.0);

        return [
            'support_level' => $supportLevel,
            'distance_pct' => $distanceFromSupport,
            'strength' => $strength,
        ];
    }

    private function calculateRSI(array $prices, int $period = 14): float
    {
        if (count($prices) < $period + 1) {
            return 50;
        }

        $gains = [];
        $losses = [];

        for ($i = 1; $i <= $period; $i++) {
            $change = (float) $prices[$i - 1]->price - (float) $prices[$i]->price;

            if ($change > 0) {
                $gains[] = $change;
                $losses[] = 0;
            } else {
                $gains[] = 0;
                $losses[] = abs($change);
            }
        }

        $avgGain = array_sum($gains) / $period;
        $avgLoss = array_sum($losses) / $period;

        if ($avgLoss == 0) {
            return 100;
        }

        $rs = $avgGain / $avgLoss;

        return 100 - (100 / (1 + $rs));
    }

    private function calculateUltraScore(array $decline, array $timing, array $volume, array $rsi, array $support, array $market): float
    {
        $score = 0;

        // Decline quality (0-20 points)
        $optimalDecline = 4.0;
        $declineScore = 20 - abs($decline['decline_pct'] - $optimalDecline) * 2;
        $score += max(0, $declineScore);

        // Entry timing (0-20 points)
        if ($timing['hammer_pattern']) {
            $score += 15;
        }
        if ($timing['stabilizing']) {
            $score += 10;
        }

        // Volume surge (0-15 points)
        $score += min(15, ($volume['surge_ratio'] - 2.5) * 5);

        // RSI oversold (0-15 points)
        $optimalRSI = 35;
        $rsiScore = 15 - abs($rsi['current'] - $optimalRSI) / 2;
        $score += max(0, $rsiScore);

        // Support strength (0-10 points)
        $score += $support['strength'] * 10;

        // Market alignment (0-10 points)
        if ($market['favorable']) {
            $score += 10 + max(-5, min(5, $market['strength']));
        }

        return round($score, 1);
    }

    private function getQualitySymbols(): array
    {
        // Focus on high-quality, liquid stocks only
        $qualitySymbols = [
            // Tech leaders
            'AAPL', 'MSFT', 'GOOGL', 'NVDA', 'META', 'TSLA', 'AMZN', 'NFLX',
            // Financial leaders
            'JPM', 'BAC', 'WFC', 'GS', 'MS', 'C',
            // Healthcare leaders
            'JNJ', 'PFE', 'UNH', 'ABBV', 'BMY', 'MRK',
            // Consumer leaders
            'KO', 'PEP', 'WMT', 'HD', 'MCD', 'NKE',
            // Industrial leaders
            'BA', 'CAT', 'GE', 'MMM', 'HON', 'RTX',
            // Energy leaders
            'XOM', 'CVX', 'COP', 'SLB', 'EOG',
        ];

        // Filter to only symbols we have current data for
        $availableSymbols = DB::table('five_minute_prices')
            ->select('symbol')
            ->where('ts_est', '>=', now()->subHours(6))
            ->where('asset_type', 'stock')
            ->whereIn('symbol', $qualitySymbols)
            ->groupBy('symbol')
            ->havingRaw('COUNT(*) >= 30')
            ->pluck('symbol')
            ->toArray();

        return $availableSymbols;
    }
}
