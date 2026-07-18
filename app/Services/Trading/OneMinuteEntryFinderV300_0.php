<?php

namespace App\Services\Trading;

use Illuminate\Support\Facades\DB;

/**
 * OneMinuteEntryFinderV300_0
 *
 * Takes 5-minute reversal/reclaim signals and finds precise 1-minute entries.
 * Looks for confirmation of the reversal pattern on the 1m chart.
 */
class OneMinuteEntryFinderV300_0
{
    use HasPriceTables;

    private string $version = 'v300.0';

    /** @var array Query result cache for 1m bars */
    private array $oneMinCache = [];

    /** @var array Query result cache for 5m bars */
    private array $fiveMinCache = [];

    public function getVersion(): string
    {
        return $this->version;
    }

    /**
     * Find best long entry for a reversal/reclaim signal
     *
     * @param  string  $symbol  Stock symbol
     * @param  string  $assetType  Asset type (stock, crypto, etc)
     * @param  string  $signalTsEst  5-minute signal timestamp
     * @param  string  $asOfTsEst  Current timestamp to analyze as of
     * @param  int  $beforeMinutes  Minutes before asOf to start analysis
     * @param  int  $afterMinutes  Minutes after signal for entry window
     * @param  int  $volLookback  Volume lookback period
     * @param  int  $pivotLookback  Pivot lookback (not used in v300)
     * @param  string  $fillModel  Fill model (next_open|close)
     * @param  array  $signal  Full 5m signal data
     * @param  int  $staleMins  Maximum staleness allowed
     * @return array Entry analysis result
     */
    public function findBestLong(
        string $symbol,
        string $assetType,
        string $signalTsEst,
        string $asOfTsEst,
        int $beforeMinutes = 15,
        int $afterMinutes = 30,
        int $volLookback = 20,
        int $pivotLookback = 15,
        string $fillModel = 'next_open',
        array $signal = [],
        int $staleMins = 999
    ): array {
        $lookback1mBars = (int) config('trading.v300.lookback_1m_bars', 20);
        $maxRiskPct = (float) config('trading.v300.max_risk_pct', 0.60);
        $minScore = (float) config('trading.v300.min_score_1m', 60);
        $maxScore = (float) config('trading.v300.max_score_1m', 999);  // Upper bound filter
        $longsOnly = (bool) config('trading.v300.longs_only', false);

        $side = $signal['side'] ?? 'LONG';
        $keyLevel = $signal['meta']['key_level'] ?? null;

        // Reject shorts if longs_only is enabled
        if ($longsOnly && $side !== 'LONG') {
            return [
                'ok' => false,
                'reason' => 'shorts_disabled',
                'filtered_best' => null,
            ];
        }

        if (! $keyLevel) {
            return [
                'ok' => false,
                'reason' => 'no_key_level',
                'filtered_best' => null,
            ];
        }

        // Fetch recent 1m bars
        $bars1 = $this->getOneMinBars($symbol, $asOfTsEst, $lookback1mBars);

        if (count($bars1) < 8) {
            return [
                'ok' => false,
                'reason' => 'insufficient_1m_data',
                'filtered_best' => null,
            ];
        }

        // Detect entry trigger on 1m chart
        if ($side === 'LONG') {
            $entryData = $this->findBullishEntry($bars1, $keyLevel);
        } else {
            $entryData = $this->findBearishEntry($bars1, $keyLevel);
        }

        if (! $entryData) {
            return [
                'ok' => false,
                'reason' => 'no_entry_trigger',
                'filtered_best' => [
                    'type' => $side === 'LONG' ? 'BUY' : 'SELL_SHORT',
                ],
            ];
        }

        // Calculate risk metrics
        $entryPrice = (float) $entryData['entry'];
        $stopPrice = (float) $entryData['stop'];

        if ($entryPrice <= 0 || $stopPrice <= 0) {
            return [
                'ok' => false,
                'reason' => 'invalid_prices',
                'filtered_best' => null,
            ];
        }

        $riskPerShare = abs($entryPrice - $stopPrice);
        $riskPct = ($riskPerShare / $entryPrice) * 100.0;

        if ($riskPct > $maxRiskPct) {
            return [
                'ok' => false,
                'reason' => 'risk_too_wide',
                'filtered_best' => [
                    'type' => $side === 'LONG' ? 'BUY' : 'SELL_SHORT',
                    'risk_pct' => $riskPct,
                ],
            ];
        }

        // Score the entry quality
        $entryScore = $this->scoreEntry($entryData, $bars1, $signal, $riskPct);

        if ($entryScore < $minScore) {
            return [
                'ok' => false,
                'reason' => 'score_too_low',
                'filtered_best' => [
                    'type' => $side === 'LONG' ? 'BUY' : 'SELL_SHORT',
                    'score' => $entryScore,
                ],
            ];
        }

        if ($entryScore > $maxScore) {
            return [
                'ok' => false,
                'reason' => 'score_too_high',
                'filtered_best' => [
                    'type' => $side === 'LONG' ? 'BUY' : 'SELL_SHORT',
                    'score' => $entryScore,
                ],
            ];
        }

        // Calculate additional metrics
        $last1 = $bars1[count($bars1) - 1];
        $last5Bar = $this->getLatest5mBar($symbol, $asOfTsEst);

        // Calculate RSI from price data
        $prices = array_map(fn ($b) => (float) ($b->price ?? 0), $bars1);
        $rsiValues = $this->calculateRSI($prices, 14);
        $currentRsi = end($rsiValues) ?? 50.0;

        // RSI filter for optimal entries (35-50 for longs had 45%+ win rate)
        $minRsi = (float) config('trading.v300.min_rsi', 0);
        $maxRsi = (float) config('trading.v300.max_rsi', 100);

        if ($currentRsi < $minRsi || $currentRsi > $maxRsi) {
            return [
                'ok' => false,
                'reason' => 'rsi_out_of_range',
                'filtered_best' => [
                    'type' => $side === 'LONG' ? 'BUY' : 'SELL_SHORT',
                    'rsi' => $currentRsi,
                ],
            ];
        }

        $atr = (float) ($last5Bar->atr ?? $signal['meta']['atr'] ?? 0);
        $atrPct = (float) ($last5Bar->atr_pct ?? $signal['meta']['atr_pct'] ?? 0);
        $volRatio = (float) ($signal['meta']['vol_ratio'] ?? 0);

        // Generate targets
        $targets = $this->generateTargets($entryPrice, $stopPrice, $side);

        return [
            'ok' => true,
            'best_entry' => [
                'type' => $side === 'LONG' ? 'BUY' : 'SELL_SHORT',
                'entry_ts_est' => $last1->ts_est ?? $asOfTsEst,
                'entry_price' => $entryPrice,
                'stop_price' => $stopPrice,
                'risk_pct' => $riskPct,
                'risk_amount' => $riskPerShare,
                'score' => $entryScore,
                'vol_ratio' => $volRatio,
                'five_min_directional_changes' => $entryData['directional_changes'] ?? null,
                'five_min_green_bar_pct' => $entryData['green_bar_pct'] ?? null,
                'five_min_net_progress' => $entryData['net_progress'] ?? null,
                'consolidation_bars' => $entryData['consolidation_bars'] ?? null,
                'breakout_volume_ratio' => $entryData['breakout_volume_ratio'] ?? null,
                'atr' => $atr,
                'atr_pct' => $atrPct,
                'rsi' => $currentRsi,
                'suggested_trailing_stop' => $this->calculateTrailingStop($entryPrice, $side, $atr),
                'suggested_trailing_stop_pct' => $this->calculateTrailingStopPct($entryPrice, $atr),
                'targets' => $targets,
            ],
        ];
    }

