<?php

namespace App\Services\Trading;

use Illuminate\Support\Facades\DB;

/**
 * Pipeline M Version 1.0 - Entry Finder (TradeThatSwing Consolidation Breakouts)
 *
 * Purpose: Find consolidation breakout entry triggers on momentum movers
 * Entry Criteria (from TradeThatSwing):
 * - Consolidation: 3+ bars sideways with diminishing range
 * - Breakout: Price breaks above/below consolidation high/low
 * - Volume confirmation: Breakout bar has higher volume
 * - Stop placement: Opposite side of consolidation + 2x ATR buffer
 *
 * Philosophy: Wait for price action consolidation, then enter on breakout with defined risk.
 * Avoids chasing - requires pullback/pause before entry trigger fires.
 */
class OneMinuteEntryFinderV1_0
{
    use HasPriceTables;

    private string $version = 'v1.0';

    private float $maxRiskPct = 2.0; // Max 2% risk per trade (TradeThatSwing uses 2x ATR, typically < 2%)

    public function getVersion(): string
    {
        return $this->version;
    }

    /**
     * Find best entry on a momentum mover using consolidation breakout logic
     *
     * @param  string  $symbol  Symbol to analyze
     * @param  string  $assetType  stock|crypto
     * @param  string  $signalTsEst  When the 5m momentum signal was detected
     * @param  string  $asOfTsEst  Current time to search for entry
     * @param  int  $beforeMinutes  Minutes before asOfTsEst to look for entry (live mode)
     * @param  int  $afterMinutes  Minutes after signal (deprecated, uses beforeMinutes)
     * @param  int  $volLookback  Minutes for volume baseline calculation
     * @param  int  $consolidationMinBars  Minimum bars required for consolidation (default 2)
     * @param  string  $fillType  Entry fill method (next_open|close)
     * @param  int  $staleMinutes  Ignore entries older than N minutes (live mode)
     * @param  float|null  $volRatio  Volume ratio from signal (relative volume)
     * @return array Result with 'ok' and 'best_entry' keys
     */
    public function findBestLong(
        string $symbol,
        string $assetType,
        string $signalTsEst,
        string $asOfTsEst,
        int $beforeMinutes = 10,
        int $afterMinutes = 20,
        int $volLookback = 20,
        int $consolidationMinBars = 2,
        string $fillType = 'next_open',
        int $staleMinutes = 5,
        ?float $volRatio = null
    ): array {
        $tradingDate = substr($asOfTsEst, 0, 10);

        // Get 1-minute bars from signal time to asOfTsEst
        $bars = $this->getBars($symbol, $assetType, $tradingDate, $signalTsEst, $asOfTsEst);

        if (count($bars) < $consolidationMinBars) {
            return ['ok' => false, 'reason' => 'Insufficient bars for consolidation'];
        }

        // Get ATR for stop calculation
        $atr = $this->getATR($symbol, $assetType, $tradingDate);

        // Detect consolidation patterns
        $consolidations = $this->detectConsolidations($bars, $consolidationMinBars);

        if (empty($consolidations)) {
            return ['ok' => false, 'reason' => 'No consolidation pattern detected'];
        }

        // Check for breakouts from consolidations
        $breakouts = $this->detectBreakouts($bars, $consolidations, $atr);

        if (empty($breakouts)) {
            return ['ok' => false, 'reason' => 'No breakout detected'];
        }

        // Get best (most recent) breakout within time window
        $bestBreakout = $this->selectBestBreakout($breakouts, $asOfTsEst, $beforeMinutes, $staleMinutes);

        if (! $bestBreakout) {
            return ['ok' => false, 'reason' => 'No valid breakout in time window'];
        }

        // Calculate entry, stop, and risk
        $entry = $this->calculateEntry($bestBreakout, $fillType, $bars, $volRatio);

        return [
            'ok' => true,
            'best_entry' => $entry,
            'consolidation_pattern' => $bestBreakout['consolidation'],
            'breakout_bar' => $bestBreakout,
        ];
    }

    /**
     * Get 1-minute bars for analysis
     */
    private function getBars(string $symbol, string $assetType, string $tradingDate, string $startTs, string $endTs): array
    {
        return DB::table($this->oneMinuteTable)
            ->where('symbol', $symbol)
            ->where('asset_type', $assetType)
            ->where('trading_date_est', $tradingDate)
            ->where('ts_est', '>=', $startTs)
            ->where('ts_est', '<=', $endTs)
            ->orderBy('ts_est')
            ->get()
            ->map(fn ($row) => (array) $row)
            ->toArray();
    }

    /**
     * Get average ATR for stop calculation
     */
    private function getATR(string $symbol, string $assetType, string $tradingDate): ?float
    {
        return DB::table('daily_prices')
            ->where('symbol', $symbol)
            ->where('asset_type', $assetType)
            ->where('date', '>=', DB::raw("DATE_SUB('$tradingDate', INTERVAL 14 DAY)"))
            ->where('date', '<', $tradingDate)
            ->avg(DB::raw('high - low'));
    }

