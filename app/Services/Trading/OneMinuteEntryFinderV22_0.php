<?php

namespace App\Services\Trading;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class OneMinuteEntryFinderV22_0
{
    use HasPriceTables;

    private string $version = 'v22.0';

    private static array $dbg = [
        'called' => 0,
        'not_enough_bars' => 0,

        'not_wakeup_confirm' => 0,
        'no_wake_in_window' => 0,

        'min_price' => 0,
        'min_vol5' => 0,
        'min_notional5' => 0,

        'no_sleep_transition' => 0,

        'max_dist_jaw' => 0,
        'hold_above_jaw' => 0,
        'spread_grow' => 0,

        'returned' => 0,
    ];

    public function getVersion(): string
    {
        return $this->version;
    }

    /**
     * Used by v21 path only (batch).
     * Kept for compatibility if you ever route v22 into the v21 batch path.
     */
    public function findEntries(array $signals, string $assetType, string $asOfTsEst): array
    {
        if (empty($signals)) {
            return [];
        }

        $entries = [];
        foreach ($signals as $signal) {
            $symbol = $signal['symbol'] ?? null;
            if (! $symbol) {
                continue;
            }

            $entry = $this->checkAlligatorWakeOrEat((string) $symbol, $assetType, $asOfTsEst);

            if ($entry !== null) {
                // For the v21 batch writer path, the entry array must contain writer keys.
                $entries[] = array_merge($signal, $entry, [
                    'signal_ts_est' => $signal['signal_ts_est'] ?? $asOfTsEst,
                    'entry_type' => $entry['type'] ?? 'ALLIGATOR_WAKE_UP',
                    'version' => $this->version,
                ]);
            }
        }

        $this->maybeLogDebug();

        return $entries;
    }

    /**
     * REQUIRED by TradePipelineRunB non-v21 path:
     * Must return ['ok'=>1, 'best_entry'=> [...]].
     */
    public function findBestLong(string $symbol, string $assetType, string $signalTsEst, string $asOfTsEst, ...$rest): array
    {
        self::$dbg['called']++;

        $entry = $this->checkAlligatorWakeOrEat($symbol, $assetType, $asOfTsEst);

        if ($entry === null) {
            $this->maybeLogDebug();

            return [
                'ok' => 0,
                'best_entry' => null,
                'reason' => 'no_entry',
            ];
        }

        self::$dbg['returned']++;
        $this->maybeLogDebug();

        // Ensure writer-required keys exist inside best_entry:
        // type, entry_ts_est, entry, stop
        if (empty($entry['entry_ts_est'])) {
            $entry['entry_ts_est'] = $asOfTsEst;
        }

        // Some pipelines like extra context in entry_meta; safe to keep.
        $entry['symbol'] = $symbol;
        $entry['asset_type'] = $assetType;
        $entry['signal_ts_est'] = $signalTsEst;

        return [
            'ok' => 1,
            'best_entry' => $entry,
            'meta' => [
                'version' => $this->version,
                'as_of_ts_est' => $asOfTsEst,
            ],
        ];
    }

    /**
     * Safe stub (not used in your current Pipeline B).
     */
    public function findBestShort(string $symbol, string $assetType, string $signalTsEst, string $asOfTsEst, ...$rest): array
    {
        return [
            'ok' => 0,
            'best_entry' => null,
            'reason' => 'short_not_implemented',
        ];
    }

    private function isDebugEnabled(): bool
    {
        return ((string) env('ALLIGATOR_DEBUG', '0') === '1')
            || (bool) config('trading.alligator_debug', false);
    }

    private function maybeLogDebug(): void
    {
        if (! $this->isDebugEnabled()) {
            return;
        }

        if (self::$dbg['called'] > 0 && self::$dbg['called'] % 20000 === 0) {
            Log::info('[AlligatorV22] debug counters', self::$dbg);
        }
    }

