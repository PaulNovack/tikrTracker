<?php

namespace App\Services\Trading;

/**
 * OneMinuteEntryFinderV200_0 - TPB 1m entries (compatible with TradeAlertWriterV1)
 *
 * Returns:
 *  [
 *    'ok' => bool,
 *    'best_entry' => array|null,
 *    'candidates' => array,
 *    'filter_reason' => string|null,
 *    'meta' => array
 *  ]
 *
 * Supports legacy-ish signature:
 *  findBestLong($symbol,$assetType,$signalTsEst,$asOfTsEst,$before,$after,$volLookback,$pivotLookback,$fillModel,$signalMeta)
 *
 * And "opts" signature:
 *  findBestLong($symbol,$assetType,$signalTsEst,$asOfTsEst, ['beforeMinutes'=>.., 'signalMeta'=>.., ...])
 */
class OneMinuteEntryFinderV200_0
{
    use HasPriceTables;

    private string $version = 'v200.0';

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
        int $freshnessMinutes = 6 // Maximum age for entries to be considered fresh
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

        // Make meta robust: allow passing whole $signal or just $signal['meta']
        $meta = $signalMeta['meta'] ?? $signalMeta;
        $pattern = (string) ($meta['pattern'] ?? 'UNKNOWN');

        if ($pattern !== 'TPB') {
            return [
                'ok' => false,
                'symbol' => $symbol,
                'asset_type' => $assetType,
                'signal_ts_est' => $signalTsEst,
                'filter_reason' => 'Unsupported/unknown pattern (expected TPB). Got: '.$pattern,
                'meta' => ['pattern' => $pattern, 'meta' => $meta],
            ];
        }

        $cfg = fn (string $k, $d) => config("trading.v200.$k", $d);

        $maxRiskPct = (float) $cfg('max_risk_pct', 2.50);
        $minAtrPct1m = (float) $cfg('min_atr_pct_1m', 0.05);
        $maxAtrPct1m = (float) $cfg('max_atr_pct_1m', 4.00);
        $minBreakPct = (float) $cfg('min_breakout_pct_1m', 0.03);
        $minVolRatio = (float) $cfg('min_vol_ratio_1m', 1.20);
        $maxVolRatio = (float) $cfg('max_vol_ratio_1m', 15.0);
        $minRsi = (float) $cfg('min_rsi_14_1m', 45.0);
        $maxRsi = (float) $cfg('max_rsi_14_1m', 90.0);
        $minMinutesAfterSignal = (int) $cfg('min_minutes_after_signal', 2);
        $maxEntryHour = (int) $cfg('max_entry_hour', 14);

        $analysisStart = date('Y-m-d H:i:s', strtotime($asOfTsEst." -{$beforeMinutes} minutes"));
        $analysisEnd = date('Y-m-d H:i:s', strtotime($asOfTsEst." +{$afterMinutes} minutes"));

        // Optional: don't allow entries before market open for stocks
        $tradeDate = substr($signalTsEst, 0, 10);
        if ($assetType === 'stock') {
            $marketOpen = $tradeDate.' 09:30:00';
            if ($analysisStart < $marketOpen) {
                $analysisStart = $marketOpen;
            }
        }

        $bars = $this->get1mBars($symbol, $assetType, $tradeDate, $analysisStart, $analysisEnd);
        if (count($bars) < 12) {
            return [
                'ok' => false,
                'symbol' => $symbol,
                'asset_type' => $assetType,
                'signal_ts_est' => $signalTsEst,
                'filter_reason' => 'Insufficient 1m bars in analysis window',
                'meta' => ['analysisStart' => $analysisStart, 'analysisEnd' => $analysisEnd],
            ];
        }

        // 5m trend map (ema9_above_ema21) to avoid entries against 5m direction
        $trendMap = $this->get5mTrendMap($symbol, $assetType, $tradeDate, $analysisStart, $analysisEnd);

        $is5mUp = function (string $ts1m) use ($trendMap): bool {
            $relevant = null;
            foreach ($trendMap as $ts5m => $val) {
                if ($ts5m <= $ts1m) {
                    $relevant = $val;
                } else {
                    break;
                }
            }

            return $relevant === 1;
        };

