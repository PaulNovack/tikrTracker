<?php

namespace App\Services\Trading;

use Illuminate\Support\Facades\DB;

/**
 * One-Minute Entry Finder for Opening Range Breakout (V1500.0)
 *
 * Finds optimal 1-minute entry points after opening range breakout signals
 *
 * Entry Logic:
 * - ORB_IMMEDIATE: Enter immediately on breakout confirmation
 * - ORB_PULLBACK: Wait for pullback to OR high (now support) then enter
 * - ORB_CONTINUATION: Enter on continued strength after initial breakout
 */
class OneMinuteEntryFinderV1500_0
{
    use HasPriceTables;

    public function findBestLong(
        string $symbol,
        string $assetType,
        string $signalTsEst,
        string $asOfTsEst,
        int $beforeMinutes = 5,
        int $afterMinutes = 15,
        int $volLookback = 20,
        int $pivotLookback = 15,
        string $fillMethod = 'next_open'
    ): ?array {
        // Get the signal bar and opening range context
        $signalBar = DB::selectOne(
            'SELECT price, high, low, open, volume, atr, atr_pct
             FROM five_minute_prices
             WHERE symbol = ? AND asset_type = ? AND ts_est = ?',
            [$symbol, $assetType, $signalTsEst]
        );

        if (! $signalBar) {
            return null;
        }

        $signalHigh = (float) $signalBar->high;
        $signalLow = (float) $signalBar->low;
        $signalClose = (float) $signalBar->price;
        $atr = $signalBar->atr ? (float) $signalBar->atr : null;
        $atrPct = $signalBar->atr_pct ? (float) $signalBar->atr_pct : null;

        // Get opening range for this symbol
        $tradeDate = substr($signalTsEst, 0, 10);
        $openingRange = DB::selectOne(
            "SELECT MAX(high) as or_high, MIN(low) as or_low
             FROM five_minute_prices
             WHERE symbol = ? 
               AND asset_type = ?
               AND trading_date_est = ?
               AND trading_time_est BETWEEN '09:30:00' AND '10:00:00'",
            [$symbol, $assetType, $tradeDate]
        );

        if (! $openingRange || ! $openingRange->or_high) {
            \Log::warning("[V1500 Finder] No opening range found for {$symbol}");

            return null;
        }

        $orHigh = (float) $openingRange->or_high;
        $orLow = (float) $openingRange->or_low;

        // Get 1-minute bars after the signal
        $searchStart = $signalTsEst;
        $searchEnd = $asOfTsEst;

        $bars = $this->dbSelect(
            'SELECT ts_est, price, open, high, low, volume
             FROM one_minute_prices
             WHERE symbol = ? 
               AND asset_type = ?
               AND trading_date_est = ?
               AND ts_est > ?
               AND ts_est <= ?
             ORDER BY ts_est ASC
             LIMIT 20',
            [$symbol, $assetType, $tradeDate, $searchStart, $searchEnd]
        );

        if (empty($bars)) {
            return null;
        }

        // Calculate average volume
        $avgVol = $this->getAvgVolume($symbol, $assetType, $signalTsEst, $volLookback);

        // Look for entry patterns specific to ORB
        $entry = $this->findOrbEntry($bars, $orHigh, $orLow, $signalClose, $avgVol, $atr);

        if (! $entry) {
            return null;
        }

        // Calculate stop loss (below opening range low with ATR buffer)
        $stopPrice = $orLow - ($atr ? $atr * 0.5 : 0.10);
        $riskPerShare = $entry['price'] - $stopPrice;
        $riskPct = ($riskPerShare / $entry['price']) * 100;

        // Target: 2x the opening range height
        $orRange = $orHigh - $orLow;
        $targetPrice = $entry['price'] + ($orRange * 2);
        $rewardPerShare = $targetPrice - $entry['price'];
        $rewardPct = ($rewardPerShare / $entry['price']) * 100;
        $rMultiple = $riskPerShare > 0 ? $rewardPerShare / $riskPerShare : 0;

        // Calculate trailing stop suggestion (2x ATR or 1.5% of price)
        $suggestedTrailingStop = $atr ? $atr * 2.0 : $entry['price'] * 0.015;
        $suggestedTrailingStopPct = ($suggestedTrailingStop / $entry['price']) * 100;

        // Fill method adjustment
        $entryPrice = $entry['price'];
        if ($fillMethod === 'next_open') {
            // Entry price is already set to appropriate level by pattern logic
        }

        $bestEntry = [
            'type' => $entry['type'],
            'entry_ts_est' => $entry['ts_est'],
            'entry_price' => round($entryPrice, 4),
            'entry' => round($entryPrice, 4), // Alias for compatibility
            'stop_price' => round($stopPrice, 4),
            'stop' => round($stopPrice, 4), // Alias
            'target_price' => round($targetPrice, 4),
            'risk_pct' => round($riskPct, 2),
            'reward_pct' => round($rewardPct, 2),
            'r_multiple' => round($rMultiple, 2),
            'risk_per_share' => round($riskPerShare, 4),
            'score' => $entry['score'],
            'vol_ratio' => $entry['vol_ratio'],
            'atr' => $atr,
            'atr_pct' => $atrPct,
            'suggested_trailing_stop' => round($suggestedTrailingStop, 4),
            'suggested_trailing_stop_pct' => round($suggestedTrailingStopPct, 2),
            'or_high' => round($orHigh, 4),
            'or_low' => round($orLow, 4),
            'or_range' => round($orRange, 4),
        ];

        return [
            'ok' => true,
            'best_entry' => $bestEntry,
        ];
    }