    /**
     * Core entry logic:
     * - Webull-ish HL2 SMMA for Alligator
     * - Confirm window: bullish order AND state in {WAKE_UP, EATING}
     * - Require >=1 WAKE_UP in window (wake then run)
     * - Liquidity + loser filters
     *
     * Returns an ENTRY ARRAY suitable for TradeAlertWriterV1:
     * - type
     * - entry_ts_est
     * - entry
     * - stop
     */
    private function checkAlligatorWakeOrEat(string $symbol, string $assetType, string $asOfTsEst): ?array
    {
        // ========= Tunables =========
        $minPrice = 1.0;

        $minVol5 = 30000;
        $minNotional5 = 25000.0;

        $confirmBars = 3;
        $sleepThreshold = 0.0015;

        $sleepLookbackMinutes = 30;
        $requireSleepTransition = false;

        $maxDistFromJawPct = 6.0;
        $holdAboveJawBars = 2;
        $minSpreadGrowPct = 0.75;
        // ============================

        $bars = DB::table($this->oneMinuteTable)
            ->where('symbol', $symbol)
            ->where('asset_type', $assetType)
            ->where('ts_est', '<=', $asOfTsEst)
            ->orderBy('ts_est', 'desc')
            ->limit(160)
            ->get(['ts_est', 'price', 'high', 'low', 'volume'])
            ->reverse()
            ->values()
            ->toArray();

        if (count($bars) < 60) {
            self::$dbg['not_enough_bars']++;

            return null;
        }

        $states = $this->calculateAlligatorStatesWebullHL2($bars, $sleepThreshold);
        if (count($states) < 15) {
            self::$dbg['not_enough_bars']++;

            return null;
        }

        $recent = array_slice($states, -$confirmBars);
        if (count($recent) < $confirmBars) {
            self::$dbg['not_enough_bars']++;

            return null;
        }

        // Confirm: bullish order + state in {WAKE_UP, EATING}, and must include at least one WAKE_UP.
        $okStates = ['WAKE_UP' => true, 'EATING' => true];
        $hasWake = false;

        foreach ($recent as $s) {
            $st = $s['state'] ?? 'UNDEFINED';
            $bull = (bool) ($s['bullish_order'] ?? false);

            if (! $bull || ! isset($okStates[$st])) {
                self::$dbg['not_wakeup_confirm']++;

                return null;
            }

            if ($st === 'WAKE_UP') {
                $hasWake = true;
            }
        }

        if (! $hasWake) {
            self::$dbg['no_wake_in_window']++;

            return null;
        }

        $last = $recent[$confirmBars - 1];
        $close = (float) ($last['close'] ?? 0.0);
        if ($close < $minPrice) {
            self::$dbg['min_price']++;

            return null;
        }

        // 5-min rolling volume + notional
        $last5Bars = array_slice($bars, -5);
        $vol5 = 0;
        foreach ($last5Bars as $b) {
            $vol5 += (int) ($b->volume ?? 0);
        }

        if ($vol5 < $minVol5) {
            self::$dbg['min_vol5']++;

            return null;
        }

        $notional5 = $vol5 * $close;
        if ($notional5 < $minNotional5) {
            self::$dbg['min_notional5']++;

            return null;
        }

        // Optional sleep transition
        $lookback = array_slice($states, -$sleepLookbackMinutes);
        $sleepFound = false;
        foreach ($lookback as $s) {
            if (($s['state'] ?? '') === 'SLEEPING') {
                $sleepFound = true;
                break;
            }
        }
        if ($requireSleepTransition && ! $sleepFound) {
            self::$dbg['no_sleep_transition']++;

            return null;
        }

        // Loser filters
        $jaw = $last['jaw'] ?? null;
        if ($jaw !== null && $close > 0) {
            $distPct = (($close - (float) $jaw) / $close) * 100.0;
            if ($distPct > $maxDistFromJawPct) {
                self::$dbg['max_dist_jaw']++;

                return null;
            }
        }

        if ($holdAboveJawBars > 0) {
            $tail = array_slice($states, -max(6, $holdAboveJawBars));
            $ok = 0;
            for ($i = count($tail) - $holdAboveJawBars; $i < count($tail); $i++) {
                if ($i < 0) {
                    continue;
                }
                $cj = $tail[$i]['jaw'] ?? null;
                $cc = $tail[$i]['close'] ?? null;
                if ($cj !== null && $cc !== null && (float) $cc >= (float) $cj) {
                    $ok++;
                }
            }
            if ($ok < $holdAboveJawBars) {
                self::$dbg['hold_above_jaw']++;

                return null;
            }
        }

        $lips = $last['lips'] ?? null;
        $prev = $states[count($states) - 2] ?? null;

        if ($prev && $lips !== null && $jaw !== null && ($prev['lips'] ?? null) !== null && ($prev['jaw'] ?? null) !== null) {
            $spreadNow = ((float) $lips - (float) $jaw) / max(1e-9, $close);
            $prevClose = (float) ($prev['close'] ?? $close);
            $spreadPrev = ((float) $prev['lips'] - (float) $prev['jaw']) / max(1e-9, $prevClose);

            if ($spreadPrev > 0) {
                $growPct = (($spreadNow - $spreadPrev) / $spreadPrev) * 100.0;
                if ($growPct < $minSpreadGrowPct) {
                    self::$dbg['spread_grow']++;

                    return null;
                }
            }
        }

        $atr = $this->calculateATRFromHighLow($bars, 14);
        $atrPct = $close > 0 ? ($atr / $close) * 100.0 : 0.0;
        $trail = $atr * 3.0;
        $trailPct = $close > 0 ? ($trail / $close) * 100.0 : 0.0;

        $score = $sleepFound ? 0.88 : 0.82;

        // Writer-required output
        return [
            'type' => 'ALLIGATOR_WAKE_UP',
            'entry_ts_est' => $asOfTsEst,
            'entry' => $close,
            'stop' => $jaw,

            'risk_pct' => ($close > 0 && $jaw !== null) ? (($close - (float) $jaw) / $close) * 100.0 : 0.0,
            'score' => $score,

            'lips' => $lips,
            'teeth' => $last['teeth'] ?? null,
            'jaw' => $jaw,
            'alligator_state' => $last['state'] ?? 'UNDEFINED',
            'alligator_consecutive' => $confirmBars,

            'vol_5min' => $vol5,
            'notional_5min' => round($notional5, 2),

            'atr' => round($atr, 6),
            'atr_pct' => round($atrPct, 2),
            'suggested_trailing_stop' => round($trail, 6),
            'suggested_trailing_stop_pct' => round($trailPct, 2),

            'sleep_transition_found' => $sleepFound ? 1 : 0,
        ];
    }

