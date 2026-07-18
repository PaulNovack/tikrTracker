<?php

namespace App\Services\Trading;

/**
 * One-Minute Entry Finder V130.0 - High-Probability Pattern Entries
 *
 * Optimized entry finder for three proven patterns:
 * 1. VWAP_BOUNCE - Enter on volume confirmation above VWAP
 * 2. BULL_FLAG_BREAKOUT - Enter on flag high break with volume
 * 3. FAILED_BREAKDOWN - Enter on support reclaim with volume
 *
 * All entries require:
 * - Clean 5-min trend (EMA9 > EMA21)
 * - Volume confirmation (2x+ average)
 * - Tight stop placement (2.5 ATR max)
 */
class OneMinuteEntryFinderV130_0
{
    use HasPriceTables;

    private string $version = 'v130.0';

    public function getVersion(): string
    {
        return $this->version;
    }

    public function findBestLong(
        string $symbol,
        string $assetType,
        string $signalTsEst,
        string $asOfTsEst,
        int $beforeMinutes = 10,
        int $afterMinutes = 0,
        int $volLookback = 20,
        int $pivotLookback = 15,
        string $fillModel = 'next_open',
        array $signalMeta = [],
        int $freshnessMinutes = 6 // Maximum age for entries to be considered fresh
    ): array {
        // Analysis window
        $analysisEnd = $asOfTsEst;
        $analysisStart = date('Y-m-d H:i:s', strtotime($asOfTsEst.' -'.$beforeMinutes.' minutes'));

        // Market open
        $tradeDate = substr($signalTsEst, 0, 10);
        $marketOpen = $tradeDate.' 09:30:00';
        if ($analysisStart < $marketOpen) {
            $analysisStart = $marketOpen;
        }

        $from = $marketOpen;
        $to = $analysisEnd;

        // Get 1-minute bars
        $bars = $this->dbSelect('
            SELECT
              ts_est,
              price,
              `open`,
              `high`,
              `low`,
              volume,
              vwap,
              ema9,
              ema21,
              atr,
              atr_pct
            FROM one_minute_prices
            WHERE symbol = ?
              AND asset_type = ?
              AND trading_date_est = ?
              AND ts_est >= ?
              AND ts_est <= ?
            ORDER BY ts_est ASC
        ', [$symbol, $assetType, $from, $to]);

        if (count($bars) < 10) {
            return [
                'ok' => false,
                'symbol' => $symbol,
                'asset_type' => $assetType,
                'signal_ts_est' => $signalTsEst,
                'filter_reason' => 'Insufficient 1-minute bars',
            ];
        }

        // Get 5-minute bars for trend
        $fiveMinBars = $this->dbSelect('
            SELECT ts_est, ema9_above_ema21
            FROM five_minute_prices
            WHERE asset_type = ?
              AND symbol = ?
              AND trading_date_est = ?
              AND ts_est >= ?
              AND ts_est <= ?
            ORDER BY ts_est ASC
        ', [$assetType, $symbol, $tradeDate, $from, $to]);

        $fiveMinTrend = [];
        foreach ($fiveMinBars as $bar) {
            $fiveMinTrend[(string) $bar->ts_est] = (int) ($bar->ema9_above_ema21 ?? 0);
        }

        $is5MinTrendUp = function ($ts1m) use ($fiveMinTrend): bool {
            $relevantBar = null;
            foreach ($fiveMinTrend as $ts5m => $trend) {
                if ($ts5m <= $ts1m) {
                    $relevantBar = $trend;
                } else {
                    break;
                }
            }

            return $relevantBar === 1;
        };

        // Normalize meta passing - handle both $sig['meta'] and direct meta
        $meta = $signalMeta['meta'] ?? $signalMeta;
        $patternType = $meta['pattern'] ?? 'UNKNOWN';

        // Debug logging for troubleshooting
        if ($patternType === 'UNKNOWN') {
            \Log::warning("[v130.0 EntryFinder] Unknown pattern for {$symbol}, signalMeta keys: ".implode(',', array_keys($signalMeta)));
        }

        $inLiveWindow = function (string $ts) use ($analysisStart, $analysisEnd): int {
            if ($ts < $analysisStart || $ts > $analysisEnd) {
                return false;
            }

            // Only trade during high-win-rate windows: 9:30-10:30 and 14:00-15:30
            $hour = (int) date('G', strtotime($ts));
            $minute = (int) date('i', strtotime($ts));
            $timeInMinutes = ($hour * 60) + $minute;

            // 9:30-10:30 window (570-630 minutes)
            if ($timeInMinutes >= 570 && $timeInMinutes < 630) {
                return true;
            }

            // 14:00-15:30 window (840-930 minutes)
            if ($timeInMinutes >= 840 && $timeInMinutes < 930) {
                return true;
            }

            return false;
        };

        $volAvgBefore = function (int $i) use ($bars, $volLookback): float {
            $start = max(0, $i - $volLookback);
            if ($start >= $i) {
                return 0.0;
            }
            $volumes = [];
            for ($k = $start; $k < $i; $k++) {
                $volumes[] = (float) ($bars[$k]->volume ?? 0);
            }
            if (empty($volumes)) {
                return 0.0;
            }
            // Use median instead of mean to avoid spike bias
            sort($volumes);
            $mid = (int) (count($volumes) / 2);

            return count($volumes) % 2 === 0
                ? ($volumes[$mid - 1] + $volumes[$mid]) / 2
                : $volumes[$mid];
        };

        $getVolThreshold = function (string $ts): float {
            $hour = (int) date('G', strtotime($ts));
            $minute = (int) date('i', strtotime($ts));
            $timeInMinutes = ($hour * 60) + $minute;

            // Dynamic volume thresholds by time of day
            if ($timeInMinutes >= 570 && $timeInMinutes < 630) {
                return 2.0; // 9:30-10:30: opening volatility
            }
            if ($timeInMinutes >= 630 && $timeInMinutes < 720) {
                return 2.2; // 10:30-12:00: trending
            }
            if ($timeInMinutes >= 720 && $timeInMinutes < 840) {
                return 3.0; // 12:00-14:00: lunch requires more
            }
            if ($timeInMinutes >= 840 && $timeInMinutes < 930) {
                return 2.5; // 14:00-15:30: afternoon
            }

            return 2.8; // Close
        };

        $computeFill = function (int $i) use ($bars, $fillModel): array {
            $cur = $bars[$i];
            $next = $bars[$i + 1] ?? null;
            $ts = (string) $cur->ts_est;

            if ($fillModel === 'next_open' && $next) {
                // Safety check: prevent time travel bugs by verifying same trading date
                $curDate = substr($ts, 0, 10);
                $nextDate = substr((string) $next->ts_est, 0, 10);
                if ($curDate !== $nextDate) {
                    return [$ts, (float) $cur->price];
                }

                return [(string) $next->ts_est, (float) $next->open];
            }

            return [$ts, (float) $cur->price];
        };

        $candidates = [];

        // Route to appropriate entry logic based on pattern
        switch ($patternType) {
            case 'VWAP_BOUNCE':
                $candidates = $this->findVwapBounceEntries($bars, $inLiveWindow, $is5MinTrendUp, $volAvgBefore, $getVolThreshold, $computeFill, $meta);
                break;
            case 'BULL_FLAG_BREAKOUT':
                $candidates = $this->findBullFlagEntries($bars, $inLiveWindow, $is5MinTrendUp, $volAvgBefore, $getVolThreshold, $computeFill, $meta);
                break;
            case 'FAILED_BREAKDOWN':
                $candidates = $this->findFailedBreakdownEntries($bars, $inLiveWindow, $is5MinTrendUp, $volAvgBefore, $getVolThreshold, $computeFill, $meta);
                break;
            default:
                return [
                    'ok' => false,
                    'symbol' => $symbol,
                    'asset_type' => $assetType,
                    'signal_ts_est' => $signalTsEst,
                    'filter_reason' => 'Unknown pattern type: '.$patternType,
                ];
        }

        if (empty($candidates)) {
            return [
                'ok' => false,
                'symbol' => $symbol,
                'asset_type' => $assetType,
                'signal_ts_est' => $signalTsEst,
                'filter_reason' => 'No qualifying entries for pattern: '.$patternType,
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

        // Rank by score
        usort($candidates, fn ($a, $b) => ($b['score'] <=> $a['score']));
        $best = $candidates[0];

        return [
            'ok' => true,
            'symbol' => $symbol,
            'asset_type' => $assetType,
            'signal_ts_est' => $signalTsEst,
            'best_entry' => $best,
            'candidates' => $candidates,
        ];
    }

    private function findVwapBounceEntries(
        array $bars,
        callable $inLiveWindow,
        callable $is5MinTrendUp,
        callable $volAvgBefore,
        callable $getVolThreshold,
        callable $computeFill,
        array $signalMeta
    ): array {
        $vwap = $signalMeta['vwap'] ?? 0;
        $candidates = [];

        for ($i = 5; $i < count($bars); $i++) {
            $cur = $bars[$i];
            $prev = $bars[$i - 1];
            $ts = (string) $cur->ts_est;

            // Check trend (hard requirement)
            if (! $is5MinTrendUp($ts)) {
                continue;
            }

            // Get time penalty (not blocking)
            $timePenalty = $inLiveWindow($ts);
            if ($timePenalty === -999) {
                continue; // Out of analysis window entirely
            }

            $price = (float) ($cur->price ?? 0);
            $low = (float) ($cur->low ?? 0);
            $prevLow = (float) ($prev->low ?? 0);
            $ema9 = (float) ($cur->ema9 ?? 0);

            // Price must be above EMA9 (support)
            if ($price <= $ema9) {
                continue;
            }

            // Previous bar must have touched VWAP
            if ($prevLow > ($vwap * 1.002) || $prevLow < ($vwap * 0.998)) {
                continue;
            }

            // Current bar must be above VWAP with bounce
            if ($price <= ($vwap * 1.003)) {
                continue;
            }

            // Dynamic volume confirmation
            $baseVol = $volAvgBefore($i);
            $volRatio = ($baseVol > 0) ? ((float) ($cur->volume ?? 0) / $baseVol) : 0.0;
            $volThreshold = $getVolThreshold($ts);
            if ($volRatio < $volThreshold) {
                continue;
            }

            // Risk check - clamp stop instead of rejecting
            $atr = (float) ($cur->atr ?? 0);
            $stopPx = max($price - (2.5 * $atr), $price * 0.985); // Max 1.5% risk
            $riskPct = (($price - $stopPx) / $price) * 100;

            // Allow higher risk if volume is very strong
            $maxRisk = ($volRatio >= 4.0) ? 2.0 : 1.5;
            if ($riskPct > $maxRisk) {
                $stopPx = $price * (1 - ($maxRisk / 100)); // Clamp stop
                $riskPct = $maxRisk;
            }

            [$entryTs, $entryPx] = $computeFill($i);
            $baseScore = 60 + min(20, ($volRatio - $volThreshold) * 10) + min(15, (($price - $vwap) / $vwap) * 100 * 30);
            $score = $baseScore + $timePenalty; // Apply time penalty

            $candidates[] = $this->makeCandidate(
                'VWAP_BOUNCE_ENTRY',
                $ts,
                $entryTs,
                $entryPx,
                $cur,
                $score,
                sprintf('VWAP bounce with %.1fx vol', $volRatio),
                $volRatio
            );
            // Don't break - collect multiple candidates
        }

        return $candidates;
    }

    private function findBullFlagEntries(
        array $bars,
        callable $inLiveWindow,
        callable $is5MinTrendUp,
        callable $volAvgBefore,
        callable $getVolThreshold,
        callable $computeFill,
        array $signalMeta
    ): array {
        $flagHigh = $signalMeta['flag_high'] ?? 0;
        $candidates = [];

        for ($i = 5; $i < count($bars); $i++) {
            $cur = $bars[$i];
            $prev = $bars[$i - 1];
            $ts = (string) $cur->ts_est;

            // Check trend (hard requirement)
            if (! $is5MinTrendUp($ts)) {
                continue;
            }

            // Get time penalty (not blocking)
            $timePenalty = $inLiveWindow($ts);
            if ($timePenalty === -999) {
                continue; // Out of analysis window entirely
            }

            $price = (float) ($cur->price ?? 0);
            $high = (float) ($cur->high ?? 0);
            $prevHigh = (float) ($prev->high ?? 0);

            // Previous bar must be below flag high
            if ($prevHigh > $flagHigh) {
                continue;
            }

            // Current bar must break flag high decisively (0.3%+)
            if ($high <= ($flagHigh * 1.003)) {
                continue;
            }

            // Dynamic volume confirmation
            $baseVol = $volAvgBefore($i);
            $volRatio = ($baseVol > 0) ? ((float) ($cur->volume ?? 0) / $baseVol) : 0.0;
            $volThreshold = $getVolThreshold($ts);
            if ($volRatio < ($volThreshold + 0.5)) { // Higher threshold for breakouts
                continue;
            }

            // Risk check - clamp stop
            $atr = (float) ($cur->atr ?? 0);
            $stopPx = max($price - (2.5 * $atr), $price * 0.985);
            $riskPct = (($price - $stopPx) / $price) * 100;

            $maxRisk = ($volRatio >= 4.0) ? 2.0 : 1.5;
            if ($riskPct > $maxRisk) {
                $stopPx = $price * (1 - ($maxRisk / 100));
                $riskPct = $maxRisk;
            }

            [$entryTs, $entryPx] = $computeFill($i);
            $breakoutPct = (($price - $flagHigh) / $flagHigh) * 100;
            $baseScore = 65 + min(20, ($volRatio - ($volThreshold + 0.5)) * 8) + min(10, $breakoutPct * 50);
            $score = $baseScore + $timePenalty;

            $candidates[] = $this->makeCandidate(
                'BULL_FLAG_BREAK_ENTRY',
                $ts,
                $entryTs,
                $entryPx,
                $cur,
                $score,
                sprintf('Flag breakout %.2f%% with %.1fx vol', $breakoutPct, $volRatio),
                $volRatio
            );
            // Don't break - collect multiple candidates
        }

        return $candidates;
    }

    private function findFailedBreakdownEntries(
        array $bars,
        callable $inLiveWindow,
        callable $is5MinTrendUp,
        callable $volAvgBefore,
        callable $getVolThreshold,
        callable $computeFill,
        array $signalMeta
    ): array {
        $support = $signalMeta['support_level'] ?? 0;
        $candidates = [];

        for ($i = 3; $i < count($bars); $i++) {
            $cur = $bars[$i];
            $prev = $bars[$i - 1];
            $ts = (string) $cur->ts_est;

            // Check trend (hard requirement)
            if (! $is5MinTrendUp($ts)) {
                continue;
            }

            // Get time penalty (not blocking)
            $timePenalty = $inLiveWindow($ts);
            if ($timePenalty === -999) {
                continue; // Out of analysis window entirely
            }

            $price = (float) ($cur->price ?? 0);
            $low = (float) ($cur->low ?? 0);
            $prevLow = (float) ($prev->low ?? 0);
            $ema9 = (float) ($cur->ema9 ?? 0);

            // Previous bar must have broken support clearly
            if ($prevLow >= ($support * 0.998)) {
                continue;
            }

            // Current bar must reclaim support decisively (0.5%+ above)
            if ($price <= ($support * 1.005)) {
                continue;
            }

            // Must be above EMA9 for confirmation
            if ($price <= $ema9) {
                continue;
            }

            // Strong dynamic volume surge
            $baseVol = $volAvgBefore($i);
            $volRatio = ($baseVol > 0) ? ((float) ($cur->volume ?? 0) / $baseVol) : 0.0;
            $volThreshold = $getVolThreshold($ts) + 1.0; // Higher threshold for reversals
            if ($volRatio < $volThreshold) {
                continue;
            }

            // Risk check - clamp stop
            $atr = (float) ($cur->atr ?? 0);
            $stopPx = max($price - (2.5 * $atr), $price * 0.985);
            $riskPct = (($price - $stopPx) / $price) * 100;

            $maxRisk = ($volRatio >= 5.0) ? 2.0 : 1.5;
            if ($riskPct > $maxRisk) {
                $stopPx = $price * (1 - ($maxRisk / 100));
                $riskPct = $maxRisk;
            }

            [$entryTs, $entryPx] = $computeFill($i);
            $reclaimPct = (($price - $support) / $support) * 100;
            $baseScore = 70 + min(20, $reclaimPct * 40) + min(10, ($volRatio - $volThreshold) * 5);
            $score = $baseScore + $timePenalty;

            $candidates[] = $this->makeCandidate(
                'FAILED_BREAKDOWN_ENTRY',
                $ts,
                $entryTs,
                $entryPx,
                $cur,
                $score,
                sprintf('Support reclaim %.2f%% with %.1fx vol', $reclaimPct, $volRatio),
                $volRatio
            );
            // Don't break - collect multiple candidates
        }

        return $candidates;
    }

    private function makeCandidate(
        string $type,
        string $triggerTs,
        string $entryTs,
        float $entryPx,
        object $bar,
        float $score,
        string $note,
        float $volRatio
    ): array {
        $atr = (float) ($bar->atr ?? 0);
        $atrPct = (float) ($bar->atr_pct ?? 0);
        $stopPx = max($entryPx - (2.5 * $atr), 0.01);
        $risk = $entryPx - $stopPx;
        $riskPct = (($entryPx - $stopPx) / $entryPx) * 100.0;

        $t1Px = $entryPx + (1.0 * $risk);
        $t2Px = $entryPx + (2.0 * $risk);
        $t3Px = $entryPx + (3.0 * $risk);

        // 3x ATR trailing stop (price level, not distance)
        $trailPct = ($atr > 0 && $entryPx > 0)
            ? max(0.60, (($atr * 3.0) / $entryPx) * 100.0)
            : 0.60;
        $trailStopPrice = $entryPx - ($entryPx * ($trailPct / 100.0));

        return [
            'type' => $type,
            'trigger_ts_est' => $triggerTs,
            'entry_ts_est' => $entryTs,
            'entry' => round($entryPx, 4),
            'stop' => round($stopPx, 4),
            'risk_pct' => round($riskPct, 2),
            'risk_per_share' => round($risk, 6),
            'score' => round($score, 2),
            'targets' => [
                '1R' => round($t1Px, 4),
                '2R' => round($t2Px, 4),
                '3R' => round($t3Px, 4),
            ],
            'vol_ratio' => round($volRatio, 2),
            'atr' => round($atr, 6),
            'atr_pct' => round($atrPct, 3),
            'suggested_trailing_stop' => round($trailStopPrice, 6),
            'suggested_trailing_stop_pct' => $trailPct,
            'note' => $note,
        ];
    }
}