    private function findOrbEntry(array $bars, float $orHigh, float $orLow, float $signalClose, float $avgVol, ?float $atr): ?array
    {
        $bestEntry = null;
        $highestScore = 0;

        foreach ($bars as $i => $bar) {
            $barPrice = (float) $bar->price;
            $barHigh = (float) $bar->high;
            $barLow = (float) $bar->low;
            $barOpen = (float) $bar->open;
            $barVol = (float) $bar->volume;
            $volRatio = $avgVol > 0 ? $barVol / $avgVol : 1.0;

            // Pattern 1: Immediate ORB entry on strong volume confirmation
            if ($i === 0 && $volRatio >= 1.8 && $barPrice > $orHigh) {
                $score = 95 + min(5, ($volRatio - 1.5) * 10);
                if ($score > $highestScore) {
                    $bestEntry = [
                        'type' => 'ORB_IMMEDIATE',
                        'ts_est' => $bar->ts_est,
                        'price' => max($orHigh + 0.01, $barOpen),
                        'score' => $score,
                        'vol_ratio' => round($volRatio, 2),
                    ];
                    $highestScore = $score;
                }
            }

            // Pattern 2: Break and retest - pullback to OR high (now support)
            if ($i > 2 && $barLow <= $orHigh * 1.002 && $barPrice > $orHigh && $volRatio >= 1.3) {
                // Check if price recently broke above OR high
                $wasAbove = false;
                for ($j = max(0, $i - 3); $j < $i; $j++) {
                    if ((float) $bars[$j]->price > $orHigh * 1.01) {
                        $wasAbove = true;
                        break;
                    }
                }

                if ($wasAbove) {
                    $score = 90 + ($volRatio >= 2.0 ? 5 : 0);
                    if ($score > $highestScore) {
                        $bestEntry = [
                            'type' => 'ORB_PULLBACK_RETEST',
                            'ts_est' => $bar->ts_est,
                            'price' => $orHigh + 0.02,
                            'score' => $score,
                            'vol_ratio' => round($volRatio, 2),
                        ];
                        $highestScore = $score;
                    }
                }
            }

            // Pattern 3: Continuation after initial breakout (strong follow-through)
            if ($i >= 1) {
                $prevBar = $bars[$i - 1];
                $prevPrice = (float) $prevBar->price;
                $prevHigh = (float) $prevBar->high;

                // Both bars above OR high, current bar making higher high
                if ($prevPrice > $orHigh && $barPrice > $orHigh && $barHigh > $prevHigh && $volRatio >= 1.5) {
                    $score = 85 + ($volRatio >= 2.5 ? 10 : 0);
                    if ($score > $highestScore) {
                        $bestEntry = [
                            'type' => 'ORB_CONTINUATION',
                            'ts_est' => $bar->ts_est,
                            'price' => $barPrice,
                            'score' => $score,
                            'vol_ratio' => round($volRatio, 2),
                        ];
                        $highestScore = $score;
                    }
                }
            }

            // Pattern 4: Gap above OR high with volume
            if ($barOpen > $orHigh * 1.005 && $volRatio >= 2.0) {
                $score = 82;
                if ($score > $highestScore) {
                    $bestEntry = [
                        'type' => 'ORB_GAP_CONTINUATION',
                        'ts_est' => $bar->ts_est,
                        'price' => $barOpen,
                        'score' => $score,
                        'vol_ratio' => round($volRatio, 2),
                    ];
                    $highestScore = $score;
                }
            }

            // Pattern 5: Consolidation above OR high then breakout
            if ($i >= 3) {
                // Check if last 3 bars consolidated above OR high
                $consolidating = true;
                $consolidationLow = $barHigh;
                $consolidationHigh = $barLow;

                for ($j = max(0, $i - 3); $j < $i; $j++) {
                    $checkBar = $bars[$j];
                    $checkLow = (float) $checkBar->low;
                    $checkHigh = (float) $checkBar->high;

                    if ($checkLow < $orHigh) {
                        $consolidating = false;
                        break;
                    }

                    $consolidationLow = min($consolidationLow, $checkLow);
                    $consolidationHigh = max($consolidationHigh, $checkHigh);
                }

                if ($consolidating) {
                    $consolidationRange = ($consolidationHigh - $consolidationLow) / $consolidationLow;
                    // Tight consolidation (< 0.5% range) then breakout
                    if ($consolidationRange < 0.005 && $barPrice > $consolidationHigh && $volRatio >= 1.8) {
                        $score = 88;
                        if ($score > $highestScore) {
                            $bestEntry = [
                                'type' => 'ORB_CONSOLIDATION_BREAK',
                                'ts_est' => $bar->ts_est,
                                'price' => $consolidationHigh + 0.01,
                                'score' => $score,
                                'vol_ratio' => round($volRatio, 2),
                            ];
                            $highestScore = $score;
                        }
                    }
                }
            }
        }

        return $bestEntry;
    }

    private function getAvgVolume(string $symbol, string $assetType, string $asOfTsEst, int $lookback): float
    {
        $result = DB::selectOne(
            'SELECT AVG(volume) as avg_vol
             FROM one_minute_prices
             WHERE symbol = ? 
               AND asset_type = ?
               AND trading_date_est = DATE(?)
               AND ts_est < ?
             ORDER BY ts_est DESC
             LIMIT ?',
            [$symbol, $assetType, $asOfTsEst, $asOfTsEst, $lookback]
        );

        return $result && $result->avg_vol ? (float) $result->avg_vol : 1000.0;
    }
}
