<?php

namespace App\Services\Trading;

/**
 * OneMinuteEntryFinderV210_0 - Oversold Bounce Entry Finder
 *
 * Finds 1-minute entry points for oversold bounce signals.
 * Strategy:
 * 1. Confirm bounce is forming on 1m chart
 * 2. Entry on first green bar after low
 * 3. Tight stop below recent low
 * 4. Target 2.5R for quick profit
 *
 * Returns standard TradeAlertWriter compatible structure:
 * [
 *   'ok' => bool,
 *   'best_entry' => array|null,
 *   'candidates' => array,
 *   'filter_reason' => string|null,
 *   'meta' => array
 * ]
 */
class OneMinuteEntryFinderV210_0
{
    use HasPriceTables;

    private string $version = 'v210.0';

    public function getVersion(): string
    {
        return $this->version;
    }

    public function findBestLong(
        string $symbol,
        string $assetType,
        string $signalTsEst,
        string $asOfTsEst,
        $beforeMinutesOrOpts = 20,
        int $afterMinutes = 0,
        int $volLookback = 20,
        int $pivotLookback = 8,
        string $fillModel = 'next_open',
        array $signalMeta = [],
        int $staleMinutes = 0  // Not used but needed for pipeline compatibility
    ): array {
        // Normalize options
        if (is_array($beforeMinutesOrOpts)) {
            $opts = $beforeMinutesOrOpts;
            $beforeMinutes = (int) ($opts['beforeMinutes'] ?? 20);
            $afterMinutes = (int) ($opts['afterMinutes'] ?? 0);
            $volLookback = (int) ($opts['volLookback'] ?? 20);
            $pivotLookback = (int) ($opts['pivotLookback'] ?? 8);
            $fillModel = (string) ($opts['fillModel'] ?? 'next_open');
            $signalMeta = (array) ($opts['signalMeta'] ?? []);
        } else {
            $beforeMinutes = (int) $beforeMinutesOrOpts;
        }

        // Make meta robust
        $meta = $signalMeta['meta'] ?? $signalMeta;
        $pattern = (string) ($meta['pattern'] ?? 'UNKNOWN');

        if ($pattern !== 'OVERSOLD_BOUNCE') {
            return [
                'ok' => false,
                'symbol' => $symbol,
                'asset_type' => $assetType,
                'signal_ts_est' => $signalTsEst,
                'filter_reason' => 'Unsupported pattern (expected OVERSOLD_BOUNCE). Got: '.$pattern,
                'meta' => ['pattern' => $pattern, 'meta' => $meta],
            ];
        }

        // Extract ATR from signal meta (calculated on 5m bars)
        $signal5mAtr = (float) ($signalMeta['atr'] ?? 0);
        $signal5mAtrPct = (float) ($signalMeta['atr_pct'] ?? 0);

        $cfg = fn (string $k, $d) => config("trading.v210.$k", $d);

        $maxRiskPct = (float) $cfg('max_risk_pct', 1.0);
        $stopLossPct = (float) $cfg('stop_loss_pct', 0.8);
        $targetMultiple = (float) $cfg('target_multiple', 2.5);
        $minVolRatio = (float) $cfg('min_vol_ratio_1m', 1.3);
        $maxVolRatio = (float) $cfg('max_vol_ratio_1m', 15.0);
        $minRsi = (float) $cfg('min_rsi_14_1m', 30.0);
        $maxRsi = (float) $cfg('max_rsi_14_1m', 70.0);
        $minEntryHour = (int) $cfg('min_entry_hour', 10);
        $maxEntryHour = (int) $cfg('max_entry_hour', 14);
        $minScore = (float) $cfg('min_score', 120.0);

        $analysisStart = date('Y-m-d H:i:s', strtotime($asOfTsEst." -{$beforeMinutes} minutes"));
        $analysisEnd = $asOfTsEst;

        // Don't allow entries before market open for stocks
        if ($assetType === 'stock') {
            $tradeDate = substr($signalTsEst, 0, 10);
            $marketOpen = $tradeDate.' 09:30:00';
            if ($analysisStart < $marketOpen) {
                $analysisStart = $marketOpen;
            }

            // Apply time restrictions - avoid poor performing hours
            $entryHour = (int) date('G', strtotime($asOfTsEst));
            if ($entryHour < $minEntryHour || $entryHour > $maxEntryHour) {
                return [
                    'ok' => false,
                    'symbol' => $symbol,
                    'asset_type' => $assetType,
                    'signal_ts_est' => $signalTsEst,
                    'filter_reason' => "Entry hour {$entryHour} outside allowed range {$minEntryHour}-{$maxEntryHour}",
                    'meta' => ['entry_hour' => $entryHour, 'min_hour' => $minEntryHour, 'max_hour' => $maxEntryHour],
                ];
            }
        }

        $bars = $this->get1mBars($symbol, $assetType, $analysisStart, $analysisEnd);
        if (count($bars) < 3) {
            return [
                'ok' => false,
                'symbol' => $symbol,
                'asset_type' => $assetType,
                'signal_ts_est' => $signalTsEst,
                'filter_reason' => 'Insufficient 1m bars in analysis window (need 3+, got '.count($bars).')',
                'meta' => ['analysisStart' => $analysisStart, 'analysisEnd' => $analysisEnd],
            ];
        }

        $computeFill = function (int $i) use ($bars, $fillModel): array {
            $cur = $bars[$i];
            $next = $bars[$i + 1] ?? null;

            // Safety check: ensure "next" bar is from the same trading date to prevent time travel bugs
            if ($fillModel === 'next_open' && $next) {
                $curTradingDate = substr((string) $cur->ts_est, 0, 10);
                $nextTradingDate = substr((string) $next->ts_est, 0, 10);

                if ($curTradingDate === $nextTradingDate) {
                    return [(string) $next->ts_est, (float) $next->open];
                }
            }

            return [(string) $cur->ts_est, (float) $cur->price];
        };

        // Get 5m signal meta data
        $pullbackLow = (float) ($meta['pullback_low'] ?? 0);
        $currentPrice5m = (float) ($meta['current_price'] ?? 0);
        $declinePct = (float) ($meta['decline_pct'] ?? 0);

        $candidates = [];

        // Strategy: Find first bounce bar after low is established
        $lowestLow = PHP_FLOAT_MAX;
        $lowestBarIndex = -1;

        // First pass: find the lowest low in recent bars
        for ($i = count($bars) - 1; $i >= 0; $i--) {
            $bar = $bars[$i];
            $barLow = (float) $bar->low;

            if ($barLow < $lowestLow) {
                $lowestLow = $barLow;
                $lowestBarIndex = $i;
            }
        }

        if ($lowestBarIndex < 0) {
            return [
                'ok' => false,
                'symbol' => $symbol,
                'asset_type' => $assetType,
                'signal_ts_est' => $signalTsEst,
                'filter_reason' => 'No valid low found in bars',
                'meta' => ['lowestBarIndex' => $lowestBarIndex],
            ];
        }

        // Precompute volume prefix sums (bars are DESC-ordered) for O(1) per-bar ratio lookups.
        $barCount = count($bars);
        $volPrefixSum = array_fill(0, $barCount + 1, 0.0);
        for ($k = 0; $k < $barCount; $k++) {
            $volPrefixSum[$k + 1] = $volPrefixSum[$k] + (float) ($bars[$k]->volume ?? 0);
        }
        $getVolRatio = function (int $idx) use ($bars, $volPrefixSum, $barCount, $volLookback): float {
            $curVol = (float) ($bars[$idx]->volume ?? 0);
            if ($curVol === 0.0) {
                return 0.0;
            }
            $start = $idx + 1;
            $end = min($idx + $volLookback, $barCount - 1);
            $n = $end - $start + 1;
            if ($n < 5) {
                return 1.0;
            }
            $sumVol = $volPrefixSum[$end + 1] - $volPrefixSum[$start];
            $avgVol = $sumVol / $n;

            return $avgVol > 0 ? $curVol / $avgVol : 0.0;
        };

        // Look for bounce bars after the low
        for ($i = $lowestBarIndex - 1; $i >= 0; $i--) {
            $bar = $bars[$i];
            $barClose = (float) $bar->price;
            $barOpen = (float) $bar->open;
            $barHigh = (float) $bar->high;
            $barLow = (float) $bar->low;
            $barVolume = (int) $bar->volume;

            // Look for bounce from low (doesn't need to be green)
            $bounceFromLow = $lowestLow > 0 ? (($barClose - $lowestLow) / $lowestLow) * 100 : 0;

            // Calculate entry, stop, and targets
            [$entryTsEst, $entryPrice] = $computeFill($i);
            $stopPrice = $lowestLow * (1 - 0.001); // Just below the low
            $riskPerShare = $entryPrice - $stopPrice;
            $riskPct = $riskPerShare > 0 ? ($riskPerShare / $entryPrice) * 100 : 0;

            // Filter by risk (allow entries at or near the low)
            if ($riskPct > $maxRiskPct) {
                continue;
            }

            $target1 = $entryPrice + ($riskPerShare * $targetMultiple);
            $target2 = $entryPrice + ($riskPerShare * $targetMultiple * 1.5);
            $target3 = $entryPrice + ($riskPerShare * $targetMultiple * 2.0);

            // Calculate volume ratio and RSI for scoring (not filtering)
            $volRatio = $getVolRatio($i);
            $rsi = $this->calculateRsi($bars, $i, 14);

            // Use ATR from 5-minute signal (more reliable than 1m bars)
            // If not available, calculate from 1m bars as fallback
            if ($signal5mAtr > 0) {
                $atr = $signal5mAtr;
                $atrPct = $signal5mAtrPct;
            } else {
                $atr = $this->calculateATR($bars, min(14, count($bars)));
                $atrPct = $entryPrice > 0 ? ($atr / $entryPrice) * 100 : 0;
            }
            $atrMultiplier = \App\Services\TradingSettingService::getStopLossAtrMultiplier();
            $minStopPct = \App\Services\TradingSettingService::getStopLossAtrMinPct();
            $maxStopPct = \App\Services\TradingSettingService::getStopLossAtrMaxPct();
            $calculatedPct = $entryPrice > 0 ? (($atr * $atrMultiplier) / $entryPrice) * 100 : $maxStopPct;
            $suggestedTrailingStopPct = max($minStopPct, min($maxStopPct, $calculatedPct));
            $suggestedTrailingStop = $entryPrice > 0 ? ($entryPrice * ($suggestedTrailingStopPct / 100.0)) : null;

            // Calculate standardized entry score (0-100)
            $entryScore = $this->computeEntryScore($bars[$i]);

            $candidates[] = [
                'type' => 'BOUNCE_ENTRY',
                'entry_ts_est' => $entryTsEst,
                'entry' => round($entryPrice, 4),
                'stop' => round($stopPrice, 4),
                'risk_pct' => round($riskPct, 4),
                'risk_per_share' => round($riskPerShare, 6),
                'targets' => [
                    '1R' => round($target1, 4),
                    '2R' => round($target2, 4),
                    '3R' => round($target3, 4),
                ],
                'score' => round($entryScore, 2),
                'vol_ratio' => round($volRatio, 2),
                'rsi_14_1m' => round($rsi, 2),
                'atr' => round($atr, 6),
                'atr_pct' => round($atrPct, 4),
                'suggested_trailing_stop' => round($suggestedTrailingStop, 6),
                'suggested_trailing_stop_pct' => round($suggestedTrailingStopPct, 4),
                'bounce_from_low_pct' => round($bounceFromLow, 2),
                'lowest_low' => round($lowestLow, 4),
                'bar_index' => $i,
            ];
        }

        if (empty($candidates)) {
            return [
                'ok' => false,
                'symbol' => $symbol,
                'asset_type' => $assetType,
                'signal_ts_est' => $signalTsEst,
                'filter_reason' => 'No valid bounce entry found after low',
                'candidates' => [],
                'meta' => ['lowest_low' => $lowestLow],
            ];
        }

        // Sort by score descending
        usort($candidates, fn ($a, $b) => ($b['score'] ?? 0) <=> ($a['score'] ?? 0));

        // Apply minimum score filter to best candidate
        $bestEntry = $candidates[0];
        if ($bestEntry['score'] < $minScore) {
            return [
                'ok' => false,
                'symbol' => $symbol,
                'asset_type' => $assetType,
                'signal_ts_est' => $signalTsEst,
                'filter_reason' => "Best entry score {$bestEntry['score']} below minimum {$minScore}",
                'candidates' => $candidates,
                'meta' => ['best_score' => $bestEntry['score'], 'min_score' => $minScore],
            ];
        }

        return [
            'ok' => true,
            'symbol' => $symbol,
            'asset_type' => $assetType,
            'signal_ts_est' => $signalTsEst,
            'best_entry' => $bestEntry,
            'candidates' => $candidates,
            'meta' => [
                'pattern' => $pattern,
                'decline_pct' => $declinePct,
                'total_candidates' => count($candidates),
            ],
        ];
    }