    private function calculateAlligatorStatesWebullHL2(array $bars, float $sleepThreshold): array
    {
        $states = [];

        $smma5 = null;
        $smma8 = null;
        $smma13 = null;
        $seed5 = [];
        $seed8 = [];
        $seed13 = [];
        $raw5 = [];
        $raw8 = [];
        $raw13 = [];

        $prevLips = null;
        $prevTeeth = null;

        foreach ($bars as $i => $bar) {
            $ts = $bar->ts_est;
            $close = (float) $bar->price;

            $high = (float) $bar->high;
            $low = (float) $bar->low;
            $hl2 = ($high + $low) / 2.0;

            if ($smma5 === null) {
                $seed5[] = $hl2;
                if (count($seed5) === 5) {
                    $smma5 = array_sum($seed5) / 5.0;
                }
            } else {
                $smma5 = (($smma5 * 4.0) + $hl2) / 5.0;
            }

            if ($smma8 === null) {
                $seed8[] = $hl2;
                if (count($seed8) === 8) {
                    $smma8 = array_sum($seed8) / 8.0;
                }
            } else {
                $smma8 = (($smma8 * 7.0) + $hl2) / 8.0;
            }

            if ($smma13 === null) {
                $seed13[] = $hl2;
                if (count($seed13) === 13) {
                    $smma13 = array_sum($seed13) / 13.0;
                }
            } else {
                $smma13 = (($smma13 * 12.0) + $hl2) / 13.0;
            }

            if ($smma5 !== null) {
                $raw5[$ts] = $smma5;
            }
            if ($smma8 !== null) {
                $raw8[$ts] = $smma8;
            }
            if ($smma13 !== null) {
                $raw13[$ts] = $smma13;
            }

            $lips = null;
            $teeth = null;
            $jaw = null;
            if ($i >= 3) {
                $t = $bars[$i - 3]->ts_est;
                $lips = $raw5[$t] ?? null;
            }
            if ($i >= 5) {
                $t = $bars[$i - 5]->ts_est;
                $teeth = $raw8[$t] ?? null;
            }
            if ($i >= 8) {
                $t = $bars[$i - 8]->ts_est;
                $jaw = $raw13[$t] ?? null;
            }

            $state = 'UNDEFINED';
            $bull = false;

            if ($lips !== null && $teeth !== null && $jaw !== null && $close > 0) {
                if (abs($lips - $teeth) / $close < $sleepThreshold &&
                    abs($teeth - $jaw) / $close < $sleepThreshold) {
                    $state = 'SLEEPING';
                } elseif ($lips > $teeth && $teeth > $jaw) {
                    $bull = true;

                    if ($close > $lips) {
                        $state = 'EATING';
                    } elseif ($close >= $teeth && $close <= $lips) {
                        $state = 'WAKE_UP';
                    }
                } elseif ($prevLips !== null && $prevTeeth !== null && $lips < $teeth && $prevLips >= $prevTeeth) {
                    $state = 'SATED';
                }
            }

            $states[] = [
                'ts' => $ts,
                'close' => $close,
                'lips' => $lips,
                'teeth' => $teeth,
                'jaw' => $jaw,
                'state' => $state,
                'bullish_order' => $bull,
            ];

            $prevLips = $lips;
            $prevTeeth = $teeth;
        }

        return $states;
    }

    private function calculateATRFromHighLow(array $bars, int $period = 14): float
    {
        if (count($bars) < $period + 1) {
            return 0.0;
        }

        $trs = [];
        for ($i = 1; $i < count($bars); $i++) {
            $prevClose = (float) $bars[$i - 1]->price;
            $high = (float) $bars[$i]->high;
            $low = (float) $bars[$i]->low;

            $tr = max(
                $high - $low,
                abs($high - $prevClose),
                abs($low - $prevClose)
            );
            $trs[] = $tr;
        }

        $count = min($period, count($trs));
        $sum = 0.0;

        for ($i = count($trs) - $count; $i < count($trs); $i++) {
            $sum += $trs[$i];
        }

        return $count > 0 ? $sum / $count : 0.0;
    }
}