        $computeFill = function (int $i) use ($bars, $fillModel): array {
            $cur = $bars[$i];
            $next = $bars[$i + 1] ?? null;

            if ($fillModel === 'next_open' && $next) {
                // Safety check: prevent time travel bugs by verifying same trading date
                $curDate = substr((string) $cur->ts_est, 0, 10);
                $nextDate = substr((string) $next->ts_est, 0, 10);
                if ($curDate !== $nextDate) {
                    return [(string) $cur->ts_est, (float) $cur->price];
                }

                return [(string) $next->ts_est, (float) $next->open];
            }

            return [(string) $cur->ts_est, (float) $cur->price];
        };

        // Meta levels from 5m scan
        $breakoutLevel5m = (float) ($meta['breakout_level'] ?? 0);
        $pullbackLow5m = (float) ($meta['pullback_low'] ?? 0);

        // We'll also allow a 1m pivot breakout even if 5m level is slightly off.
        $candidates = [];
        $reasons = [];

        // NEW STRATEGY: Track breakout, wait for pullback, enter on bounce
        $breakoutBarIndex = null;
        $breakoutHigh = null;

        for ($i = $pivotLookback + 2; $i < count($bars) - 1; $i++) {
            $cur = $bars[$i];
            $ts = (string) $cur->ts_est;

            if (! $is5mUp($ts)) {
                continue;
            }

            $price = (float) ($cur->price ?? 0);
            $high = (float) ($cur->high ?? 0);
            $low = (float) ($cur->low ?? 0);
            $vwap = (float) ($cur->vwap ?? 0);
            $ema9 = (float) ($cur->ema9 ?? 0);
            $atr = (float) ($cur->atr ?? 0);
            $atrPct = (float) ($cur->atr_pct ?? 0);
            $rsi = (float) ($cur->rsi_14 ?? $cur->rsi ?? 0);

            if ($price <= 0 || $high <= 0 || $low <= 0 || $vwap <= 0 || $ema9 <= 0) {
                continue;
            }

            if ($atrPct > 0 && ($atrPct < $minAtrPct1m || $atrPct > $maxAtrPct1m)) {
                continue;
            }

            if ($rsi > 0 && ($rsi < $minRsi || $rsi > $maxRsi)) {
                continue;
            }

            // Build 1m pivot high from last N bars
            $pivotHigh = 0.0;
            for ($k = $i - $pivotLookback; $k < $i; $k++) {
                $pivotHigh = max($pivotHigh, (float) ($bars[$k]->high ?? 0));
            }

            if ($pivotHigh <= 0) {
                continue;
            }

            // Breakout condition: detect initial breakout
            $req = $pivotHigh * (1.0 + ($minBreakPct / 100.0));
            $hasBreakout = $high > $req;

            // Track the first breakout bar
            if ($hasBreakout && $breakoutBarIndex === null) {
                $breakoutBarIndex = $i;
                $breakoutHigh = $high;
                // Continue to allow entry on breakout bar itself if it meets all criteria
            }

            // FLEXIBLE ENTRY: Allow entry either on breakout OR after pullback+bounce

            // Must still be above VWAP and EMA9 (trend support on 1m)
            if ($price <= $vwap || $price <= $ema9) {
                continue;
            }

            // Determine entry type and quality
            $entryType = 'BREAKOUT';
            $pullbackDepth = 0.0;

            if ($breakoutBarIndex !== null && $i > $breakoutBarIndex) {
                // We've seen a breakout - check if this is a bounce after pullback
                // Pullback = any bar after breakout with low below 99.5% of breakout high
                // But still above support (VWAP or EMA9 - more flexible than pivot)
                $hasPulledBack = ($low < $breakoutHigh * 0.995);

                if ($hasPulledBack) {
                    // This is a bounce candidate - check for higher low vs previous bar
                    $prev = $bars[$i - 1];
                    $prevLow = (float) ($prev->low ?? 0);

                    $isHigherLow = $prevLow > 0 && $low >= $prevLow * 0.9995; // Very slight higher low or equal

                    if ($isHigherLow) {
                        $entryType = 'PULLBACK_BOUNCE';
                        $pullbackDepth = (($breakoutHigh - $low) / $breakoutHigh) * 100.0;
                    }
                }
            } elseif ($hasBreakout) {
                // This IS the breakout bar - allow entry
                $entryType = 'BREAKOUT';
                $pullbackDepth = 0.0;
            } else {
                // No breakout yet, skip
                continue;
            }

            // Not too extended above VWAP at entry
            $maxVwapExtEntry = (float) $cfg('max_vwap_extension_entry_pct', 0.50);
            $vwapExtPct = (($price - $vwap) / $vwap) * 100.0;
            if ($vwapExtPct > $maxVwapExtEntry) {
                continue;
            }

            // If 5m breakout level exists, ensure we're still above it (soft check)
            $break5mOk = ($breakoutLevel5m > 0) ? ($low >= $breakoutLevel5m * 0.995) : true;
            if (! $break5mOk) {
                continue;
            }

            // Strong close requirement: close must be in top portion of candle
            $close = (float) ($cur->price ?? 0);
            $range = max(0.0000001, $high - $low);
            $closePos = ($close - $low) / $range;

            $minClosePos = (float) $cfg('min_close_pos', 0.70);
            if ($closePos < $minClosePos) {
                continue;
            }

            // Volume confirmation on the BOUNCE bar
            $pivotVolMedian = $this->medianVolume($bars, max(0, $i - $volLookback), $i - 1);
            $curVol = (float) ($cur->volume ?? 0);
            $volRatio = ($pivotVolMedian > 0) ? ($curVol / $pivotVolMedian) : 0.0;
            if ($volRatio < $minVolRatio || $volRatio > $maxVolRatio) {
                continue; // Block both too low AND extreme spikes
            }

            // Fill
            [$entryTs, $entryPx] = $computeFill($i);
            if ($entryPx <= 0) {
                continue;
            }

            // Time filters: avoid too early after signal and late day entries
            $minutesAfterSignal = (strtotime($entryTs) - strtotime($signalTsEst)) / 60;
            if ($minutesAfterSignal < $minMinutesAfterSignal) {
                continue; // Too soon - no confirmation
            }

            $entryHour = (int) date('H', strtotime($entryTs));
            if ($entryHour >= $maxEntryHour) {
                continue; // Late day - avoid chop
            }
            if ($entryPx <= 0) {
                continue;
            }

            // Stop: use tighter of (pullback low / recent swing low) and ATR-based,
            // then CLAMP to maxRiskPct (raise stop if needed) instead of rejecting.
            $swingLow = $this->recentSwingLow($bars, max(0, $i - $pivotLookback), $i);
            $refLow = $this->minPos($pullbackLow5m, $swingLow);

            $atrStop = $entryPx - (2.2 * max(0.00001, $atr));
            $structStop = ($refLow > 0) ? ($refLow * 0.999) : $atrStop; // small buffer
            $rawStop = max(0.01, min($atrStop, $structStop));

            // Clamp to max risk %
            $minStopAllowed = $entryPx * (1.0 - ($maxRiskPct / 100.0));
            $stopPx = max($rawStop, $minStopAllowed);

            $risk = $entryPx - $stopPx;
            if ($risk <= 0) {
                continue;
            }

            $riskPct = ($risk / $entryPx) * 100.0;

            // Resistance room check: require enough room to resistance (avoid late chases)
            $resistance5m = (float) ($meta['resistance_5m'] ?? 0);
            $roomR = 0.0;
            if ($resistance5m > 0 && $risk > 0) {
                $roomR = ($resistance5m - $entryPx) / $risk;
            }

            $minRoomR = (float) $cfg('min_room_r', 1.00);
            if ($roomR < $minRoomR) {
                continue;
            }

            // Score: base + volume + entry type bonus + breakout strength + VWAP + RSI - time penalty
            $breakPct = (($entryPx - $pivotHigh) / $pivotHigh) * 100.0;
            $extPct = (($entryPx - $vwap) / $vwap) * 100.0;

            // Entry type bonus: Pullback+bounce entries get bonus points (better quality)
            $entryTypeBonus = 0.0;
            if ($entryType === 'PULLBACK_BOUNCE') {
                // Shallow pullback (0.1-0.5%) = higher bonus
                $entryTypeBonus = max(0, min(8, 8.0 - ($pullbackDepth * 10)));
            }

            $timePenalty = $this->timePenalty($ts, $assetType);

            $score = 60.0;
            $score += min(18, max(0, ($volRatio - 1.0) * 8)); // Volume surge
            $score += $entryTypeBonus; // Entry type quality (0-8 points for pullback+bounce)
            $score += min(10, max(0, $breakPct * 40)); // Breakout strength
            $score += max(0, 8 - max(0, $extPct) * 6); // Tight to VWAP
            if ($rsi > 0) {
                $score += min(6, max(0, ($rsi - 50.0) * 0.25)); // RSI momentum
            }
            $score -= $timePenalty;

            $score = max(0, min(100, $score));

            // Minimum score filter: only keep high-quality setups
            $minScore = (float) $cfg('min_score', 75.0);
            if ($score < $minScore) {
                continue;
            }

            // Targets
            $t1 = $entryPx + (1.0 * $risk);
            $t2 = $entryPx + (2.0 * $risk);
            $t3 = $entryPx + (3.0 * $risk);

            // Trailing stop suggestion: 3*ATR behind entry (as a PRICE)
            $trailDist = max(0.01, 3.0 * max(0.00001, $atr));
            $trailStopPx = max(0.01, $entryPx - $trailDist);
            $trailPct = ($trailDist / $entryPx) * 100.0;

            $candidates[] = [
                'type' => 'TPB_1M_ENTRY',
                'trigger_ts_est' => $ts,
                'entry_ts_est' => $entryTs,
                'entry' => round($entryPx, 4),
                'stop' => round($stopPx, 4),

                'risk_pct' => round($riskPct, 2),
                'risk_per_share' => round($risk, 6),

                'score' => round($score, 2),
                'vol_ratio' => round($volRatio, 2),

                // extra fields your writer supports
                'breakout_volume_ratio' => round($volRatio, 2),
                'consolidation_bars' => (int) ($meta['consolidation_bars_5m'] ?? 0),

                'five_min_directional_changes' => (int) ($meta['five_min_directional_changes'] ?? null),
                'five_min_green_bar_pct' => (float) ($meta['five_min_green_bar_pct'] ?? null),
                'five_min_net_progress' => (float) ($meta['five_min_net_progress'] ?? null),

                'atr' => round($atr, 6),
                'atr_pct' => round($atrPct, 3),
                'rsi' => ($rsi > 0 ? round($rsi, 2) : null),

                'suggested_trailing_stop' => round($trailStopPx, 4),
                'suggested_trailing_stop_pct' => round($trailPct, 2),

                'targets' => [
                    '1R' => round($t1, 4),
                    '2R' => round($t2, 4),
                    '3R' => round($t3, 4),
                ],

                'note' => sprintf(
                    'TPB %s: pivot=%.4f entry=%.4f vol=%.2fx room=%.2fR%s',
                    $entryType,
                    $pivotHigh,
                    $entryPx,
                    $volRatio,
                    $roomR,
                    $entryType === 'PULLBACK_BOUNCE' ? sprintf(' pullback=%.2f%%', $pullbackDepth) : ''
                ),

                // Keep raw meta for post-mortem
                'meta' => [
                    'pattern' => $entryType,
                    'pivot_high_1m' => $pivotHigh,
                    'breakout_high' => $breakoutHigh ?? $high,
                    'pullback_depth_pct' => $pullbackDepth,
                    'entry_type' => $entryType,
                    'breakout_level_5m' => $breakoutLevel5m,
                    'pullback_low_5m' => $pullbackLow5m,
                    'swing_low_1m' => $swingLow,
                    'vwap_1m' => $vwap,
                    'ema9_1m' => $ema9,
                    'time_penalty' => $timePenalty,
                    'resistance_5m' => $resistance5m,
                    'room_r' => $roomR,
                ],
            ];
        }

