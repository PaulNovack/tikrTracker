<?php

namespace App\Services\Trading;

use Illuminate\Support\Facades\Log;

/**
 * Version 31.0 - Explosive Move Entry Finder
 * Purpose: Find entry points before big price jumps in volatile stocks
 * Strategy: Look for consolidation breakouts, volume spikes, and momentum acceleration
 * Base: V17.0 patterns + explosive move detection
 * Changes:
 * - VOLUME_EXPLOSION: Sudden volume spike (5x+) with price acceleration
 * - TIGHT_CONSOLIDATION_BREAK: Narrow range breakout after consolidation
 * - MOMENTUM_ACCELERATION: Increasing rate of price change
 * - VWAP_POWER_BREAK: Strong break above VWAP with heavy volume
 */
class OneMinuteEntryFinderV31_0
{
    use HasPriceTables;

    private string $version = 'v31.0';

    public function getVersion(): string
    {
        return $this->version;
    }

    /**
     * Calculate ATR for volatility/stop placement.
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

        $atrSum = 0.0;
        $count = min($period, count($trueRanges));

        for ($i = count($trueRanges) - $count; $i < count($trueRanges); $i++) {
            $atrSum += $trueRanges[$i];
        }

        return $count > 0 ? $atrSum / $count : 0.0;
    }

    /**
     * Find explosive entry opportunities.
     *
     * Patterns:
     * 1. VOLUME_EXPLOSION: 5x+ volume spike with 0.5%+ move
     * 2. TIGHT_CONSOLIDATION_BREAK: Narrow range (< 0.3%) then breakout
     * 3. MOMENTUM_ACCELERATION: Accelerating price gains (each bar > previous)
     * 4. VWAP_POWER_BREAK: Break above VWAP with 3x+ volume
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
        string $fillModel = 'next_open'
    ): array {
        $analysisEnd = $asOfTsEst;
        $analysisStart = date('Y-m-d H:i:s', strtotime($asOfTsEst." -{$beforeMinutes} minutes"));

        $tradeDate = substr($signalTsEst, 0, 10);
        $marketOpen = $tradeDate.' 09:30:00';

        if ($analysisStart < $marketOpen) {
            $analysisStart = $marketOpen;
        }

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
        ', [$assetType, $symbol, $tradeDate, $from, $to]);

        if (! $bars || count($bars) < 20) {
            return [
                'ok' => false,
                'error' => 'Not enough 1m data for analysis.',
                'symbol' => $symbol,
                'asset_type' => $assetType,
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

        // Build normalized data with VWAP
        $norm = [];
        $cumPV = 0.0;
        $cumV = 0.0;

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

            $norm[] = [
                'ts_est' => (string) $r->ts_est,
                'open' => $o,
                'high' => $h,
                'low' => $l,
                'close' => $c,
                'volume' => $v,
                'vwap' => $vwap,
            ];
        }

        $vols = array_map(fn ($b) => (float) $b['volume'], $norm);

        $volAvgBefore = function (int $i) use ($vols, $volLookback): float {
            $start = max(0, $i - $volLookback);
            if ($start >= $i) {
                return 0.0;
            }
            $slice = array_slice($vols, $start, $i - $start);

            return array_sum($slice) / max(1, count($slice));
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

        $candidates = [];

        // Pattern 1: VOLUME_EXPLOSION - 5x+ volume spike with price move
        for ($i = $volLookback; $i < count($norm); $i++) {
            if ($norm[$i]['ts_est'] < $analysisStart || $norm[$i]['ts_est'] > $analysisEnd) {
                continue;
            }

            $cur = $norm[$i];
            $prev = $norm[$i - 1];
            $baseVol = $volAvgBefore($i);

            if ($baseVol <= 0) {
                continue;
            }

            $volRatio = $cur['volume'] / $baseVol;
            $movePct = (($cur['close'] - $prev['close']) / $prev['close']) * 100.0;

            // Volume explosion: 5x+ volume AND 0.5%+ move
            if ($volRatio >= 5.0 && $movePct >= 0.5 && $cur['close'] > $prev['close']) {
                [$entryTs, $entryPx] = $computeFill($i);
                $stop = min($cur['low'], $prev['low']);

                $score = $volRatio * $movePct * 2; // Heavy weighting on explosiveness

                $candidates[] = [
                    'type' => 'VOLUME_EXPLOSION',
                    'trigger_ts_est' => $cur['ts_est'],
                    'entry_ts_est' => $entryTs,
                    'entry' => round($entryPx, 6),
                    'stop' => round($stop, 6),
                    'vol_ratio' => round($volRatio, 2),
                    'move_pct' => round($movePct, 2),
                    'score' => round($score, 2),
                    'notes' => "Volume explosion: {$volRatio}x vol, {$movePct}% move",
                ];
            }
        }

        // Pattern 2: TIGHT_CONSOLIDATION_BREAK - Narrow range then breakout
        for ($i = 5; $i < count($norm); $i++) {
            if ($norm[$i]['ts_est'] < $analysisStart || $norm[$i]['ts_est'] > $analysisEnd) {
                continue;
            }

            // Check last 3-5 bars for tight consolidation (range < 0.3%)
            $consolBars = array_slice($norm, $i - 5, 5);
            $consolHigh = max(array_column($consolBars, 'high'));
            $consolLow = min(array_column($consolBars, 'low'));
            $consolAvg = ($consolHigh + $consolLow) / 2;
            $consolRangePct = (($consolHigh - $consolLow) / $consolAvg) * 100.0;

            if ($consolRangePct >= 0.3) {
                continue;
            }

            $cur = $norm[$i];
            $prev = $norm[$i - 1];

            // Breakout: close above consolidation range with volume
            $baseVol = $volAvgBefore($i);
            $volRatio = $baseVol > 0 ? $cur['volume'] / $baseVol : 0;

            if ($cur['close'] > $consolHigh && $volRatio >= 2.0) {
                [$entryTs, $entryPx] = $computeFill($i);
                $stop = $consolLow;

                $breakoutPct = (($cur['close'] - $consolHigh) / $consolHigh) * 100.0;
                $score = (1.0 / max(0.1, $consolRangePct)) * $volRatio * $breakoutPct * 10;

                $candidates[] = [
                    'type' => 'TIGHT_CONSOLIDATION_BREAK',
                    'trigger_ts_est' => $cur['ts_est'],
                    'entry_ts_est' => $entryTs,
                    'entry' => round($entryPx, 6),
                    'stop' => round($stop, 6),
                    'vol_ratio' => round($volRatio, 2),
                    'consol_range_pct' => round($consolRangePct, 3),
                    'breakout_pct' => round($breakoutPct, 2),
                    'score' => round($score, 2),
                    'notes' => "Tight consolidation ({$consolRangePct}%) breakout",
                ];
            }
        }

        // Pattern 3: MOMENTUM_ACCELERATION - Accelerating gains
        for ($i = 3; $i < count($norm); $i++) {
            if ($norm[$i]['ts_est'] < $analysisStart || $norm[$i]['ts_est'] > $analysisEnd) {
                continue;
            }

            // Check if last 3 bars show accelerating gains
            $gains = [];
            for ($j = $i - 2; $j <= $i; $j++) {
                $gain = (($norm[$j]['close'] - $norm[$j - 1]['close']) / $norm[$j - 1]['close']) * 100.0;
                $gains[] = $gain;
            }

            // All gains positive AND each gain > previous
            if ($gains[0] > 0 && $gains[1] > $gains[0] && $gains[2] > $gains[1]) {
                $cur = $norm[$i];
                $baseVol = $volAvgBefore($i);
                $volRatio = $baseVol > 0 ? $cur['volume'] / $baseVol : 0;

                if ($volRatio >= 1.5) {
                    [$entryTs, $entryPx] = $computeFill($i);
                    $stop = min($norm[$i - 2]['low'], $norm[$i - 1]['low'], $cur['low']);

                    $acceleration = ($gains[2] - $gains[0]);
                    $score = $acceleration * $volRatio * 5;

                    $candidates[] = [
                        'type' => 'MOMENTUM_ACCELERATION',
                        'trigger_ts_est' => $cur['ts_est'],
                        'entry_ts_est' => $entryTs,
                        'entry' => round($entryPx, 6),
                        'stop' => round($stop, 6),
                        'vol_ratio' => round($volRatio, 2),
                        'acceleration' => round($acceleration, 3),
                        'score' => round($score, 2),
                        'notes' => "Accelerating momentum: {$gains[0]}% → {$gains[1]}% → {$gains[2]}%",
                    ];
                }
            }
        }

        // Pattern 4: VWAP_POWER_BREAK - Strong VWAP break with heavy volume
        for ($i = 1; $i < count($norm); $i++) {
            if ($norm[$i]['ts_est'] < $analysisStart || $norm[$i]['ts_est'] > $analysisEnd) {
                continue;
            }

            $cur = $norm[$i];
            $prev = $norm[$i - 1];

            // Break above VWAP with authority
            if ($prev['close'] < $prev['vwap'] && $cur['close'] > $cur['vwap']) {
                $baseVol = $volAvgBefore($i);
                $volRatio = $baseVol > 0 ? $cur['volume'] / $baseVol : 0;

                $distAboveVwap = (($cur['close'] - $cur['vwap']) / $cur['vwap']) * 100.0;

                // Require 3x volume and meaningful distance above VWAP
                if ($volRatio >= 3.0 && $distAboveVwap >= 0.2) {
                    [$entryTs, $entryPx] = $computeFill($i);
                    $stop = min($cur['low'], $cur['vwap'] * 0.997);

                    $score = $volRatio * $distAboveVwap * 8;

                    $candidates[] = [
                        'type' => 'VWAP_POWER_BREAK',
                        'trigger_ts_est' => $cur['ts_est'],
                        'entry_ts_est' => $entryTs,
                        'entry' => round($entryPx, 6),
                        'stop' => round($stop, 6),
                        'vwap' => round($cur['vwap'], 6),
                        'vol_ratio' => round($volRatio, 2),
                        'dist_above_vwap_pct' => round($distAboveVwap, 2),
                        'score' => round($score, 2),
                        'notes' => "VWAP power break with {$volRatio}x volume",
                    ];
                }
            }
        }

        // Filter: require minimum volume ratio and positive score
        $filtered = array_filter($candidates, function ($c) {
            return $c['vol_ratio'] >= 1.5 && $c['score'] > 0;
        });

        Log::channel('trading')->info("[V31.0] {$symbol}: Found ".count($candidates).' candidates, '.count($filtered).' after basic filters', [
            'symbol' => $symbol,
            'total_candidates' => count($candidates),
            'after_basic_filter' => count($filtered),
        ]);

        // V31.0 Quality Filters - Reject low-quality setups
        $filtered = array_filter($filtered, function ($c) use ($symbol) {
            // Filter 1: Reject excessive risk (> 3.5%)
            if (isset($c['entry']) && isset($c['stop'])) {
                $risk = (($c['entry'] - $c['stop']) / $c['entry']) * 100;
                if ($risk > 3.5) {
                    Log::channel('trading')->debug("[V31.0] {$symbol} REJECTED: Risk too high", [
                        'symbol' => $symbol,
                        'type' => $c['type'],
                        'risk_pct' => round($risk, 2),
                        'reason' => 'risk > 3.5%',
                    ]);

                    return false;
                }
            }

            // Filter 2: Pattern-specific minimum scores (INCREASED after backtest)
            $minScores = [
                'VOLUME_EXPLOSION' => 50.0,      // Increased from 30 - need strong explosion
                'MOMENTUM_ACCELERATION' => 15.0,  // DRASTICALLY increased from 3 - only 25% win rate!
                'TIGHT_CONSOLIDATION_BREAK' => 75.0, // Increased from 50 - best pattern, keep high bar
                'VWAP_POWER_BREAK' => 40.0,      // Increased from 25 - need more power
            ];

            $minScore = $minScores[$c['type']] ?? 0;
            if ($c['score'] < $minScore) {
                Log::channel('trading')->debug("[V31.0] {$symbol} REJECTED: Score too low", [
                    'symbol' => $symbol,
                    'type' => $c['type'],
                    'score' => $c['score'],
                    'min_required' => $minScore,
                    'reason' => 'score below minimum',
                ]);

                return false;
            }

            // Filter 3: Volume explosion - reject extreme outliers (potential data errors)
            if ($c['type'] === 'VOLUME_EXPLOSION' && $c['vol_ratio'] > 200) {
                Log::channel('trading')->warning("[V31.0] {$symbol} REJECTED: Extreme volume", [
                    'symbol' => $symbol,
                    'vol_ratio' => $c['vol_ratio'],
                    'reason' => 'likely data error or illiquid stock',
                ]);

                return false;
            }

            // Filter 4: Momentum acceleration - MUCH stricter (only 25% win rate in backtest!)
            if ($c['type'] === 'MOMENTUM_ACCELERATION') {
                // Require strong acceleration
                if (isset($c['acceleration']) && $c['acceleration'] < 1.0) {
                    Log::channel('trading')->debug("[V31.0] {$symbol} REJECTED: Weak acceleration", [
                        'symbol' => $symbol,
                        'acceleration' => $c['acceleration'],
                        'reason' => 'acceleration < 1.0%',
                    ]);

                    return false;
                }

                // Require higher volume for momentum plays
                if ($c['vol_ratio'] < 3.0) {
                    Log::channel('trading')->debug("[V31.0] {$symbol} REJECTED: Momentum insufficient volume", [
                        'symbol' => $symbol,
                        'vol_ratio' => $c['vol_ratio'],
                        'reason' => 'momentum pattern needs 3x+ volume',
                    ]);

                    return false;
                }
            }

            // Filter 5: Avoid early morning entries (10:00-10:44) - many losers in backtest
            $entryTime = \DateTime::createFromFormat('Y-m-d H:i:s', $c['entry_ts_est'], new \DateTimeZone('America/New_York'));
            if ($entryTime) {
                $hour = (int) $entryTime->format('H');
                $minute = (int) $entryTime->format('i');

                if ($hour === 10 && $minute < 45) {
                    Log::channel('trading')->debug("[V31.0] {$symbol} REJECTED: Too early", [
                        'symbol' => $symbol,
                        'entry_time' => $c['entry_ts_est'],
                        'reason' => 'entries before 10:45 AM have lower win rate',
                    ]);

                    return false;
                }
            }

            // Filter 6: Require momentum cushion to avoid immediate ATR stop
            // Check if entry has enough upside momentum (most losses hit stop immediately)
            if (isset($c['entry']) && isset($c['stop'])) {
                $risk = (($c['entry'] - $c['stop']) / $c['entry']) * 100;

                // Require move_pct or acceleration to be at least 2x the risk
                $momentum = 0;
                if (isset($c['move_pct'])) {
                    $momentum = $c['move_pct'];
                } elseif (isset($c['acceleration'])) {
                    $momentum = $c['acceleration'];
                } elseif (isset($c['breakout_pct'])) {
                    $momentum = $c['breakout_pct'];
                } elseif (isset($c['dist_above_vwap_pct'])) {
                    $momentum = $c['dist_above_vwap_pct'];
                }

                if ($momentum < $risk * 1.5) {
                    Log::channel('trading')->debug("[V31.0] {$symbol} REJECTED: Insufficient momentum cushion", [
                        'symbol' => $symbol,
                        'type' => $c['type'],
                        'momentum' => round($momentum, 2),
                        'risk' => round($risk, 2),
                        'reason' => 'momentum must be 1.5x risk to avoid immediate stop',
                    ]);

                    return false;
                }
            }

            return true;
        });

        Log::channel('trading')->info("[V31.0] {$symbol}: After quality filters", [
            'symbol' => $symbol,
            'after_quality_filter' => count($filtered),
            'rejected_count' => count($candidates) - count($filtered),
        ]);

        usort($filtered, fn ($a, $b) => ($b['score'] <=> $a['score']));
        $best = $filtered[0] ?? null;

        if ($best) {
            $risk = max(1e-9, (float) $best['entry'] - (float) $best['stop']);
            $riskPct = ((float) $best['entry'] > 0) ? ($risk / (float) $best['entry']) * 100.0 : 0.0;

            Log::channel('trading')->info("[V31.0] {$symbol}: ENTRY SELECTED", [
                'symbol' => $symbol,
                'type' => $best['type'],
                'entry' => $best['entry'],
                'stop' => $best['stop'],
                'risk_pct' => round($riskPct, 2),
                'score' => $best['score'],
                'vol_ratio' => $best['vol_ratio'],
                'entry_ts' => $best['entry_ts_est'],
                'notes' => $best['notes'] ?? '',
            ]);

            $best['risk_per_share'] = round($risk, 6);
            $best['risk_pct'] = round($riskPct, 3);
            $best['targets'] = [
                '1R' => round((float) $best['entry'] + 1.0 * $risk, 6),
                '2R' => round((float) $best['entry'] + 2.0 * $risk, 6),
                '3R' => round((float) $best['entry'] + 3.0 * $risk, 6),
            ];

            // ATR for trailing stop
            $atr = $this->calculateATR($norm, 14);
            $best['atr'] = round($atr, 6);
            $best['atr_pct'] = ((float) $best['entry'] > 0) ? ($atr / (float) $best['entry']) * 100.0 : 0.0;
            $best['suggested_trailing_stop'] = round((float) $best['entry'] - (2.5 * $atr), 6);
            $best['suggested_trailing_stop_pct'] = ((float) $best['entry'] > 0) ? ((2.5 * $atr) / (float) $best['entry']) * 100.0 : 0.0;
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
