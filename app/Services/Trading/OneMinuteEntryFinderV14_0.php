<?php

namespace App\Services\Trading;

/**
 * Version 14.0 - Entry Finder (Copy of V13.0 MAXIMUM PICKS MODE)
 * Identical to V13.0 for baseline comparison and future modifications.
 * V13.0 features:
 * - Added RSI calculation (14-period)
 * - Added Stochastic Oscillator calculation (14, 3)
 * - New pattern: EMA_CROSS_BULL - detects 9 EMA crossing above 21 EMA
 * - CRITICAL: Fixed analysis window for live trading (now relative to current time)
 * - FILTERS DRAMATICALLY RELAXED to generate 15+ picks per day:
 *   * Recency: STRICT 6 minutes max (fresh entries only)
 *   * Volume: 1.0x minimum (was 3.2x)
 *   * Score minimums: REMOVED
 *   * Risk limits: REMOVED
 *   * Pattern filters: REMOVED (RSI, Stochastic, resistance break all removed)
 *   * Strategy: Generate volume of FRESH picks, then filter winners by performance
 */
class OneMinuteEntryFinderV14_0
{
    use HasPriceTables;

    private string $version = 'v14.0';

    public function getVersion(): string
    {
        return $this->version;
    }

    /**
     * v12.0: Power Hour time weighting
     * Apply time-based score multipliers based on intraday volatility patterns:
     * - 9:30-11:30am: Prime time (1.0x - baseline)
     * - 11:00am-2:00pm: Lunch chop (0.8x - reduce scores by 20%)
     * - 3:00-4:00pm: Power hour (1.2x - boost scores by 20%)
     * - Other times: Normal (1.0x)
     */
    private function getTimeMultiplier(string $tsEst): float
    {
        $hour = (int) substr($tsEst, 11, 2);
        $minute = (int) substr($tsEst, 14, 2);
        $timeDecimal = $hour + ($minute / 60.0);

        // Power Hour: 3pm-4pm (15:00-16:00)
        if ($timeDecimal >= 15.0 && $timeDecimal < 16.0) {
            return 1.2; // Boost 20%
        }

        // Lunch Chop: 11am-2pm (11:00-14:00)
        if ($timeDecimal >= 11.0 && $timeDecimal < 14.0) {
            return 0.8; // Reduce 20%
        }

        // Prime Morning & Late Afternoon: Normal
        return 1.0;
    }

    /**
     * Calculate RSI (Relative Strength Index) for momentum measurement.
     * RSI = 100 - (100 / (1 + RS)) where RS = Average Gain / Average Loss
     *
     * @param  array  $closes  Array of closing prices
     * @param  int  $period  RSI period (typically 14)
     * @return array RSI values (null for first N periods)
     */
    private function calculateRSI(array $closes, int $period = 14): array
    {
        $n = count($closes);
        $rsi = array_fill(0, $n, null);

        if ($n <= $period) {
            return $rsi;
        }

        // Calculate initial average gain/loss over first N periods
        $gain = 0.0;
        $loss = 0.0;
        for ($i = 1; $i <= $period; $i++) {
            $change = $closes[$i] - $closes[$i - 1];
            if ($change >= 0) {
                $gain += $change;
            } else {
                $loss += abs($change);
            }
        }
        $avgGain = $gain / $period;
        $avgLoss = $loss / $period;

        $rs = ($avgLoss > 0) ? ($avgGain / $avgLoss) : 999999.0;
        $rsi[$period] = 100.0 - (100.0 / (1.0 + $rs));

        // Use smoothed moving average for subsequent periods
        for ($i = $period + 1; $i < $n; $i++) {
            $change = $closes[$i] - $closes[$i - 1];
            $g = ($change > 0) ? $change : 0.0;
            $l = ($change < 0) ? abs($change) : 0.0;

            $avgGain = (($avgGain * ($period - 1)) + $g) / $period;
            $avgLoss = (($avgLoss * ($period - 1)) + $l) / $period;

            $rs = ($avgLoss > 0) ? ($avgGain / $avgLoss) : 999999.0;
            $rsi[$i] = 100.0 - (100.0 / (1.0 + $rs));
        }

        return $rsi;
    }