    /**
     * Detect consolidation patterns (3+ bars with diminishing range)
     *
     * TradeThatSwing criteria:
     * - Minimum 3 bars sideways (can be more)
     * - Range should be tightening (each bar's range <= previous)
     * - Total consolidation range should be < 1.5% of price
     */
    private function detectConsolidations(array $bars, int $minBars): array
    {
        $consolidations = [];
        $n = count($bars);

        for ($i = 0; $i <= $n - $minBars; $i++) {
            // Try to build consolidation starting at bar $i
            $consoBars = [];
            $high = 0;
            $low = PHP_FLOAT_MAX;
            $rangePct = 0; // Initialize rangePct

            for ($j = $i; $j < $n; $j++) {
                $bar = $bars[$j];
                $barHigh = max($bar['price'], $bar['open']);
                $barLow = min($bar['price'], $bar['open']);

                $high = max($high, $barHigh);
                $low = min($low, $barLow);

                $consoBars[] = $bar;

                // Check if we have minimum bars
                if (count($consoBars) >= $minBars) {
                    $midPrice = ($high + $low) / 2;
                    $rangePct = ($midPrice > 0) ? (($high - $low) / $midPrice) * 100 : 0;

                    // Consolidation criteria: range < 2.0% (tighter for quality)
                    if ($rangePct < 2.0) {
                        $consolidations[] = [
                            'start_idx' => $i,
                            'end_idx' => $j,
                            'bars' => count($consoBars),
                            'high' => $high,
                            'low' => $low,
                            'range_pct' => $rangePct,
                            'last_bar_ts' => $bar['ts_est'],
                        ];
                    }
                }

                // Stop extending if range gets too wide
                if ($rangePct >= 2.0) {
                    break;
                }
            }
        }

        // Return unique consolidations (avoid overlapping patterns)
        return $this->filterOverlappingConsolidations($consolidations);
    }

    /**
     * Filter out overlapping consolidations, keeping the best ones
     */
    private function filterOverlappingConsolidations(array $consolidations): array
    {
        if (empty($consolidations)) {
            return [];
        }

        // Sort by tightest range first, then by most bars
        usort($consolidations, function ($a, $b) {
            if (abs($a['range_pct'] - $b['range_pct']) < 0.1) {
                return $b['bars'] <=> $a['bars'];
            }

            return $a['range_pct'] <=> $b['range_pct'];
        });

        $filtered = [];
        foreach ($consolidations as $conso) {
            $overlaps = false;
            foreach ($filtered as $existing) {
                // Check if they overlap
                if ($conso['start_idx'] <= $existing['end_idx'] && $conso['end_idx'] >= $existing['start_idx']) {
                    $overlaps = true;
                    break;
                }
            }
            if (! $overlaps) {
                $filtered[] = $conso;
            }
        }

        return $filtered;
    }

    /**
     * Detect breakouts from consolidation patterns
     *
     * Breakout criteria:
     * - Price closes above consolidation high (long) OR below consolidation low (short)
     * - Breakout bar volume > previous bar volume (confirmation)
     * - Occurs within reasonable time after consolidation ends
     */
    private function detectBreakouts(array $bars, array $consolidations, ?float $atr): array
    {
        $breakouts = [];

        foreach ($consolidations as $conso) {
            // Look for breakout starting from consolidation end (inclusive)
            // This allows breakout on the last consolidation bar
            $startIdx = $conso['end_idx'];

            for ($i = $startIdx; $i < min($startIdx + 10, count($bars)); $i++) {
                $bar = $bars[$i];
                $prevBar = $bars[$i - 1] ?? null;

                if (! $prevBar) {
                    continue;
                }

                $direction = null;

                // Long breakout: close above consolidation high
                if ($bar['price'] > $conso['high']) {
                    $direction = 'long';
                }
                // Short breakout: close below consolidation low
                elseif ($bar['price'] < $conso['low']) {
                    $direction = 'short';
                }

                if ($direction) {
                    // Volume confirmation: breakout bar volume > 1.2x previous bar
                    $volConfirmed = $bar['volume'] > ($prevBar['volume'] * 1.2);

                    // Calculate stop loss using consolidation range (tighter for intraday)
                    // Stop just below/above consolidation with small buffer
                    $rangeBuffer = ($conso['high'] - $conso['low']) * 0.2; // 20% buffer

                    if ($direction === 'long') {
                        $entry = $bar['price'];
                        $stop = $conso['low'] - $rangeBuffer;
                    } else {
                        $entry = $bar['price'];
                        $stop = $conso['high'] + $rangeBuffer;
                    }

                    $riskPerShare = abs($entry - $stop);
                    $riskPct = ($entry > 0) ? ($riskPerShare / $entry) * 100 : 0;

                    // Skip if risk > 3% (tighter for quality)
                    if ($riskPct > 3.0) {
                        continue;
                    }

                    $breakouts[] = [
                        'consolidation' => $conso,
                        'breakout_idx' => $i,
                        'breakout_ts' => $bar['ts_est'],
                        'direction' => $direction,
                        'entry_price' => $entry,
                        'stop_price' => $stop,
                        'risk_pct' => $riskPct,
                        'risk_per_share' => $riskPerShare,
                        'volume_confirmed' => $volConfirmed,
                        'bar' => $bar,
                        'type' => 'CONSOLIDATION_BREAKOUT_'.strtoupper($direction),
                        'score' => $this->scoreBreakout($conso, $bar, $volConfirmed, $riskPct),
                    ];

                    // Only take first breakout from each consolidation
                    break;
                }
            }
        }

        return $breakouts;
    }