    /**
     * Find bullish entry: reclaim of support level
     */
    private function findBullishEntry(array $bars1, float $keyLevel): ?array
    {
        $n = count($bars1);
        if ($n < 3) {
            return null;
        }

        $last = $bars1[$n - 1];
        $prev = $bars1[$n - 2];

        // Check if price recently dipped below key level (flush) - check ALL bars
        $minLow = $this->minLow($bars1, $n);
        $flushed = $minLow < $keyLevel;

        // Check if currently above key level (reclaimed in last 3 bars)
        $lastClose = (float) ($last->price ?? 0);
        $prevClose = (float) ($prev->price ?? 0);
        $isAbove = $lastClose > $keyLevel;

        // More lenient: just need to be above now after having been below
        if ($flushed && $isAbove) {
            // Calculate consolidation bars before breakout
            $consolidationBars = $this->countConsolidationBars($bars1, 10);

            // Calculate breakout volume ratio
            $breakoutVolRatio = $this->calculateBreakoutVolume($bars1, 5);

            // Check for bullish momentum
            $hasUpMomentum = $lastClose > $prevClose;

            return [
                'entry' => $lastClose,
                'stop' => min((float) $last->low, $minLow) * 0.999, // Slightly below flush low
                'reason' => $hasUpMomentum ? 'reclaim_with_momentum' : 'reclaim_basic',
                'consolidation_bars' => $consolidationBars,
                'breakout_volume_ratio' => $breakoutVolRatio,
                'directional_changes' => $this->countDirectionChanges($bars1, 10),
                'green_bar_pct' => $this->calculateGreenBarPct($bars1, 10),
                'net_progress' => $this->calculateNetProgress($bars1, 10),
            ];
        }

        return null;
    }

