<?php

namespace App\Services\Trading;

use Illuminate\Support\Facades\Cache;

/**
 * Version 60.1 - Hybrid Entry Finder
 *
 * Best of both worlds:
 * - v17-style entry "types" (VWAP reclaim, pivot break, OR breakout, EMA bounces, bull flag)
 * - v50 EntryScore as the universal quality filter + tie-breaker (from precomputed 1m columns)
 *
 * Uses config('trading.entry_score_min/max') so it works with config:cache.
 */
class OneMinuteEntryFinderV60_3
{
    use HasPriceTables;

    private string $version = 'v60.3';

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
        string $fillModel = 'next_open' // next_open|close
    ): array {
        $minScore = (float) config('trading.v60.entry_score_min', 80);
        $maxScore = (float) config('trading.v60.entry_score_max', 98);
        if ($maxScore <= 0) {
            $maxScore = 100.0;
        }
        if ($minScore > $maxScore) {
            [$minScore, $maxScore] = [$maxScore, $minScore];
        }

        // Live analysis window relative to NOW (asOf)
        $analysisEnd = $asOfTsEst;
        $analysisStart = date('Y-m-d H:i:s', strtotime("{$asOfTsEst} -{$beforeMinutes} minutes"));

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

        $cacheKey1m = "1m_bars:v60_3:{$assetType}:{$symbol}:{$tradeDate}:{$bucketTs}";
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

        // Get 5-minute bars to check for downtrends and calculate choppiness
        $cacheKey5m = "5m_bars:v60_3:{$assetType}:{$symbol}:{$tradeDate}:{$bucketTs}";
        $fiveMinBars = Cache::remember($cacheKey5m, 90, function () use ($assetType, $symbol, $tradeDate, $from, $to) {
            return $this->dbSelect('
                SELECT ts_est, open, high, low, price, ema9_above_ema21, above_vwap,
                       ema9_ema21_spread, vwap_dist_pct
                FROM five_minute_prices
                WHERE asset_type = ?
                  AND symbol = ?
                  AND trading_date_est = ?
                  AND ts_est >= ?
                  AND ts_est <= ?
                ORDER BY ts_est ASC
        ', [$assetType, $symbol, $tradeDate, $from, $to]);
        });

        // Precompute 5m trend bool for each 1m timestamp: O(n+m) single pass instead of O(n×m).
        // Both arrays are already sorted ascending by ts_est; a monotonic pointer walks them in lockstep.
        $fiveMinTrendPrecomputed = [];
        $fiveMinIdx = 0;
        $fiveMinCount = count($fiveMinBars);
        $activeFiveMinBar = null;
        foreach ($bars as $oneMinBar) {
            $ts = (string) $oneMinBar->ts_est;
            while ($fiveMinIdx < $fiveMinCount && (string) $fiveMinBars[$fiveMinIdx]->ts_est <= $ts) {
                $activeFiveMinBar = $fiveMinBars[$fiveMinIdx];
                $fiveMinIdx++;
            }
            $emaSpread = $activeFiveMinBar !== null ? (float) ($activeFiveMinBar->ema9_ema21_spread ?? 0) : 0.0;
            $fiveMinTrendPrecomputed[$ts] = $activeFiveMinBar !== null
                && ((int) ($activeFiveMinBar->ema9_above_ema21 ?? 0) === 1)
                && ((int) ($activeFiveMinBar->above_vwap ?? 0) === 1)
                && ($emaSpread > 0.0005);
        }

        $is5MinTrendUp = fn (string $ts): bool => $fiveMinTrendPrecomputed[$ts] ?? false;

        // Opening range high (first 5 bars)
        $orHigh = null;
        for ($i = 0; $i < min(5, count($bars)); $i++) {
            $h = (float) ($bars[$i]->high ?? 0);
            $orHigh = ($orHigh === null) ? $h : max($orHigh, $h);
        }

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

        // avg_vol_20 is pre-computed per bar in SQL via AVG(volume) OVER (ROWS BETWEEN 20 PRECEDING AND 1 PRECEDING).
        // Use $bars[$i]->avg_vol_20 directly — no PHP recomputation needed.

        $inLiveWindow = function (string $ts) use ($analysisStart, $analysisEnd): bool {
            return $ts >= $analysisStart && $ts <= $analysisEnd;
        };

        // Calculate choppiness using last 12 five-minute bars (60 minutes)
        $choppiness = [];
        if (count($fiveMinBars) >= 6) {
            $recent5MinBars = array_slice($fiveMinBars, -12);
            $choppiness = $this->calculate5MinChoppiness($recent5MinBars);

            // Filter out choppy 5-minute price action (>= 8 direction changes)
            // Loosened from 6 to allow compression-then-expansion patterns
            if (($choppiness['directional_changes'] ?? 0) >= 8) {
                return [
                    'ok' => false,
                    'symbol' => $symbol,
                    'asset_type' => $assetType,
                    'range_est' => [$from, $to],
                    'bars_found' => count($bars),
                    'filter_reason' => 'Choppy 5-minute action (directional_changes >= 8)',
                ];
            }

            // Require minimum green bars - loosened from 55% to 40%
            // Early morning has fewer bars, so strict green bar % kills good setups
            $totalBars = $choppiness['total_bars'] ?? 1;
            $greenBars = $choppiness['green_bars'] ?? 0;
            $greenBarPct = $greenBars / max(1, $totalBars);
            if ($greenBarPct < 0.40 && $totalBars >= 6) {
                return [
                    'ok' => false,
                    'symbol' => $symbol,
                    'asset_type' => $assetType,
                    'range_est' => [$from, $to],
                    'bars_found' => count($bars),
                    'filter_reason' => sprintf('Insufficient 5m green bars (%.1f%% < 40%%)', $greenBarPct * 100),
                ];
            }
        }

        $candidates = [];

        // -------------------------
        // A) VWAP reclaim
        // -------------------------
        for ($i = 1; $i < count($bars); $i++) {
            $prev = $bars[$i - 1];
            $cur = $bars[$i];

            $ts = (string) $cur->ts_est;
            if (! $inLiveWindow($ts)) {
                continue;
            }

            // Require 5-minute timeframe to also be in uptrend
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

            $baseVol = (float) ($bars[$i]->avg_vol_20 ?? 0);
            $volRatio = ($baseVol > 0) ? ((float) ($cur->volume ?? 0) / $baseVol) : 0.0;
            if ($volRatio < 2.0 || $volRatio > 100.0) {
                continue;
            }

            $entryScore = $this->computeEntryScore($cur);
            if ($entryScore < $minScore || $entryScore > $maxScore) {
                continue;
            }

            [$entryTs, $entryPx] = $computeFill($i);

            $patternScore = min(3.0, $volRatio) + 1.0; // simple pattern score
            $candidates[] = $this->makeCandidate(
                'VWAP_RECLAIM_1M',
                (string) $cur->ts_est,
                $entryTs,
                $entryPx,
                $cur,
                $entryScore,
                $patternScore,
                '1m cross up through VWAP with volume.',
                $volRatio,
                $choppiness
            );

            break;
        }

        // -------------------------
        // B) Pivot high break (lookback pivotLookback before signalTsEst)
        // -------------------------
        $idxSignal = 0;
        for ($i = 0; $i < count($bars); $i++) {
            if ((string) $bars[$i]->ts_est >= $signalTsEst) {
                $idxSignal = $i;
                break;
            }
        }

        $pivotHigh = 0.0;
        $pivotStart = max(0, $idxSignal - max(3, $pivotLookback));
        for ($i = $pivotStart; $i < $idxSignal; $i++) {
            $pivotHigh = max($pivotHigh, (float) ($bars[$i]->high ?? 0));
        }

        if ($pivotHigh > 0) {
            for ($i = $idxSignal; $i < count($bars); $i++) {
                $cur = $bars[$i];
                $ts = (string) $cur->ts_est;
                if (! $inLiveWindow($ts)) {
                    continue;
                }

                // Require 5-minute timeframe to also be in uptrend
                if (! $is5MinTrendUp($ts)) {
                    continue;
                }

                $close = (float) ($cur->price ?? 0);
                if ($close <= $pivotHigh) {
                    continue;
                }

                $baseVol = (float) ($bars[$i]->avg_vol_20 ?? 0);
                $volRatio = ($baseVol > 0) ? ((float) ($cur->volume ?? 0) / $baseVol) : 0.0;
                if ($volRatio < 2.5 || $volRatio > 100.0) {
                    continue;
                }

                $entryScore = $this->computeEntryScore($cur);
                if ($entryScore < $minScore || $entryScore > $maxScore) {
                    continue;
                }

                [$entryTs, $entryPx] = $computeFill($i);
                $patternScore = min(3.0, $volRatio) + 0.8;

                $candidates[] = $this->makeCandidate(
                    'PIVOT_HIGH_BREAK',
                    $ts,
                    $entryTs,
                    $entryPx,
                    $cur,
                    $entryScore,
                    $patternScore,
                    'Break above recent pivot high with volume.',
                    $volRatio,
                    $choppiness
                );
                break;
            }
        }

        // -------------------------
        // C) Opening range breakout (above first 5-min high)
        // -------------------------
        if ($orHigh !== null && $orHigh > 0) {
            for ($i = 1; $i < count($bars); $i++) {
                $prev = $bars[$i - 1];
                $cur = $bars[$i];
                $ts = (string) $cur->ts_est;
                if (! $inLiveWindow($ts)) {
                    continue;
                }

                // Require 5-minute timeframe to also be in uptrend
                if (! $is5MinTrendUp($ts)) {
                    continue;
                }

                $prevHigh = (float) ($prev->high ?? 0);
                $close = (float) ($cur->price ?? 0);

                $breaks = ($prevHigh <= $orHigh) && ($close > $orHigh);
                if (! $breaks) {
                    continue;
                }

                $baseVol = (float) ($bars[$i]->avg_vol_20 ?? 0);
                $volRatio = ($baseVol > 0) ? ((float) ($cur->volume ?? 0) / $baseVol) : 0.0;
                if ($volRatio < 3.0 || $volRatio > 100.0) {
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
                    'Opening range breakout with volume.',
                    $volRatio,
                    $choppiness
                );
                break;
            }
        }

        // -------------------------
        // D) EMA9 bounce (trend continuation)
        // -------------------------
        for ($i = 5; $i < count($bars); $i++) {
            $prev = $bars[$i - 1];
            $cur = $bars[$i];

            $ts = (string) $cur->ts_est;
            if (! $inLiveWindow($ts)) {
                continue;
            }

            // Require 5-minute timeframe to also be in uptrend
            if (! $is5MinTrendUp($ts)) {
                continue;
            }

            $ema9 = (float) ($cur->ema9 ?? 0);
            if ($ema9 <= 0) {
                continue;
            }

            $prevLow = (float) ($prev->low ?? 0);
            $prevTouched = ($prevLow > 0) && (abs($prevLow - (float) ($prev->ema9 ?? 0)) / max(1e-9, (float) ($prev->ema9 ?? 0)) < 0.005);

            $close = (float) ($cur->price ?? 0);
            $open = (float) ($cur->open ?? 0);
            $curAbove = $close > $ema9;
            $green = ($open > 0) ? ($close > $open) : true;

            if (! ($prevTouched && $curAbove && $green)) {
                continue;
            }

            $baseVol = (float) ($bars[$i]->avg_vol_20 ?? 0);
            $volRatio = ($baseVol > 0) ? ((float) ($cur->volume ?? 0) / $baseVol) : 0.0;
            if ($volRatio < 2.0 || $volRatio > 100.0) {
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
                'Bounce off EMA9 with volume.',
                $volRatio,
                $choppiness
            );
            break;
        }

        // -------------------------
        // E) Bull flag breakout (tight range then break)
        // -------------------------
        for ($i = 8; $i < count($bars) - 2; $i++) {
            $ts = (string) $bars[$i]->ts_est;
            if (! $inLiveWindow($ts)) {
                continue;
            }

            // Require 5-minute timeframe to also be in uptrend
            if (! $is5MinTrendUp($ts)) {
                continue;
            }

            // look back last 5 bars for tight range
            $flagHigh = 0.0;
            $flagLow = PHP_FLOAT_MAX;
            for ($j = max(0, $i - 5); $j <= $i - 1; $j++) {
                $flagHigh = max($flagHigh, (float) ($bars[$j]->high ?? 0));
                $flagLow = min($flagLow, (float) ($bars[$j]->low ?? 0));
            }
            if ($flagLow <= 0 || $flagLow === PHP_FLOAT_MAX) {
                continue;
            }

            $rangePct = (($flagHigh - $flagLow) / $flagLow) * 100.0;
            if ($rangePct > 2.0 || $rangePct < 0.15) {
                continue;
            }

            $cur = $bars[$i];
            $close = (float) ($cur->price ?? 0);
            if ($close <= $flagHigh) {
                continue;
            }

            $baseVol = (float) ($bars[$i]->avg_vol_20 ?? 0);
            $volRatio = ($baseVol > 0) ? ((float) ($cur->volume ?? 0) / $baseVol) : 0.0;
            if ($volRatio < 3.0 || $volRatio > 100.0) {
                continue;
            }

            $entryScore = $this->computeEntryScore($cur);
            if ($entryScore < $minScore || $entryScore > $maxScore) {
                continue;
            }

            [$entryTs, $entryPx] = $computeFill($i);
            $patternScore = min(3.0, $volRatio) + (1.5 / max(0.2, $rangePct));

            $candidates[] = $this->makeCandidate(
                'BULL_FLAG_BREAK',
                $ts,
                $entryTs,
                $entryPx,
                $cur,
                $entryScore,
                $patternScore,
                'Tight consolidation breakout with volume.',
                $volRatio,
                $choppiness
            );
            break;
        }

        // -------------------------
        // F) Fallback: Accept scanner signal even if no specific pattern matched
        //    This ensures the scanner's momentum detection isn't wasted
        // -------------------------
        if (empty($candidates)) {
            // Find the best bar in the analysis window that meets minimum entry score
            for ($i = 1; $i < count($bars); $i++) {
                $cur = $bars[$i];
                $ts = (string) $cur->ts_est;

                if (! $inLiveWindow($ts)) {
                    continue;
                }

                $entryScore = $this->computeEntryScore($cur);
                if ($entryScore < $minScore || $entryScore > $maxScore) {
                    continue;
                }

                $baseVol = (float) ($bars[$i]->avg_vol_20 ?? 0);
                $volRatio = ($baseVol > 0) ? ((float) ($cur->volume ?? 0) / $baseVol) : 0.0;

                // Only require 1.5x volume for fallback pattern
                if ($volRatio < 1.5) {
                    continue;
                }

                [$entryTs, $entryPx] = $computeFill($i);
                $patternScore = min(2.0, $volRatio);

                $candidates[] = $this->makeCandidate(
                    'SCANNER_SIGNAL',
                    $ts,
                    $entryTs,
                    $entryPx,
                    $cur,
                    $entryScore,
                    $patternScore,
                    'Scanner momentum signal without specific 1m pattern',
                    $volRatio,
                    $choppiness
                );
                break;
            }
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
                'filter_reason' => 'No entry patterns detected in analysis window',
                'best_entry' => null,
                'candidates' => [],
                'meta' => [
                    'entry_score_min' => $minScore,
                    'entry_score_max' => $maxScore,
                    'version' => $this->version,
                ],
            ];
        }

        // v61.0: DATA-DRIVEN FINAL FILTER - Apply after all pattern detection
        // Score 40-59 (5.40% P/L) OR 80-100 (1.28% P/L) - REJECT 0-39 and 60-79
        $candidates = array_filter($candidates, function ($c) {
            $score = (float) ($c['score'] ?? 0);
            $hour = isset($c['trigger_ts_est']) ? (int) substr($c['trigger_ts_est'], 11, 2) : 9;
            $type = $c['type'] ?? '';

            // Proven types only (+ SCANNER_SIGNAL fallback)
            $allowedTypes = [
                'VWAP_RECLAIM_1M', 'PIVOT_HIGH_BREAK', 'BULL_FLAG_BREAK',
                'EMA9_BOUNCE', 'BOTTOM_ENTRY', 'OR_BREAKOUT', 'BREAKOUT_RETEST',
                'EMA_CROSS_BULL', 'EMA21_BOUNCE', 'MA_SQUEEZE', 'ORB_RETEST',
                'SCANNER_SIGNAL', // Fallback for scanner momentum signals
            ];
            if (! in_array($type, $allowedTypes)) {
                return false;
            }

            // No 3pm entries
            if ($hour >= 15) {
                return false;
            }

            // Type-specific filters based on v60.3 backtest data analysis
            $atrPct = (float) ($c['atr_pct'] ?? 0);
            $volRatio = (float) ($c['vol_ratio'] ?? 0);

            if ($type === 'BULL_FLAG_BREAK') {
                // Loosened significantly to allow more setups through
                // ATR <0.8%, vol 2.0-8x, score 70-100
                if ($atrPct > 0.8) {
                    return false;
                }
                if ($volRatio < 2.0 || $volRatio > 8.0) {
                    return false;
                }

                return $score >= 70.0 && $score <= 100.0;
            }

            if ($type === 'VWAP_RECLAIM_1M') {
                // Loosened significantly to allow more setups through
                // ATR 0.1-1.5%, vol <6x, score 80-100
                if ($atrPct < 0.1 || $atrPct > 1.5) {
                    return false;
                }
                if ($volRatio >= 6.0) {
                    return false;
                }

                return $score >= 80.0 && $score <= 100.0;
            }

            // For all other types, use original score filter: 40-59 OR 80-100
            if (($score >= 40.0 && $score < 60.0) || ($score >= 80.0 && $score <= 100.0)) {
                return true;
            }

            return false;
        });

        if (empty($candidates)) {
            return [
                'ok' => false,
                'filter_reason' => 'No qualifying entries after data-driven filters.',
                'reason' => 'No qualifying entries after data-driven filters.',
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
                ],
            ];
        }

        // Sort: most recent entry_ts_est first, then EntryScore, then pattern score
        usort($candidates, function ($a, $b) {
            $timeCmp = ($b['entry_ts_est'] ?? '') <=> ($a['entry_ts_est'] ?? '');
            if ($timeCmp !== 0) {
                return $timeCmp;
            }
            if ($b['score'] !== $a['score']) {
                return $b['score'] <=> $a['score'];
            }

            return $b['pattern_score'] <=> $a['pattern_score'];
        });

        $best = $candidates[0];

        // Targets (R-multiples)
        $r = max(1e-9, (float) $best['risk_per_share']);
        $best['targets'] = [
            '1R' => round((float) $best['entry'] + 1.0 * $r, 6),
            '2R' => round((float) $best['entry'] + 2.0 * $r, 6),
            '3R' => round((float) $best['entry'] + 3.0 * $r, 6),
        ];

        // Suggested trailing stop = live trading settings with bounds
        $atr = (float) ($best['atr'] ?? 0);
        $entryPrice = (float) $best['entry'];
        $atrMultiplier = \App\Services\TradingSettingService::getStopLossAtrMultiplier();
        $minPct = \App\Services\TradingSettingService::getStopLossAtrMinPct();
        $maxPct = \App\Services\TradingSettingService::getStopLossAtrMaxPct();
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
            ],
        ];
    }

    /**
     * Calculate 5-minute choppiness metrics to detect erratic price action
     *
     * @param  array  $fiveMinBars  Array of 5-minute bars (most recent 6-12 bars)
     * @return array ['directional_changes', 'green_bar_pct', 'net_progress']
     */
    private function calculate5MinChoppiness(array $fiveMinBars): array
    {
        if (count($fiveMinBars) < 2) {
            return [
                'directional_changes' => 0,
                'green_bar_pct' => 0.0,
                'net_progress' => 0.0,
            ];
        }

        $dirChanges = 0;
        $greenBars = 0;
        $totalRange = 0.0;

        $lastDir = null;
        foreach ($fiveMinBars as $idx => $bar) {
            $open = (float) ($bar->open ?? 0);
            $close = (float) ($bar->price ?? 0);
            $high = (float) ($bar->high ?? $close);
            $low = (float) ($bar->low ?? $close);

            // Direction
            $currentDir = $close >= $open ? 'up' : 'down';
            if ($lastDir !== null && $currentDir !== $lastDir) {
                $dirChanges++;
            }
            $lastDir = $currentDir;

            // Green bars
            if ($close >= $open) {
                $greenBars++;
            }

            // Range
            $totalRange += ($high - $low);
        }

        // Net progress = net price movement / total range covered
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

    private function makeCandidate(
        string $type,
        string $triggerTs,
        string $entryTs,
        float $entryPx,
        object $row,
        float $entryScore,
        float $patternScore,
        string $notes,
        float $volRatio = 0.0,
        array $choppiness = []
    ): array {
        // Stop: ATR-based if available, otherwise percent-based.
        $atr = (float) ($row->atr ?? 0);
        $atrStopDist = ($atr > 0) ? ($atr * 1.8) : 0.0;
        $pctStopDist = $entryPx * 0.0085; // 0.85% baseline
        $stopDist = max($atrStopDist, $pctStopDist);

        $rawStop = $entryPx - $stopDist;

        // Clamp risk to the configured band
        $minStopPct = \App\Services\TradingSettingService::getStopLossAtrMinPct();
        $maxStopPct = \App\Services\TradingSettingService::getStopLossAtrMaxPct();
        $minStop = $entryPx * (1 - ($minStopPct / 100));
        $maxStop = $entryPx * (1 - ($maxStopPct / 100));

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
            'type' => $type,
            'trigger_ts_est' => $triggerTs,
            'entry_ts_est' => $entryTs,
            'entry' => round($entryPx, 6),
            'stop' => round($stop, 6),

            // Universal score = EntryScore (0..100)
            'score' => round($entryScore, 2),

            // Pattern score for diagnostics/tie-breaks
            'pattern_score' => round($patternScore, 3),

            'atr' => round((float) ($row->atr ?? 0), 6),
            'atr_pct' => (float) ($row->atr_pct ?? 0),
            'risk_per_share' => round($risk, 6),
            'risk_pct' => round($riskPct, 3),

            'vol_ratio' => $volRatio > 0 ? round($volRatio, 3) : null,

            'vwap' => $row->vwap !== null ? round((float) $row->vwap, 6) : null,
            'ema9' => $row->ema9 !== null ? round((float) $row->ema9, 6) : null,
            'ema21' => $row->ema21 !== null ? round((float) $row->ema21, 6) : null,

            'five_min_directional_changes' => $choppiness['directional_changes'] ?? null,
            'five_min_green_bar_pct' => isset($choppiness['green_bar_pct']) ? round($choppiness['green_bar_pct'], 1) : null,
            'five_min_net_progress' => isset($choppiness['net_progress']) ? round($choppiness['net_progress'], 3) : null,

            'notes' => $notes.' Filtered by EntryScore window.',
        ];
    }

    /**
     * v50 EntryScore formula (same shape)
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
