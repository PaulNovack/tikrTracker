<?php

namespace App\Services\Trading;

/**
 * One-Minute Entry Finder V700.0 - Risk-Off Winners LONG Entries
 *
 * NOTE: Keeps the same class + findBestShort() signature for compatibility.
 * Internally, it now finds LONG entries for "risk-off winners":
 * - Pullback to VWAP/EMA9 that holds + reclaim
 * - Higher-low forms + break of micro base high
 * - 1m base breakout with volume (preferably above VWAP)
 *
 * Stops: Below pullback low or VWAP - buffer (0.8-1.2× 1m ATR)
 * Targets: +1R, +2R, +3R, and +2/+3/+4% targets
 * Time stop: 5-10 minutes if no follow-through
 */
class OneMinuteEntryFinderV700_0
{
    use HasPriceTables;

    private string $version = 'v700.0';

    public function getVersion(): string
    {
        return $this->version;
    }

    /**
     * Main entry finder method for LONG positions.
     * Returns LONG entry candidates (side=long).
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
        string $fillModel = 'next_open' // next_open|close
    ): array {
        $minScore = (float) config('trading.v700.entry_score_min', 80);
        $maxScore = (float) config('trading.v700.entry_score_max', 100);
        if ($maxScore <= 0) {
            $maxScore = 100.0;
        }
        if ($minScore > $maxScore) {
            [$minScore, $maxScore] = [$maxScore, $minScore];
        }

        // LONG entry gates
        $minAtrPct = (float) config('trading.v700.entry_min_atr_pct', 0.20);
        $minVolRatio = (float) config('trading.v700.entry_min_vol_ratio', 1.3);
        $maxHour = (int) config('trading.v700.max_entry_hour', 14);

        $minRsi = (float) config('trading.v700.entry_min_rsi', 50);
        $maxRsi = (float) config('trading.v700.entry_max_rsi', 78);

        // Entry search window: look forward from signal time, not from market open
        // This prevents forward-looking bias in backtesting
        $maxEntryWindowMinutes = 30; // Only look 30 minutes ahead of signal

        $signalTime = strtotime($signalTsEst);
        $asOfTime = strtotime($asOfTsEst);

        // Search end: min of (now, signal + 30 min)
        $searchEndTime = min($asOfTime, $signalTime + ($maxEntryWindowMinutes * 60));
        $analysisEnd = date('Y-m-d H:i:s', $searchEndTime);

        // Search start: need historical context, so go back $beforeMinutes from signal
        $analysisStart = date('Y-m-d H:i:s', $signalTime - ($beforeMinutes * 60));

        $tradeDate = substr($signalTsEst, 0, 10);
        $marketOpen = $tradeDate.' 09:30:00';
        if ($analysisStart < $marketOpen) {
            $analysisStart = $marketOpen;
        }

        // Get 1m bars: from (signal - beforeMinutes) to (signal + 30min or now)
        $from = $analysisStart;
        $to = $analysisEnd;

        // Get 1m bars
        $bars = $this->dbSelect('
            SELECT
              ts_est,
              price,
              `open`,
              `high`,
              `low`,
              volume,
              vwap,
              vwap_dist_pct,
              above_vwap,
              ema9,
              ema21,
              ema9_ema21_spread,
              ema9_above_ema21,
              atr,
              atr_pct,
              AVG(volume) OVER (
                PARTITION BY symbol, asset_type
                ORDER BY ts_est
                ROWS BETWEEN 20 PRECEDING AND 1 PRECEDING
              ) AS avg_vol_20
            FROM one_minute_prices
            WHERE asset_type = ?
              AND symbol = ?
              AND trading_date_est = ?
              AND ts_est >= ?
              AND ts_est <= ?
            ORDER BY ts_est ASC
        ', [$assetType, $symbol, $tradeDate, $from, $to]);

        // V700 targets inverse/leveraged ETFs which often have sparse 1-minute data
        // Ultra-low threshold (3 bars) for ML training mode
        if (! $bars || count($bars) < 3) {
            return [
                'ok' => false,
                'error' => 'Not enough 1m data in range (market closed or missing bars).',
                'symbol' => $symbol,
                'asset_type' => $assetType,
                'range_est' => [$from, $to],
                'bars_found' => $bars ? count($bars) : 0,
            ];
        }

        // Validate data quality
        for ($i = 1; $i < count($bars); $i++) {
            $prevClose = (float) $bars[$i - 1]->price;
            $currentOpen = (float) $bars[$i]->open;
            if ($prevClose > 0 && (($currentOpen - $prevClose) / $prevClose) * 100.0 < -50.0) {
                return ['ok' => false, 'error' => 'Bad data - extreme drop', 'symbol' => $symbol];
            }
        }

        // Get 5m bars for trend/chop filter
        $fiveMinBars = $this->dbSelect('
            SELECT ts_est, open, high, low, price, ema9_above_ema21, above_vwap
            FROM five_minute_prices
            WHERE asset_type = ?
              AND symbol = ?
              AND trading_date_est = ?
              AND ts_est >= ?
              AND ts_est <= ?
            ORDER BY ts_est ASC
        ', [$assetType, $symbol, $tradeDate, $from, $to]);

        // Choppiness filter
        $choppiness = [];
        if (count($fiveMinBars) >= 6) {
            $recent5MinBars = array_slice($fiveMinBars, -12);
            $choppiness = $this->calculate5MinChoppiness($recent5MinBars);

            // Loosened choppiness filter for ML training - was >= 11, now >= 20
            // This allows more entries for ML to learn from
            if (($choppiness['directional_changes'] ?? 0) >= 20) {
                return [
                    'ok' => false,
                    'symbol' => $symbol,
                    'asset_type' => $assetType,
                    'range_est' => [$from, $to],
                    'bars_found' => count($bars),
                    'filter_reason' => 'Excessive 5-minute choppiness',
                ];
            }
        }

        // HOD (high of day) for target context
        $hod = 0.0;
        foreach ($bars as $bar) {
            $high = (float) ($bar->high ?? 0);
            $hod = max($hod, $high);
        }

        $computeFill = function (int $i) use ($bars, $fillModel): array {
            if ($fillModel === 'close') {
                return [(string) $bars[$i]->ts_est, (float) $bars[$i]->price];
            }
            $next = $i + 1;
            if ($next >= count($bars)) {
                return [(string) $bars[$i]->ts_est, (float) $bars[$i]->price];
            }

            $curDate = substr((string) $bars[$i]->ts_est, 0, 10);
            $nextDate = substr((string) $bars[$next]->ts_est, 0, 10);
            if ($curDate !== $nextDate) {
                return [(string) $bars[$i]->ts_est, (float) $bars[$i]->price];
            }

            $o = (float) ($bars[$next]->open ?? 0);
            if ($o <= 0) {
                $o = (float) $bars[$next]->price;
            }

            return [(string) $bars[$next]->ts_est, $o];
        };

        $volAvgBefore = function (int $i) use ($bars, $volLookback): float {
            $start = max(0, $i - $volLookback);
            if ($start >= $i) {
                return 0.0;
            }
            $sum = 0.0;
            $n = 0;
            for ($k = $start; $k < $i; $k++) {
                $sum += (float) ($bars[$k]->volume ?? 0);
                $n++;
            }

            return $n > 0 ? $sum / $n : 0.0;
        };

        $inLiveWindow = function (string $ts) use ($analysisStart, $analysisEnd): bool {
            return $ts >= $analysisStart && $ts <= $analysisEnd;
        };

        $isTooLate = function (string $ts) use ($maxHour): bool {
            $hour = (int) substr($ts, 11, 2);

            return $hour >= $maxHour;
        };

        // Entry freshness check: In real-time, only accept fresh entries (5 min)
        // In backtest, allow entries within the search window (signal + maxEntryWindowMinutes)
        // This ensures real-time trading only acts on recent opportunities
        $maxRealtimeAgeMinutes = 5;
        $isTooOld = function (string $ts) use ($asOfTsEst, $signalTsEst, $maxRealtimeAgeMinutes, $maxEntryWindowMinutes): bool {
            $entryTime = strtotime($ts);
            $nowTime = strtotime($asOfTsEst);
            $signalTime = strtotime($signalTsEst);
            $ageSeconds = $nowTime - $entryTime;

            // If we're very close to signal time (within maxEntryWindowMinutes), use that as limit
            // This allows backtest to work with any step size
            $windowFromSignal = $nowTime - $signalTime;
            if ($windowFromSignal <= ($maxEntryWindowMinutes * 60)) {
                // We're in the entry window after signal - allow all entries
                return false;
            }

            // Otherwise, only accept entries from last 5 minutes (real-time mode)
            return $ageSeconds > ($maxRealtimeAgeMinutes * 60);
        };

        // Long readiness (0..1)
        $longReadiness = function (object $b, float $volRatio) use ($minAtrPct): float {
            $atrPct = (float) ($b->atr_pct ?? 0);
            $emaUp = (int) ($b->ema9_above_ema21 ?? 0);
            $aboveVwap = (int) ($b->above_vwap ?? 0);

            $atrComponent = $this->clamp(($atrPct - $minAtrPct) / (1.00 - $minAtrPct));
            $volComponent = $this->clamp(($volRatio - 1.2) / (3.0 - 1.2));
            $trendComponent = (0.6 * $emaUp) + (0.4 * $aboveVwap);

            return (0.45 * $atrComponent) + (0.35 * $volComponent) + (0.20 * $trendComponent);
        };

        $candidates = [];

        // -------------------------
        // A) Pullback to VWAP/EMA9 hold + reclaim (LONG)
        // -------------------------
        for ($i = 2; $i < count($bars); $i++) {
            $prev = $bars[$i - 1];
            $cur = $bars[$i];

            $ts = (string) $cur->ts_est;
            if (! $inLiveWindow($ts) || $isTooLate($ts) || $isTooOld($ts)) {
                continue;
            }

            $curPx = (float) ($cur->price ?? 0);
            $curV = (float) ($cur->vwap ?? 0);
            if ($curV <= 0) {
                continue;
            }

            $curLow = (float) ($cur->low ?? 0);
            $curHigh = (float) ($cur->high ?? 0);
            $ema9 = (float) ($cur->ema9 ?? 0);

            // "Held" VWAP/EMA9: dipped near/through but closed above VWAP
            // LOOSENED for ML training - was 1.001, now 1.01 (1% tolerance)
            $touched = ($curLow <= $curV * 1.01) || ($ema9 > 0 && $curLow <= $ema9 * 1.01);
            $closedAbove = ($curPx >= $curV * 0.99); // Allow 1% below VWAP

            if (! ($touched && $closedAbove)) {
                continue;
            }

            $baseVol = $volAvgBefore($i);
            $volRatio = ($baseVol > 0) ? ((float) ($cur->volume ?? 0) / $baseVol) : 0.0;
            // Removed lower limit - allow any volume for ML training
            if ($volRatio > 200.0) {
                continue;
            }

            $entryScore = $this->computeLongEntryScore($cur);
            if ($entryScore < $minScore || $entryScore > $maxScore) {
                continue;
            }

            $atrPct = (float) ($cur->atr_pct ?? 0);
            // Removed ATR minimum for ML training - let ML learn volatility patterns
            // if ($atrPct < $minAtrPct) { continue; }

            // RSI check removed - one_minute_prices doesn't have rsi_14 column

            $lr = $longReadiness($cur, $volRatio);

            [$entryTs, $entryPx] = $computeFill($i);

            $patternScore = min(4.0, $volRatio) + 1.6;

            $candidates[] = $this->makeLongCandidate(
                'VWAP_PULLBACK_HOLD',
                (string) $cur->ts_est,
                $entryTs,
                $entryPx,
                $cur,
                $entryScore,
                $patternScore,
                'Pullback to VWAP/EMA9 holds and reclaims - long entry.',
                $volRatio,
                $choppiness,
                $lr,
                $hod
            );
            break;
        }

        // -------------------------
        // B) Higher-low + break of micro base high (LONG)
        // -------------------------
        $idxSignal = 0;
        for ($i = 0; $i < count($bars); $i++) {
            if ((string) $bars[$i]->ts_est >= $signalTsEst) {
                $idxSignal = $i;
                break;
            }
        }

        // Find recent base high
        $baseHigh = 0.0;
        $baseStart = max(0, $idxSignal - max(3, $pivotLookback));
        for ($i = $baseStart; $i < $idxSignal; $i++) {
            $baseHigh = max($baseHigh, (float) ($bars[$i]->high ?? 0));
        }

        if ($baseHigh > 0) {
            for ($i = $idxSignal; $i < count($bars) - 1; $i++) {
                $cur = $bars[$i];
                $next = $bars[$i + 1];
                $ts = (string) $cur->ts_est;

                if (! $inLiveWindow($ts) || $isTooLate($ts) || $isTooOld($ts)) {
                    continue;
                }

                $curLow = (float) ($cur->low ?? 0);
                $nextHigh = (float) ($next->high ?? 0);

                // Higher low (relative to prior 3 bars) + break of baseHigh
                $prevLowMin = PHP_FLOAT_MAX;
                for ($k = max(0, $i - 3); $k < $i; $k++) {
                    $prevLowMin = min($prevLowMin, (float) ($bars[$k]->low ?? 0));
                }
                $higherLow = ($prevLowMin < PHP_FLOAT_MAX) ? ($curLow > $prevLowMin * 0.999) : false;

                $breakHigh = ($nextHigh > $baseHigh * 1.0005);

                if (! ($higherLow && $breakHigh)) {
                    continue;
                }

                $baseVol = $volAvgBefore($i + 1);
                $volRatio = ($baseVol > 0) ? ((float) ($next->volume ?? 0) / $baseVol) : 0.0;
                // Removed lower limit - allow any volume for ML training
                if ($volRatio > 200.0) {
                    continue;
                }

                $entryScore = $this->computeLongEntryScore($next);
                if ($entryScore < $minScore || $entryScore > $maxScore) {
                    continue;
                }

                $atrPct = (float) ($next->atr_pct ?? 0);
                // Removed ATR minimum for ML training - let ML learn volatility patterns
                // if ($atrPct < $minAtrPct) { continue; }

                // RSI check removed - one_minute_prices doesn't have rsi_14 column

                $lr = $longReadiness($next, $volRatio);

                [$entryTs, $entryPx] = $computeFill($i + 1);
                $patternScore = min(4.0, $volRatio) + 1.3;

                $candidates[] = $this->makeLongCandidate(
                    'HIGHER_LOW_BREAKOUT',
                    (string) $next->ts_est,
                    $entryTs,
                    $entryPx,
                    $next,
                    $entryScore,
                    $patternScore,
                    'Higher-low forms, then breaks micro base high.',
                    $volRatio,
                    $choppiness,
                    $lr,
                    $hod
                );
                break;
            }
        }

        // -------------------------
        // C) 1m base breakout above VWAP (LONG)
        // -------------------------
        // Find a tight base in last N mins and breakout
        $baseWindow = 8;
        for ($i = max(10, $idxSignal); $i < count($bars); $i++) {
            $cur = $bars[$i];
            $ts = (string) $cur->ts_est;

            if (! $inLiveWindow($ts) || $isTooLate($ts) || $isTooOld($ts)) {
                continue;
            }

            $start = max(0, $i - $baseWindow);
            $hi = 0.0;
            $lo = PHP_FLOAT_MAX;
            $avgV = 0.0;
            $n = 0;

            for ($k = $start; $k < $i; $k++) {
                $hi = max($hi, (float) ($bars[$k]->high ?? 0));
                $lo = min($lo, (float) ($bars[$k]->low ?? 0));
                $avgV += (float) ($bars[$k]->volume ?? 0);
                $n++;
            }
            $avgV = $n > 0 ? $avgV / $n : 0.0;

            if ($hi <= 0 || $lo === PHP_FLOAT_MAX) {
                continue;
            }

            $rangePct = (($hi - $lo) / max(1e-9, $lo)) * 100.0;
            if ($rangePct > 0.55) {
                continue; // too wide, not a tight base
            }

            $curHigh = (float) ($cur->high ?? 0);
            $curPx = (float) ($cur->price ?? 0);
            $curVwap = (float) ($cur->vwap ?? 0);

            // breakout + above VWAP
            if (! ($curHigh > $hi * 1.0005 && $curPx >= $curVwap)) {
                continue;
            }

            $baseVol = $volAvgBefore($i);
            $volRatio = ($baseVol > 0) ? ((float) ($cur->volume ?? 0) / $baseVol) : 0.0;
            // 2026-03-03: RE-ENABLED volume requirement for quality
            if ($volRatio < $minVolRatio) {
                continue;
            }

            $entryScore = $this->computeLongEntryScore($cur);
            if ($entryScore < $minScore || $entryScore > $maxScore) {
                continue;
            }

            $atrPct = (float) ($cur->atr_pct ?? 0);
            // 2026-03-03: RE-ENABLED ATR requirement for quality
            if ($atrPct < $minAtrPct) {
                continue;
            }

            // RSI check removed - one_minute_prices doesn't have rsi_14 column
            $rsi = 50; // Default for scoring

            $lr = $longReadiness($cur, $volRatio);

            [$entryTs, $entryPx] = $computeFill($i);
            $patternScore = min(4.0, $volRatio) + 1.4;

            $candidates[] = $this->makeLongCandidate(
                'BASE_BREAKOUT_1M',
                $ts,
                $entryTs,
                $entryPx,
                $cur,
                $entryScore,
                $patternScore,
                'Tight 1m base breakout above VWAP with volume.',
                $volRatio,
                $choppiness,
                $lr,
                $hod
            );
            break;
        }

        // -------------------------
        // D) GENERIC FALLBACK - REMOVED 2026-03-03
        // -------------------------
        // Removed ultra-permissive generic entries that were causing 69% of losses
        // Now only accept specific pattern-based entries with quality filters

        if (empty($candidates)) {
            return [
                'ok' => false,
                'symbol' => $symbol,
                'asset_type' => $assetType,
                'signal_ts_est' => $signalTsEst,
                'analysis_window_est' => [$analysisStart, $analysisEnd],
                'market_open_est' => $marketOpen,
                'bars_loaded' => count($bars),
                'best_entry' => null,
                'candidates' => [],
                'meta' => [
                    'entry_score_min' => $minScore,
                    'entry_score_max' => $maxScore,
                    'version' => $this->version,
                    'goal' => 'risk-off winners LONG entries',
                    'filters' => [
                        'min_atr_pct' => $minAtrPct,
                        'min_vol_ratio' => $minVolRatio,
                        'max_entry_hour' => $maxHour,
                        'min_rsi' => $minRsi,
                    ],
                ],
            ];
        }

        // Allowed long types
        $allowedTypes = [
            'VWAP_PULLBACK_HOLD',
            'HIGHER_LOW_BREAKOUT',
            'BASE_BREAKOUT_1M',
            'GENERIC_ENTRY', // Fallback for sparse 1-minute data
        ];
        $candidates = array_values(array_filter($candidates, fn ($c) => in_array(($c['type'] ?? ''), $allowedTypes, true)));

        if (empty($candidates)) {
            return [
                'ok' => false,
                'symbol' => $symbol,
                'asset_type' => $assetType,
                'signal_ts_est' => $signalTsEst,
                'analysis_window_est' => [$analysisStart, $analysisEnd],
                'market_open_est' => $marketOpen,
                'bars_loaded' => count($bars),
                'best_entry' => null,
                'candidates' => [],
                'meta' => [
                    'version' => $this->version,
                    'filtered_reason' => 'No candidates passed allowedTypes for longs',
                ],
            ];
        }

        // Sort: highest score first
        usort($candidates, function ($a, $b) {
            if (($b['score'] ?? 0) === ($a['score'] ?? 0)) {
                if (($b['long_readiness'] ?? 0) === ($a['long_readiness'] ?? 0)) {
                    return ($b['pattern_score'] ?? 0) <=> ($a['pattern_score'] ?? 0);
                }

                return ($b['long_readiness'] ?? 0) <=> ($a['long_readiness'] ?? 0);
            }

            return ($b['score'] ?? 0) <=> ($a['score'] ?? 0);
        });

        $best = $candidates[0];

        // Targets for longs: +2%, +3%, +4%
        $entry = (float) $best['entry'];
        $best['pct_targets'] = [
            '+2%' => round($entry * 1.02, 6),
            '+3%' => round($entry * 1.03, 6),
            '+4%' => round($entry * 1.04, 6),
        ];

        // R targets (risk-based)
        $r = max(1e-9, (float) $best['risk_per_share']);
        $best['targets'] = [
            '1R' => round($entry + 1.0 * $r, 6),
            '2R' => round($entry + 2.0 * $r, 6),
            '3R' => round($entry + 3.0 * $r, 6),
        ];

        // Suggested trailing stop for longs (below entry)
        $atr = (float) ($best['atr'] ?? 0);
        $atrMultiplier = \App\Services\TradingSettingService::getStopLossAtrMultiplier();
        $minPct = \App\Services\TradingSettingService::getStopLossAtrMinPct();
        $maxPct = \App\Services\TradingSettingService::getStopLossAtrMaxPct();
        $calculatedPct = ($atr > 0 && $entry > 0)
            ? (($atr * $atrMultiplier) / $entry) * 100.0
            : $minPct;
        $trailPct = max($minPct, min($maxPct, $calculatedPct));

        $best['suggested_trailing_stop'] = round($entry * (1 - ($trailPct / 100.0)), 6);
        $best['suggested_trailing_stop_pct'] = $trailPct;

        return [
            'ok' => true,
            'symbol' => $symbol,
            'asset_type' => $assetType,
            'signal_ts_est' => $signalTsEst,
            'analysis_window_est' => [$analysisStart, $analysisEnd],
            'market_open_est' => $marketOpen,
            'bars_loaded' => count($bars),
            'best_entry' => $best,
            'candidates' => $candidates,
            'meta' => [
                'entry_score_min' => $minScore,
                'entry_score_max' => $maxScore,
                'version' => $this->version,
                'fill_model' => $fillModel,
                'goal' => 'risk-off winners LONG entries',
            ],
        ];
    }

    private function calculate5MinChoppiness(array $fiveMinBars): array
    {
        if (count($fiveMinBars) < 2) {
            return ['directional_changes' => 0, 'green_bar_pct' => 0.0, 'net_progress' => 0.0];
        }

        $dirChanges = 0;
        $greenBars = 0;
        $totalRange = 0.0;

        $lastDir = null;
        foreach ($fiveMinBars as $bar) {
            $open = (float) ($bar->open ?? 0);
            $close = (float) ($bar->price ?? 0);
            $high = (float) ($bar->high ?? $close);
            $low = (float) ($bar->low ?? $close);

            $currentDir = $close >= $open ? 'up' : 'down';
            if ($lastDir !== null && $currentDir !== $lastDir) {
                $dirChanges++;
            }
            $lastDir = $currentDir;

            if ($close >= $open) {
                $greenBars++;
            }
            $totalRange += ($high - $low);
        }

        $firstBar = $fiveMinBars[0];
        $lastBar = $fiveMinBars[count($fiveMinBars) - 1];
        $netMove = abs((float) ($lastBar->price ?? 0) - (float) ($firstBar->open ?? 0));
        $netProgress = $totalRange > 0 ? $netMove / $totalRange : 0.0;

        return [
            'directional_changes' => $dirChanges,
            'green_bar_pct' => round((($greenBars / count($fiveMinBars)) * 100), 1),
            'net_progress' => round($netProgress, 3),
        ];
    }

    private function makeLongCandidate(
        string $type,
        string $triggerTs,
        string $entryTs,
        float $entryPx,
        object $row,
        float $entryScore,
        float $patternScore,
        string $notes,
        float $volRatio = 0.0,
        array $choppiness = [],
        float $longReadiness = 0.0,
        ?float $hod = null
    ): array {
        // Stop: ATR-based BELOW entry for longs
        $atr = (float) ($row->atr ?? 0);

        $atrStopDist = ($atr > 0) ? ($atr * 1.2) : 0.0;
        $pctStopDist = $entryPx * 0.0095; // 0.95% baseline
        $stopDist = max($atrStopDist, $pctStopDist);

        $rawStop = $entryPx - $stopDist;

        // Clamp stop band: 0.75% – 1.35% below entry
        $minStopPct = 0.75;
        $maxStopPct = 1.35;
        $minStop = $entryPx * (1 - ($maxStopPct / 100));
        $maxStop = $entryPx * (1 - ($minStopPct / 100));

        $stop = $rawStop;
        if ($stop < $minStop) {
            $stop = $minStop;
        }
        if ($stop > $maxStop) {
            $stop = $maxStop;
        }

        $risk = max(1e-9, $entryPx - $stop);
        $riskPct = ($entryPx > 0) ? ($risk / $entryPx) * 100.0 : 0.0;

        return [
            'side' => 'long',

            'type' => $type,
            'trigger_ts_est' => $triggerTs,
            'entry_ts_est' => $entryTs,
            'entry' => round($entryPx, 6),
            'stop' => round($stop, 6),

            'score' => round($entryScore, 2),
            'pattern_score' => round($patternScore, 3),
            'long_readiness' => round($longReadiness, 3),

            'atr' => round((float) ($row->atr ?? 0), 6),
            'atr_pct' => (float) ($row->atr_pct ?? 0),
            'risk_per_share' => round($risk, 6),
            'risk_pct' => round($riskPct, 3),

            'vol_ratio' => $volRatio > 0 ? round($volRatio, 3) : null,

            'vwap' => $row->vwap !== null ? round((float) $row->vwap, 6) : null,
            'ema9' => $row->ema9 !== null ? round((float) $row->ema9, 6) : null,
            'ema21' => $row->ema21 !== null ? round((float) $row->ema21, 6) : null,

            'hod' => $hod !== null ? round($hod, 6) : null,

            'five_min_directional_changes' => $choppiness['directional_changes'] ?? null,
            'five_min_green_bar_pct' => isset($choppiness['green_bar_pct']) ? round($choppiness['green_bar_pct'], 1) : null,
            'five_min_net_progress' => isset($choppiness['net_progress']) ? round($choppiness['net_progress'], 3) : null,

            'notes' => $notes,
        ];
    }

    private function computeLongEntryScore(object $b): float
    {
        $price = (float) ($b->price ?? 0);
        if ($price <= 0) {
            return 0.0;
        }

        $emaSpread = (float) ($b->ema9_ema21_spread ?? 0);
        $spreadFrac = $emaSpread / $price;

        // Positive spread is good for longs (EMA9 above EMA21)
        $spread_strength = $this->clamp(($spreadFrac - 0.0005) / (0.0030 - 0.0005));

        $vwap_dist_pct = (float) ($b->vwap_dist_pct ?? 0);
        // Above VWAP is good for longs
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
            // For longs: higher position in candle is better
            $pos = ($price - $low) / ($high - $low);
            $candle_score = $this->clamp(($pos - 0.45) / (0.80 - 0.45));
        }

        $ema9_above_ema21 = (float) ((int) ($b->ema9_above_ema21 ?? 0));
        $above_vwap = (float) ((int) ($b->above_vwap ?? 0));

        $rsi = (float) ($b->rsi_14 ?? 50);
        $rsi_score = $this->clamp(($rsi - 50) / 20.0); // RSI 70 => 1.0, RSI 50 => 0.0

        $ts = (string) ($b->ts_est ?? '');
        $time_bonus = 0.0;
        if ($ts) {
            $timeStr = substr($ts, 11, 8);
            // Mid-morning strength entries
            if ($timeStr >= '09:45:00' && $timeStr <= '11:15:00') {
                $time_bonus = 1.0;
            } elseif ($timeStr <= '12:30:00') {
                $time_bonus = 0.5;
            }
        }

        $S_trend = 0.70 * $ema9_above_ema21 + 0.30 * $spread_strength;
        $S_vwap = $above_vwap * $vwap_dist_score;

        $final = 100.0 * (
            0.30 * $S_trend +
            0.25 * $S_vwap +
            0.10 * $atr_score +
            0.15 * $vol_score +
            0.10 * $candle_score +
            0.05 * $rsi_score +
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

    /**
     * Compatibility alias: method name unchanged from original short strategy.
     * Calls findBestLong() internally.
     */
    public function findBestShort(
        string $symbol,
        string $assetType,
        string $signalTsEst,
        string $asOfTsEst,
        int $beforeMinutes = 15,
        int $afterMinutes = 30,
        int $volLookback = 20,
        int $pivotLookback = 15,
        string $fillModel = 'next_open'
    ): array {
        return $this->findBestLong(
            $symbol,
            $assetType,
            $signalTsEst,
            $asOfTsEst,
            $beforeMinutes,
            $afterMinutes,
            $volLookback,
            $pivotLookback,
            $fillModel
        );
    }
}