    /**
     * Find bearish entry: rejection at resistance level
     */
    private function findBearishEntry(array $bars1, float $keyLevel): ?array
    {
        $n = count($bars1);
        if ($n < 3) {
            return null;
        }

        $last = $bars1[$n - 1];
        $prev = $bars1[$n - 2];

        // Check if price recently poked above key level - check ALL bars
        $maxHigh = $this->maxHigh($bars1, $n);
        $poked = $maxHigh > $keyLevel;

        // Check if currently below key level (rejected in last 3 bars)
        $lastClose = (float) ($last->price ?? 0);
        $prevClose = (float) ($prev->price ?? 0);
        $isBelow = $lastClose < $keyLevel;

        // More lenient: just need to be below now after having been above
        if ($poked && $isBelow) {
            // Calculate consolidation bars before breakdown
            $consolidationBars = $this->countConsolidationBars($bars1, 10);

            // Calculate breakdown volume ratio
            $breakoutVolRatio = $this->calculateBreakoutVolume($bars1, 5);

            // Check for bearish momentum
            $hasDownMomentum = $lastClose < $prevClose;

            return [
                'entry' => $lastClose,
                'stop' => max((float) $last->high, $maxHigh) * 1.001, // Slightly above poke high
                'reason' => $hasDownMomentum ? 'reject_with_momentum' : 'reject_basic',
                'consolidation_bars' => $consolidationBars,
                'breakout_volume_ratio' => $breakoutVolRatio,
                'directional_changes' => $this->countDirectionChanges($bars1, 10),
                'green_bar_pct' => $this->calculateGreenBarPct($bars1, 10),
                'net_progress' => $this->calculateNetProgress($bars1, 10),
            ];
        }

        return null;
    }

    /**
     * Score the entry quality
     */
    private function scoreEntry(array $entryData, array $bars1, array $signal, float $riskPct): float
    {
        $score = 50.0;
        $side = $signal['side'] ?? 'LONG';

        // Calculate RSI from 1m bar prices
        $prices = array_map(fn ($b) => (float) ($b->price ?? 0), $bars1);
        $rsiValues = $this->calculateRSI($prices, 14);
        $currentRsi = end($rsiValues) ?? 50.0;

        // RSI favorability for direction
        if ($side === 'LONG') {
            // For longs, prefer RSI < 50 (oversold conditions)
            if ($currentRsi < 30) {
                $score += 15;
            } elseif ($currentRsi < 40) {
                $score += 10;
            } elseif ($currentRsi < 50) {
                $score += 5;
            } elseif ($currentRsi > 70) {
                $score -= 10; // Penalize overbought
            }
        } else {
            // For shorts, prefer RSI > 50 (overbought conditions)
            if ($currentRsi > 70) {
                $score += 15;
            } elseif ($currentRsi > 60) {
                $score += 10;
            } elseif ($currentRsi > 50) {
                $score += 5;
            } elseif ($currentRsi < 30) {
                $score -= 10; // Penalize oversold
            }
        }

        // Risk tightness bonus
        if ($riskPct <= 0.25) {
            $score += 15;
        } elseif ($riskPct <= 0.35) {
            $score += 10;
        } elseif ($riskPct >= 0.55) {
            $score -= 10;
        }

        // Volume confirmation
        $breakoutVolRatio = $entryData['breakout_volume_ratio'] ?? 1.0;
        if ($breakoutVolRatio >= 1.3) {
            $score += 10;
        }
        if ($breakoutVolRatio >= 1.5) {
            $score += 5;
        }

        // Structure quality
        if (str_contains($entryData['reason'] ?? '', 'with_structure')) {
            $score += 10;
        }

        // Consolidation quality (tight consolidation before breakout is good)
        $consolidationBars = $entryData['consolidation_bars'] ?? 0;
        if ($consolidationBars >= 3 && $consolidationBars <= 8) {
            $score += 8;
        }

        // Add 5m signal score contribution
        $signalScore = $signal['score'] ?? 50;
        $score += ($signalScore - 50) * 0.3; // 30% weight from 5m signal

        return max(0, min(100, $score));
    }

