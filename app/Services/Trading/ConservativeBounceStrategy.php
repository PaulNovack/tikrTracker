<?php

namespace App\Services\Trading;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Conservative High-Quality Bounce Strategy
 *
 * Only trades the absolute best setups with:
 * 1. Strong trend confirmation
 * 2. Clear reversal patterns
 * 3. High volume confirmation
 * 4. Only stocks that have proven volatility (1_min = 1)
 */
class ConservativeBounceStrategy
{
    private $config;

    public function __construct()
    {
        $this->config = [
            'min_decline_pct' => 3.0,          // Require 3%+ meaningful decline
            'min_reversal_pct' => 0.8,         // Must see 0.8%+ bounce off low
            'min_volume_spike' => 2.0,          // 2x+ volume on reversal
            'min_score_threshold' => 25.0,      // High quality threshold
            'min_price' => 20.0,               // Focus on quality stocks
            'max_price' => 150.0,              // Avoid mega-caps
            'stop_loss_pct' => 1.5,            // Reasonable 1.5% stop
            'target_multiple' => 2.0,          // Conservative 2:1 R/R
            'trend_strength_min' => 1.0,       // Require uptrend
            'lookback_periods' => 25,          // More data for confidence
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

        // Only return top 5 highest quality setups
        usort($opportunities, function ($a, $b) {
            return $b['score'] <=> $a['score'];
        });

        return array_slice($opportunities, 0, 5);
    }

    private function analyzeSymbol(string $symbol, Carbon $asOfTime): ?array
    {
        $prices = $this->getPriceData($symbol, $asOfTime, 70);

        if (count($prices) < 60) {
            return null;
        }

        $latest = $prices[0];

        // Strict basic filters
        if (! $this->passesStrictFilters($latest)) {
            return null;
        }

        // Must be in clear uptrend
        $trendStrength = $this->calculateTrendStrength($prices);
        if ($trendStrength < $this->config['trend_strength_min']) {
            return null;
        }

        // Require meaningful decline
        $declineInfo = $this->detectMeaningfulDecline($prices);
        if (! $declineInfo) {
            return null;
        }

        // Must have clear reversal confirmation
        $reversalInfo = $this->detectClearReversal($prices);
        if (! $reversalInfo) {
            return null;
        }

        // Strong volume confirmation on reversal
        $volumeSpike = $this->calculateVolumeSpike($prices);
        if ($volumeSpike < $this->config['min_volume_spike']) {
            return null;
        }

        // Calculate comprehensive quality score
        $score = $this->calculateComprehensiveScore($declineInfo, $reversalInfo, $volumeSpike, $trendStrength);

        // Only accept highest quality setups
        if ($score < $this->config['min_score_threshold']) {
            return null;
        }

        $entry = (float) $latest->price;
        $stop = $entry * (1 - $this->config['stop_loss_pct'] / 100);
        $riskAmount = $entry - $stop;
        $target = $entry + ($riskAmount * $this->config['target_multiple']);

        return [
            'symbol' => $symbol,
            'type' => 'conservative_bounce',
            'trigger_ts_est' => $asOfTime->format('Y-m-d H:i:s'),
            'entry_ts_est' => $asOfTime->format('Y-m-d H:i:s'),
            'entry' => $entry,
            'stop' => $stop,
            'targets' => ['1R' => $target],
            'score' => $score,
            'decline_pct' => $declineInfo['decline_pct'],
            'reversal_pct' => $reversalInfo['reversal_pct'],
            'volume_spike' => $volumeSpike,
            'trend_strength' => $trendStrength,
            'risk_pct' => $this->config['stop_loss_pct'],
            'notes' => sprintf(
                'High-quality: %.1f%% decline → %.1f%% reversal, %dx volume, trend: +%.1f%%',
                $declineInfo['decline_pct'],
                $reversalInfo['reversal_pct'],
                round($volumeSpike, 1),
                $trendStrength
            ),
        ];
    }

    private function passesStrictFilters($latest): bool
    {
        $price = (float) $latest->price;

        if ($price < $this->config['min_price'] || $price > $this->config['max_price']) {
            return false;
        }

        // Require meaningful volume
        if ((int) $latest->volume < 10000) {
            return false;
        }

        return true;
    }

    private function calculateTrendStrength(array $prices): float
    {
        if (count($prices) < 40) {
            return 0;
        }

        // 30-day trend
        $recent = array_slice($prices, 0, 10);
        $older = array_slice($prices, 30, 10);

        $recentAvg = array_sum(array_map(fn ($bar) => (float) $bar->price, $recent)) / count($recent);
        $olderAvg = array_sum(array_map(fn ($bar) => (float) $bar->price, $older)) / count($older);

        return (($recentAvg - $olderAvg) / $olderAvg) * 100;
    }

    private function detectMeaningfulDecline(array $prices): ?array
    {
        $currentPrice = (float) $prices[0]->price;
        $lookback = array_slice($prices, 1, $this->config['lookback_periods']);

        if (count($lookback) < 15) {
            return null;
        }

        $recentHigh = max(array_map(fn ($bar) => (float) $bar->high, $lookback));
        $declinePct = (($recentHigh - $currentPrice) / $recentHigh) * 100;

        if ($declinePct < $this->config['min_decline_pct']) {
            return null;
        }

        return [
            'recent_high' => $recentHigh,
            'decline_pct' => $declinePct,
            'current_price' => $currentPrice,
        ];
    }

    private function detectClearReversal(array $prices): ?array
    {
        if (count($prices) < 15) {
            return null;
        }

        // Find lowest point in recent 10 bars
        $recentBars = array_slice($prices, 0, 10);
        $lowPoint = min(array_map(fn ($bar) => (float) $bar->low, $recentBars));

        $currentPrice = (float) $prices[0]->price;
        $reversalPct = (($currentPrice - $lowPoint) / $lowPoint) * 100;

        if ($reversalPct < $this->config['min_reversal_pct']) {
            return null;
        }

        return [
            'low_point' => $lowPoint,
            'reversal_pct' => $reversalPct,
            'current_price' => $currentPrice,
        ];
    }

    private function calculateVolumeSpike(array $prices): float
    {
        $currentVolume = (int) $prices[0]->volume;
        $avgVolume = $this->getAverageVolume($prices, 15);

        return $avgVolume > 0 ? $currentVolume / $avgVolume : 0;
    }

    private function getAverageVolume(array $prices, int $periods): float
    {
        $volumes = array_slice($prices, 1, $periods);
        if (empty($volumes)) {
            return 0;
        }

        return array_sum(array_map(fn ($bar) => (int) $bar->volume, $volumes)) / count($volumes);
    }

    private function calculateComprehensiveScore(array $declineInfo, array $reversalInfo, float $volumeSpike, float $trendStrength): float
    {
        $score = 0;

        // Decline magnitude (3-10% range optimal)
        $decline = $declineInfo['decline_pct'];
        if ($decline >= 3 && $decline <= 6) {
            $score += $decline * 3;  // Sweet spot
        } else {
            $score += min($decline * 2, 12);
        }

        // Reversal strength (higher is better)
        $score += $reversalInfo['reversal_pct'] * 5;

        // Volume confirmation (diminishing returns above 5x)
        $score += min($volumeSpike * 3, 15);

        // Trend strength bonus
        $score += max($trendStrength, 0) * 1.5;

        return $score;
    }

    private function getQualitySymbols(): array
    {
        return DB::table('asset_info')
            ->where('asset_type', 'stock')
            ->whereNull('deleted_at')
            ->where('1_min', 1)  // Only volatile stocks
            ->where('over_1mil', 1)  // Only liquid stocks
            ->pluck('symbol')
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
