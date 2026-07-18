<?php

namespace App\Services\Trading;

use Illuminate\Support\Facades\DB;

/**
 * One-Minute Entry Finder for Market Movers Momentum (V1200.0)
 *
 * Finds optimal 1-minute entry points for two-bar momentum signals
 *
 * Entry Logic:
 * - MOMENTUM_CONTINUATION: Enter on first pullback or consolidation
 * - BREAKOUT_HIGH: Enter on break of 5-minute bar high
 * - IMMEDIATE: Enter immediately if strong volume spike
 */
class OneMinuteEntryFinderV1200_0
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
        // Get the signal bar to establish context
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

        // Get 1-minute bars after the signal
        $searchStart = $signalTsEst;
        $searchEnd = $asOfTsEst;

        $bars = $this->dbSelect(
            'SELECT ts_est, price, open, high, low, volume
             FROM one_minute_prices
             WHERE symbol = ? 
               AND asset_type = ?
               AND trading_date_est = DATE(?)
               AND ts_est > ?
               AND ts_est <= ?
             ORDER BY ts_est ASC
             LIMIT 20',
            [$symbol, $assetType, $signalTsEst, $searchStart, $searchEnd]
        );

        if (empty($bars)) {
            return null;
        }

        // Calculate average volume
        $avgVol = $this->getAvgVolume($symbol, $assetType, $signalTsEst, $volLookback);

        // Look for entry patterns
        $entry = $this->findMomentumEntry($bars, $signalHigh, $signalLow, $signalClose, $avgVol, $atr);

        if (! $entry) {
            return null;
        }

        // Calculate stop loss (below signal bar low or recent swing low)
        $stopPrice = max($signalLow - ($atr ?? 0.10), $entry['price'] * 0.98);
        $riskPerShare = $entry['price'] - $stopPrice;
        $riskPct = ($riskPerShare / $entry['price']) * 100;

        // Calculate trailing stop suggestion
        $suggestedTrailingStop = $atr ? $atr * 2.5 : $entry['price'] * 0.015;
        $suggestedTrailingStopPct = ($suggestedTrailingStop / $entry['price']) * 100;

        $bestEntry = [
            'type' => $entry['type'],
            'entry_ts_est' => $entry['ts_est'],
            'entry' => $entry['price'],
            'stop' => $stopPrice,
            'risk_pct' => round($riskPct, 2),
            'risk_per_share' => round($riskPerShare, 4),
            'score' => $entry['score'],
            'vol_ratio' => $entry['vol_ratio'],
            'atr' => $atr,
            'atr_pct' => $atrPct,
            'suggested_trailing_stop' => round($suggestedTrailingStop, 4),
            'suggested_trailing_stop_pct' => round($suggestedTrailingStopPct, 2),
        ];

        return [
            'ok' => true,
            'best_entry' => $bestEntry,
        ];
    }

    private function findMomentumEntry(array $bars, float $signalHigh, float $signalLow, float $signalClose, float $avgVol, ?float $atr): ?array
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

            // Pattern 1: Immediate entry on volume spike (first bar after signal)
            if ($i === 0 && $volRatio >= 2.0 && $barPrice > $signalClose) {
                $score = 90 + min(10, $volRatio);
                if ($score > $highestScore) {
                    $bestEntry = [
                        'type' => 'IMMEDIATE_MOMENTUM',
                        'ts_est' => $bar->ts_est,
                        'price' => $barOpen > $signalClose ? $barOpen : $barPrice,
                        'score' => $score,
                        'vol_ratio' => round($volRatio, 2),
                    ];
                    $highestScore = $score;
                }
            }

            // Pattern 2: Breakout above signal high
            if ($barHigh > $signalHigh && $barPrice > $signalHigh) {
                $score = 85 + ($volRatio >= 1.5 ? 10 : 0);
                if ($score > $highestScore) {
                    $bestEntry = [
                        'type' => 'BREAKOUT_HIGH',
                        'ts_est' => $bar->ts_est,
                        'price' => $signalHigh + 0.01,
                        'score' => $score,
                        'vol_ratio' => round($volRatio, 2),
                    ];
                    $highestScore = $score;
                }
            }

            // Pattern 3: Pullback entry (dip below signal close but above signal low)
            if ($i > 0 && $barLow < $signalClose && $barPrice > $signalLow) {
                $pullbackDepth = ($signalClose - $barLow) / $signalClose;
                if ($pullbackDepth <= 0.005 && $volRatio >= 1.2) { // Small pullback with volume
                    $score = 75 + ($volRatio >= 2.0 ? 10 : 0);
                    if ($score > $highestScore) {
                        $bestEntry = [
                            'type' => 'MOMENTUM_CONTINUATION',
                            'ts_est' => $bar->ts_est,
                            'price' => $barPrice,
                            'score' => $score,
                            'vol_ratio' => round($volRatio, 2),
                        ];
                        $highestScore = $score;
                    }
                }
            }

            // Pattern 4: Consolidation then continuation
            if ($i >= 2) {
                $prevBar = $bars[$i - 1];
                $prevPrice = (float) $prevBar->price;
                $prevHigh = (float) $prevBar->high;
                $prevLow = (float) $prevBar->low;

                // Tight range previous bar, then breakout
                $prevRange = ($prevHigh - $prevLow) / $prevLow;
                if ($prevRange <= 0.003 && $barPrice > $prevHigh && $volRatio >= 1.5) {
                    $score = 80;
                    if ($score > $highestScore) {
                        $bestEntry = [
                            'type' => 'CONSOLIDATION_BREAK',
                            'ts_est' => $bar->ts_est,
                            'price' => $prevHigh + 0.01,
                            'score' => $score,
                            'vol_ratio' => round($volRatio, 2),
                        ];
                        $highestScore = $score;
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
