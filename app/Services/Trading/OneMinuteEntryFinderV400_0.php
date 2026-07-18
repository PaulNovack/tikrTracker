<?php

namespace App\Services\Trading;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * One-Minute Entry Finder V400.0 - Trend Continuation Entry Confirmation
 *
 * Uses 1-minute bars to confirm continuation is "real right now":
 *
 * During pullback:
 * - Red candles are small, wicks are buyable, volume dries up
 *
 * Then a shift (continuation entry triggers):
 * - Higher low on 1m (HH/HL micro-structure)
 * - Break of 1m downtrend line / last lower high
 * - 1m reclaim of VWAP or EMA9
 * - Volume expansion on turn candle(s) (buyers show up)
 *
 * Entry Types:
 * 1. BULL_FLAG_BREAK - Sharp push → tight drift → breakout
 * 2. HIGHER_LOW_BOUNCE - Higher low at 5m support (EMA9/21, VWAP)
 * 3. FAILED_PUSH_DOWN - Same low holds 2-3 times then pops
 * 4. VWAP_EMA_RECLAIM - Reclaiming key moving averages
 * 5. VOLUME_SURGE_CONT - Volume expansion at continuation zone
 *
 * Continuation failures (avoids):
 * - Bounces weak: can't take out prior 1m swing high
 * - Lower highs stacking and VWAP reclaim keeps failing
 * - Big red candles on high volume (5m rolling over)
 */
class OneMinuteEntryFinderV400_0
{
    use HasPriceTables;

    private string $version = 'v400.0';

    public function getVersion(): string
    {
        return $this->version;
    }