    /**
     * Calculate RSI (Relative Strength Index) from price array
     */
    private function calculateRSI(array $closes, int $period = 14): array
    {
        $count = count($closes);
        $rsi = [];

        if ($count < $period + 1) {
            // Not enough data
            return array_fill(0, $count, null);
        }

        // Calculate initial average gain and loss
        $gains = [];
        $losses = [];

        for ($i = 1; $i < $count; $i++) {
            $change = $closes[$i] - $closes[$i - 1];
            $gains[] = max(0, $change);
            $losses[] = max(0, -$change);
        }

        // First RSI value
        $avgGain = array_sum(array_slice($gains, 0, $period)) / $period;
        $avgLoss = array_sum(array_slice($losses, 0, $period)) / $period;

        // Fill initial nulls
        for ($i = 0; $i < $period; $i++) {
            $rsi[] = null;
        }

        // Calculate first RSI
        if ($avgLoss == 0) {
            $rsi[] = 100;
        } else {
            $rs = $avgGain / $avgLoss;
            $rsi[] = 100 - (100 / (1 + $rs));
        }

        // Calculate subsequent RSI values using smoothed moving average
        for ($i = $period + 1; $i < $count; $i++) {
            $avgGain = (($avgGain * ($period - 1)) + $gains[$i - 1]) / $period;
            $avgLoss = (($avgLoss * ($period - 1)) + $losses[$i - 1]) / $period;

            if ($avgLoss == 0) {
                $rsi[] = 100;
            } else {
                $rs = $avgGain / $avgLoss;
                $rsi[] = 100 - (100 / (1 + $rs));
            }
        }

        return $rsi;
    }

    /**
     * Count consolidation bars (low volatility bars)
     */
    private function countConsolidationBars(array $bars, int $lookback): int
    {
        $slice = array_slice($bars, max(0, count($bars) - $lookback));
        $consolidation = 0;

        foreach ($slice as $bar) {
            $range = (float) ($bar->high ?? 0) - (float) ($bar->low ?? 0);
            $close = (float) ($bar->price ?? 0);

            if ($close > 0 && $range / $close <= 0.003) {
                $consolidation++;
            }
        }

        return $consolidation;
    }

    /**
     * Calculate breakout volume ratio
     */
    private function calculateBreakoutVolume(array $bars, int $lookback): float
    {
        if (count($bars) < 2) {
            return 1.0;
        }

        $slice = array_slice($bars, max(0, count($bars) - $lookback - 1), $lookback);
        $last = $bars[count($bars) - 1];

        if (count($slice) === 0) {
            return 1.0;
        }

        $avgVolume = 0;
        foreach ($slice as $bar) {
            $avgVolume += (float) ($bar->volume ?? 0);
        }
        $avgVolume /= count($slice);

        $lastVolume = (float) ($last->volume ?? 0);

        return $avgVolume > 0 ? $lastVolume / $avgVolume : 1.0;
    }

    /**
     * Count directional changes in recent bars
     */
    private function countDirectionChanges(array $bars, int $n): int
    {
        $slice = array_slice($bars, max(0, count($bars) - $n));
        $changes = 0;
        $prevDir = 0;

        for ($i = 1; $i < count($slice); $i++) {
            $d = (float) ($slice[$i]->price ?? 0) - (float) ($slice[$i - 1]->price ?? 0);
            $dir = $d > 0 ? 1 : ($d < 0 ? -1 : 0);

            if ($dir !== 0 && $prevDir !== 0 && $dir !== $prevDir) {
                $changes++;
            }

            if ($dir !== 0) {
                $prevDir = $dir;
            }
        }

        return $changes;
    }