        if (empty($candidates)) {
            return [
                'ok' => false,
                'symbol' => $symbol,
                'asset_type' => $assetType,
                'signal_ts_est' => $signalTsEst,
                'filter_reason' => 'No qualifying TPB entries (trend/levels/vol/risk gates)',
                'meta' => [
                    'pattern' => $pattern,
                    'analysisStart' => $analysisStart,
                    'analysisEnd' => $analysisEnd,
                    'meta' => $meta,
                ],
            ];
        }

        // Filter out stale entries - only allow fresh entries within configured minutes
        $candidates = array_filter($candidates, function ($c) use ($asOfTsEst, $freshnessMinutes) {
            $entryTime = strtotime($c['entry_ts_est']);
            $currentTime = strtotime($asOfTsEst);
            $ageMinutes = ($currentTime - $entryTime) / 60;

            return $ageMinutes <= $freshnessMinutes;
        });

        if (empty($candidates)) {
            return [
                'ok' => false,
                'symbol' => $symbol,
                'asset_type' => $assetType,
                'signal_ts_est' => $signalTsEst,
                'filter_reason' => "No entries within last {$freshnessMinutes} minutes (all stale)",
            ];
        }

        usort($candidates, fn ($a, $b) => ($b['score'] ?? 0) <=> ($a['score'] ?? 0));
        $best = $candidates[0];

