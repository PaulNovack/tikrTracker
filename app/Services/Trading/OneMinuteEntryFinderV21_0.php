<?php

namespace App\Services\Trading;

use Illuminate\Support\Facades\DB;

/**
 * Version 21.0 - Alligator WAKE_UP Pattern Detection
 * Base: Fresh implementation
 * Purpose: Use Bill Williams Alligator indicator to detect WAKE_UP pattern transitions
 *
 * v21.0 features:
 * - Alligator WAKE_UP pattern: Lips > Teeth > Jaw (bullish order)
 * - Pattern: price between Teeth and Lips (WAKE_UP zone)
 * - Confirmation: 3 consecutive WAKE_UP bars with bullish order maintained
 * - Transition detection: Must have been SLEEPING within last 30 minutes
 * - Volume filter: Minimum 5-minute rolling volume of 30,000 shares
 * - Price filter: Minimum price of $1.00
 *
 * Alligator Indicator Components:
 * - Lips (Green): SMMA(5) shifted forward by 3 bars
 * - Teeth (Red): SMMA(8) shifted forward by 5 bars
 * - Jaw (Blue): SMMA(13) shifted forward by 8 bars
 *
 * WAKE_UP State: Lips > Teeth > Jaw AND Teeth <= Price <= Lips
 * SLEEPING State: abs(Lips - Teeth) / Price < 0.0015 AND abs(Teeth - Jaw) / Price < 0.0015
 */
class OneMinuteEntryFinderV21_0
{
    use HasPriceTables;

    private string $version = 'v21.0';

    public function getVersion(): string
    {
        return $this->version;
    }

    /**
     * Find 1-minute entry opportunities for signals from the 5-minute scanner
     *
     * @param  array  $signals  Signals from FiveMinuteSignalScannerV21_0
     * @param  string  $assetType  'stock' or 'crypto'
     * @param  string  $asOfTsEst  EST timestamp
     * @return array Entry opportunities with Alligator WAKE_UP confirmation
     */
    public function findEntries(array $signals, string $assetType, string $asOfTsEst): array
    {
        if (empty($signals)) {
            return [];
        }

        $entries = [];

        foreach ($signals as $signal) {
            $symbol = $signal['symbol'];

            // Get Alligator WAKE_UP confirmation for this symbol
            $alligatorEntry = $this->checkAlligatorWakeUp($symbol, $assetType, $asOfTsEst);

            if ($alligatorEntry !== null) {
                $entries[] = array_merge($signal, $alligatorEntry, [
                    'signal_ts_est' => $asOfTsEst,
                    'entry_type' => 'ALLIGATOR_WAKE_UP',
                    'version' => $this->version,
                ]);
            }
        }

        return $entries;
    }

