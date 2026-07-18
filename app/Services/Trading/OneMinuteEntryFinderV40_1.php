<?php

namespace App\Services\Trading;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Version 40.1 - Runner Entry Finder with Wider Trailing Stops
 *
 * KEY IMPROVEMENTS FROM V40.0:
 * - Increased trailing stop from 3x to 7x ATR to let runners run
 * - V40.0 analysis: 48% win rate but exiting way too early (3-10 min holds)
 * - Biggest winner only +2.54% because 3x ATR stops were too tight
 * - Runners need room for pullbacks and consolidation during the move
 *
 * Purpose: Find best entries on stocks making sustained runs
 * Strategy: Enter on pullbacks or micro-consolidations, then LET IT RUN
 * Patterns:
 * - PULLBACK_BOUNCE: Small dip then recovery (buy the dip on runners)
 * - MICRO_CONSOLIDATION: Tight range then breakout continuation
 * - MOMENTUM_CONTINUATION: Fresh higher high with volume
 */
class OneMinuteEntryFinderV40_1
{
    use HasPriceTables;

    private string $version = 'v40.1';

    public function getVersion(): string
    {
        return $this->version;
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
     * Find best entry on a runner.
     *
     * @param  string  $symbol  Stock symbol
     * @param  string  $assetType  'stock' or 'crypto'
     * @param  string  $signalTsEst  When scanner detected the runner
     * @param  string  $asOfTsEst  Current time (for backtest stepping)
     * @param  int  $beforeMinutes  Look back from signal
     * @param  int  $afterMinutes  Look forward from signal
     * @param  int  $volLookback  Bars for volume average
     * @param  int  $pivotLookback  Bars for pivot analysis
     * @param  string  $fillModel  'next_open' or 'close'
     * @return array Result with best_entry or error
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
        $tradeDate = substr($signalTsEst, 0, 10);

        // Get 1-minute bars - fetch extra history for ATR calculation (need 15+ bars for 14-period ATR)
        // Use max of beforeMinutes and 45 to ensure we have enough data
        $lookbackMinutes = max($beforeMinutes, 45);

        $bars = DB::table($this->oneMinuteTable)
            ->where('symbol', $symbol)
            ->where('asset_type', $assetType)
            ->where('trading_date_est', $tradeDate)
            ->where('ts_est', '>=', DB::raw("DATE_SUB('{$signalTsEst}', INTERVAL {$lookbackMinutes} MINUTE)"))
            ->where('ts_est', '<=', $asOfTsEst)
            ->orderBy('ts_est')
            ->get()
            ->toArray();

        if (count($bars) < 10) {
            return ['ok' => false, 'error' => 'insufficient_data'];
        }

        // Convert to normalized array
        $norm = array_map(function ($b) {
            return [
                'ts_est' => $b->ts_est,
                'open' => (float) $b->open,
                'high' => (float) $b->high,
                'low' => (float) $b->low,
                'close' => (float) $b->price,
                'volume' => (float) $b->volume,
            ];
        }, $bars);

        // Analysis window: from signal time to asOf
        $analysisStart = $signalTsEst;
        $analysisEnd = $asOfTsEst;

        // Helper: Calculate average volume before a bar
        $volAvgBefore = function ($idx) use ($norm, $volLookback) {
            $start = max(0, $idx - $volLookback);
            $sum = 0.0;
            $count = 0;
            for ($i = $start; $i < $idx; $i++) {
                $sum += $norm[$i]['volume'];
                $count++;
            }

            return $count > 0 ? $sum / $count : 1.0;
        };

        // Helper: Compute fill price based on model
        $computeFill = function ($idx) use ($norm, $fillModel) {
            if ($fillModel === 'next_open') {
                if ($idx + 1 < count($norm)) {
                    // Safety check: prevent time travel bugs by verifying same trading date
                    $curDate = substr($norm[$idx]['ts_est'], 0, 10);
                    $nextDate = substr($norm[$idx + 1]['ts_est'], 0, 10);
                    if ($curDate !== $nextDate) {
                        return [$norm[$idx]['ts_est'], $norm[$idx]['close']];
                    }

                    return [$norm[$idx + 1]['ts_est'], $norm[$idx + 1]['open']];
                }

                return [$norm[$idx]['ts_est'], $norm[$idx]['close']];
            }

            return [$norm[$idx]['ts_est'], $norm[$idx]['close']];
        };

        $candidates = [];

        // Pattern 1: PULLBACK_BOUNCE - Small pullback then recovery
        for ($i = 5; $i < count($norm); $i++) {
            if ($norm[$i]['ts_est'] < $analysisStart || $norm[$i]['ts_est'] > $analysisEnd) {
                continue;
            }

            $cur = $norm[$i];
            $prev = $norm[$i - 1];
            $prev2 = $norm[$i - 2];

            // Check for pullback: previous 2 bars pulled back from high
            $recentHigh = max($norm[$i - 5]['high'], $norm[$i - 4]['high'], $norm[$i - 3]['high']);
            $pullbackPct = (($recentHigh - $prev['low']) / $recentHigh) * 100.0;

            // Now bouncing back
            $bouncePct = (($cur['close'] - $prev['low']) / $prev['low']) * 100.0;

            // Pullback 1-3%, then bounce with volume
            if ($pullbackPct >= 1.0 && $pullbackPct <= 3.0 && $bouncePct >= 0.5) {
                $baseVol = $volAvgBefore($i);
                $volRatio = $baseVol > 0 ? $cur['volume'] / $baseVol : 0;

                if ($volRatio >= 1.5 && $cur['close'] > $cur['open']) {
                    [$entryTs, $entryPx] = $computeFill($i);
                    $stop = min($prev['low'], $prev2['low']);

                    $score = $bouncePct * $volRatio * 5;

                    $candidates[] = [
                        'type' => 'PULLBACK_BOUNCE',
                        'trigger_ts_est' => $cur['ts_est'],
                        'entry_ts_est' => $entryTs,
                        'entry' => round($entryPx, 6),
                        'stop' => round($stop, 6),
                        'vol_ratio' => round($volRatio, 2),
                        'pullback_pct' => round($pullbackPct, 2),
                        'bounce_pct' => round($bouncePct, 2),
                        'score' => round($score, 2),
                        'notes' => "Pullback bounce: {$pullbackPct}% dip → {$bouncePct}% recovery",
                    ];
                }
            }
        }

        // Pattern 2: MICRO_CONSOLIDATION - Tight consolidation then breakout
        for ($i = 5; $i < count($norm); $i++) {
            if ($norm[$i]['ts_est'] < $analysisStart || $norm[$i]['ts_est'] > $analysisEnd) {
                continue;
            }

            // Check last 3-4 bars for tight range
            $consolBars = array_slice($norm, $i - 4, 4);
            $consolHigh = max(array_column($consolBars, 'high'));
            $consolLow = min(array_column($consolBars, 'low'));
            $consolAvg = ($consolHigh + $consolLow) / 2;
            $consolRangePct = (($consolHigh - $consolLow) / $consolAvg) * 100.0;

            // Consolidation < 0.5%, then breakout
            if ($consolRangePct < 0.5) {
                $cur = $norm[$i];

                // Breakout above consolidation
                if ($cur['close'] > $consolHigh) {
                    $baseVol = $volAvgBefore($i);
                    $volRatio = $baseVol > 0 ? $cur['volume'] / $baseVol : 0;

                    $breakoutPct = (($cur['close'] - $consolHigh) / $consolHigh) * 100.0;

                    if ($volRatio >= 2.0 && $breakoutPct >= 0.3) {
                        [$entryTs, $entryPx] = $computeFill($i);
                        $stop = $consolLow;

                        $score = (1.0 / max(0.1, $consolRangePct)) * $volRatio * $breakoutPct * 20;

                        $candidates[] = [
                            'type' => 'MICRO_CONSOLIDATION',
                            'trigger_ts_est' => $cur['ts_est'],
                            'entry_ts_est' => $entryTs,
                            'entry' => round($entryPx, 6),
                            'stop' => round($stop, 6),
                            'vol_ratio' => round($volRatio, 2),
                            'consol_range_pct' => round($consolRangePct, 3),
                            'breakout_pct' => round($breakoutPct, 2),
                            'score' => round($score, 2),
                            'notes' => "Micro consolidation ({$consolRangePct}%) → breakout",
                        ];
                    }
                }
            }
        }

        // Pattern 3: MOMENTUM_CONTINUATION - Fresh higher high with strong volume
        for ($i = 5; $i < count($norm); $i++) {
            if ($norm[$i]['ts_est'] < $analysisStart || $norm[$i]['ts_est'] > $analysisEnd) {
                continue;
            }

            $cur = $norm[$i];

            // Check if making new high in last 10 bars
            $recentHigh = 0;
            for ($j = max(0, $i - 10); $j < $i; $j++) {
                $recentHigh = max($recentHigh, $norm[$j]['high']);
            }

            // Fresh higher high
            if ($cur['high'] > $recentHigh) {
                $baseVol = $volAvgBefore($i);
                $volRatio = $baseVol > 0 ? $cur['volume'] / $baseVol : 0;

                // Strong volume + close near high
                $closeToHighPct = (($cur['high'] - $cur['close']) / $cur['high']) * 100.0;

                if ($volRatio >= 2.5 && $closeToHighPct < 0.3 && $cur['close'] > $cur['open']) {
                    [$entryTs, $entryPx] = $computeFill($i);

                    // Stop at recent swing low
                    $stop = $recentHigh * 0.985; // 1.5% below new high

                    $higherHighPct = (($cur['high'] - $recentHigh) / $recentHigh) * 100.0;
                    $score = $higherHighPct * $volRatio * 10;

                    $candidates[] = [
                        'type' => 'MOMENTUM_CONTINUATION',
                        'trigger_ts_est' => $cur['ts_est'],
                        'entry_ts_est' => $entryTs,
                        'entry' => round($entryPx, 6),
                        'stop' => round($stop, 6),
                        'vol_ratio' => round($volRatio, 2),
                        'higher_high_pct' => round($higherHighPct, 2),
                        'score' => round($score, 2),
                        'notes' => "New high: +{$higherHighPct}% with {$volRatio}x volume",
                    ];
                }
            }
        }

        // Filter: require minimum volume ratio and positive score
        $filtered = array_filter($candidates, function ($c) {
            return $c['vol_ratio'] >= 1.5 && $c['score'] > 0;
        });

        Log::channel('trading')->info("[V40.1] {$symbol}: Found ".count($candidates).' candidates, '.count($filtered).' after basic filters', [
            'symbol' => $symbol,
            'total_candidates' => count($candidates),
            'after_basic_filter' => count($filtered),
        ]);

        // V40.0 Quality Filters
        $filtered = array_filter($filtered, function ($c) use ($symbol) {
            // Filter 1: Reject excessive risk (> 3.0%)
            if (isset($c['entry']) && isset($c['stop'])) {
                $risk = (($c['entry'] - $c['stop']) / $c['entry']) * 100;
                if ($risk > 3.0) {
                    Log::channel('trading')->debug("[V40.1] {$symbol} REJECTED: Risk too high", [
                        'symbol' => $symbol,
                        'type' => $c['type'],
                        'risk_pct' => round($risk, 2),
                        'reason' => 'risk > 3.0%',
                    ]);

                    return false;
                }
            }

            // Filter 2: Pattern-specific minimum scores
            $minScores = [
                'PULLBACK_BOUNCE' => 10.0,           // Require decent bounce
                'MICRO_CONSOLIDATION' => 50.0,       // Require tight consol + strong breakout
                'MOMENTUM_CONTINUATION' => 15.0,     // Require strong volume
            ];

            $minScore = $minScores[$c['type']] ?? 0;
            if ($c['score'] < $minScore) {
                Log::channel('trading')->debug("[V40.1] {$symbol} REJECTED: Score too low", [
                    'symbol' => $symbol,
                    'type' => $c['type'],
                    'score' => $c['score'],
                    'min_required' => $minScore,
                    'reason' => 'score below minimum',
                ]);

                return false;
            }

            // Filter 3: Avoid entries too early (before 9:45 AM)
            $entryTime = \DateTime::createFromFormat('Y-m-d H:i:s', $c['entry_ts_est'], new \DateTimeZone('America/New_York'));
            if ($entryTime) {
                $hour = (int) $entryTime->format('H');
                $minute = (int) $entryTime->format('i');

                if ($hour === 9 && $minute < 45) {
                    Log::channel('trading')->debug("[V40.1] {$symbol} REJECTED: Too early", [
                        'symbol' => $symbol,
                        'entry_time' => $c['entry_ts_est'],
                        'reason' => 'entries before 9:45 AM often whipsaw',
                    ]);

                    return false;
                }
            }

            return true;
        });

        Log::channel('trading')->info("[V40.1] {$symbol}: After quality filters", [
            'symbol' => $symbol,
            'after_quality_filter' => count($filtered),
            'rejected_count' => count($candidates) - count($filtered),
        ]);

        // Sort by score and take best
        usort($filtered, fn ($a, $b) => ($b['score'] <=> $a['score']));
        $best = $filtered[0] ?? null;

        if (! $best) {
            return ['ok' => false, 'error' => 'no_quality_entry'];
        }

        // Calculate ATR for trailing stop strategy
        $atr = $this->calculateATR($norm, 14);

        // Debug logging for ATR calculation
        if ($atr == 0) {
            Log::channel('trading')->warning("[V40.1] {$symbol}: ATR is ZERO", [
                'symbol' => $symbol,
                'bars_count' => count($norm),
                'first_bar' => $norm[0] ?? null,
                'last_bar' => $norm[count($norm) - 1] ?? null,
            ]);
        }

        $best['atr'] = round($atr, 6);
        $atrPct = ((float) $best['entry'] > 0) ? ($atr / (float) $best['entry']) * 100.0 : 0.0;
        $best['atr_pct'] = round($atrPct, 6);

        // Calculate risk
        $risk = max(1e-9, (float) $best['entry'] - (float) $best['stop']);
        $riskPct = ((float) $best['entry'] > 0) ? ($risk / (float) $best['entry']) * 100.0 : 0.0;
        $best['risk_per_share'] = round($risk, 6);
        $best['risk_pct'] = round($riskPct, 3);

        // Set targets (5R and 7R added for runner potential)
        $best['targets'] = [
            '1R' => round((float) $best['entry'] + 1.0 * $risk, 6),
            '2R' => round((float) $best['entry'] + 2.0 * $risk, 6),
            '3R' => round((float) $best['entry'] + 3.0 * $risk, 6),
            '5R' => round((float) $best['entry'] + 5.0 * $risk, 6),
            '7R' => round((float) $best['entry'] + 7.0 * $risk, 6),
        ];

        // ATR-based trailing stop (7x ATR for runners - V40.1 improvement)
        // V40.0 used 3x but runners need more room to breathe
        // Analysis showed early exits at 3-10 mins, max +2.54%
        // Note: This is the STOP PRICE, not the ATR distance
        $best['suggested_trailing_stop'] = round((float) $best['entry'] - ($atr * 7.0), 6);
        $best['suggested_trailing_stop_pct'] = ((float) $best['entry'] > 0) ? (($atr * 7.0) / (float) $best['entry']) * 100.0 : 0.0;

        // Log selected entry
        Log::channel('trading')->info("[V40.1] {$symbol}: Selected entry", [
            'symbol' => $symbol,
            'type' => $best['type'],
            'entry_price' => $best['entry'],
            'stop' => $best['stop'],
            'risk_pct' => $best['risk_pct'],
            'score' => $best['score'],
            'vol_ratio' => $best['vol_ratio'],
            'entry_time' => $best['entry_ts_est'],
            'atr' => $best['atr'],
            'atr_pct' => $best['atr_pct'],
        ]);

        return [
            'ok' => true,
            'best_entry' => $best,
            'candidates' => $filtered,
        ];
    }
}