    /**
     * Score breakout quality (0-100)
     */
    private function scoreBreakout(array $conso, array $bar, bool $volConfirmed, float $riskPct): float
    {
        $score = 50; // Base score

        // Consolidation quality (0-25 points)
        // Tighter consolidation = higher score
        if ($conso['range_pct'] < 0.5) {
            $score += 25;
        } elseif ($conso['range_pct'] < 1.0) {
            $score += 15;
        } else {
            $score += 5;
        }

        // More bars = better (0-15 points)
        $score += min(15, $conso['bars'] * 3);

        // Volume confirmation (0-10 points)
        if ($volConfirmed) {
            $score += 10;
        }

        // Lower risk = better (0-10 points)
        $riskScore = max(0, 10 - ($riskPct * 5));
        $score += $riskScore;

        return round(min(100, $score), 2);
    }

    /**
     * Select best breakout within time window
     */
    private function selectBestBreakout(array $breakouts, string $asOfTsEst, int $beforeMinutes, int $staleMinutes): ?array
    {
        $asOfEpoch = strtotime($asOfTsEst);
        $earliestEpoch = $asOfEpoch - ($beforeMinutes * 60);
        $staleEpoch = $asOfEpoch - ($staleMinutes * 60);

        $validBreakouts = array_filter($breakouts, function ($b) use ($staleEpoch, $asOfEpoch) {
            $bEpoch = strtotime($b['breakout_ts']);

            return $bEpoch >= $staleEpoch && $bEpoch <= $asOfEpoch;
        });

        if (empty($validBreakouts)) {
            return null;
        }

        // Sort by score descending, then by most recent
        usort($validBreakouts, function ($a, $b) {
            if (abs($a['score'] - $b['score']) < 1) {
                return strtotime($b['breakout_ts']) <=> strtotime($a['breakout_ts']);
            }

            return $b['score'] <=> $a['score'];
        });

        return $validBreakouts[0];
    }

    /**
     * Calculate final entry details based on fill type
     */
    private function calculateEntry(array $breakout, string $fillType, array $bars, ?float $volRatio = null): array
    {
        $entry = $breakout['entry_price'];
        $stop = $breakout['stop_price'];

        // If using next_open, get next bar's open price
        if ($fillType === 'next_open') {
            $nextBarIdx = $breakout['breakout_idx'] + 1;
            if (isset($bars[$nextBarIdx])) {
                $entry = $bars[$nextBarIdx]['open'];
                // Recalculate risk with new entry
                $riskPerShare = abs($entry - $stop);
                $riskPct = ($entry > 0) ? ($riskPerShare / $entry) * 100 : 0;
            }
        }

        // Calculate ATR as the risk per share (consolidation-based stop distance)
        $atr = abs($entry - $stop);
        $atrPct = ($entry > 0) ? ($atr / $entry) * 100 : 0;

        // Calculate R-multiple targets (1R, 2R, 3R)
        $riskAmount = abs($entry - $stop);
        $targets = [
            '1R' => round($entry + ($riskAmount * 1), 6),
            '2R' => round($entry + ($riskAmount * 2), 6),
            '3R' => round($entry + ($riskAmount * 3), 6),
        ];

        return [
            'type' => $breakout['type'],
            'entry_ts_est' => $breakout['breakout_ts'],
            'entry' => $entry,
            'stop' => $stop,
            'risk_pct' => $breakout['risk_pct'],
            'risk_per_share' => $breakout['risk_per_share'],
            'score' => $breakout['score'],
            'volume_confirmed' => $breakout['volume_confirmed'],
            'consolidation_bars' => $breakout['consolidation']['bars'],
            'consolidation_range_pct' => $breakout['consolidation']['range_pct'],
            'atr' => $atr,
            'atr_pct' => $atrPct,
            'suggested_trailing_stop' => $stop,
            'suggested_trailing_stop_pct' => $atrPct,
            'vol_ratio' => $volRatio, // From signal scanner
            'targets' => $targets, // R-multiple targets for ML model
        ];
    }
}