    /**
     * Check for Alligator WAKE_UP pattern on 1-minute timeframe
     *
     * Returns entry data if:
     * - 3 consecutive WAKE_UP bars detected
     * - Bullish order maintained: Lips > Teeth > Jaw
     * - Symbol was SLEEPING within last 30 minutes (transition detection)
     * - Meets minimum volume (30K shares in last 5 minutes)
     * - Meets minimum price ($1.00)
     *
     * @param  string  $symbol  Stock symbol
     * @param  string  $assetType  'stock' or 'crypto'
     * @param  string  $asOfTsEst  Current EST timestamp
     * @return array|null Entry data or null if no WAKE_UP pattern
     */
    private function checkAlligatorWakeUp(string $symbol, string $assetType, string $asOfTsEst): ?array
    {
        $minPrice = 1.0;
        $minVol5 = 30000;
        $sleepLookbackMinutes = 30;
        $confirmBars = 3;
        $sleepThreshold = 0.0015;

        // Get last 100 1-minute bars for calculation (need history for SMMA and shifts)
        $bars = DB::table($this->oneMinuteTable)
            ->where('symbol', $symbol)
            ->where('asset_type', $assetType)
            ->where('ts_est', '<=', $asOfTsEst)
            ->orderBy('ts_est', 'desc')
            ->limit(100)
            ->get(['ts_est', 'price', 'volume'])
            ->reverse()
            ->values()
            ->toArray();

        if (count($bars) < 50) {
            return null; // Not enough history
        }

        // Calculate Alligator lines and detect WAKE_UP pattern
        $alligatorStates = $this->calculateAlligatorStates($bars, $sleepThreshold);

        if (empty($alligatorStates)) {
            return null;
        }

        // Get last 3 states for confirmation
        $recentStates = array_slice($alligatorStates, -$confirmBars);

        if (count($recentStates) < $confirmBars) {
            return null;
        }

        // Check if all 3 recent bars are WAKE_UP with bullish order
        $allWakeUp = true;
        foreach ($recentStates as $state) {
            if ($state['state'] !== 'WAKE_UP' || ! $state['bullish_order']) {
                $allWakeUp = false;
                break;
            }
        }

        if (! $allWakeUp) {
            return null;
        }

        $lastState = end($recentStates);
        $currentPrice = (float) $lastState['price'];

        // Check minimum price
        if ($currentPrice < $minPrice) {
            return null;
        }

        // Check 5-minute rolling volume
        $last5Bars = array_slice($bars, -5);
        $vol5 = array_sum(array_column($last5Bars, 'volume'));

        if ($vol5 < $minVol5) {
            return null;
        }

        // Check for SLEEPING state within lookback window (transition detection)
        $sleepFound = false;
        $lookbackStates = array_slice($alligatorStates, -$sleepLookbackMinutes);

        foreach ($lookbackStates as $state) {
            if ($state['state'] === 'SLEEPING') {
                $sleepFound = true;
                break;
            }
        }

        if (! $sleepFound) {
            return null; // No transition from SLEEPING - already trending
        }

        // WAKE_UP pattern confirmed!
        // Calculate ATR for trailing stop
        $atr = $this->calculateATR($bars, 14);
        $atrPct = $currentPrice > 0 ? ($atr / $currentPrice) * 100.0 : 0.0;
        $suggestedTrailingStop = $atr * 3.0;
        $suggestedTrailingStopPct = $currentPrice > 0 ? ($suggestedTrailingStop / $currentPrice) * 100.0 : 0.0;

        return [
            'entry' => $currentPrice,
            'entry_ts_est' => $asOfTsEst,
            'type' => 'ALLIGATOR_WAKE_UP',
            'stop' => $lastState['jaw'], // Use Jaw as initial stop loss
            'risk_pct' => (($currentPrice - $lastState['jaw']) / $currentPrice) * 100,
            'score' => 0.85, // High score for Alligator WAKE_UP
            'vol_ratio' => $vol5 / ($minVol5 > 0 ? $minVol5 : 1),
            'atr' => round($atr, 6),
            'atr_pct' => round($atrPct, 2),
            'suggested_trailing_stop' => round($suggestedTrailingStop, 6),
            'suggested_trailing_stop_pct' => round($suggestedTrailingStopPct, 2),
            'lips' => $lastState['lips'],
            'teeth' => $lastState['teeth'],
            'jaw' => $lastState['jaw'],
            'alligator_state' => 'WAKE_UP',
            'alligator_consecutive' => $confirmBars,
            'vol_5min' => $vol5,
            'notional_5min' => $vol5 * $currentPrice,
        ];
    }

