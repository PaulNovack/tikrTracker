<?php

namespace App\Services\Trading;

use Illuminate\Support\Facades\DB;

/**
 * FiveMinuteSignalScannerV300_0
 *
 * Reversal/Reclaim Strategy at 5-minute timeframe:
 *  - Bullish: Failed breakdown -> reclaim of support level
 *  - Bearish: Failed breakout -> rejection at resistance level
 *
 * Identifies potential reversal signals based on key 5m levels and recent price action.
 */
class FiveMinuteSignalScannerV300_0
{
    use HasPriceTables;

    private string $version = 'v300.0';

    private string $name = 'Reversal Reclaim';

    /** @var array Query result cache */
    private array $barsCache = [];

    public function getVersion(): string
    {
        return $this->version;
    }

    public function getName(): string
    {
        return $this->name;
    }

    /**
     * Main scanner entry point - compatible with pipeline interface
     *
     * @param  string  $assetType  Asset type to scan (stock, crypto, etc)
     * @param  string  $asOfTsEst  Timestamp to scan as of (EST)
     * @param  int  $lookbackMinutes  Not used (uses lookback_bars config)
     * @param  float  $minMovePct  Not used (uses reversal logic)
     * @param  float  $volMult  Not used (uses min_vol_ratio config)
     * @param  int  $limit  Maximum results to return
     * @return array Array of reversal candidates with scores
     */
    public function scan(
        string $assetType,
        string $asOfTsEst,
        int $lookbackMinutes = 15,
        float $minMovePct = 0.2,
        float $volMult = 2.00,
        int $limit = 60
    ): array {
        $signals = [];

        // Configuration
        $lookback5mBars = (int) config('trading.v300.lookback_5m_bars', 24); // 2 hours
        $minAtrPct = (float) config('trading.v300.min_atr_pct', 0.15);
        $maxAtrPct = (float) config('trading.v300.max_atr_pct', 2.50);
        $minVolRatio = (float) config('trading.v300.min_vol_ratio', 1.20);
        $minScore = (float) config('trading.v300.min_score_5m', 50);
        $minDollarVol = (float) config('trading.v300.min_dollar_vol', 100000);

        // Get candidate symbols
        $symbols = $this->getCandidateSymbols($assetType, $asOfTsEst, $minDollarVol);

        foreach ($symbols as $symbol) {
            $signal = $this->evaluateSymbol($symbol, $asOfTsEst, [
                'lookback_bars' => $lookback5mBars,
                'min_atr_pct' => $minAtrPct,
                'max_atr_pct' => $maxAtrPct,
                'min_vol_ratio' => $minVolRatio,
                'min_score' => $minScore,
            ]);

            if ($signal) {
                $signals[] = $signal;
            }
        }

        // Sort by score descending and limit results
        usort($signals, fn ($a, $b) => $b['score'] <=> $a['score']);

        return array_slice($signals, 0, $limit);
    }

    /**
     * Get candidate symbols with sufficient liquidity
     */
    private function getCandidateSymbols(string $assetType, string $asOfTsEst, float $minDollarVol): array
    {
        // Get all active symbols with recent data
        // Liquidity filtering happens during evaluation
        return DB::table($this->fiveMinuteTable.' as fmp')
            ->join('asset_info as a', function ($join) {
                $join->on('fmp.symbol', '=', 'a.symbol')
                    ->on('fmp.asset_type', '=', 'a.asset_type');
            })
            ->select('fmp.symbol')
            ->where('fmp.asset_type', $assetType)
            ->where('fmp.ts_est', '<=', $asOfTsEst)
            ->whereRaw('fmp.ts_est >= DATE_SUB(?, INTERVAL 2 HOUR)', [$asOfTsEst])
            ->whereNull('a.deleted_at')
            ->groupBy('fmp.symbol')
            ->pluck('symbol')
            ->toArray();
    }

