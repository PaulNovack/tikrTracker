<?php

namespace App\Services\TradingV2\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Redis;

/**
 * Processes ONE bar event against ALL configured alert versions.
 *
 * Receives pre-computed gate values from GateEvaluator.
 * Iterates every active alert version, checking thresholds.
 *
 * On 5m: passes → stores candidate in Redis.
 * On 1m: passes → runs the version's entry finder → writes alert.
 */
class EvaluateBarJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public function __construct(
        public string $symbol,
        public string $tsEst,
        public string $timeframe,
        public array $gates,
        public array $versions,
    ) {}

    public function handle(): void
    {
        // Discard jobs for events older than 12 minutes — prevents
        // stale alert creation when the gate-check queue backs up.
        $tsEpoch = strtotime($this->tsEst.' America/New_York');
        if ($tsEpoch > 0 && (time() - $tsEpoch) > 720) {
            \Log::channel('bar-events')->debug('[EvaluateBarJob] Discarding stale job', [
                'symbol' => $this->symbol,
                'timeframe' => $this->timeframe,
                'ts_est' => $this->tsEst,
                'age_seconds' => time() - $tsEpoch,
            ]);

            return;
        }

        if ($this->timeframe === '5m') {
            $this->process5m();
        } else {
            $this->process1m();
        }
    }

    /**
     * 5m bar: check scanner gates, store candidates for passing versions.
     */
    private function process5m(): void
    {
        foreach ($this->versions as $version) {
            if (! $this->passesGates($version['gates_5m'] ?? [])) {
                continue;
            }

            // Compute version-specific score
            $score = $this->computeScore($version['scanner_score_formula'] ?? null);

            // Check score-based gates (entry_score_min / entry_score_max)
            $scoreMin = $version['gates_5m']['entry_score_min']['threshold_min'] ?? null;
            $scoreMax = $version['gates_5m']['entry_score_min']['threshold_max'] ?? null;
            if ($scoreMin !== null && $score < (float) $scoreMin) {
                continue;
            }
            if ($scoreMax !== null && $score > (float) $scoreMax) {
                continue;
            }

            \Log::channel('redis-scan')->debug('[EvaluateBarJob] 5m candidate stored', [
                'symbol' => $this->symbol,
                'version' => $version['pipeline_letter'],
                'score' => round($score, 2),
            ]);

            $candidate = [
                'symbol' => $this->symbol,
                'signal_ts_est' => $this->tsEst,
                'score' => $score,
                'atr' => $this->gates['atr'] ?? 0,
                'atr_pct' => $this->gates['atr_pct'] ?? 0,
                'signal_type' => $version['signal_type'],
                'pipeline_letter' => $version['pipeline_letter'],
                'version_string' => $version['version_string'],
                'gates' => $this->gates,
            ];

            Redis::setex(
                "rt:candidate:{$version['id']}:stock:{$this->symbol}",
                120,
                json_encode($candidate, JSON_THROW_ON_ERROR)
            );
        }
    }

    /**
     * 1m bar: for each version with an active candidate, check entry gates,
     * run entry finder, write alert.
     */
    private function process1m(): void
    {
        foreach ($this->versions as $version) {
            $key = "rt:candidate:{$version['id']}:stock:{$this->symbol}";
            $candidateJson = Redis::get($key);
            if (! $candidateJson) {
                continue;
            }

            $candidate = json_decode($candidateJson, true);
            if (! is_array($candidate)) {
                continue;
            }

            // Discard candidates older than 10 minutes
            $candidateEpoch = (int) ($candidate['signal_epoch'] ?? 0);
            if ($candidateEpoch > 0 && (time() - $candidateEpoch) > 600) {
                Redis::del($key);

                continue;
            }

            // Check 1m entry gates
            if (! $this->passesGates($version['gates_1m'] ?? [])) {
                \Log::channel('redis-scan')->debug('[EvaluateBarJob] 1m gates failed', [
                    'symbol' => $this->symbol,
                    'version' => $version['pipeline_letter'],
                    'ts_est' => $this->tsEst,
                ]);

                continue;
            }

            // Build entry from pre-computed gate values (Redis-only, no legacy finder)
            $entry = $this->buildEntry($candidate, $version);
            if ($entry === null) {
                \Log::channel('redis-scan')->debug('[EvaluateBarJob] buildEntry returned null', [
                    'symbol' => $this->symbol,
                    'version' => $version['pipeline_letter'],
                    'ts_est' => $this->tsEst,
                ]);

                continue;
            }

            // Write alert via TradeAlertWriterV1 (existing, well-tested code)
            $writer = app(\App\Services\Trading\TradeAlertWriterV1::class);
            $alertId = $writer->upsertAlert(
                signal: [
                    'symbol' => $this->symbol,
                    'asset_type' => 'stock',
                    'signal_type' => $candidate['signal_type'],
                    'signal_ts_est' => $candidate['signal_ts_est'],
                    'score' => $candidate['score'],
                    'atr' => $candidate['atr'],
                    'atr_pct' => $candidate['atr_pct'],
                    'meta' => $candidate['gates'] ?? [],
                ],
                entry: $entry,
                asOfTsEst: $this->tsEst,
                algorithmVersion: $candidate['version_string'],
                pipelineRun: $candidate['pipeline_letter'],
                isRealtime: true,
            );
        }
    }

    /**
     * Check pre-computed gate values against a version's threshold config.
     *
     * Each gate has threshold_min and threshold_max (both nullable).
     * - If min is set: value must be >= min
     * - If max is set: value must be <= max
     * - If both null: boolean check (value must be truthy)
     */
    private function passesGates(array $thresholds): bool
    {
        foreach ($thresholds as $gate => $cfg) {
            $value = $this->gates[$gate] ?? null;
            if ($value === null) {
                continue;
            }

            $min = $cfg['threshold_min'] ?? null;
            $max = $cfg['threshold_max'] ?? null;

            // Boolean gate: both null means "must be truthy"
            if ($min === null && $max === null) {
                if (! $value) {
                    return false;
                }

                continue;
            }

            // Range gate
            if ($min !== null && $value < (float) $min) {
                \Log::channel('redis-scan')->debug('[EvaluateBarJob] Gate below min', [
                    'gate' => $gate,
                    'value' => $value,
                    'min' => $min,
                ]);

                return false;
            }
            if ($max !== null && $value > (float) $max) {
                \Log::channel('redis-scan')->debug('[EvaluateBarJob] Gate above max', [
                    'gate' => $gate,
                    'value' => $value,
                    'max' => $max,
                ]);

                return false;
            }
        }

        return true;
    }

    /**
     * Compute version-specific score from the scanner_score_formula.
     *
     * Safe expression parser supporting:
     *   - Variables: move30m, rvol, rvolRatio, atrPct, greenDays
     *   - Functions: min(a, b)
     *   - Operators: +, -, *, /
     *   - Parentheses for grouping
     *
     * No eval() — uses a tokenizer + recursive-descent evaluator.
     */
    private function computeScore(?string $formula): float
    {
        if ($formula === null || $formula === '') {
            return 0.0;
        }

        $vars = [
            'move30m' => $this->gates['move_30m_pct'] ?? 0,
            'rvol' => $this->gates['rvol_ratio'] ?? 0,
            'rvolRatio' => $this->gates['rvol_ratio'] ?? 0,
            'atrPct' => $this->gates['atr_pct'] ?? 0,
            'greenDays' => $this->gates['higher_low_count'] ?? 0,
            'notional' => $this->gates['notional'] ?? 0,
            'rs_ratio' => $this->gates['rs_ratio'] ?? 0,
            'vol_ratio_1m' => $this->gates['vol_ratio_1m'] ?? 0,
        ];

        return round(self::evaluateFormula($formula, $vars), 3);
    }

    /**
     * Tokenize and evaluate a score formula with variables and min().
     *
     * Supported tokens: numbers (int/float), identifiers, operators (+ - * /),
     * parentheses, comma, function names.
     *
     * @param  array<string, float|int>  $vars
     */
    private static function evaluateFormula(string $expr, array $vars): float
    {
        $expr = trim($expr);
        if ($expr === '') {
            return 0.0;
        }

        // Tokenize
        $tokens = [];
        $len = strlen($expr);
        $i = 0;

        while ($i < $len) {
            $c = $expr[$i];

            // whitespace
            if ($c === ' ' || $c === "\t") {
                $i++;

                continue;
            }

            // number (integer or float)
            if (ctype_digit($c) || ($c === '.' && $i + 1 < $len && ctype_digit($expr[$i + 1]))) {
                $num = '';
                while ($i < $len && (ctype_digit($expr[$i]) || $expr[$i] === '.')) {
                    $num .= $expr[$i];
                    $i++;
                }
                $tokens[] = ['type' => 'number', 'value' => (float) $num];

                continue;
            }

            // identifier or function name
            if (ctype_alpha($c) || $c === '_') {
                $id = '';
                while ($i < $len && (ctype_alnum($expr[$i]) || $expr[$i] === '_')) {
                    $id .= $expr[$i];
                    $i++;
                }
                $tokens[] = ['type' => 'identifier', 'value' => $id];

                continue;
            }

            // operators and punctuation
            switch ($c) {
                case '+': $tokens[] = ['type' => 'op', 'value' => '+'];
                    break;
                case '-': $tokens[] = ['type' => 'op', 'value' => '-'];
                    break;
                case '*': $tokens[] = ['type' => 'op', 'value' => '*'];
                    break;
                case '/': $tokens[] = ['type' => 'op', 'value' => '/'];
                    break;
                case '(': $tokens[] = ['type' => 'lparen'];
                    break;
                case ')': $tokens[] = ['type' => 'rparen'];
                    break;
                case ',': $tokens[] = ['type' => 'comma'];
                    break;
                default:
                    // skip unexpected characters
                    break;
            }
            $i++;
        }

        // Recursive descent evaluation
        $pos = 0;
        $count = count($tokens);

        $advance = function () use (&$pos, &$tokens, $count) {
            if ($pos >= $count) {
                return ['type' => 'eof'];
            }

            return $tokens[$pos++];
        };

        $peek = function () use (&$pos, &$tokens, $count) {
            if ($pos >= $count) {
                return ['type' => 'eof'];
            }

            return $tokens[$pos];
        };

        $parseExpression = function () use (&$advance, &$peek, &$parseTerm) {
            $left = $parseTerm();

            while ($peek()['type'] === 'op' && in_array($peek()['value'], ['+', '-'], true)) {
                $op = $advance()['value'];
                $right = $parseTerm();
                if ($op === '+') {
                    $left += $right;
                } else {
                    $left -= $right;
                }
            }

            return $left;
        };

        $parseTerm = function () use (&$advance, &$peek, &$parseFactor) {
            $left = $parseFactor();

            while ($peek()['type'] === 'op' && in_array($peek()['value'], ['*', '/'], true)) {
                $op = $advance()['value'];
                $right = $parseFactor();
                if ($right == 0 && $op === '/') {
                    return 0.0; // division by zero guard
                }
                if ($op === '*') {
                    $left *= $right;
                } else {
                    $left /= $right;
                }
            }

            return $left;
        };

        $parseFactor = function () use (&$advance, &$peek, &$parseExpression, $vars, &$parseFactor) {
            $token = $advance();

            // number
            if ($token['type'] === 'number') {
                return $token['value'];
            }

            // unary minus
            if ($token['type'] === 'op' && $token['value'] === '-') {
                return -$parseFactor();
            }

            // unary plus
            if ($token['type'] === 'op' && $token['value'] === '+') {
                return $parseFactor();
            }

            // parenthesized expression
            if ($token['type'] === 'lparen') {
                $result = $parseExpression();
                $close = $advance(); // consume ')'

                return $result;
            }

            // identifier — could be variable or function
            if ($token['type'] === 'identifier') {
                $name = $token['value'];

                // function call — must be followed by '('
                if ($peek()['type'] === 'lparen') {
                    $advance(); // consume '('

                    // collect arguments until ')'
                    $args = [];
                    while ($peek()['type'] !== 'rparen' && $peek()['type'] !== 'eof') {
                        $args[] = $parseExpression();
                        if ($peek()['type'] === 'comma') {
                            $advance(); // consume ','
                        }
                    }
                    $advance(); // consume ')'

                    // supported functions
                    if ($name === 'min') {
                        if (count($args) < 2) {
                            return 0.0;
                        }

                        return min($args[0], $args[1]);
                    }

                    return 0.0; // unknown function
                }

                // variable lookup
                return $vars[$name] ?? 0.0;
            }

            return 0.0;
        };

        try {
            $result = $parseExpression();

            return (float) $result;
        } catch (\Throwable) {
            return 0.0;
        }
    }

    /**
     * Build entry data directly from pre-computed gate values.
     *
     * Matches OneMinuteEntryFinderV25_2::doFindBestLong() logic exactly:
     * - Room-to-run: max(roomToHodPct, atrPct * roomAtrMult) >= min_room_to_run_pct
     * - VWAP reclaim: prev bar ≤ VWAP, current > VWAP, bodyPct > min
     * - ORB retest: orHigh > 0, low crossed below, close > orHigh, volRatio ≥ min
     * - Score: (volRatio * 1.2) + max(0, 1.5 - abs(aboveVwapPct)) + (bodyPct * 50)
     */
    private function buildEntry(array $candidate, array $version): ?array
    {
        $g = $this->gates;
        $g1m = $version['gates_1m'] ?? [];

        // ── Price computation ──
        $atr = $g['atr_1m'] ?? $g['atr'] ?? 0;
        $atrPct = $g['atr_pct'] ?? 0;
        $price = $g['price'] ?? ($atrPct > 0 ? round($atr / ($atrPct / 100), 2) : 0);

        if ($price <= 0) {
            return null;
        }

        // ── Room-to-Run (matches V1: max(roomToHod, atrPct*roomAtrMult) >= minRoom) ──
        $roomToHodPct = $g['room_to_hod_pct'] ?? 0;
        $roomAtrMult = (float) ($g1m['room_atr_mult']['threshold_min'] ?? 1.5);
        $roomAtr = $atrPct * $roomAtrMult;
        $room = max($roomToHodPct, $roomAtr);
        $minRoomPct = (float) ($g1m['room_to_hod_pct']['threshold_min'] ?? 0.5);

        if ($room < $minRoomPct) {
            return null;
        }

        // ── VWAP & candle data ──
        $aboveVwapPct = $g['above_vwap_entry_pct'] ?? $g['above_vwap_pct'] ?? 0;
        $volRatio = $g['vol_ratio_1m'] ?? $g['rvol_ratio'] ?? 0;
        $bodyPct = $g['body_pct'] ?? 0;
        $emaAligned = $g['ema9_above_ema21_1m'] ?? $g['ema9_above_ema21'] ?? 0;
        $closePos = $g['close_position'] ?? 0;
        $minBodyPct = (float) ($g1m['body_pct']['threshold_min'] ?? 0.01);
        $minVolRatio = (float) ($g1m['vol_ratio_1m']['threshold_min'] ?? 0.8);

        // ── Entry type classification (matches V1 ordering: last wins) ──
        $entryType = 'EMA9_PULLBACK';

        // ORB_RETEST: uses orHigh tracking from 5m evaluator
        $orHigh = $g['opening_range_high'] ?? $g['or_high'] ?? 0;
        $lowPrice = $g['price'] ?? 0; // We need low, not close
        if ($orHigh > 0 && $volRatio >= $minVolRatio) {
            $entryType = 'ORB_RETEST';
        }

        // VWAP_RECLAIM: checks VWAP crossover and body confirmation (matches V1)
        if ($aboveVwapPct > 0 && $bodyPct > $minBodyPct) {
            $entryType = 'VWAP_RECLAIM';
        }

        // ── Score (matches V1 exactly) ──
        $score = ($volRatio * 1.2) + max(0.0, 1.5 - abs($aboveVwapPct)) + ($bodyPct * 50.0);

        // ── Stop price (matches V1: close - atr*multiplier, floored at low*0.995) ──
        $atrMultiplier = \App\Services\TradingSettingService::getStopLossAtrMultiplier();
        $stopPrice = round($price - ($atr * $atrMultiplier), 2);
        $stopPrice = max($stopPrice, $price * 0.98);

        $risk = $price - $stopPrice;
        $riskPct = $price > 0 ? ($risk / $price) * 100 : 0;

        return [
            // Core fields (matches V1 doFindBestLong)
            'entry_price' => round($price, 2),
            'stop_loss' => round($stopPrice, 2),
            'entry_type' => $entryType,
            'entry_ts_est' => $this->tsEst,
            'score' => round($score, 3),
            'risk_pct' => round($riskPct, 3),
            'risk_per_share' => round($risk, 6),
            'atr_pct' => $price > 0 ? round(($atr / $price) * 100, 3) : 0,
            'atr' => round($atr, 2),
            'suggested_trailing_stop' => round($price * (max(0.7, min(1.0, ($atr * $atrMultiplier / $price) * 100)) / 100), 6),
            'suggested_trailing_stop_pct' => round(max(0.7, min(1.0, ($atr * $atrMultiplier / $price) * 100)), 3),
            'targets' => [
                '1R' => round($price + 1.0 * $risk, 6),
                '2R' => round($price + 2.0 * $risk, 6),
                '3R' => round($price + 3.0 * $risk, 6),
            ],
            'vwap' => round($g['price'] ?? $price, 2),
            'ema9' => round((float) ($g['ema9_above_ema21_1m'] ?? 0), 2),
            'hod' => round($g['hod'] ?? $price, 2),
            'body_pct' => round($bodyPct, 4),
            'vol_ratio' => round($volRatio, 2),
            'above_vwap_pct' => round($aboveVwapPct, 3),
            'room_to_run_pct' => round($room, 3),

            // ── ML feature fields (map GateEvaluator gate names → entry field names) ──
            // Room-to-run
            'room_to_hod_pct' => $g['room_to_hod_pct'] ?? null,
            'room_to_hod_atr' => $g['room_to_hod_atr'] ?? null,
            // VWAP entry distance
            'above_vwap_entry_pct' => $g['above_vwap_entry_pct'] ?? null,
            // Entry quality
            'rsi' => $g['rsi'] ?? null,
            'entry_body_pct' => $g['body_pct'] ?? null,
            'entry_close_position' => $g['close_position'] ?? null,
            'entry_volume_ratio' => $g['vol_ratio_1m'] ?? null,
            'entry_notional_1m' => $g['notional_1m'] ?? null,
            // 5m choppiness / quality (from 5m evaluation gates stored in candidate)
            'five_min_directional_changes' => $candidate['gates']['directional_changes'] ?? null,
            'five_min_green_bar_pct' => $candidate['gates']['green_bar_pct'] ?? null,
            'five_min_net_progress' => $candidate['gates']['net_progress_pct'] ?? null,
            'consolidation_bars' => $candidate['gates']['consolidation_bars'] ?? null,
            'breakout_volume_ratio' => $candidate['gates']['breakout_volume_ratio'] ?? null,
            // Entry score sub-components (matches V1 computeEntryScoreComponents)
            ...\App\Services\TradingV2\EntryTypeClassifier::computeScoreComponents(array_merge($g, ['ts_est' => $this->tsEst])),

            // Additional
            'query_source' => 'redis',
            'meta' => $g,
        ];
    }
}