        return [
            'ok' => true,
            'symbol' => $symbol,
            'asset_type' => $assetType,
            'signal_ts_est' => $signalTsEst,
            'best_entry' => $best,
            'candidates' => $candidates,
            'meta' => [
                'pattern' => $pattern,
                'meta' => $meta,
            ],
        ];
    }

    private function get1mBars(string $symbol, string $assetType, string $tradeDate, string $from, string $to): array
    {
        return $this->dbSelect('
            SELECT
              ts_est,
              price,
              `open`,
              high,
              low,
              volume,
              vwap,
              ema9,
              ema21,
              atr,
              atr_pct,
              NULL AS rsi_14
            FROM one_minute_prices
            WHERE symbol = ?
              AND asset_type = ?
              AND trading_date_est = ?
              AND ts_est >= ?
              AND ts_est <= ?
            ORDER BY ts_est ASC
        ', [$symbol, $assetType, $tradeDate, $from, $to]);
    }

    private function get5mTrendMap(string $symbol, string $assetType, string $tradeDate, string $from, string $to): array
    {
        $rows = $this->dbSelect('
            SELECT
              ts_est,
              CASE WHEN ema9 IS NOT NULL AND ema21 IS NOT NULL AND ema9 > ema21 THEN 1 ELSE 0 END AS trend_up
            FROM five_minute_prices
            WHERE symbol = ?
              AND asset_type = ?
              AND trading_date_est = ?
              AND ts_est >= ?
              AND ts_est <= ?
            ORDER BY ts_est ASC
        ', [$symbol, $assetType, $tradeDate, $from, $to]);

        $map = [];
        foreach ($rows as $r) {
            $map[(string) $r->ts_est] = (int) ($r->trend_up ?? 0);
        }

        return $map;
    }

    private function medianVolume(array $bars, int $startIdx, int $endIdx): float
    {
        $startIdx = max(0, $startIdx);
        $endIdx = min(count($bars) - 1, $endIdx);
        if ($endIdx <= $startIdx) {
            return 0.0;
        }

        $vals = [];
        for ($i = $startIdx; $i <= $endIdx; $i++) {
            $v = (float) ($bars[$i]->volume ?? 0);
            if ($v > 0) {
                $vals[] = $v;
            }
        }
        if (empty($vals)) {
            return 0.0;
        }

        sort($vals);
        $n = count($vals);
        $mid = intdiv($n, 2);
        if ($n % 2 === 1) {
            return $vals[$mid];
        }

        return ($vals[$mid - 1] + $vals[$mid]) / 2.0;
    }

    private function recentSwingLow(array $bars, int $startIdx, int $endIdx): float
    {
        $startIdx = max(0, $startIdx);
        $endIdx = min(count($bars) - 1, $endIdx);
        $min = 999999.0;

        for ($i = $startIdx; $i <= $endIdx; $i++) {
            $lo = (float) ($bars[$i]->low ?? 0);
            if ($lo > 0) {
                $min = min($min, $lo);
            }
        }

        return ($min >= 999999.0) ? 0.0 : $min;
    }

    private function minPos(float $a, float $b): float
    {
        if ($a > 0 && $b > 0) {
            return min($a, $b);
        }

        return ($a > 0) ? $a : (($b > 0) ? $b : 0.0);
    }

    private function timePenalty(string $ts, string $assetType): float
    {
        // For stocks: penalize lunchtime chop without hard-blocking.
        if ($assetType !== 'stock') {
            return 0.0;
        }

        $t = strtotime($ts);
        if (! $t) {
            return 0.0;
        }

        $hour = (int) date('G', $t);
        $minute = (int) date('i', $t);
        $m = $hour * 60 + $minute;

        // 9:30-10:45 best (0 penalty)
        if ($m >= 570 && $m <= 645) {
            return 0.0;
        }

        // 10:45-12:00 ok (small penalty)
        if ($m > 645 && $m <= 720) {
            return 4.0;
        }

        // 12:00-14:00 chop zone (bigger penalty)
        if ($m > 720 && $m < 840) {
            return 10.0;
        }

        // 14:00-15:30 good (small penalty)
        if ($m >= 840 && $m <= 930) {
            return 3.0;
        }

        // 15:30-16:00 can be wild (medium penalty)
        if ($m > 930 && $m <= 960) {
            return 6.0;
        }

        return 8.0;
    }
}