    /**
     * Evaluate a single symbol for reversal/reclaim setup on 5m timeframe
     */
    private function evaluateSymbol(string $symbol, string $asOfTsEst, array $config): ?array
    {
        // Fetch recent 5m bars
        $bars5 = $this->getFiveMinBars($symbol, $asOfTsEst, $config['lookback_bars']);

        if (count($bars5) < 10) {
            return null;
        }

        $last5 = $bars5[count($bars5) - 1];

        // Basic gating: ATR%
        $atrPct = (float) ($last5->atr_pct ?? 0);
        if ($atrPct < $config['min_atr_pct'] || $atrPct > $config['max_atr_pct']) {
            return null;
        }

        // Calculate volume ratio manually (current volume vs average)
        $volRatio = $this->calculateVolumeRatio($bars5, 10);
        if ($volRatio < $config['min_vol_ratio']) {
            return null;
        }

        // Define obvious support/resistance levels using 5m structure
        $support = $this->calculateSupport($bars5, 12); // Last ~1 hour
        $resistance = $this->calculateResistance($bars5, 12);

        // Check for reversal setup patterns
        $bullishSetup = $this->detectBullishSetup($bars5, $support);
        $bearishSetup = $this->detectBearishSetup($bars5, $resistance);

        if (! $bullishSetup && ! $bearishSetup) {
            return null;
        }

        // Pick the best setup
        $candidates = [];
        if ($bullishSetup) {
            $bullishSetup['score'] = $this->scoreSetup($bullishSetup, $bars5, $atrPct, $volRatio);
            $candidates[] = $bullishSetup;
        }
        if ($bearishSetup) {
            $bearishSetup['score'] = $this->scoreSetup($bearishSetup, $bars5, $atrPct, $volRatio);
            $candidates[] = $bearishSetup;
        }

        $best = null;
        foreach ($candidates as $cand) {
            if (! $best || $cand['score'] > $best['score']) {
                $best = $cand;
            }
        }

        if (! $best || $best['score'] < $config['min_score']) {
            return null;
        }

        // Return signal with metadata for 1m entry finder
        return [
            'symbol' => $symbol,
            'asset_type' => 'stock',
            'signal_type' => $best['signal_type'],
            'signal_ts_est' => $last5->ts_est ?? $asOfTsEst,
            'side' => $best['side'], // LONG or SHORT
            'score' => $best['score'],
            'meta' => [
                'support' => $support,
                'resistance' => $resistance,
                'key_level' => $best['key_level'],
                'atr_pct' => $atrPct,
                'vol_ratio' => $volRatio,
                'trend_bias' => $this->calculate5mTrendBias($bars5, 12),
                'setup_type' => $best['setup_type'],
                'reason' => $best['reason'] ?? null,
            ],
        ];
    }

    /**
     * Detect bullish reversal setup: failed breakdown -> reclaim potential
     */
    private function detectBullishSetup(array $bars5, float $support): ?array
    {
        $n = count($bars5);
        $last = $bars5[$n - 1];
        $prev = $bars5[$n - 2];

        // Check if price recently tested or broke below support
        $recentLow = $this->minLow($bars5, 6); // Last 30 minutes
        $hasTestedSupport = $recentLow <= $support * 1.01;

        if (! $hasTestedSupport) {
            return null;
        }

        // Check if currently showing strength above support
        $lastClose = (float) ($last->price ?? 0);
        $isAboveSupport = $lastClose > $support;
        $showingStrength = (float) ($last->price ?? 0) > (float) ($prev->price ?? 0);

        if ($isAboveSupport && $showingStrength) {
            return [
                'side' => 'LONG',
                'signal_type' => 'REVERSAL_RECLAIM_BULL',
                'setup_type' => 'failed_breakdown',
                'key_level' => $support,
                'reason' => 'support_test_reclaim',
            ];
        }

        return null;
    }

    /**
     * Detect bearish reversal setup: failed breakout -> rejection potential
     */
    private function detectBearishSetup(array $bars5, float $resistance): ?array
    {
        $n = count($bars5);
        $last = $bars5[$n - 1];
        $prev = $bars5[$n - 2];

        // Check if price recently tested or broke above resistance
        $recentHigh = $this->maxHigh($bars5, 6); // Last 30 minutes
        $hasTestedResistance = $recentHigh >= $resistance * 0.99;

        if (! $hasTestedResistance) {
            return null;
        }

        // Check if currently showing weakness below resistance
        $lastClose = (float) ($last->price ?? 0);
        $isBelowResistance = $lastClose < $resistance;
        $showingWeakness = (float) ($last->price ?? 0) < (float) ($prev->price ?? 0);

        if ($isBelowResistance && $showingWeakness) {
            return [
                'side' => 'SHORT',
                'signal_type' => 'REVERSAL_REJECT_BEAR',
                'setup_type' => 'failed_breakout',
                'key_level' => $resistance,
                'reason' => 'resistance_test_reject',
            ];
        }

        return null;
    }

    /**
     * Score the setup quality on 5m timeframe
     */
    private function scoreSetup(array $setup, array $bars5, float $atrPct, float $volRatio): float
    {
        $score = 50.0;

        // Volatility sweet spot
        if ($atrPct >= 0.25 && $atrPct <= 1.50) {
            $score += 15;
        }

        // Volume confirmation
        if ($volRatio >= 1.5) {
            $score += 12;
        }
        if ($volRatio >= 2.0) {
            $score += 8;
        }

        // Trend alignment on 5m
        $trendBias = $this->calculate5mTrendBias($bars5, 12);
        if ($setup['side'] === 'LONG' && $trendBias >= 0) {
            $score += 10;
        }
        if ($setup['side'] === 'SHORT' && $trendBias <= 0) {
            $score += 10;
        }

        // Recent volatility stability
        $volatilityScore = $this->assessVolatilityQuality($bars5, 8);
        $score += $volatilityScore * 5;

        return max(0, min(100, $score));
    }