    /**
     * Calculate Average True Range (ATR) for volatility measurement.
     * ATR is the average of True Range over N periods.
     * True Range = max of (High-Low, |High-PrevClose|, |Low-PrevClose|)
     *
     * @param  array  $bars  Array of OHLCV bars sorted chronologically
     * @param  int  $period  Number of periods for ATR (typically 14)
     * @return float ATR value
     */
    private function calculateATR(array $bars, int $period = 14): float
    {
        if (count($bars) < $period + 1) {
            return 0.0;
        }

        $trueRanges = [];
        for ($i = 1; $i < count($bars); $i++) {
            $high = (float) $bars[$i]['high'];
            $low = (float) $bars[$i]['low'];
            $prevClose = (float) $bars[$i - 1]['close'];

            $tr = max(
                $high - $low,
                abs($high - $prevClose),
                abs($low - $prevClose)
            );

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

    /**
     * Calculate Stochastic Oscillator (%K and %D lines).
     * %K = (Current Close - Lowest Low) / (Highest High - Lowest Low) * 100
     * %D = 3-period SMA of %K
     *
     * @param  array  $highs  Array of high prices
     * @param  array  $lows  Array of low prices
     * @param  array  $closes  Array of closing prices
     * @param  int  $kPeriod  Period for %K calculation (typically 14)
     * @param  int  $dPeriod  Period for %D smoothing (typically 3)
     * @return array ['k' => [...], 'd' => [...]] with null for first N periods
     */
    private function calculateStochastic(array $highs, array $lows, array $closes, int $kPeriod = 14, int $dPeriod = 3): array
    {
        $n = count($closes);
        $k = array_fill(0, $n, null);
        $d = array_fill(0, $n, null);

        if ($n < $kPeriod) {
            return ['k' => $k, 'd' => $d];
        }

        // Calculate %K for each period
        for ($i = $kPeriod - 1; $i < $n; $i++) {
            // Find highest high and lowest low in the period
            $highestHigh = $highs[$i];
            $lowestLow = $lows[$i];

            for ($j = $i - ($kPeriod - 1); $j <= $i; $j++) {
                if ($highs[$j] > $highestHigh) {
                    $highestHigh = $highs[$j];
                }
                if ($lows[$j] < $lowestLow) {
                    $lowestLow = $lows[$j];
                }
            }

            // Calculate %K
            $range = $highestHigh - $lowestLow;
            if ($range > 0) {
                $k[$i] = (($closes[$i] - $lowestLow) / $range) * 100.0;
            } else {
                $k[$i] = 50.0; // Neutral if no range
            }
        }

        // Calculate %D (3-period SMA of %K)
        for ($i = $kPeriod - 1 + $dPeriod - 1; $i < $n; $i++) {
            $sum = 0.0;
            $count = 0;
            for ($j = $i - ($dPeriod - 1); $j <= $i; $j++) {
                if ($k[$j] !== null) {
                    $sum += $k[$j];
                    $count++;
                }
            }
            if ($count > 0) {
                $d[$i] = $sum / $count;
            }
        }

        return ['k' => $k, 'd' => $d];
    }

    /**
     * Find best LONG entry after a given signal timestamp (EST) for a symbol.
     * V12.7 changes:
     * - Universe expanded to include top 25 losers from previous trading day (bounce opportunities)
     * - Expected: More picks on quiet days, potential for bounce plays from oversold stocks
     * V12.6 changes:
     * - Universe expanded: 200 → 300 stocks (5-day lookback), signal limit 40 → 60
     * - MA_SQUEEZE volume filter tightened: 10x → 6x (based on v12.5 backtest showing losers >6x)
     * - Expected: More picks on low-volume days while maintaining quality
     * V12.5 changes:
     * - Keep only profitable patterns: MA_SQUEEZE (55.6% WR), VWAP_RECLAIM (56.3% WR),
     *   EMA9/EMA21_BOUNCE (100% WR), BULL_FLAG_BREAK (100% WR)
     * - DISABLED: VOL_SHELF (36.1% WR, -0.10% avg P&L) and BREAKOUT_RETEST (38.5% WR, -0.27% avg P&L)
     * - Expected: ~56% WR, 0.65% avg P&L, ~28 trades (vs v12.4: 50.6% WR, 0.21% avg P&L, 77 trades)
     * V12.4 adds:
     * - Pattern-specific volume and score filters based on v12.3 backtest data
     * - ATR calculation (14-period on 1m bars) and 2.5x ATR trailing stop suggestion
     * V12.2 adds:
     * - BREAKOUT_RETEST: Retest of recent breakout as support (now disabled in v12.5)
     * - GREEN_AFTER_RED: First green after 3+ reds with volume (disabled in v12.5)
     * V12.1 adds:
     * - MA_SQUEEZE: 9 EMA crosses above 21 EMA with volume (kept in v12.5)
     * - VOL_SHELF: Consolidation on elevated volume then breakout (disabled in v12.5)
     * V12.0 adds:
     * - Power Hour time weighting (boost 3-4pm, reduce 11am-2pm)
     * - Relative strength awareness (meta from scanner)
     * Returns:
     *  ['ok'=>bool,'best_entry'=>..., 'candidates'=>...]
     *  best_entry includes: atr, atr_pct, suggested_trailing_stop, suggested_trailing_stop_pct
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
        string $fillModel = 'next_open', // next_open|close
        int $freshnessMinutes = 6 // Maximum age for entries to be considered fresh
    ): array {
        // v13.0: LIVE MODE FIX - Analysis window relative to NOW, not signal time
        // For live trading, we only care about RECENT entries (last 6 minutes from now)
        // The signal time tells us WHEN momentum was detected, but entries must be fresh
        $analysisEnd = $asOfTsEst; // Now
        $analysisStart = date('Y-m-d H:i:s', strtotime($asOfTsEst." -{$beforeMinutes} minutes"));

        // Market open (NY time) - BUT your ts_est is fixed UTC-5. We'll use 09:30 on same trading date as signal.
        $tradeDate = substr($signalTsEst, 0, 10);
        $marketOpen = $tradeDate.' 09:30:00';

        if ($analysisStart < $marketOpen) {
            $analysisStart = $marketOpen;
        }

        // Need data from market open through analysisEnd for VWAP and EMA
        $vwapStart = $marketOpen;
        $vwapEnd = $analysisEnd;

        $bars = $this->dbSelect('
            SELECT
              ts_est,
              `open`,
              `high`,
              `low`,
              `price` AS `close`,
              `volume`
            FROM one_minute_prices
            WHERE asset_type = ?
              AND symbol = ?
              AND trading_date_est = ?
              AND ts_est >= ?
              AND ts_est <= ?
            ORDER BY ts_est ASC
        ', [$assetType, $symbol, $tradeDate, $vwapStart, $vwapEnd]);

        if (! $bars || count($bars) < 25) {
            return [
                'ok' => false,
                'error' => 'Not enough 1m data in range (market closed or missing bars).',
                'symbol' => $symbol,
                'asset_type' => $assetType,
                'range_est' => [$vwapStart, $vwapEnd],
                'bars_found' => $bars ? count($bars) : 0,
            ];
        }

        // Validate data quality: reject if extreme price drops (reverse splits, bad data)
        for ($i = 1; $i < count($bars); $i++) {
            $prevClose = (float) $bars[$i - 1]->close;
            $currentOpen = (float) $bars[$i]->open;
            if ($prevClose > 0 && (($currentOpen - $prevClose) / $prevClose) * 100.0 < -50.0) {
                return ['ok' => false, 'error' => 'Bad data - extreme drop', 'symbol' => $symbol];
            }
        }

        // Normalize + VWAP series + 9 EMA + 21 EMA
        $norm = [];
        $cumPV = 0.0;
        $cumV = 0.0;
        $ema9 = null;
        $ema21 = null;
        $emaMultiplier9 = 2.0 / (9 + 1); // 0.2 for 9 EMA
        $emaMultiplier21 = 2.0 / (21 + 1); // ~0.0909 for 21 EMA

        // Track opening range (first 5 minutes)
        $openingRangeHigh = null;
        $openingRangeCount = 0;
        $openingRangeComplete = false;

        foreach ($bars as $r) {
            $o = (float) ($r->open ?? 0);
            $h = (float) ($r->high ?? 0);
            $l = (float) ($r->low ?? 0);
            $c = (float) ($r->close ?? 0);
            $v = (float) ($r->volume ?? 0);

            $typ = ($h + $l + $c) / 3.0;
            if ($v > 0) {
                $cumPV += $typ * $v;
                $cumV += $v;
            }
            $vwap = ($cumV > 0) ? ($cumPV / $cumV) : $c;

            // Calculate 9 EMA
            if ($ema9 === null) {
                $ema9 = $c; // First value
            } else {
                $ema9 = ($c * $emaMultiplier9) + ($ema9 * (1 - $emaMultiplier9));
            }

            // Calculate 21 EMA
            if ($ema21 === null) {
                $ema21 = $c; // First value
            } else {
                $ema21 = ($c * $emaMultiplier21) + ($ema21 * (1 - $emaMultiplier21));
            }

            // Track opening range (first 5 bars from market open)
            if (! $openingRangeComplete) {
                $openingRangeCount++;
                if ($openingRangeHigh === null || $h > $openingRangeHigh) {
                    $openingRangeHigh = $h;
                }
                if ($openingRangeCount >= 5) {
                    $openingRangeComplete = true;
                }
            }

            $norm[] = [
                'ts_est' => (string) $r->ts_est,
                'open' => $o,
                'high' => $h,
                'low' => $l,
                'close' => $c,
                'volume' => $v,
                'vwap' => $vwap,
                'ema9' => $ema9,
                'ema21' => $ema21,
                'or_high' => $openingRangeHigh,
            ];
        }

        $vols = array_map(fn ($b) => (float) $b['volume'], $norm);
        $closes = array_map(fn ($b) => (float) $b['close'], $norm);
        $highs = array_map(fn ($b) => (float) $b['high'], $norm);
        $lows = array_map(fn ($b) => (float) $b['low'], $norm);

        // Calculate RSI (14-period)
        $rsi = $this->calculateRSI($closes, 14);

        // Calculate Stochastic Oscillator (14, 3)
        $stochastic = $this->calculateStochastic($highs, $lows, $closes, 14, 3);
        $stochK = $stochastic['k'];
        $stochD = $stochastic['d'];

        $idxSignal = null;
        for ($i = 0; $i < count($norm); $i++) {
            if ($norm[$i]['ts_est'] >= $signalTsEst) {
                $idxSignal = $i;
                break;
            }
        }
        if ($idxSignal === null) {
            $idxSignal = 0;
        }

        $candidates = [];

        $volAvgBefore = function (int $i) use ($vols, $volLookback): float {
            $start = max(0, $i - $volLookback);
            if ($start >= $i) {
                return 0.0;
            }
            $slice = array_slice($vols, $start, $i - $start);
            $avg = array_sum($slice) / max(1, count($slice));

            return $avg;
        };

        $computeFill = function (int $triggerIdx) use ($norm, $fillModel): array {
            if ($fillModel === 'close') {
                return [$norm[$triggerIdx]['ts_est'], (float) $norm[$triggerIdx]['close']];
            }
            $nextIdx = $triggerIdx + 1;
            if ($nextIdx >= count($norm)) {
                return [$norm[$triggerIdx]['ts_est'], (float) $norm[$triggerIdx]['close']];
            }

            // Safety check: prevent time travel bugs by verifying same trading date
            $curDate = substr($norm[$triggerIdx]['ts_est'], 0, 10);
            $nextDate = substr($norm[$nextIdx]['ts_est'], 0, 10);
            if ($curDate !== $nextDate) {
                return [$norm[$triggerIdx]['ts_est'], (float) $norm[$triggerIdx]['close']];
            }

            $open = (float) $norm[$nextIdx]['open'];
            if ($open <= 0) {
                $open = (float) $norm[$nextIdx]['close'];
            }

            return [$norm[$nextIdx]['ts_est'], $open];
        };

        // Candidate A: VWAP reclaim cross
        for ($i = max(1, $idxSignal); $i < count($norm); $i++) {
            $prev = $norm[$i - 1];
            $cur = $norm[$i];

            $isReclaim = ($prev['close'] < $prev['vwap']) && ($cur['close'] > $cur['vwap']);
            if (! $isReclaim) {
                continue;
            }

            // Additional quality filters for VWAP reclaim
            $vwapDistance = abs($cur['close'] - $cur['vwap']) / $cur['vwap'];
            $bodySize = abs($cur['close'] - $cur['open']) / $cur['open'];

            // Require meaningful reclaim (not just barely above VWAP)
            if ($vwapDistance < 0.001) { // Less than 0.1% above VWAP
                continue;
            }

            // Prefer strong candle bodies vs wicks
            if ($bodySize < 0.002) { // Less than 0.2% body size
                continue;
            }

            $baseVol = $volAvgBefore($i);
            $volRatio = ($baseVol > 0) ? ($cur['volume'] / $baseVol) : 0.0;

            // Require stronger volume confirmation for VWAP reclaims
            if ($volRatio < 2.5) {
                continue;
            }

            [$entryTs, $entryPx] = $computeFill($i);
            $stop = min($cur['low'], $cur['vwap'] * 0.997);

            $distPct = ($entryPx > 0) ? (($entryPx - $cur['vwap']) / $entryPx) * 100.0 : 0.0;

            // Enhanced scoring with time-of-day and momentum factors
            $baseScore = min(3.0, $volRatio) + max(0.0, 1.8 - abs($distPct));

            // Time of day bonus
            $entryHour = (int) substr($cur['ts_est'], 11, 2);
            $entryMinute = (int) substr($cur['ts_est'], 14, 2);
            $timeOfDayBonus = 0.0;
            if ($entryHour == 9 && $entryMinute >= 30) {
                $timeOfDayBonus = 0.8;
            } elseif ($entryHour == 10 && $entryMinute <= 30) {
                $timeOfDayBonus = 0.6;
            } elseif ($entryHour >= 14) {
                $timeOfDayBonus = 0.4;
            }

            // Momentum quality bonus
            $momentumBonus = ($bodySize > 0.005) ? 0.5 : 0.0;
            $momentumBonus += ($vwapDistance > 0.003) ? 0.3 : 0.0;

            $score = $baseScore + $timeOfDayBonus + $momentumBonus;

            // v12.0: Apply power hour time weighting
            $score *= $this->getTimeMultiplier($cur['ts_est']);

            $candidates[] = [
                'type' => 'VWAP_RECLAIM_1M',
                'trigger_ts_est' => $cur['ts_est'],
                'entry_ts_est' => $entryTs,
                'entry' => round($entryPx, 6),
                'stop' => round($stop, 6),
                'vwap' => round($cur['vwap'], 6),
                'vol_ratio' => round($volRatio, 3),
                'score' => round($score, 3),
                'notes' => '1m cross up through VWAP with vol confirmation.',
            ];
            break;
        }

        // Candidate B: pivot high break
        $pivotStart = max(0, $idxSignal - $pivotLookback);
        $pivotHigh = 0.0;
        for ($i = $pivotStart; $i < $idxSignal; $i++) {
            $pivotHigh = max($pivotHigh, $norm[$i]['high']);
        }

        if ($pivotHigh > 0) {
            for ($i = $idxSignal; $i < count($norm); $i++) {
                $cur = $norm[$i];
                if ($cur['close'] <= $pivotHigh) {
                    continue;
                }

                $baseVol = $volAvgBefore($i);
                $volRatio = ($baseVol > 0) ? ($cur['volume'] / $baseVol) : 0.0;
                if ($volRatio < 1.6) {
                    continue;
                }

                [$entryTs, $entryPx] = $computeFill($i);
                $stop = min($cur['low'], $pivotHigh * 0.997);

                $breakPct = (($cur['close'] - $pivotHigh) / $pivotHigh) * 100.0;
                $score = min(3.0, $volRatio) + max(0.0, 1.6 - $breakPct) + 0.5;

                // v12.0: Apply power hour time weighting
                $score *= $this->getTimeMultiplier($cur['ts_est']);

                $candidates[] = [
                    'type' => 'PIVOT_HIGH_BREAK',
                    'trigger_ts_est' => $cur['ts_est'],
                    'entry_ts_est' => $entryTs,
                    'entry' => round($entryPx, 6),
                    'stop' => round($stop, 6),
                    'pivot_high' => round($pivotHigh, 6),
                    'vol_ratio' => round($volRatio, 3),
                    'score' => round($score, 3),
                    'notes' => '1m break above pivot high with volume.',
                ];
                break;
            }
        }

        // Candidate C: Bull Flag Breakout
        // Look for consolidation (3-5 tight candles) after strong move, then breakout
        for ($i = max(5, $idxSignal); $i < count($norm) - 3; $i++) {
            // Check for consolidation pattern (tight range over 3-5 candles)
            $flagStart = $i - 5;
            $flagEnd = $i;

            // Calculate range of last 5 candles
            $flagHigh = 0.0;
            $flagLow = PHP_FLOAT_MAX;
            for ($j = $flagStart; $j <= $flagEnd; $j++) {
                $flagHigh = max($flagHigh, $norm[$j]['high']);
                $flagLow = min($flagLow, $norm[$j]['low']);
            }

            $flagRange = ($flagLow > 0) ? (($flagHigh - $flagLow) / $flagLow) * 100.0 : 999.0;

            // Require tight consolidation (less than 1.5% range)
            if ($flagRange > 1.5) {
                continue;
            }

            // Check for prior strong move (5-10 candles before flag)
            $preMoveStart = max(0, $flagStart - 10);
            $preMovePrice = $norm[$preMoveStart]['close'];
            $flagPrice = $norm[$flagEnd]['close'];
            $priorMove = ($preMovePrice > 0) ? (($flagPrice - $preMovePrice) / $preMovePrice) * 100.0 : 0.0;

            // Require prior move of at least 2%
            if ($priorMove < 2.0) {
                continue;
            }

            // Now look for breakout
            $cur = $norm[$i + 1];
            if ($cur['close'] <= $flagHigh) {
                continue;
            }

            $baseVol = $volAvgBefore($i + 1);
            $volRatio = ($baseVol > 0) ? ($cur['volume'] / $baseVol) : 0.0;

            // Require volume confirmation
            if ($volRatio < 2.0) {
                continue;
            }

            [$entryTs, $entryPx] = $computeFill($i + 1);
            $stop = min($cur['low'], $flagLow * 0.998); // Stop below flag low

            // Score based on volume, flag tightness, and prior move strength
            $score = min(3.0, $volRatio) + min(2.0, $priorMove / 2.0) + max(0.0, 1.5 - $flagRange) + 0.5;

            // v12.0: Apply power hour time weighting
            $score *= $this->getTimeMultiplier($cur['ts_est']);

            $candidates[] = [
                'type' => 'BULL_FLAG_BREAK',
                'trigger_ts_est' => $cur['ts_est'],
                'entry_ts_est' => $entryTs,
                'entry' => round($entryPx, 6),
                'stop' => round($stop, 6),
                'flag_high' => round($flagHigh, 6),
                'flag_low' => round($flagLow, 6),
                'prior_move_pct' => round($priorMove, 2),
                'vol_ratio' => round($volRatio, 3),
                'score' => round($score, 3),
                'notes' => 'Bull flag consolidation breakout with volume.',
            ];
            break;
        }

        // Candidate D: 9 EMA Bounce
        // Look for pullback to 9 EMA followed by bounce with volume
        for ($i = max(10, $idxSignal); $i < count($norm); $i++) {
            $prev = $norm[$i - 1];
            $cur = $norm[$i];

            // Check if 9 EMA is rising strongly (trend filter)
            $ema5BarsAgo = $norm[max(0, $i - 5)]['ema9'] ?? $cur['ema9'];
            $emaRising = $cur['ema9'] > $ema5BarsAgo;
            $emaRiseStrength = ($ema5BarsAgo > 0) ? (($cur['ema9'] - $ema5BarsAgo) / $ema5BarsAgo) * 100.0 : 0.0;

            // Require strong rising EMA (at least 0.5% rise over 5 bars)
            if (! $emaRising || $emaRiseStrength < 0.5) {
                continue;
            }

            // Check for touch/bounce: previous bar low near EMA, current bar closes above EMA
            $prevTouchedEMA = abs($prev['low'] - $prev['ema9']) / $prev['ema9'] < 0.003; // Within 0.3% (tighter)
            $curAboveEMA = $cur['close'] > $cur['ema9'];

            if (! $prevTouchedEMA || ! $curAboveEMA) {
                continue;
            }

            // Require strong green candle (close > open)
            $bodySize = abs($cur['close'] - $cur['open']) / $cur['open'];
            if ($cur['close'] <= $cur['open'] || $bodySize < 0.003) {
                continue;
            }

            $baseVol = $volAvgBefore($i);
            $volRatio = ($baseVol > 0) ? ($cur['volume'] / $baseVol) : 0.0;

            // Require strong volume confirmation (6x instead of 10x)
            if ($volRatio < 6.0) {
                continue;
            }

            [$entryTs, $entryPx] = $computeFill($i);
            $stop = min($cur['low'], $cur['ema9'] * 0.996); // Stop below EMA

            $distFromEMA = (($cur['close'] - $cur['ema9']) / $cur['ema9']) * 100.0;

            // Require close proximity to EMA (within 1%)
            if (abs($distFromEMA) > 1.0) {
                continue;
            }

            // Score based on volume, body strength, and proximity to EMA (removed +0.7 bonus)
            $score = min(3.0, $volRatio) + ($bodySize > 0.005 ? 0.8 : 0.3) + max(0.0, 1.5 - abs($distFromEMA));

            // v12.0: Apply power hour time weighting
            $score *= $this->getTimeMultiplier($cur['ts_est']);

            $candidates[] = [
                'type' => 'EMA9_BOUNCE',
                'trigger_ts_est' => $cur['ts_est'],
                'entry_ts_est' => $entryTs,
                'entry' => round($entryPx, 6),
                'stop' => round($stop, 6),
                'ema9' => round($cur['ema9'], 6),
                'vol_ratio' => round($volRatio, 3),
                'score' => round($score, 3),
                'notes' => 'Bounce off rising 9 EMA with volume.',
            ];
            break;
        }

        // Candidate E: 21 EMA Bounce
        // Similar to EMA9 but longer timeframe - typically stronger support
        for ($i = max(22, $idxSignal); $i < count($norm); $i++) {
            $prev = $norm[$i - 1];
            $cur = $norm[$i];

            // Check if 21 EMA is rising (trend filter)
            $ema10BarsAgo = $norm[max(0, $i - 10)]['ema21'] ?? $cur['ema21'];
            $emaRising = $cur['ema21'] > $ema10BarsAgo;
            $emaRiseStrength = ($ema10BarsAgo > 0) ? (($cur['ema21'] - $ema10BarsAgo) / $ema10BarsAgo) * 100.0 : 0.0;

            // Require rising EMA (at least 0.3% over 10 bars)
            if (! $emaRising || $emaRiseStrength < 0.3) {
                continue;
            }

            // Check for touch/bounce: previous bar low near EMA21, current closes above
            $prevTouchedEMA = abs($prev['low'] - $prev['ema21']) / $prev['ema21'] < 0.005; // Within 0.5%
            $curAboveEMA = $cur['close'] > $cur['ema21'];

            if (! $prevTouchedEMA || ! $curAboveEMA) {
                continue;
            }

            // Require green candle
            $bodySize = abs($cur['close'] - $cur['open']) / $cur['open'];
            if ($cur['close'] <= $cur['open'] || $bodySize < 0.003) {
                continue;
            }

            $baseVol = $volAvgBefore($i);
            $volRatio = ($baseVol > 0) ? ($cur['volume'] / $baseVol) : 0.0;

            // Require volume confirmation (4x for EMA21 - less strict than EMA9)
            if ($volRatio < 4.0) {
                continue;
            }

            [$entryTs, $entryPx] = $computeFill($i);
            $stop = min($cur['low'], $cur['ema21'] * 0.995); // Stop below EMA21

            $distFromEMA = (($cur['close'] - $cur['ema21']) / $cur['ema21']) * 100.0;

            // Require close proximity to EMA (within 1.5%)
            if (abs($distFromEMA) > 1.5) {
                continue;
            }

            $score = min(3.0, $volRatio) + ($bodySize > 0.005 ? 1.0 : 0.4) + max(0.0, 1.5 - abs($distFromEMA));

            // v11.0: Filter out weak EMA21 bounces (score < 4.7 frequently stop out)
            if ($score < 4.7) {
                continue;
            }

            // v12.0: Apply power hour time weighting
            $score *= $this->getTimeMultiplier($cur['ts_est']);

            $candidates[] = [
                'type' => 'EMA21_BOUNCE',
                'trigger_ts_est' => $cur['ts_est'],
                'entry_ts_est' => $entryTs,
                'entry' => round($entryPx, 6),
                'stop' => round($stop, 6),
                'ema21' => round($cur['ema21'], 6),
                'vol_ratio' => round($volRatio, 3),
                'score' => round($score, 3),
                'notes' => 'Bounce off rising 21 EMA with volume.',
            ];
            break;
        }

        // Candidate F: EMA Crossover with RSI Confirmation (NEW in v13.0)
        // Detects 9 EMA crossing above 21 EMA with price above both and RSI > 50
        // Entry on strong volume candle breaking recent resistance
        for ($i = max(22, $idxSignal); $i < count($norm); $i++) {
            $prev = $norm[$i - 1];
            $cur = $norm[$i];

            // Check for recent EMA crossover (9 EMA crossed above 21 EMA within last 5 bars)
            $crossoverDetected = false;
            for ($lookback = 0; $lookback <= min(5, $i - 1); $lookback++) {
                $checkIdx = $i - $lookback;
                if ($checkIdx < 1) {
                    continue;
                }

                $beforeCross = $norm[$checkIdx - 1];
                $afterCross = $norm[$checkIdx];

                // Detect bullish crossover: 9 EMA crosses above 21 EMA
                if ($beforeCross['ema9'] <= $beforeCross['ema21'] && $afterCross['ema9'] > $afterCross['ema21']) {
                    $crossoverDetected = true;
                    break;
                }
            }

            if (! $crossoverDetected) {
                continue;
            }

            // Condition A: 9 EMA above 21 EMA (crossover already detected above)
            // Condition B: Price trading above both EMAs
            if ($cur['close'] <= $cur['ema9'] || $cur['close'] <= $cur['ema21']) {
                continue;
            }

            // v13.0: RSI check removed to generate more picks (was RSI > 50)

            // Stochastic Oscillator confirmation (bullish signals):
            // 1. %K > %D (fast line above slow line - bullish)
            // 2. %K crossing above 20 (exiting oversold) OR both lines below 80 (not overbought)
            $hasStochConfirmation = false;
            if (isset($stochK[$i]) && $stochK[$i] !== null && isset($stochD[$i]) && $stochD[$i] !== null) {
                $kAboveD = $stochK[$i] > $stochD[$i];

                // Check for bullish crossover (%K crossing above %D in last 3 bars)
                $recentBullishCross = false;
                for ($lookback = 0; $lookback <= min(3, $i - 1); $lookback++) {
                    $checkIdx = $i - $lookback;
                    if ($checkIdx < 1) {
                        continue;
                    }
                    if (isset($stochK[$checkIdx - 1]) && isset($stochD[$checkIdx - 1]) &&
                        isset($stochK[$checkIdx]) && isset($stochD[$checkIdx]) &&
                        $stochK[$checkIdx - 1] !== null && $stochD[$checkIdx - 1] !== null &&
                        $stochK[$checkIdx] !== null && $stochD[$checkIdx] !== null) {
                        if ($stochK[$checkIdx - 1] <= $stochD[$checkIdx - 1] && $stochK[$checkIdx] > $stochD[$checkIdx]) {
                            $recentBullishCross = true;
                            break;
                        }
                    }
                }

                // Bullish if: %K > %D AND (recent crossover OR exiting oversold OR not overbought)
                $exitingOversold = $stochK[$i] > 20.0 && ($i > 0 && isset($stochK[$i - 1]) && $stochK[$i - 1] !== null && $stochK[$i - 1] <= 20.0);
                $notOverbought = $stochK[$i] < 80.0 && $stochD[$i] < 80.0;

                if ($kAboveD && ($recentBullishCross || $exitingOversold || $notOverbought)) {
                    $hasStochConfirmation = true;
                }
            }

            // Require stochastic confirmation
            if (! $hasStochConfirmation) {
                continue;
            }

            // Find recent resistance (highest high in last 10 bars before current)
            $resistanceHigh = 0.0;
            for ($j = max(0, $i - 10); $j < $i; $j++) {
                if ($norm[$j]['high'] > $resistanceHigh) {
                    $resistanceHigh = $norm[$j]['high'];
                }
            }

            // v13.0: Resistance break removed to generate more picks (was required to break)

            // Require green candle
            $bodySize = abs($cur['close'] - $cur['open']) / $cur['open'];
            if ($cur['close'] <= $cur['open']) {
                continue;
            }

            $baseVol = $volAvgBefore($i);
            $volRatio = ($baseVol > 0) ? ($cur['volume'] / $baseVol) : 0.0;

            // v13.0: Lowered volume from 3x to 1.0x to maximize picks (any volume increase acceptable)
            if ($volRatio < 1.0) {
                continue;
            }

            [$entryTs, $entryPx] = $computeFill($i);

            // Stop below the lower EMA (21 EMA as primary support after crossover)
            $stop = min($cur['low'], $cur['ema21'] * 0.995);

            // Score based on RSI strength, volume, body size, breakout distance, and stochastic momentum
            $rsiStrength = ($rsi[$i] - 50.0) / 50.0; // 0.0 at RSI=50, 1.0 at RSI=100
            $breakoutPct = (($cur['close'] - $resistanceHigh) / $resistanceHigh) * 100.0;

            // Add stochastic strength bonus (%K above %D adds momentum confirmation)
            $stochStrength = 0.0;
            if (isset($stochK[$i]) && $stochK[$i] !== null && isset($stochD[$i]) && $stochD[$i] !== null) {
                $stochStrength = max(0.0, min(1.0, ($stochK[$i] - $stochD[$i]) / 20.0)); // Max 1.0 bonus
            }

            $score = min(3.0, $volRatio) + ($rsiStrength * 2.0) + ($stochStrength * 1.0) + ($bodySize > 0.005 ? 1.0 : 0.5) + min(1.5, $breakoutPct * 10);

            // v13.0: Score filter removed to maximize picks (was 2.0)

            // Apply power hour time weighting
            $score *= $this->getTimeMultiplier($cur['ts_est']);

            $candidates[] = [
                'type' => 'EMA_CROSS_BULL',
                'trigger_ts_est' => $cur['ts_est'],
                'entry_ts_est' => $entryTs,
                'entry' => round($entryPx, 6),
                'stop' => round($stop, 6),
                'ema9' => round($cur['ema9'], 6),
                'ema21' => round($cur['ema21'], 6),
                'rsi' => round($rsi[$i], 2),
                'stoch_k' => isset($stochK[$i]) ? round($stochK[$i], 2) : null,
                'stoch_d' => isset($stochD[$i]) ? round($stochD[$i], 2) : null,
                'resistance' => round($resistanceHigh, 6),
                'vol_ratio' => round($volRatio, 3),
                'score' => round($score, 3),
                'notes' => '9 EMA crossed above 21 EMA with price above both EMAs and increased volume (v13.0: relaxed filters).',
            ];
            break;
        }

        // Candidate G: VWAP Bounce (from above) - DISABLED in v10.7
        // REMOVED: Underperforming pattern (-0.06% avg P&L, 65.4% stop-out rate)
        // for ($i = max(5, $idxSignal); $i < count($norm); $i++) {
        //     $prev = $norm[$i - 1];
        //     $cur = $norm[$i];
        //
        //     // Must be above VWAP initially (check 2 bars ago)
        //     if ($i < 2) continue;
        //     $twoAgo = $norm[$i - 2];
        //     $wasAbove = $twoAgo['close'] > $twoAgo['vwap'];
        //
        //     if (!$wasAbove) {
        //         continue;
        //     }
        //
        //     // Previous bar touched/went through VWAP from above
        //     $prevTouchedVWAP = $prev['low'] <= $prev['vwap'] && $prev['close'] >= $prev['vwap'] * 0.998;
        //
        //     // Current bar bounces back above VWAP
        //     $curBounced = $cur['close'] > $cur['vwap'] && $cur['close'] > $prev['close'];
        //
        //     if (!$prevTouchedVWAP || !$curBounced) {
        //         continue;
        //     }
        //
        //     // Require green candle
        //     if ($cur['close'] <= $cur['open']) {
        //         continue;
        //     }
        //
        //     $baseVol = $volAvgBefore($i);
        //     $volRatio = ($baseVol > 0) ? ($cur['volume'] / $baseVol) : 0.0;
        //
        //     // Volume confirmation (3x for bounce)
        //     if ($volRatio < 3.0) {
        //         continue;
        //     }
        //
        //     [$entryTs, $entryPx] = $computeFill($i);
        //     $stop = min($cur['low'], $cur['vwap'] * 0.997); // Stop below VWAP
        //
        //     $distFromVWAP = (($cur['close'] - $cur['vwap']) / $cur['vwap']) * 100.0;
        //     $bodySize = ($cur['close'] - $cur['open']) / $cur['open'];
        //
        //     $score = min(3.0, $volRatio) + ($bodySize > 0.005 ? 1.0 : 0.5) + max(0.0, 1.0 - abs($distFromVWAP));
        //
        //     $candidates[] = [
        //         'type' => 'VWAP_BOUNCE',
        //         'trigger_ts_est' => $cur['ts_est'],
        //         'entry_ts_est' => $entryTs,
        //         'entry' => round($entryPx, 6),
        //         'stop' => round($stop, 6),
        //         'vwap' => round($cur['vwap'], 6),
        //         'vol_ratio' => round($volRatio, 3),
        //         'score' => round($score, 3),
        //         'notes' => 'Bounce off VWAP support with volume.',
        //     ];
        //     break;
        // }

        // Candidate G: Moving Average Squeeze (v12.1)
        // 9 EMA crosses above 21 EMA with volume - catches trend initiation
        for ($i = max(22, $idxSignal); $i < count($norm); $i++) {
            $prev = $norm[$i - 1];
            $cur = $norm[$i];

            // Check if 9 EMA just crossed above 21 EMA (bullish crossover)
            $wasBelowOrEqual = $prev['ema9'] <= $prev['ema21'];
            $nowAbove = $cur['ema9'] > $cur['ema21'];

            if (! ($wasBelowOrEqual && $nowAbove)) {
                continue;
            }

            // Require both EMAs rising (trending up, not just crossing in downtrend)
            $ema9Rising = $cur['ema9'] > $norm[max(0, $i - 3)]['ema9'];
            $ema21Rising = $cur['ema21'] > $norm[max(0, $i - 5)]['ema21'];

            if (! ($ema9Rising && $ema21Rising)) {
                continue;
            }

            // Require green candle on crossover
            if ($cur['close'] <= $cur['open']) {
                continue;
            }

            $baseVol = $volAvgBefore($i);
            $volRatio = ($baseVol > 0) ? ($cur['volume'] / $baseVol) : 0.0;

            // Strong volume confirmation (3x minimum for trend initiation)
            if ($volRatio < 3.0) {
                continue;
            }

            [$entryTs, $entryPx] = $computeFill($i);
            $stop = min($cur['low'], $cur['ema21'] * 0.998); // Stop below 21 EMA

            $bodySize = ($cur['close'] - $cur['open']) / $cur['open'];
            $emaGap = (($cur['ema9'] - $cur['ema21']) / $cur['ema21']) * 100.0;

            // Score: volume + body strength + EMA gap tightness (closer = fresher signal)
            $score = min(3.0, $volRatio) + ($bodySize > 0.005 ? 1.5 : 0.5) + max(0.0, 2.0 - abs($emaGap * 10));

            // v12.0: Apply power hour time weighting
            $score *= $this->getTimeMultiplier($cur['ts_est']);

            $candidates[] = [
                'type' => 'MA_SQUEEZE',
                'trigger_ts_est' => $cur['ts_est'],
                'entry_ts_est' => $entryTs,
                'entry' => round($entryPx, 6),
                'stop' => round($stop, 6),
                'ema9' => round($cur['ema9'], 6),
                'ema21' => round($cur['ema21'], 6),
                'vol_ratio' => round($volRatio, 3),
                'score' => round($score, 3),
                'notes' => '9 EMA crossed above 21 EMA with volume - trend initiation.',
            ];
            break;
        }

        // Candidate H: Volume Shelf Breakout (v12.1)
        // Price consolidates 3-5 bars on ABOVE average volume (accumulation) then breaks out
        for ($i = max(6, $idxSignal); $i < count($norm); $i++) {
            $cur = $norm[$i];

            // Look back 3-5 bars for consolidation pattern
            $shelfStart = $i - 5;
            $shelfEnd = $i - 1;

            if ($shelfStart < 0) {
                continue;
            }

            // Calculate consolidation range
            $shelfHigh = 0.0;
            $shelfLow = PHP_FLOAT_MAX;
            $shelfVolumeSum = 0.0;
            $shelfBars = 0;

            for ($j = $shelfStart; $j <= $shelfEnd; $j++) {
                $shelfHigh = max($shelfHigh, $norm[$j]['high']);
                $shelfLow = min($shelfLow, $norm[$j]['low']);
                $shelfVolumeSum += $norm[$j]['volume'];
                $shelfBars++;
            }

            $shelfAvgVol = $shelfVolumeSum / max(1, $shelfBars);
            $shelfRange = (($shelfHigh - $shelfLow) / $shelfLow) * 100.0;

            // Require tight consolidation (less than 1.5% range = coiling)
            if ($shelfRange > 1.5 || $shelfRange < 0.2) {
                continue;
            }

            // Key: consolidation must be on ABOVE average volume (accumulation)
            $baseVol = $volAvgBefore($shelfStart);
            $shelfVolRatio = ($baseVol > 0) ? ($shelfAvgVol / $baseVol) : 0.0;

            // Shelf volume must be at least 1.5x average (institutions loading)
            if ($shelfVolRatio < 1.5) {
                continue;
            }

            // Current bar breaks above shelf high (breakout)
            $breaks = $cur['close'] > $shelfHigh;

            if (! $breaks) {
                continue;
            }

            // Require green breakout candle
            if ($cur['close'] <= $cur['open']) {
                continue;
            }

            // Breakout volume must be strong
            $breakoutVol = $volAvgBefore($i);
            $breakoutVolRatio = ($breakoutVol > 0) ? ($cur['volume'] / $breakoutVol) : 0.0;

            if ($breakoutVolRatio < 3.0) {
                continue;
            }

            [$entryTs, $entryPx] = $computeFill($i);
            $stop = $shelfLow * 0.998; // Stop below shelf low

            $breakoutStrength = (($cur['close'] - $shelfHigh) / $shelfHigh) * 100.0;
            $bodySize = ($cur['close'] - $cur['open']) / $cur['open'];

            // Score: shelf tightness + breakout volume + shelf volume quality
            $score = (1.5 / max(0.5, $shelfRange)) + min(3.0, $breakoutVolRatio) + min(2.0, $shelfVolRatio);

            // v12.0: Apply power hour time weighting
            $score *= $this->getTimeMultiplier($cur['ts_est']);

            $candidates[] = [
                'type' => 'VOL_SHELF',
                'trigger_ts_est' => $cur['ts_est'],
                'entry_ts_est' => $entryTs,
                'entry' => round($entryPx, 6),
                'stop' => round($stop, 6),
                'shelf_high' => round($shelfHigh, 6),
                'shelf_low' => round($shelfLow, 6),
                'shelf_vol_ratio' => round($shelfVolRatio, 3),
                'vol_ratio' => round($breakoutVolRatio, 3),
                'score' => round($score, 3),
                'notes' => 'Breakout from consolidation on elevated volume (accumulation).',
            ];
            break;
        }

        // Candidate I: Opening Range Breakout
        // Break above first 5 minutes high with volume
        for ($i = max(6, $idxSignal); $i < count($norm); $i++) {
            $cur = $norm[$i];

            // Need opening range established
            if (! $cur['or_high']) {
                continue;
            }

            // Check for breakout above opening range high
            $prev = $norm[$i - 1];
            $breaks = $prev['high'] <= $cur['or_high'] && $cur['close'] > $cur['or_high'];

            if (! $breaks) {
                continue;
            }

            // Require green candle
            if ($cur['close'] <= $cur['open']) {
                continue;
            }

            $baseVol = $volAvgBefore($i);
            $volRatio = ($baseVol > 0) ? ($cur['volume'] / $baseVol) : 0.0;

            // Strong volume for breakout (6x in v10.7 - tightened from 4x)
            if ($volRatio < 6.0) {
                continue;
            }

            // v11.0: Cap volume at 80x to avoid false spikes (LYFT 78x was marginal)
            if ($volRatio > 80.0) {
                continue;
            }

            [$entryTs, $entryPx] = $computeFill($i);
            $stop = $cur['or_high'] * 0.998; // Stop just below OR high

            $distAboveOR = (($cur['close'] - $cur['or_high']) / $cur['or_high']) * 100.0;
            $bodySize = ($cur['close'] - $cur['open']) / $cur['open'];

            // Prefer clean breakouts (not too far extended)
            if ($distAboveOR > 2.0) {
                continue;
            }

            // v11.0: Require stronger body size (1.2% vs 1%) for OR breakouts
            if ($bodySize < 0.012) {
                continue;
            }

            $score = min(3.0, $volRatio) + ($bodySize > 0.01 ? 1.2 : 0.5) + max(0.0, 2.0 - $distAboveOR);

            // v11.0: Require higher quality score (5.5+ vs 5.3+) to further reduce false breakouts
            if ($score < 5.5) {
                continue;
            }

            // v12.0: Apply power hour time weighting
            $score *= $this->getTimeMultiplier($cur['ts_est']);

            $candidates[] = [
                'type' => 'OR_BREAKOUT',
                'trigger_ts_est' => $cur['ts_est'],
                'entry_ts_est' => $entryTs,
                'entry' => round($entryPx, 6),
                'stop' => round($stop, 6),
                'or_high' => round($cur['or_high'], 6),
                'vol_ratio' => round($volRatio, 3),
                'score' => round($score, 3),
                'notes' => 'Opening range breakout with volume.',
            ];
            break;
        }

        // Candidate H: Momentum Continuation - DISABLED in v10.7
        // REMOVED: Underperforming pattern (-0.68% avg P&L, 66.7% stop-out rate)
        // for ($i = max(10, $idxSignal); $i < count($norm); $i++) {
        //     $cur = $norm[$i];
        //
        //     // Look back to find a strong up move (at least 1.5% in 3-5 bars)
        //     if ($i < 7) continue;
        //
        //     $strongMoveStart = null;
        //     for ($lookback = 3; $lookback <= 5; $lookback++) {
        //         $startIdx = $i - 3 - $lookback;
        //         if ($startIdx < 0) continue;
        //
        //         $startBar = $norm[$startIdx];
        //         $moveEndIdx = $i - 3;
        //         $moveEnd = $norm[$moveEndIdx];
        //
        //         $moveSize = (($moveEnd['close'] - $startBar['close']) / $startBar['close']) * 100.0;
        //
        //         if ($moveSize >= 1.5) {
        //             $strongMoveStart = $startIdx;
        //             break;
        //         }
        //     }
        //
        //     if ($strongMoveStart === null) {
        //         continue;
        //     }
        //
        //     // Check for 2-3 candle pullback (consolidation)
        //     $pullbackHigh = 0.0;
        //     $pullbackLow = PHP_FLOAT_MAX;
        //     for ($pb = 1; $pb <= 3; $pb++) {
        //         $pbBar = $norm[$i - $pb];
        //         $pullbackHigh = max($pullbackHigh, $pbBar['high']);
        //         $pullbackLow = min($pullbackLow, $pbBar['low']);
        //     }
        //
        //     $pullbackRange = (($pullbackHigh - $pullbackLow) / $pullbackLow) * 100.0;
        //
        //     // Tight pullback (less than 1.5% range)
        //     if ($pullbackRange > 1.5) {
        //         continue;
        //     }
        //
        //     // Current bar breaks above pullback high (continuation)
        //     $prev = $norm[$i - 1];
        //     $breaks = $cur['close'] > $pullbackHigh && $cur['close'] > $prev['close'];
        //
        //     if (!$breaks) {
        //         continue;
        //     }
        //
        //     // Require green candle
        //     if ($cur['close'] <= $cur['open']) {
        //         continue;
        //     }
        //
        //     $baseVol = $volAvgBefore($i);
        //     $volRatio = ($baseVol > 0) ? ($cur['volume'] / $baseVol) : 0.0;
        //
        //     // Volume confirmation (3x)
        //     if ($volRatio < 3.0) {
        //         continue;
        //     }
        //
        //     [$entryTs, $entryPx] = $computeFill($i);
        //     $stop = $pullbackLow * 0.998; // Stop below pullback low
        //
        //     $bodySize = ($cur['close'] - $cur['open']) / $cur['open'];
        //
        //     $score = min(3.0, $volRatio) + ($bodySize > 0.008 ? 1.2 : 0.5) + max(0.0, 1.5 - $pullbackRange);
        //
        //     $candidates[] = [
        //         'type' => 'MOMENTUM_CONTINUATION',
        //         'trigger_ts_est' => $cur['ts_est'],
        //         'entry_ts_est' => $entryTs,
        //         'entry' => round($entryPx, 6),
        //         'stop' => round($stop, 6),
        //         'pullback_range' => round($pullbackRange, 3),
        //         'vol_ratio' => round($volRatio, 3),
        //         'score' => round($score, 3),
        //         'notes' => 'Continuation after tight pullback.',
        //     ];
        //     break;
        // }

        // Candidate J: Breakout Retest (v12.2)
        // Price breaks above recent pivot, pulls back to retest as support, then continues
        for ($i = max(15, $idxSignal); $i < count($norm); $i++) {
            $cur = $norm[$i];

            // Look back 5-15 bars for a recent breakout level (pivot high that was broken)
            $breakoutLevel = null;
            $breakoutIdx = null;

            for ($lookback = 5; $lookback <= 15; $lookback++) {
                $checkIdx = $i - $lookback;
                if ($checkIdx < 10) {
                    continue;
                }

                // Find pivot high (higher than 3 bars before and after)
                $isPivot = true;
                $pivotHigh = $norm[$checkIdx]['high'];

                for ($offset = 1; $offset <= 3; $offset++) {
                    if ($checkIdx - $offset < 0 || $checkIdx + $offset >= count($norm)) {
                        $isPivot = false;
                        break;
                    }
                    if ($norm[$checkIdx - $offset]['high'] >= $pivotHigh ||
                        $norm[$checkIdx + $offset]['high'] >= $pivotHigh) {
                        $isPivot = false;
                        break;
                    }
                }

                if (! $isPivot) {
                    continue;
                }

                // Check if price broke ABOVE this pivot and is now testing it
                $brokePivot = false;
                for ($j = $checkIdx + 1; $j < $i; $j++) {
                    if ($norm[$j]['close'] > $pivotHigh * 1.003) { // Broke 0.3% above
                        $brokePivot = true;
                        break;
                    }
                }

                if ($brokePivot) {
                    $breakoutLevel = $pivotHigh;
                    $breakoutIdx = $checkIdx;
                    break;
                }
            }

            if ($breakoutLevel === null) {
                continue;
            }

            // Current bar must be retesting the breakout level (within 0.5%)
            $distanceFromLevel = abs($cur['low'] - $breakoutLevel) / $breakoutLevel;
            if ($distanceFromLevel > 0.005) { // More than 0.5% away
                continue;
            }

            // Must hold (not break below) - low should be at or above breakout level
            if ($cur['low'] < $breakoutLevel * 0.997) { // Broke below by more than 0.3%
                continue;
            }

            // Require green candle showing bounce
            if ($cur['close'] <= $cur['open']) {
                continue;
            }

            // Volume confirmation
            $baseVol = $volAvgBefore($i);
            $volRatio = ($baseVol > 0) ? ($cur['volume'] / $baseVol) : 0.0;

            if ($volRatio < 3.0) {
                continue;
            }

            [$entryTs, $entryPx] = $computeFill($i);
            $stop = $breakoutLevel * 0.995; // Stop just below breakout level

            $bodySize = ($cur['close'] - $cur['open']) / $cur['open'];
            $bounceStrength = (($cur['close'] - $breakoutLevel) / $breakoutLevel) * 100.0;

            // Score: volume + body + bounce strength + level touch accuracy
            $score = min(3.0, $volRatio) + ($bodySize > 0.005 ? 1.5 : 0.5) +
                     min(2.0, max(0.0, $bounceStrength * 5)) + (1.5 / max(0.2, $distanceFromLevel * 100));

            // v12.0: Apply power hour time weighting
            $score *= $this->getTimeMultiplier($cur['ts_est']);

            $candidates[] = [
                'type' => 'BREAKOUT_RETEST',
                'trigger_ts_est' => $cur['ts_est'],
                'entry_ts_est' => $entryTs,
                'entry' => round($entryPx, 6),
                'stop' => round($stop, 6),
                'breakout_level' => round($breakoutLevel, 6),
                'vol_ratio' => round($volRatio, 3),
                'score' => round($score, 3),
                'notes' => 'Retest of recent breakout level as new support.',
            ];
            break;
        }

        // Candidate K: First Green After Red Streak (v12.2)
        // 3+ consecutive red candles followed by first green with volume (panic reversal)
        for ($i = max(4, $idxSignal); $i < count($norm); $i++) {
            $cur = $norm[$i];

            // Current must be green
            if ($cur['close'] <= $cur['open']) {
                continue;
            }

            // Count consecutive red candles before current
            $redCount = 0;
            for ($j = $i - 1; $j >= max(0, $i - 6); $j--) {
                $bar = $norm[$j];
                if ($bar['close'] < $bar['open']) { // Red candle
                    $redCount++;
                } else {
                    break; // Stop counting at first non-red
                }
            }

            // Require at least 3 consecutive red candles
            if ($redCount < 3) {
                continue;
            }

            // Calculate total decline during red streak
            $streakStart = $norm[$i - $redCount];
            $streakEnd = $norm[$i - 1];
            $declinePct = (($streakStart['close'] - $streakEnd['close']) / $streakStart['close']) * 100.0;

            // Require meaningful decline (at least 1%)
            if ($declinePct < 1.0) {
                continue;
            }

            // Volume on reversal candle must be elevated
            $baseVol = $volAvgBefore($i);
            $volRatio = ($baseVol > 0) ? ($cur['volume'] / $baseVol) : 0.0;

            if ($volRatio < 3.0) {
                continue;
            }

            // Green candle should be strong (reclaim at least 30% of decline)
            $greenRecovery = (($cur['close'] - $cur['open']) / $streakStart['close']) * 100.0;
            $recoverPct = ($declinePct > 0) ? ($greenRecovery / $declinePct) * 100.0 : 0.0;

            if ($recoverPct < 30.0) {
                continue;
            }

            [$entryTs, $entryPx] = $computeFill($i);
            $stop = min($cur['low'], $streakEnd['low']) * 0.998; // Stop below recent lows

            $bodySize = ($cur['close'] - $cur['open']) / $cur['open'];

            // Score: volume + red streak length + decline magnitude + recovery strength
            $score = min(3.0, $volRatio) + min(2.0, $redCount * 0.5) +
                     min(2.0, $declinePct * 0.5) + ($recoverPct > 50.0 ? 1.0 : 0.5);

            // v12.0: Apply power hour time weighting
            $score *= $this->getTimeMultiplier($cur['ts_est']);

            $candidates[] = [
                'type' => 'GREEN_AFTER_RED',
                'trigger_ts_est' => $cur['ts_est'],
                'entry_ts_est' => $entryTs,
                'entry' => round($entryPx, 6),
                'stop' => round($stop, 6),
                'red_count' => $redCount,
                'decline_pct' => round($declinePct, 2),
                'vol_ratio' => round($volRatio, 3),
                'score' => round($score, 3),
                'notes' => "First green after {$redCount} red candles - panic reversal.",
            ];
            break;
        }

        // v10.7: Filter to working patterns only (removed VWAP_BOUNCE, MOMENTUM_CONTINUATION)
        // v12.1: Added MA_SQUEEZE and VOL_SHELF patterns
        // v12.2: Added BREAKOUT_RETEST and GREEN_AFTER_RED patterns
        // v12.4: Pattern-specific volume and score filters based on backtest data
        // v12.5: Keep only profitable patterns - disabled VOL_SHELF and BREAKOUT_RETEST
        $filtered = array_filter($candidates, function ($c) {
            // v13.0: Added EMA_CROSS_BULL pattern (9 EMA cross above 21 EMA + RSI > 50)
            // v12.5: Only allow profitable patterns based on v12.4 backtest results
            // MA_SQUEEZE: 55.6% WR, 0.65% avg P&L
            // VWAP_RECLAIM: 56.3% WR, 0.44% avg P&L
            // EMA9/EMA21_BOUNCE: 100% WR (small sample)
            // BULL_FLAG_BREAK: 100% WR (small sample)
            $allowedTypes = ['VWAP_RECLAIM_1M', 'BULL_FLAG_BREAK', 'EMA9_BOUNCE',
                'EMA21_BOUNCE', 'MA_SQUEEZE', 'EMA_CROSS_BULL'];

            if (! in_array($c['type'], $allowedTypes)) {
                return false;
            }

            // v12.5: Pattern-specific filters (keeping v12.4 filters for allowed patterns)
            // v12.6: Tightened MA_SQUEEZE volume filter from 10x to 6x based on v12.5 analysis

            // v13.0: MA_SQUEEZE volume filter removed to generate more picks

            // VWAP_RECLAIM: Score filter (v13.0: Aggressively loosened to 3.0 to generate picks)
            if ($c['type'] === 'VWAP_RECLAIM_1M') {
                if ($c['score'] < 3.0) {
                    return false;
                }
            }

            // v10.7: Global filters (v13.0: Aggressively loosened from 3.2x to 1.5x)
            // - Exclude extremely high volume (>100x correlates with losers)
            // - Require minimum 1.5x volume
            return $c['vol_ratio'] >= 1.5 && $c['vol_ratio'] <= 100.0;
        });

        // v13.0: Filter out stale entries - only allow fresh entries within configured minutes
        // Entries older than freshnessMinutes are likely already moved and impractical to enter
        $filtered = array_filter($filtered, function ($c) use ($asOfTsEst, $freshnessMinutes) {
            $entryTime = strtotime($c['entry_ts_est']);
            $currentTime = strtotime($asOfTsEst);
            $ageMinutes = ($currentTime - $entryTime) / 60;

            return $ageMinutes <= $freshnessMinutes;
        });

        usort($filtered, fn ($a, $b) => ($b['score'] <=> $a['score']));
        $best = $filtered[0] ?? null;

        if ($best) {
            $risk = max(1e-9, (float) $best['entry'] - (float) $best['stop']);
            $riskPct = ((float) $best['entry'] > 0) ? ($risk / (float) $best['entry']) * 100.0 : 0.0;

            // v13.0: Risk filters removed to maximize picks (was 2.0%)
            // Accept all risk levels for now, will filter by performance later
        }

        if ($best) {
            $risk = max(1e-9, (float) $best['entry'] - (float) $best['stop']);
            $riskPct = ((float) $best['entry'] > 0) ? ($risk / (float) $best['entry']) * 100.0 : 0.0;

            $best['risk_per_share'] = round($risk, 6);
            $best['risk_pct'] = round($riskPct, 3);
            $best['targets'] = [
                '1R' => round((float) $best['entry'] + 1.0 * $risk, 6),
                '2R' => round((float) $best['entry'] + 2.0 * $risk, 6),
                '3R' => round((float) $best['entry'] + 3.0 * $risk, 6),
            ];

            // v12.4: Calculate ATR (Average True Range) for volatility-based trailing stop
            // Use 14-period ATR on 1-minute bars with live trading settings
            $atr = $this->calculateATR($norm, 14);
            $atrMultiplier = \App\Services\TradingSettingService::getStopLossAtrMultiplier();
            $minStopPct = \App\Services\TradingSettingService::getStopLossAtrMinPct();
            $maxStopPct = \App\Services\TradingSettingService::getStopLossAtrMaxPct();
            $calculatedPct = ((float) $best['entry'] > 0)
                ? (($atr * $atrMultiplier) / (float) $best['entry']) * 100.0
                : $minStopPct;
            $trailPct = max($minStopPct, min($maxStopPct, $calculatedPct));
            $best['atr'] = round($atr, 6);
            $best['atr_pct'] = ((float) $best['entry'] > 0) ? ($atr / (float) $best['entry']) * 100.0 : 0.0;
            $best['suggested_trailing_stop'] = round((float) $best['entry'] * ($trailPct / 100.0), 6);
            $best['suggested_trailing_stop_pct'] = round($trailPct, 3);
        }

        return [
            'ok' => (bool) $best,
            'symbol' => $symbol,
            'asset_type' => $assetType,
            'signal_ts_est' => $signalTsEst,
            'analysis_window_est' => [$analysisStart, $analysisEnd],
            'market_open_est' => $marketOpen,
            'bars_loaded' => count($norm),
            'best_entry' => $best,
            'candidates' => $candidates,
        ];
    }
}