    public function findBestLong(
        string $symbol,
        string $assetType,
        string $signalTsEst,
        string $asOfTsEst,
        int|array $beforeMinutesOrOptions = 10,
        int $afterMinutes = 30,
        int $volLookback = 20,
        int $pivotLookback = 15,
        string $fillModel = 'next_open',
        int $staleMinutes = 5 // Maximum age for entries to be considered fresh
    ): array {
        // Handle options array (like v200.0) or individual parameters
        if (is_array($beforeMinutesOrOptions)) {
            $options = $beforeMinutesOrOptions;
            $beforeMinutes = $options['beforeMinutes'] ?? 10;
            $afterMinutes = $options['afterMinutes'] ?? 30;
            $signalMeta = $options['signalMeta'] ?? [];
            $fillModel = $options['fillModel'] ?? 'next_open';
            $volLookback = $options['volLookback'] ?? 20;
            $pivotLookback = $options['pivotLookback'] ?? 15;
            $staleMinutes = $options['freshnessMinutes'] ?? 5;
        } else {
            $beforeMinutes = $beforeMinutesOrOptions;
            $signalMeta = [];
        }
        // Config
        $minVolRatio = (float) config('trading.v400.entry_min_vol_ratio', 1.3);
        $minScore = (float) config('trading.v400.entry_score_min', 94);
        $maxScore = (float) config('trading.v400.entry_score_max', 95);

        // Extract signal metadata for filters
        $signalTs = $signalMeta['ts_est'] ?? $signalTsEst;
        $continuationScore = (float) ($signalMeta['continuation_score'] ?? 0);

        // NOTE: Scanner passes top-level values to signalMeta array via pipeline
        // Don't apply base gate filters here - scanner already filtered everything

        if ($maxScore <= 0) {
            $maxScore = 100.0;
        }
        if ($minScore > $maxScore) {
            [$minScore, $maxScore] = [$maxScore, $minScore];
        }

        // Live analysis window relative to NOW (asOf)
        $analysisEnd = $asOfTsEst;
        $analysisStart = date('Y-m-d H:i:s', strtotime($asOfTsEst.' -'.$beforeMinutes.' minutes'));

        $tradeDate = substr($signalTsEst, 0, 10);
        $marketOpen = $tradeDate.' 09:30:00';
        if ($analysisStart < $marketOpen) {
            $analysisStart = $marketOpen;
        }

        $from = $marketOpen;
        $to = $analysisEnd;
        $bucketTs = date('Y-m-d H:i', strtotime($to));

        // Get 1-minute bars
        $cacheKey1m = "1m_bars:v400_0:{$assetType}:{$symbol}:{$tradeDate}:{$bucketTs}";
        $bars = Cache::remember($cacheKey1m, 90, function () use ($assetType, $symbol, $tradeDate, $from, $to) {
            return $this->dbSelect('
                SELECT
                                    symbol,
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

        if (! $bars || count($bars) < 15) {
            return [
                'ok' => false,
                'error' => 'Not enough 1m data for continuation analysis',
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
            if ($prevClose > 0) {
                $dropPct = (($currentOpen - $prevClose) / $prevClose) * 100.0;
                if ($dropPct < -50.0) {
                    return [
                        'ok' => false,
                        'error' => 'Bad data detected - extreme price drop (reverse split or data error)',
                        'symbol' => $symbol,
                        'asset_type' => $assetType,
                        'drop_pct' => round($dropPct, 2),
                    ];
                }
            }
        }

        // Extract continuation context from scanner metadata
        $periodHigh = $signalMeta['period_high'] ?? 0;
        $periodLow = $signalMeta['period_low'] ?? 0;
        $higherLowCount = $signalMeta['higher_low_count'] ?? 0;
        $ema9SlopePct = $signalMeta['ema9_slope_pct'] ?? 0;
        $continuationScore = $signalMeta['continuation_score'] ?? 0;

        // Helpers
        $inLiveWindow = function (string $ts) use ($analysisStart, $analysisEnd): bool {
            return $ts >= $analysisStart && $ts <= $analysisEnd;
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

        $candidates = [];

        // --------------------------------------------------
        // ENTRY TYPE 1: BULL_FLAG_BREAK
        // Sharp push up → tight consolidation → breakout
        // --------------------------------------------------
        $flagHigh = null;
        $flagLow = null;
        $flagStart = null;

        for ($i = 5; $i < count($bars) - 3; $i++) {
            if (! $inLiveWindow((string) $bars[$i]->ts_est)) {
                continue;
            }

            // Look for consolidation pattern (3-6 bars)
            $consolidationBars = array_slice($bars, $i - 3, 6);
            $consolidationHigh = max(array_map(fn ($b) => (float) $b->high, $consolidationBars));
            $consolidationLow = min(array_map(fn ($b) => (float) $b->low, $consolidationBars));
            $consolidationRange = $consolidationHigh - $consolidationLow;
            $consolidationMid = ($consolidationHigh + $consolidationLow) / 2;

            // Tight range (< 1.5% of price, loosened from 0.5%)
            if ($consolidationRange / $consolidationMid > 0.015) {
                continue;
            }

            // Current bar breaks above consolidation high (allow equal)
            $cur = $bars[$i];
            $curClose = (float) $cur->price;

            if ($curClose < $consolidationHigh) {
                continue;
            }

            // Volume expansion
            $baseVol = $volAvgBefore($i);
            $volRatio = ($baseVol > 0) ? ((float) ($cur->volume ?? 0) / $baseVol) : 0.0;
            if ($volRatio < $minVolRatio) {
                continue;
            }

            // Must be above VWAP
            if (! (int) ($cur->above_vwap ?? 0)) {
                continue;
            }

            $entryScore = $this->computeEntryScore($cur, $continuationScore);
            if ($entryScore < $minScore || $entryScore > $maxScore) {
                continue;
            }

            [$entryTs, $entryPx] = $computeFill($i);

            $candidates[] = $this->makeCandidate(
                'BULL_FLAG_BREAK',
                (string) $cur->ts_est,
                $entryTs,
                $entryPx,
                $cur,
                $entryScore,
                min(5.0, $volRatio) + 2.0,
                sprintf('Bull flag break with %.1fx volume', $volRatio),
                $volRatio
            );
            break; // Take first match
        }

        // --------------------------------------------------
        // ENTRY TYPE 2: HIGHER_LOW_BOUNCE
        // Higher low at 5m support (EMA9/21, VWAP)
        // Extra filters: higher low vs prior 5-10 bars, close in top 60% of candle, above/reclaim VWAP
        // --------------------------------------------------
        $swingLows = [];
        for ($i = 2; $i < count($bars) - 2; $i++) {
            $low = (float) $bars[$i]->low;
            $prevLow = (float) $bars[$i - 1]->low;
            $nextLow = (float) $bars[$i + 1]->low;

            if ($low < $prevLow && $low < $nextLow) {
                $swingLows[] = ['i' => $i, 'low' => $low, 'ts' => (string) $bars[$i]->ts_est];
            }
        }

        if (count($swingLows) >= 2) {
            $lastTwo = array_slice($swingLows, -2);
            if ($lastTwo[1]['low'] > $lastTwo[0]['low']) {
                // Higher low detected
                $i = $lastTwo[1]['i'] + 1; // Entry bar after higher low

                if ($i < count($bars) && $inLiveWindow((string) $bars[$i]->ts_est)) {
                    $cur = $bars[$i];
                    $curClose = (float) $cur->price;
                    $ema9 = (float) ($cur->ema9 ?? 0);
                    $vwap = (float) ($cur->vwap ?? 0);

                    // Bounce off EMA9 or VWAP
                    if ($curClose >= $ema9 || $curClose >= $vwap) {
                        $baseVol = $volAvgBefore($i);
                        $volRatio = ($baseVol > 0) ? ((float) ($cur->volume ?? 0) / $baseVol) : 0.0;

                        if ($volRatio >= $minVolRatio) {
                            $entryScore = $this->computeEntryScore($cur, $continuationScore);

                            if ($entryScore >= $minScore && $entryScore <= $maxScore) {
                                [$entryTs, $entryPx] = $computeFill($i);

                                $candidates[] = $this->makeCandidate(
                                    'HIGHER_LOW_BOUNCE',
                                    (string) $cur->ts_est,
                                    $entryTs,
                                    $entryPx,
                                    $cur,
                                    $entryScore,
                                    min(4.5, $volRatio) + 1.5,
                                    'Higher low bounce at support',
                                    $volRatio
                                );
                            }
                        }
                    }
                }
            }
        }

        // --------------------------------------------------
        // ENTRY TYPE 3: FAILED_PUSH_DOWN
        // Same low holds 2-3 times then pops
        // --------------------------------------------------
        for ($i = 3; $i < count($bars) - 1; $i++) {
            if (! $inLiveWindow((string) $bars[$i]->ts_est)) {
                continue;
            }

            $recentLows = array_map(fn ($b) => (float) $b->low, array_slice($bars, max(0, $i - 5), 5));
            $minLow = min($recentLows);

            // Count how many times we've touched this low (within 0.2%)
            $touchCount = 0;
            foreach ($recentLows as $low) {
                if (abs($low - $minLow) / $minLow < 0.002) {
                    $touchCount++;
                }
            }

            if ($touchCount < 2) {
                continue;
            }

            // Current bar pops above
            $cur = $bars[$i];
            $curClose = (float) $cur->price;
            $curHigh = (float) $cur->high;

            $recentHigh = max(array_map(fn ($b) => (float) $b->high, array_slice($bars, max(0, $i - 3), 3)));

            if ($curHigh <= $recentHigh) {
                continue;
            }

            // Volume surge
            $baseVol = $volAvgBefore($i);
            $volRatio = ($baseVol > 0) ? ((float) ($cur->volume ?? 0) / $baseVol) : 0.0;
            if ($volRatio < $minVolRatio * 1.2) {
                continue;
            }

            $entryScore = $this->computeEntryScore($cur, $continuationScore);
            if ($entryScore < $minScore || $entryScore > $maxScore) {
                continue;
            }

            [$entryTs, $entryPx] = $computeFill($i);

            $candidates[] = $this->makeCandidate(
                'FAILED_PUSH_DOWN',
                (string) $cur->ts_est,
                $entryTs,
                $entryPx,
                $cur,
                $entryScore,
                min(5.0, $volRatio) + 1.0,
                sprintf('%d failed push attempts then pop', $touchCount),
                $volRatio
            );
            break;
        }

        // --------------------------------------------------
        // ENTRY TYPE 4: VWAP_EMA_RECLAIM
        // Reclaiming key moving averages
        // --------------------------------------------------
        for ($i = 1; $i < count($bars); $i++) {
            if (! $inLiveWindow((string) $bars[$i]->ts_est)) {
                continue;
            }

            $prev = $bars[$i - 1];
            $cur = $bars[$i];

            $prevClose = (float) $prev->price;
            $curClose = (float) $cur->price;
            $prevVwap = (float) ($prev->vwap ?? 0);
            $curVwap = (float) ($cur->vwap ?? 0);
            $curEma9 = (float) ($cur->ema9 ?? 0);

            // Reclaim VWAP or EMA9
            $vwapReclaim = ($prevClose < $prevVwap) && ($curClose > $curVwap);
            $ema9Reclaim = ($prevClose < $curEma9) && ($curClose > $curEma9);

            if (! $vwapReclaim && ! $ema9Reclaim) {
                continue;
            }

            // Volume confirmation
            $baseVol = $volAvgBefore($i);
            $volRatio = ($baseVol > 0) ? ((float) ($cur->volume ?? 0) / $baseVol) : 0.0;
            if ($volRatio < $minVolRatio) {
                continue;
            }

            $entryScore = $this->computeEntryScore($cur, $continuationScore);
            if ($entryScore < $minScore || $entryScore > $maxScore) {
                continue;
            }

            [$entryTs, $entryPx] = $computeFill($i);

            $reclaimType = $vwapReclaim ? 'VWAP' : 'EMA9';

            $candidates[] = $this->makeCandidate(
                'VWAP_EMA_RECLAIM',
                (string) $cur->ts_est,
                $entryTs,
                $entryPx,
                $cur,
                $entryScore,
                min(4.0, $volRatio) + 1.5,
                sprintf('%s reclaim with volume', $reclaimType),
                $volRatio
            );
            break;
        }

        // --------------------------------------------------
        // ENTRY TYPE 5: VOLUME_SURGE_CONT
        // Volume expansion at continuation zone
        // --------------------------------------------------
        for ($i = 1; $i < count($bars); $i++) {
            if (! $inLiveWindow((string) $bars[$i]->ts_est)) {
                continue;
            }

            $cur = $bars[$i];
            $baseVol = $volAvgBefore($i);
            $volRatio = ($baseVol > 0) ? ((float) ($cur->volume ?? 0) / $baseVol) : 0.0;

            // Strong volume surge (2x+)
            if ($volRatio < 2.0) {
                continue;
            }

            // Must be green candle closing near high
            $open = (float) ($cur->open ?? 0);
            $close = (float) $cur->price;
            $high = (float) $cur->high;
            $low = (float) $cur->low;

            if ($close <= $open) {
                continue;
            }

            $candleRange = $high - $low;
            $closeFromHigh = $high - $close;

            if ($candleRange > 0 && $closeFromHigh / $candleRange > 0.3) {
                continue; // Not closing near high
            }

            // Above VWAP
            if (! (int) ($cur->above_vwap ?? 0)) {
                continue;
            }

            $entryScore = $this->computeEntryScore($cur, $continuationScore);
            if ($entryScore < $minScore || $entryScore > $maxScore) {
                continue;
            }

            [$entryTs, $entryPx] = $computeFill($i);

            $candidates[] = $this->makeCandidate(
                'VOLUME_SURGE_CONT',
                (string) $cur->ts_est,
                $entryTs,
                $entryPx,
                $cur,
                $entryScore,
                min(5.5, $volRatio) + 0.5,
                sprintf('%.1fx volume surge continuation', $volRatio),
                $volRatio
            );
            break;
        }

        // Return best candidate
        if (empty($candidates)) {
            return [
                'ok' => false,
                'error' => 'No continuation entry patterns found in analysis window',
                'symbol' => $symbol,
                'asset_type' => $assetType,
                'candidates_checked' => 0,
            ];
        }

        usort($candidates, fn ($a, $b) => $b['pattern_score'] <=> $a['pattern_score']);

        $best = $candidates[0];

        return [
            'ok' => true,
            'symbol' => $symbol,
            'asset_type' => $assetType,
            'best_entry' => $best,
            'candidates' => $candidates,
        ];
    }

    private function computeEntryScore(object $bar, float $continuationScore = 0): float
    {
        $score = 0.0;

        // Base continuation score passed from scanner
        $score += min(40.0, $continuationScore * 0.4);

        // VWAP alignment
        if ((int) ($bar->above_vwap ?? 0)) {
            $score += 15.0;
        }

        // EMA alignment
        if ((int) ($bar->ema9_above_ema21 ?? 0)) {
            $score += 15.0;
        }

        // Price vs EMA9
        $price = (float) ($bar->price ?? 0);
        $ema9 = (float) ($bar->ema9 ?? 0);
        if ($price > 0 && $ema9 > 0 && $price >= $ema9) {
            $score += 10.0;
        }

        // Candle quality (green, closing near high)
        $open = (float) ($bar->open ?? 0);
        $close = (float) $bar->price;
        $high = (float) $bar->high;
        $low = (float) $bar->low;

        if ($close > $open) {
            $score += 10.0;
            $range = $high - $low;
            if ($range > 0) {
                $closePos = ($close - $low) / $range;
                $score += $closePos * 10.0; // Up to 10 points for closing near high
            }
        }

        return round($score, 2);
    }

    private function makeCandidate(
        string $entryType,
        string $signalTs,
        string $entryTs,
        float $entryPrice,
        object $bar,
        float $entryScore,
        float $patternScore,
        string $reason,
        float $volRatio
    ): array {
        $atrPct = (float) ($bar->atr_pct ?? 0);
        if ($atrPct <= 0) {
            $atrPct = $this->estimateAtrPct($bar, $entryPrice);
            $symbol = (string) ($bar->symbol ?? '?');
            $tsEst = (string) ($bar->ts_est ?? '?');
            $cacheKey = "pipeline_e:atr_pct_fallback_logged:{$symbol}:{$tsEst}";

            if (Cache::add($cacheKey, true, now()->addMinutes(20))) {
                Log::warning('Pipeline E: atr_pct missing on entry bar, using estimated fallback', [
                    'symbol' => $symbol,
                    'ts_est' => $tsEst,
                    'estimated_atr_pct' => round($atrPct, 4),
                ]);
            }
        }
        $atr = ($atrPct > 0 && $entryPrice > 0) ? ($atrPct / 100 * $entryPrice) : null;

        // Calculate ATR-based stop using live trading settings
        $atrMultiplier = \App\Services\TradingSettingService::getStopLossAtrMultiplier();
        $minStopPct = \App\Services\TradingSettingService::getStopLossAtrMinPct();
        $maxStopPct = \App\Services\TradingSettingService::getStopLossAtrMaxPct();
        $calculatedPct = $atrPct > 0 ? ($atrPct * $atrMultiplier) : $maxStopPct;
        $trailPct = max($minStopPct, min($maxStopPct, $calculatedPct));
        $stop = $entryPrice * (1 - ($trailPct / 100.0));

        $suggestedTrailingStop = $entryPrice > 0 ? ($entryPrice * ($trailPct / 100.0)) : null;
        $suggestedTrailingStopPct = $trailPct;

        // Calculate R-multiple targets
        $riskPerShare = $entryPrice - $stop;
        $targets = [
            '1R' => round($entryPrice + ($riskPerShare * 1.0), 4),
            '2R' => round($entryPrice + ($riskPerShare * 2.0), 4),
            '3R' => round($entryPrice + ($riskPerShare * 3.0), 4),
        ];

        return [
            'ok' => true,
            'type' => $entryType, // Match TradeAlertWriter expected key
            'entry_type' => $entryType,
            'signal_ts_est' => $signalTs,
            'entry_ts_est' => $entryTs,
            'entry' => round($entryPrice, 4),
            'stop' => round($stop, 4),
            'risk_pct' => round(($entryPrice - $stop) / $entryPrice * 100, 2),
            'risk_per_share' => round($entryPrice - $stop, 4),
            'score' => round($entryScore, 2), // Add 'score' for writer
            'entry_score' => round($entryScore, 2),
            'pattern_score' => round($patternScore, 2),
            'vol_ratio' => round($volRatio, 2),
            'atr' => $atr ? round($atr, 6) : null,
            'atr_pct' => round($atrPct, 3),
            'suggested_trailing_stop' => $suggestedTrailingStop ? round($suggestedTrailingStop, 6) : null,
            'suggested_trailing_stop_pct' => $suggestedTrailingStopPct ? round($suggestedTrailingStopPct, 6) : null,
            'targets' => $targets,
            'vwap' => round((float) ($bar->vwap ?? 0), 4),
            'ema9' => round((float) ($bar->ema9 ?? 0), 4),
            'ema21' => round((float) ($bar->ema21 ?? 0), 4),
            'above_vwap' => (int) ($bar->above_vwap ?? 0),
            'ema9_above_ema21' => (int) ($bar->ema9_above_ema21 ?? 0),
            'reason' => $reason,
            'version' => $this->version,
        ];
    }

    /**
     * Estimate ATR percentage from the bar's high-low range when atr_pct is missing.
     * Uses (high-low)/close × 100 as an approximation, capped by config min/max.
     */
    private function estimateAtrPct(object $bar, float $entryPrice): float
    {
        $high = (float) ($bar->high ?? 0);
        $low = (float) ($bar->low ?? 0);
        $close = (float) ($bar->close ?? ($bar->price ?? 0));

        if ($high > 0 && $low > 0 && $close > 0 && $high >= $low) {
            $rangePct = (($high - $low) / $close) * 100;
            $min = \App\Services\TradingSettingService::getStopLossAtrMinPct();
            $max = \App\Services\TradingSettingService::getStopLossAtrMaxPct();

            return max($min, min($max, $rangePct));
        }

        return \App\Services\TradingSettingService::getStopLossAtrMinPct();
    }
}