    /**
     * Calculate support level from recent 5m lows
     */
    private function calculateSupport(array $bars5, int $lookback): float
    {
        return $this->minLow($bars5, $lookback);
    }

    /**
     * Calculate resistance level from recent 5m highs
     */
    private function calculateResistance(array $bars5, int $lookback): float
    {
        return $this->maxHigh($bars5, $lookback);
    }

    /**
     * Calculate trend bias: +1 bullish, -1 bearish, 0 neutral
     */
    private function calculate5mTrendBias(array $bars5, int $n): int
    {
        $slice = array_slice($bars5, max(0, count($bars5) - $n));
        if (count($slice) < 2) {
            return 0;
        }

        $first = (float) ($slice[0]->price ?? 0);
        $last = (float) ($slice[count($slice) - 1]->price ?? 0);

        if ($last > $first * 1.005) {
            return 1;
        }
        if ($last < $first * 0.995) {
            return -1;
        }

        return 0;
    }

    /**
     * Assess volatility quality: returns 0-1 score
     */
    private function assessVolatilityQuality(array $bars5, int $n): float
    {
        $slice = array_slice($bars5, max(0, count($bars5) - $n));
        if (count($slice) < 3) {
            return 0.5;
        }

        // Check for consistent ATR (not spiking wildly)
        $atrValues = [];
        foreach ($slice as $bar) {
            if (isset($bar->atr_pct)) {
                $atrValues[] = (float) $bar->atr_pct;
            }
        }

        if (count($atrValues) < 3) {
            return 0.5;
        }

        $avg = array_sum($atrValues) / count($atrValues);
        $variance = 0;
        foreach ($atrValues as $val) {
            $variance += pow($val - $avg, 2);
        }
        $stdDev = sqrt($variance / count($atrValues));

        // Lower std dev = more stable = better
        $coefficientOfVariation = $avg > 0 ? $stdDev / $avg : 1.0;

        return max(0, min(1, 1.0 - $coefficientOfVariation));
    }

    /**
     * Fetch 5-minute bars up to timestamp (with caching)
     */
    private function getFiveMinBars(string $symbol, string $asOfTsEst, int $limit): array
    {
        $cacheKey = "{$symbol}:{$asOfTsEst}:{$limit}";

        if (isset($this->barsCache[$cacheKey])) {
            return $this->barsCache[$cacheKey];
        }

        $result = DB::table($this->fiveMinuteTable)
            ->select(['ts_est', 'price', 'open', 'high', 'low', 'volume', 'atr', 'atr_pct'])
            ->where('symbol', $symbol)
            ->where('ts_est', '<=', $asOfTsEst)
            ->orderByDesc('ts_est')
            ->limit($limit)
            ->get()
            ->reverse()
            ->values()
            ->all();

        $this->barsCache[$cacheKey] = $result;

        // Prevent memory bloat - keep only last 200 queries
        if (count($this->barsCache) > 200) {
            array_shift($this->barsCache);
        }

        return $result;
    }

    /**
     * Find minimum low in recent bars
     */
    private function minLow(array $bars, int $n): float
    {
        $slice = array_slice($bars, max(0, count($bars) - $n));
        $min = INF;

        foreach ($slice as $b) {
            $min = min($min, (float) $b->low);
        }

        return $min === INF ? 0.0 : $min;
    }

    /**
     * Find maximum high in recent bars
     */
    private function maxHigh(array $bars, int $n): float
    {
        $slice = array_slice($bars, max(0, count($bars) - $n));
        $max = -INF;

        foreach ($slice as $b) {
            $max = max($max, (float) $b->high);
        }

        return $max === -INF ? 0.0 : $max;
    }

    /**
     * Calculate volume ratio: current volume vs average volume
     */
    private function calculateVolumeRatio(array $bars, int $lookback): float
    {
        if (count($bars) < 2) {
            return 1.0;
        }

        $last = $bars[count($bars) - 1];
        $currentVol = (float) ($last->volume ?? 0);

        if ($currentVol <= 0) {
            return 0.0;
        }

        // Calculate average volume over lookback period (excluding current bar)
        $slice = array_slice($bars, max(0, count($bars) - $lookback - 1), $lookback);
        $totalVol = 0;
        $count = 0;

        foreach ($slice as $bar) {
            $vol = (float) ($bar->volume ?? 0);
            if ($vol > 0) {
                $totalVol += $vol;
                $count++;
            }
        }

        if ($count === 0 || $totalVol === 0) {
            return 1.0;
        }

        $avgVol = $totalVol / $count;

        return $currentVol / $avgVol;
    }
}