    private function get1mBars(string $symbol, string $assetType, string $startTsEst, string $endTsEst): array
    {
        // Extract trading_date_est from timestamp to prevent time travel data leaks
        $tradingDate = substr($startTsEst, 0, 10);

        return $this->dbSelect('
            SELECT *,
                AVG(volume) OVER (
                    PARTITION BY symbol, asset_type
                    ORDER BY ts_est
                    ROWS BETWEEN 20 PRECEDING AND 1 PRECEDING
                ) AS avg_vol_20
            FROM one_minute_prices
            WHERE symbol = ?
              AND asset_type = ?
              AND trading_date_est = ?
              AND ts_est >= ?
              AND ts_est <= ?
            ORDER BY ts_est DESC
            LIMIT 200
        ', [$symbol, $assetType, $tradingDate, $startTsEst, $endTsEst]);
    }

    private function calculateVolumeRatio(array $bars, int $currentIndex, int $lookback): float
    {
        if ($currentIndex >= count($bars) - 1) {
            return 0.0;
        }

        $currentVolume = (int) $bars[$currentIndex]->volume;

        if ($currentVolume === 0) {
            return 0.0;
        }

        // Calculate average volume from lookback period
        $lookbackBars = array_slice($bars, $currentIndex + 1, $lookback);

        if (count($lookbackBars) < 5) {
            return 1.0;
        }

        $avgVolume = array_sum(array_map(fn ($bar) => (int) $bar->volume, $lookbackBars)) / count($lookbackBars);

        if ($avgVolume === 0) {
            return 0.0;
        }

        return $currentVolume / $avgVolume;
    }

    private function calculateRsi(array $bars, int $currentIndex, int $period = 14): float
    {
        // Need enough bars for RSI calculation
        if ($currentIndex + $period + 1 >= count($bars)) {
            return 50.0; // Neutral default
        }

        $prices = [];
        for ($i = $currentIndex; $i < min($currentIndex + $period + 1, count($bars)); $i++) {
            $prices[] = (float) $bars[$i]->price;
        }

        if (count($prices) < $period + 1) {
            return 50.0;
        }

        $gains = [];
        $losses = [];

        for ($i = 0; $i < count($prices) - 1; $i++) {
            $change = $prices[$i] - $prices[$i + 1];

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
            return 100.0;
        }

        $rs = $avgGain / $avgLoss;
        $rsi = 100 - (100 / (1 + $rs));

        return $rsi;
    }

    private function calculateATR(array $bars, int $period = 14): float
    {
        if (count($bars) < $period + 1) {
            return 0.0;
        }

        $trueRanges = [];

        for ($i = count($bars) - 1; $i > count($bars) - $period - 1; $i--) {
            if ($i >= count($bars) - 1) {
                continue;
            }

            $high = (float) $bars[$i]->high;
            $low = (float) $bars[$i]->low;
            $prevClose = (float) $bars[$i + 1]->price;

            $tr = max(
                $high - $low,
                abs($high - $prevClose),
                abs($low - $prevClose)
            );

            $trueRanges[] = $tr;
        }

        if (empty($trueRanges)) {
            return 0.0;
        }

        return array_sum($trueRanges) / count($trueRanges);
    }

    /**
     * Standardized entry score formula (0-100)
     * Same formula used across all pipelines for ML training consistency
     */
    private function computeEntryScore(object $b): float
    {
        $price = (float) ($b->price ?? 0);
        if ($price <= 0) {
            return 0.0;
        }

        $emaSpread = (float) ($b->ema9_ema21_spread ?? 0);
        $spreadFrac = $emaSpread / $price;
        $spread_strength = $this->clamp(($spreadFrac - 0.0005) / (0.0030 - 0.0005));

        $vwap_dist_pct = (float) ($b->vwap_dist_pct ?? 0);
        $vwap_dist_score = max(0.0, 1.0 - (abs($vwap_dist_pct - 0.15) / 0.30));

        $atr_pct = (float) ($b->atr_pct ?? 0);
        $atr_low_ok = $this->clamp(($atr_pct - 0.08) / (0.20 - 0.08));
        $atr_high_pen = $this->clamp(($atr_pct - 0.50) / (1.50 - 0.50));
        $atr_score = $atr_low_ok * (1.0 - $atr_high_pen);

        $avg_vol_20 = (float) ($b->avg_vol_20 ?? 0);
        $vol = (float) ($b->volume ?? 0);
        $vol_ratio = ($avg_vol_20 > 0) ? ($vol / $avg_vol_20) : 0.0;
        $vol_score = ($avg_vol_20 > 0)
            ? $this->clamp(($vol_ratio - 0.8) / (2.5 - 0.8))
            : 0.0;

        $high = (float) ($b->high ?? 0);
        $low = (float) ($b->low ?? 0);
        $candle_score = 0.0;
        if ($high > $low) {
            $pos = ($price - $low) / ($high - $low);
            $candle_score = $this->clamp(($pos - 0.45) / (0.80 - 0.45));
        }

        $ema9_above_ema21 = (float) ((int) ($b->ema9_above_ema21 ?? 0));
        $above_vwap = (float) ((int) ($b->above_vwap ?? 0));

        $ts = (string) ($b->ts_est ?? '');
        $time_bonus = 0.0;
        if ($ts) {
            $timeStr = substr($ts, 11, 8);
            if ($timeStr <= '10:30:00') {
                $time_bonus = 1.0;
            } elseif ($timeStr <= '11:00:00') {
                $time_bonus = 0.5;
            }
        }

        $S_trend = 0.70 * $ema9_above_ema21 + 0.30 * $spread_strength;
        $S_vwap = $above_vwap * $vwap_dist_score;

        $final = 100.0 * (
            0.30 * $S_trend +
            0.25 * $S_vwap +
            0.10 * $atr_score +
            0.20 * $vol_score +
            0.10 * $candle_score +
            0.05 * $time_bonus
        );

        return round($final, 2);
    }

    private function clamp(float $x, float $lo = 0.0, float $hi = 1.0): float
    {
        if ($x < $lo) {
            return $lo;
        }
        if ($x > $hi) {
            return $hi;
        }

        return $x;
    }
}
