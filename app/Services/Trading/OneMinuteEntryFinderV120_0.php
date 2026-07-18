<?php

namespace App\Services\Trading;

use App\Services\TradingSettingService;
use Illuminate\Support\Facades\Cache;

/**
 * One-Minute Entry Finder V120.0 - Elite Multi-Day Momentum Continuation
 *
 * Enhanced v90.1 entry finder optimized for multi-day runners:
 * - Focus on continuation patterns (NO pullbacks)
 * - Breaking multi-day highs with volume
 * - Price above VWAP and EMA9 (strong trending)
 * - Score range 40-59 (sweet spot for momentum: 5.40% avg P&L)
 * - Gap-up continuation entries
 * - Relative strength confirmation
 *
 * Entry Types:
 * 1. MULTI_DAY_HIGH_BREAK - Breaking yesterday's AND prior day's highs
 * 2. GAP_CONTINUATION - Holding gap above key levels
 * 3. VWAP_RECLAIM_1M - Momentum continuation after brief dip
 * 4. OR_BREAKOUT - Opening range break with multi-day context
 * 5. EMA9_BOUNCE - Trend continuation off key moving average
 */
class OneMinuteEntryFinderV120_0
{
    use HasPriceTables;

    private string $version = 'v120.0';

    public function getVersion(): string
    {
        return $this->version;
    }

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
        array $signalMeta = [], // Pass in metadata from scanner
        int $freshnessMinutes = 4 // Maximum age for entries to be considered fresh (tightened from 6 to 4 for lower slippage)
    ): array {
        // Focus on 40-59 score sweet spot (5.40% avg P&L) - uses finder-specific score, not the 5m scanner score
        $minScore = (float) config('trading.v120.finder_entry_score_min', 40);
        $maxScore = (float) config('trading.v120.finder_entry_score_max', 75);

        if ($maxScore <= 0) {
            $maxScore = 100.0;
        }
        if ($minScore > $maxScore) {
            [$minScore, $maxScore] = [$maxScore, $minScore];
        }

        // Live analysis window relative to NOW (asOf)
        $analysisEnd = $asOfTsEst;
        $analysisStart = date('Y-m-d H:i:s', strtotime($asOfTsEst.' -'.$beforeMinutes.' minutes'));

        // Market open based on trade date
        $tradeDate = substr($signalTsEst, 0, 10);
        $marketOpen = $tradeDate.' 09:30:00';
        if ($analysisStart < $marketOpen) {
            $analysisStart = $marketOpen;
        }

        // Need data from market open through analysis end
        $from = $marketOpen;
        $to = $analysisEnd;

        $bucketTs = date('Y-m-d H:i', strtotime($to));
        $cacheKey1m = "1m_bars:v120:{$assetType}:{$symbol}:{$tradeDate}:{$bucketTs}";
        $bars = Cache::remember($cacheKey1m, 90, function () use ($assetType, $symbol, $tradeDate, $from, $to) {
            return $this->dbSelect('
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
        });

        if (! $bars || count($bars) < 25) {
            return [
                'ok' => false,
                'error' => 'Not enough 1m data in range (market closed or missing bars).',
                'symbol' => $symbol,
                'asset_type' => $assetType,
                'range_est' => [$from, $to],
                'bars_found' => $bars ? count($bars) : 0,
            ];
        }

        // Validate data quality: reject if extreme price drops (reverse splits, bad data)
        for ($i = 1; $i < count($bars); $i++) {
            $prevClose = (float) $bars[$i - 1]->price;
            $currentOpen = (float) $bars[$i]->open;
            if ($prevClose > 0 && (($currentOpen - $prevClose) / $prevClose) * 100.0 < -50.0) {
                return ['ok' => false, 'error' => 'Bad data - extreme drop', 'symbol' => $symbol];
            }
        }

        // Get 5-minute bars for trend confirmation
        $cacheKey5m = "5m_bars:v120:{$assetType}:{$symbol}:{$tradeDate}:{$bucketTs}";
        $fiveMinBars = Cache::remember($cacheKey5m, 90, function () use ($assetType, $symbol, $tradeDate, $from, $to) {
            return $this->dbSelect('
                SELECT ts_est, open, high, low, price, ema9_above_ema21, above_vwap
                FROM five_minute_prices
                WHERE asset_type = ?
                  AND symbol = ?
                  AND trading_date_est = ?
                  AND ts_est >= ?
                  AND ts_est <= ?
                ORDER BY ts_est ASC
            ', [$assetType, $symbol, $tradeDate, $from, $to]);
        });

        // Optimize 5-minute trend lookup using pointer (O(N) instead of O(N²))
        $fiveMinIdx = 0;
        $fiveMinCount = count($fiveMinBars);

        // Helper to check if 5-minute trend is up
        $is5MinTrendUp = function ($ts1m) use ($fiveMinBars, &$fiveMinIdx, $fiveMinCount): bool {
            // Advance pointer to find the most recent 5m bar at or before ts1m
            while ($fiveMinIdx < $fiveMinCount - 1 && (string) $fiveMinBars[$fiveMinIdx + 1]->ts_est <= $ts1m) {
                $fiveMinIdx++;
            }

            if ($fiveMinIdx >= $fiveMinCount || (string) $fiveMinBars[$fiveMinIdx]->ts_est > $ts1m) {
                return false;
            }

            return (int) ($fiveMinBars[$fiveMinIdx]->ema9_above_ema21 ?? 0) === 1;
        };

        // Extract multi-day context from signal metadata
        $yesterdayHigh = $signalMeta['yesterday_high'] ?? null;
        $dayBeforeHigh = $signalMeta['day_before_high'] ?? null;
        $consecutiveDays = $signalMeta['consecutive_up_days'] ?? 1;
        $gapPct = $signalMeta['gap_pct'] ?? 0;
        $holdingGap = $signalMeta['holding_gap'] ?? false;
        $catalystLikelihood = $signalMeta['catalyst_likelihood'] ?? 'unknown';

        // Opening range high (first 5 bars)
        $orHigh = null;
        for ($i = 0; $i < min(5, count($bars)); $i++) {
            $h = (float) ($bars[$i]->high ?? 0);
            $orHigh = ($orHigh === null) ? $h : max($orHigh, $h);
        }

        // Day open for gap calculations
        $dayOpen = (float) ($bars[0]->open ?? 0);

        $computeFill = function (int $i) use ($bars, $fillModel): array {
            if ($fillModel === 'close') {
                return [(string) $bars[$i]->ts_est, (float) $bars[$i]->price];
            }
            $next = $i + 1;
            if ($next >= count($bars)) {
                return [(string) $bars[$i]->ts_est, (float) $bars[$i]->price];
            }

            // Safety check: prevent time travel bugs by verifying same trading date
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

        // Calculate choppiness (scaled to number of bars)
        $choppiness = [];
        if (count($fiveMinBars) >= 6) {
            $recent5MinBars = array_slice($fiveMinBars, -12);
            $choppiness = $this->calculate5MinChoppiness($recent5MinBars);

            // Filter out excessive choppiness: scale threshold to actual bars
            // With 12 bars max changes is 11, reject if > 70% are direction changes
            $maxChanges = count($recent5MinBars) - 1;
            $choppinessThreshold = max(5, (int) ceil($maxChanges * 0.70));
            if (($choppiness['directional_changes'] ?? 0) >= $choppinessThreshold) {
                return [
                    'ok' => false,
                    'symbol' => $symbol,
                    'asset_type' => $assetType,
                    'range_est' => [$from, $to],
                    'bars_found' => count($bars),
                    'filter_reason' => sprintf('Excessive 5-minute choppiness (%d/%d changes)', $choppiness['directional_changes'] ?? 0, $maxChanges),
                ];
            }
        }

        $candidates = [];

        // -------------------------
        // A) MULTI_DAY_HIGH_BREAK - Breaking multiple day highs
        // -------------------------
        $multiDayHigh = max($yesterdayHigh ?? 0, $dayBeforeHigh ?? 0);
        if ($multiDayHigh > 0 && $consecutiveDays >= 2) {
            for ($i = 1; $i < count($bars); $i++) {
                $cur = $bars[$i];
                $ts = (string) $cur->ts_est;

                if (! $inLiveWindow($ts)) {
                    continue;
                }

                // Must be in strong 5-minute uptrend
                if (! $is5MinTrendUp($ts)) {
                    continue;
                }

                $close = (float) ($cur->price ?? 0);

                // Breaking multi-day high
                if ($close <= $multiDayHigh) {
                    continue;
                }

                // Volume confirmation - stronger requirement for multi-day breaks
                // Require 2.5x minimum (avoids later filter conflicts)
                $baseVol = $volAvgBefore($i);
                $volRatio = ($baseVol > 0) ? ((float) ($cur->volume ?? 0) / $baseVol) : 0.0;
                if ($volRatio < 2.5) {
                    continue;
                }

                $entryScore = $this->computeEntryScore($cur);
                if ($entryScore < $minScore || $entryScore > $maxScore) {
                    continue;
                }

                [$entryTs, $entryPx] = $computeFill($i);
                $patternScore = min(4.0, $volRatio) + ($consecutiveDays * 0.5);

                $candidates[] = $this->makeCandidate(
                    'MULTI_DAY_HIGH_BREAK',
                    $ts,
                    $entryTs,
                    $entryPx,
                    $cur,
                    $entryScore,
                    $patternScore,
                    sprintf('%d-day high break with volume (%.1fx)', $consecutiveDays, $volRatio),
                    $volRatio,
                    $choppiness
                );
                break;
            }
        }

        // -------------------------
        // B) GAP_CONTINUATION - Holding gap above key levels
        // -------------------------
        if ($gapPct >= 2.0 && $holdingGap) {
            for ($i = 5; $i < count($bars); $i++) {
                $cur = $bars[$i];
                $ts = (string) $cur->ts_est;

                if (! $inLiveWindow($ts)) {
                    continue;
                }

                // Must be in uptrend
                if (! $is5MinTrendUp($ts)) {
                    continue;
                }

                $close = (float) ($cur->price ?? 0);
                $vwap = (float) ($cur->vwap ?? 0);
                $ema9 = (float) ($cur->ema9 ?? 0);

                // Must be above VWAP and EMA9 (holding gap)
                if ($close <= $vwap || $close <= $ema9) {
                    continue;
                }

                // Volume surge for continuation (2.5x to avoid later filter conflicts)
                $baseVol = $volAvgBefore($i);
                $volRatio = ($baseVol > 0) ? ((float) ($cur->volume ?? 0) / $baseVol) : 0.0;
                if ($volRatio < 2.5) {
                    continue;
                }

                $entryScore = $this->computeEntryScore($cur);
                if ($entryScore < $minScore || $entryScore > $maxScore) {
                    continue;
                }

                [$entryTs, $entryPx] = $computeFill($i);
                $patternScore = min(3.5, $volRatio) + ($gapPct * 0.2);

                $candidates[] = $this->makeCandidate(
                    'GAP_CONTINUATION',
                    $ts,
                    $entryTs,
                    $entryPx,
                    $cur,
                    $entryScore,
                    $patternScore,
                    sprintf('%.1f%% gap continuation above VWAP/EMA9', $gapPct),
                    $volRatio,
                    $choppiness
                );
                break;
            }
        }

        // -------------------------
        // C) VWAP reclaim (momentum continuation)
        // -------------------------
        for ($i = 1; $i < count($bars); $i++) {
            $prev = $bars[$i - 1];
            $cur = $bars[$i];
            $ts = (string) $cur->ts_est;

            if (! $inLiveWindow($ts)) {
                continue;
            }

            // Require 5-minute uptrend
            if (! $is5MinTrendUp($ts)) {
                continue;
            }

            $prevPx = (float) ($prev->price ?? 0);
            $curPx = (float) ($cur->price ?? 0);
            $prevV = (float) ($prev->vwap ?? 0);
            $curV = (float) ($cur->vwap ?? 0);

            if ($prevV <= 0 || $curV <= 0) {
                continue;
            }

            $isReclaim = ($prevPx < $prevV) && ($curPx > $curV);
            if (! $isReclaim) {
                continue;
            }

            $baseVol = $volAvgBefore($i);
            $volRatio = ($baseVol > 0) ? ((float) ($cur->volume ?? 0) / $baseVol) : 0.0;
            if ($volRatio < 2.5) {
                continue;
            }

            $entryScore = $this->computeEntryScore($cur);
            if ($entryScore < $minScore || $entryScore > $maxScore) {
                continue;
            }

            [$entryTs, $entryPx] = $computeFill($i);
            $patternScore = min(3.0, $volRatio) + 1.0;

            $candidates[] = $this->makeCandidate(
                'VWAP_RECLAIM_1M',
                $ts,
                $entryTs,
                $entryPx,
                $cur,
                $entryScore,
                $patternScore,
                'VWAP reclaim with volume',
                $volRatio,
                $choppiness
            );
            break;
        }

        // -------------------------
        // D) Opening range breakout (early momentum)
        // -------------------------
        if ($orHigh !== null && $orHigh > 0) {
            for ($i = 1; $i < count($bars); $i++) {
                $prev = $bars[$i - 1];
                $cur = $bars[$i];
                $ts = (string) $cur->ts_est;

                if (! $inLiveWindow($ts)) {
                    continue;
                }

                // Require 5-minute uptrend
                if (! $is5MinTrendUp($ts)) {
                    continue;
                }

                $prevHigh = (float) ($prev->high ?? 0);
                $close = (float) ($cur->price ?? 0);

                $breaks = ($prevHigh <= $orHigh) && ($close > $orHigh);
                if (! $breaks) {
                    continue;
                }

                $baseVol = $volAvgBefore($i);
                $volRatio = ($baseVol > 0) ? ((float) ($cur->volume ?? 0) / $baseVol) : 0.0;
                if ($volRatio < 2.4) {
                    continue;
                }

                $entryScore = $this->computeEntryScore($cur);
                if ($entryScore < $minScore || $entryScore > $maxScore) {
                    continue;
                }

                [$entryTs, $entryPx] = $computeFill($i);
                $patternScore = min(3.0, $volRatio) + 1.2;

                $candidates[] = $this->makeCandidate(
                    'OR_BREAKOUT',
                    $ts,
                    $entryTs,
                    $entryPx,
                    $cur,
                    $entryScore,
                    $patternScore,
                    'Opening range breakout',
                    $volRatio,
                    $choppiness
                );
                break;
            }
        }

        // -------------------------
        // E) EMA9 bounce (trend continuation)
        // -------------------------
        for ($i = 5; $i < count($bars); $i++) {
            $prev = $bars[$i - 1];
            $cur = $bars[$i];
            $ts = (string) $cur->ts_est;

            if (! $inLiveWindow($ts)) {
                continue;
            }

            // Require 5-minute uptrend
            if (! $is5MinTrendUp($ts)) {
                continue;
            }

            $ema9 = (float) ($cur->ema9 ?? 0);
            if ($ema9 <= 0) {
                continue;
            }

            $prevLow = (float) ($prev->low ?? 0);
            $prevTouched = ($prevLow > 0) && (abs($prevLow - (float) ($prev->ema9 ?? 0)) / max(1e-9, (float) ($prev->ema9 ?? 0)) < 0.003);

            $close = (float) ($cur->price ?? 0);
            $open = (float) ($cur->open ?? 0);
            $curAbove = $close > $ema9;
            $green = ($open > 0) ? ($close > $open) : true;

            if (! ($prevTouched && $curAbove && $green)) {
                continue;
            }

            $baseVol = $volAvgBefore($i);
            $volRatio = ($baseVol > 0) ? ((float) ($cur->volume ?? 0) / $baseVol) : 0.0;
            if ($volRatio < 2.5) {
                continue;
            }

            $entryScore = $this->computeEntryScore($cur);
            if ($entryScore < $minScore || $entryScore > $maxScore) {
                continue;
            }

            [$entryTs, $entryPx] = $computeFill($i);
            $patternScore = min(3.0, $volRatio) + 0.9;

            $candidates[] = $this->makeCandidate(
                'EMA9_BOUNCE',
                $ts,
                $entryTs,
                $entryPx,
                $cur,
                $entryScore,
                $patternScore,
                'EMA9 bounce continuation',
                $volRatio,
                $choppiness
            );
            break;
        }

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
                'filter_reason' => 'No qualifying multi-day momentum entries found',
                'meta' => [
                    'version' => $this->version,
                    'entry_score_min' => $minScore,
                    'entry_score_max' => $maxScore,
                    'consecutive_days' => $consecutiveDays,
                    'catalyst_likelihood' => $catalystLikelihood,
                ],
            ];
        }

        // Performance-based filtering: Target 75%+ WR and 1.5%+ avg P&L
        $candidates = array_filter($candidates, function ($c) use ($consecutiveDays) {
            $type = $c['type'] ?? '';
            $risk = $c['risk_pct'] ?? 999;
            $vol = $c['vol_ratio'] ?? 0;
            $chop = $c['five_min_directional_changes'] ?? 0;
            $entryTs = $c['entry_ts_est'] ?? '';
            $hour = $entryTs ? (int) substr($entryTs, 11, 2) : 0;
            $minute = $entryTs ? (int) substr($entryTs, 14, 2) : 0;
            $timeInMinutes = ($hour * 60) + $minute;

            // Remove OR_BREAKOUT completely (0% WR)
            if ($type === 'OR_BREAKOUT') {
                return false;
            }

            // VWAP_RECLAIM_1M: Target 76%+ WR, 1.5%+ P&L
            // Winners: risk <= 0.7 (1.52% / 91% combined), early 9:30-10:30 (2.69% / 100%), afternoon 14:00+ (2.31% / 100%)
            // Losers: morning 10:30-12 (0.18% / 55.6%)
            if ($type === 'VWAP_RECLAIM_1M') {
                // Reject morning 10:30-12:00 (underperforms)
                if ($timeInMinutes >= 630 && $timeInMinutes < 720) {
                    return false;
                }
                // Elite: tight risk <= 0.7
                if ($risk <= 0.7) {
                    return true;
                }
                // Elite: early morning 9:30-10:30 OR afternoon 14:00+
                if ($timeInMinutes < 630 || $hour >= 14) {
                    return true;
                }
                // Good: high volume 5.0+ (3.09% / 100%)
                if ($vol >= 5.0) {
                    return true;
                }

                return false;
            }

            // GAP_CONTINUATION: DISABLED - only 67.9% WR / 1.04% P&L (below threshold)
            // Too many losses with vol 3x and wide risk despite filters
            if ($type === 'GAP_CONTINUATION') {
                return false;
            }

            // MULTI_DAY_HIGH_BREAK: Target 80%+ WR, 1.5%+ P&L
            // Winners: vol 5.0+ (1.79% / 84.6%), tight risk (1.32% / 76.5%), afternoon 14:00+ (0.99% / 76.9%)
            // Losers: vol 3.0-4.9 (0.07% / 43.8% combined), normal risk 0.5-0.7 (0.25% / 58.3%), morning 10:30-12 (0.56% / 50%)
            if ($type === 'MULTI_DAY_HIGH_BREAK') {
                // Reject vol 3.0-5.0 range (underperforms)
                if ($vol >= 3.0 && $vol < 5.0) {
                    return false;
                }
                // Reject normal risk 0.5-0.7 (mediocre results)
                if ($risk > 0.5 && $risk <= 0.7) {
                    return false;
                }
                // Reject morning 10:30-12:00 (worst time)
                if ($timeInMinutes >= 630 && $timeInMinutes < 720) {
                    return false;
                }
                // Elite: vol 5.0+ (explosive moves)
                if ($vol >= 5.0) {
                    return true;
                }
                // Elite: tight risk <= 0.5
                if ($risk <= 0.5) {
                    return true;
                }
                // Good: afternoon 14:00+ with decent volume
                if ($hour >= 14 && $vol >= 2.5) {
                    return true;
                }

                return false;
            }

            // EMA9_BOUNCE: Target 90%+ WR, 1.5%+ P&L
            // Winners: early 9:30-10:30 (2.41% / 100%), vol under 3 with normal risk (1.71% / 100%)
            // Losers: afternoon 14:00+ (2 losses at 14:30, 14:40 slipped through)
            if ($type === 'EMA9_BOUNCE') {
                // REJECT afternoon 14:00+ (losses at 14:30, 14:40)
                if ($timeInMinutes >= 840) {
                    return false;
                }
                // Elite: early morning 9:30-10:30 only
                if ($timeInMinutes >= 570 && $timeInMinutes < 630) {
                    return true;
                }
                // Elite: vol under 3 with normal risk 0.5-0.7 (before 14:00)
                if ($vol < 3.0 && $risk > 0.5 && $risk <= 0.7) {
                    return true;
                }

                // Otherwise reject
                return false;
            }

            // Fallback for any multi-day context (2+ days)
            return $consecutiveDays >= 2;
        });

        if (empty($candidates)) {
            return [
                'ok' => false,
                'symbol' => $symbol,
                'asset_type' => $assetType,
                'signal_ts_est' => $signalTsEst,
                'filter_reason' => 'Filtered by elite multi-day momentum criteria',
                'meta' => [
                    'version' => $this->version,
                    'consecutive_days' => $consecutiveDays,
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

        // Rank by pattern score
        usort($candidates, fn ($a, $b) => ($b['pattern_score'] <=> $a['pattern_score']));

        $best = $candidates[0];

        // Add trailing stop calculation (config-driven ATR multiplier with bounds)
        $atr = (float) ($best['atr'] ?? 0);
        $entryPrice = (float) $best['entry'];
        $atrMultiplier = TradingSettingService::getStopLossAtrMultiplier();
        $minPct = TradingSettingService::getStopLossAtrMinPct();
        $maxPct = TradingSettingService::getStopLossAtrMaxPct();
        $calculatedPct = ($atr > 0 && $entryPrice > 0)
            ? (($atr * $atrMultiplier) / $entryPrice) * 100.0
            : $minPct;
        $trailPct = max($minPct, min($maxPct, $calculatedPct));

        $best['suggested_trailing_stop'] = round($entryPrice * ($trailPct / 100.0), 6);
        $best['suggested_trailing_stop_pct'] = $trailPct;

        return [
            'ok' => true,
            'symbol' => $symbol,
            'asset_type' => $assetType,
            'signal_ts_est' => $signalTsEst,
            'best_entry' => $best,
            'candidates' => $candidates,
            'meta' => [
                'version' => $this->version,
                'entry_score_min' => $minScore,
                'entry_score_max' => $maxScore,
                'consecutive_days' => $consecutiveDays,
                'gap_pct' => $gapPct,
                'holding_gap' => $holdingGap,
                'catalyst_likelihood' => $catalystLikelihood,
            ],
        ];
    }

    private function makeCandidate(
        string $type,
        string $triggerTs,
        string $entryTs,
        float $entryPx,
        object $bar,
        float $entryScore,
        float $patternScore,
        string $note,
        float $volRatio,
        array $choppiness
    ): array {
        $atr = (float) ($bar->atr ?? 0);
        $atrPct = (float) ($bar->atr_pct ?? 0);
        $stopPx = max($entryPx - (2.5 * $atr), 0.01);
        $risk = $entryPx - $stopPx;
        $riskPct = (($entryPx - $stopPx) / $entryPx) * 100.0;

        $t1Px = $entryPx + (0.75 * ($entryPx - $stopPx));
        $t2Px = $entryPx + (1.5 * ($entryPx - $stopPx));
        $t3Px = $entryPx + (3.0 * ($entryPx - $stopPx));

        return [
            'type' => $type,
            'trigger_ts_est' => $triggerTs,
            'entry_ts_est' => $entryTs,
            'entry' => round($entryPx, 4),
            'stop' => round($stopPx, 4),
            'risk_pct' => round($riskPct, 2),
            'risk_per_share' => round($risk, 6),
            'score' => round($entryScore, 2),
            'pattern_score' => round($patternScore, 2),
            'targets' => [
                't1' => round($t1Px, 4),
                't2' => round($t2Px, 4),
                't3' => round($t3Px, 4),
            ],
            'vol_ratio' => round($volRatio, 2),
            'price' => round((float) $bar->price, 4),
            'atr' => round($atr, 6),
            'atr_pct' => round($atrPct, 3),
            'five_min_directional_changes' => $choppiness['directional_changes'] ?? null,
            'five_min_green_bar_pct' => isset($choppiness['green_bar_pct']) ? round($choppiness['green_bar_pct'], 1) : null,
            'five_min_net_progress' => isset($choppiness['net_progress']) ? round($choppiness['net_progress'], 3) : null,
            'note' => $note,
            'choppiness' => $choppiness,
        ];
    }

    private function computeEntryScore(object $bar): float
    {
        $vwapDist = (float) ($bar->vwap_dist_pct ?? 0);
        $emaDist = (float) ($bar->ema9_ema21_spread ?? 0);
        $atrPct = (float) ($bar->atr_pct ?? 0);

        $score = 50.0;

        // VWAP distance: favor continuation near VWAP, not chasing extended moves
        // Best: 0-2% above VWAP (+10 to +15 points)
        // Good: 2-4% above VWAP (+5 to +10 points)
        // Penalty: >5% above VWAP (chasing, mean reversion risk)
        if ($vwapDist < 0) {
            $score += max(-10.0, $vwapDist * 10); // Below VWAP: penalty
        } elseif ($vwapDist <= 2.0) {
            $score += min(15.0, $vwapDist * 7.5); // 0-2%: strong bonus
        } elseif ($vwapDist <= 4.0) {
            $score += 15.0 - (($vwapDist - 2.0) * 2.5); // 2-4%: diminishing bonus
        } else {
            $score += max(0.0, 10.0 - (($vwapDist - 4.0) * 3.0)); // >4%: penalty for chasing
        }

        // EMA spread: positive trend but cap the bonus
        $score += min(15.0, max(0, $emaDist * 5));

        // ATR: favor lower volatility for cleaner entries
        $score += min(5.0, max(-5.0, (1.5 - $atrPct) * 3));

        return max(0.0, min(100.0, $score));
    }

    private function calculate5MinChoppiness(array $bars): array
    {
        if (count($bars) < 2) {
            return [
                'directional_changes' => 0,
                'green_bar_pct' => 0.0,
                'net_progress' => 0.0,
            ];
        }

        $changes = 0;
        $greenBars = 0;
        $firstOpen = (float) ($bars[0]->open ?? 0);
        $lastClose = (float) ($bars[count($bars) - 1]->price ?? 0);

        for ($i = 1; $i < count($bars); $i++) {
            $prev = $bars[$i - 1];
            $cur = $bars[$i];

            $prevDir = ((float) ($prev->price ?? 0)) > ((float) ($prev->open ?? 0));
            $curDir = ((float) ($cur->price ?? 0)) > ((float) ($cur->open ?? 0));

            if ($prevDir !== $curDir) {
                $changes++;
            }

            if ($curDir) {
                $greenBars++;
            }
        }

        $greenPct = (count($bars) > 0) ? ($greenBars / count($bars)) * 100.0 : 0.0;
        $netProgress = ($firstOpen > 0) ? (($lastClose - $firstOpen) / $firstOpen) * 100.0 : 0.0;

        return [
            'directional_changes' => $changes,
            'green_bar_pct' => round($greenPct, 1),
            'net_progress' => round($netProgress, 2),
        ];
    }
}