    /**
     * Calculate Alligator indicator states for all bars
     *
     * @param  array  $bars  Array of OHLCV bars
     * @param  float  $sleepThreshold  Threshold for SLEEPING state detection
     * @return array Array of states with Alligator lines and classification
     */
    private function calculateAlligatorStates(array $bars, float $sleepThreshold): array
    {
        $states = [];
        $smma5 = null;
        $smma8 = null;
        $smma13 = null;
        $seed5 = [];
        $seed8 = [];
        $seed13 = [];

        // Store raw SMMA values with timestamps for shifting
        $raw5 = [];
        $raw8 = [];
        $raw13 = [];

        $prevLips = null;
        $prevTeeth = null;

        foreach ($bars as $i => $bar) {
            $ts = $bar->ts_est;
            $price = (float) $bar->price;

            // Calculate SMMA(5)
            if ($smma5 === null) {
                $seed5[] = $price;
                if (count($seed5) === 5) {
                    $smma5 = array_sum($seed5) / 5.0;
                }
            } else {
                $smma5 = (($smma5 * 4.0) + $price) / 5.0;
            }

            // Calculate SMMA(8)
            if ($smma8 === null) {
                $seed8[] = $price;
                if (count($seed8) === 8) {
                    $smma8 = array_sum($seed8) / 8.0;
                }
            } else {
                $smma8 = (($smma8 * 7.0) + $price) / 8.0;
            }

            // Calculate SMMA(13)
            if ($smma13 === null) {
                $seed13[] = $price;
                if (count($seed13) === 13) {
                    $smma13 = array_sum($seed13) / 13.0;
                }
            } else {
                $smma13 = (($smma13 * 12.0) + $price) / 13.0;
            }

            // Store raw values
            if ($smma5 !== null) {
                $raw5[$ts] = $smma5;
            }
            if ($smma8 !== null) {
                $raw8[$ts] = $smma8;
            }
            if ($smma13 !== null) {
                $raw13[$ts] = $smma13;
            }

            // Apply shifts to get Alligator lines
            // Lips: SMMA(5) shifted +3
            // Teeth: SMMA(8) shifted +5
            // Jaw: SMMA(13) shifted +8
            $lips = null;
            $teeth = null;
            $jaw = null;

            if ($i >= 3 && isset($bars[$i - 3])) {
                $tMinus3 = $bars[$i - 3]->ts_est;
                $lips = $raw5[$tMinus3] ?? null;
            }

            if ($i >= 5 && isset($bars[$i - 5])) {
                $tMinus5 = $bars[$i - 5]->ts_est;
                $teeth = $raw8[$tMinus5] ?? null;
            }

            if ($i >= 8 && isset($bars[$i - 8])) {
                $tMinus8 = $bars[$i - 8]->ts_est;
                $jaw = $raw13[$tMinus8] ?? null;
            }

            // Classify Alligator state
            $state = 'UNDEFINED';
            $bullishOrder = false;

            if ($lips !== null && $teeth !== null && $jaw !== null) {
                // Check for SLEEPING state
                if (abs($lips - $teeth) / $price < $sleepThreshold &&
                    abs($teeth - $jaw) / $price < $sleepThreshold) {
                    $state = 'SLEEPING';
                }
                // Check for bullish order
                elseif ($lips > $teeth && $teeth > $jaw) {
                    $bullishOrder = true;

                    // EATING: Price above Lips
                    if ($price > $lips) {
                        $state = 'EATING';
                    }
                    // WAKE_UP: Price between Teeth and Lips
                    elseif ($price >= $teeth && $price <= $lips) {
                        $state = 'WAKE_UP';
                    }
                }
                // Check for SATED state (bearish transition)
                elseif ($prevLips !== null && $prevTeeth !== null &&
                        $lips < $teeth && $prevLips >= $prevTeeth) {
                    $state = 'SATED';
                }
            }

            $states[] = [
                'ts' => $ts,
                'price' => $price,
                'lips' => $lips,
                'teeth' => $teeth,
                'jaw' => $jaw,
                'state' => $state,
                'bullish_order' => $bullishOrder,
            ];

            $prevLips = $lips;
            $prevTeeth = $teeth;
        }

        return $states;
    }

    /**
     * Calculate Average True Range (ATR) for volatility-based stops
     *
     * @param  array  $bars  Array of bar objects with price data
     * @param  int  $period  ATR period (default 14)
     * @return float ATR value
     */
    private function calculateATR(array $bars, int $period = 14): float
    {
        if (count($bars) < $period + 1) {
            return 0.0;
        }

        $trueRanges = [];

        // Calculate True Range for each bar
        // TR = max(high-low, |high-prevClose|, |low-prevClose|)
        // Since we only have price (close), approximate with price movement
        for ($i = 1; $i < count($bars); $i++) {
            $current = (float) $bars[$i]->price;
            $previous = (float) $bars[$i - 1]->price;

            // Approximate TR using close-to-close movement
            $tr = abs($current - $previous);
            $trueRanges[] = $tr;
        }

        // Calculate ATR as simple moving average of TR
        $atrSum = 0.0;
        $count = min($period, count($trueRanges));

        for ($i = count($trueRanges) - $count; $i < count($trueRanges); $i++) {
            $atrSum += $trueRanges[$i];
        }

        return $count > 0 ? $atrSum / $count : 0.0;
    }
}