    /**
     * Calculate percentage of green bars
     */
    private function calculateGreenBarPct(array $bars, int $n): float
    {
        $slice = array_slice($bars, max(0, count($bars) - $n));
        $greens = 0;

        foreach ($slice as $b) {
            if ((float) ($b->price ?? 0) > (float) ($b->open ?? 0)) {
                $greens++;
            }
        }

        return count($slice) ? ($greens / count($slice)) * 100.0 : 0.0;
    }

    /**
     * Calculate net progress (first close to last close)
     */
    private function calculateNetProgress(array $bars, int $n): float
    {
        $slice = array_slice($bars, max(0, count($bars) - $n));

        if (count($slice) < 2) {
            return 0.0;
        }

        return (float) ($slice[count($slice) - 1]->price ?? 0) - (float) ($slice[0]->price ?? 0);
    }

    /**
     * Generate R-multiple targets
     */
    private function generateTargets(float $entry, float $stop, string $side): array
    {
        $r = abs($entry - $stop);

        if ($r <= 0) {
            return [];
        }

        if ($side === 'LONG') {
            return [
                '1R' => $entry + (1.0 * $r),
                '2R' => $entry + (2.0 * $r),
                '3R' => $entry + (3.0 * $r),
            ];
        }

        return [
            '1R' => $entry - (1.0 * $r),
            '2R' => $entry - (2.0 * $r),
            '3R' => $entry - (3.0 * $r),
        ];
    }

    /**
     * Calculate trailing stop price
     */
    private function calculateTrailingStop(float $entry, string $side, float $atr): ?float
    {
        if ($atr <= 0) {
            return null;
        }

        $mult = (float) config('trading.v300.trailing_atr_mult', 2.0);
        $dist = $atr * $mult;

        return $side === 'LONG' ? ($entry - $dist) : ($entry + $dist);
    }

    /**
     * Calculate trailing stop percentage
     */
    private function calculateTrailingStopPct(float $entry, float $atr): ?float
    {
        if ($entry <= 0 || $atr <= 0) {
            return null;
        }

        $mult = (float) config('trading.v300.trailing_atr_mult', 2.0);

        return (($atr * $mult) / $entry) * 100.0;
    }

    /**
     * Fetch 1-minute bars (with caching)
     */
    private function getOneMinBars(string $symbol, string $asOfTsEst, int $limit): array
    {
        $cacheKey = "{$symbol}:{$asOfTsEst}:{$limit}";

        if (isset($this->oneMinCache[$cacheKey])) {
            return $this->oneMinCache[$cacheKey];
        }

        $result = DB::table($this->oneMinuteTable)
            ->select(['ts_est', 'price', 'open', 'high', 'low', 'volume'])
            ->where('symbol', $symbol)
            ->where('ts_est', '<=', $asOfTsEst)
            ->orderByDesc('ts_est')
            ->limit($limit)
            ->get()
            ->reverse()
            ->values()
            ->all();

        $this->oneMinCache[$cacheKey] = $result;

        // Prevent memory bloat - keep only last 100 queries
        if (count($this->oneMinCache) > 100) {
            array_shift($this->oneMinCache);
        }

        return $result;
    }

    /**
     * Get latest 5m bar for ATR data (with caching)
     */
    private function getLatest5mBar(string $symbol, string $asOfTsEst): ?object
    {
        $cacheKey = "{$symbol}:{$asOfTsEst}";

        if (isset($this->fiveMinCache[$cacheKey])) {
            return $this->fiveMinCache[$cacheKey];
        }

        $result = DB::table($this->fiveMinuteTable)
            ->where('symbol', $symbol)
            ->where('ts_est', '<=', $asOfTsEst)
            ->orderByDesc('ts_est')
            ->first();

        $this->fiveMinCache[$cacheKey] = $result;

        // Prevent memory bloat
        if (count($this->fiveMinCache) > 100) {
            array_shift($this->fiveMinCache);
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
}
