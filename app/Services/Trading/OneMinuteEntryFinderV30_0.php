<?php

namespace App\Services\Trading;

use Illuminate\Support\Facades\Log;

/**
 * OneMinuteEntryFinderV30_0
 *
 * Supports BOTH APIs:
 *
 * 1) Legacy (Pipeline B non-v21.0 currently uses this):
 *    findBestLong($symbol,$assetType,$signalTsEst,$asOfTsEst,$before,$after,$volLookback,$pivotLookback,$fill)
 *    Returns: ['ok'=>bool,'best_entry'=>array|null,'reasons'=>array,'meta'=>array]
 *
 * 2) Modern:
 *    findBestLong($symbol,$assetType,$signalTsEst,$asOfTsEst,$optsArrayOrFillScalar)
 *    Returns same legacy-shaped response for compatibility.
 *
 * TradeAlertWriterV1 requires best_entry keys:
 *   type, entry_ts_est, entry, stop
 */
class OneMinuteEntryFinderV30_0
{
    use HasPriceTables;

    private string $version = 'v30.0';

    public function getVersion(): string
    {
        return $this->version;
    }

    /**
     * Flexible signature to match Pipeline B legacy calls.
     *
     * @return array{ok:bool,best_entry:?array,reasons:array<int,string>,meta:array<string,mixed>}
     */
    public function findBestLong(
        string $symbol,
        string $assetType,
        string $signalTsEst,
        string $asOfTsEst,
        mixed ...$args
    ): array {
        // ----------------------------
        // Normalize inputs (legacy vs modern)
        // ----------------------------
        $opts = [];

        // Legacy form: 5 args after asOf => before, after, volLookback, pivotLookback, fill
        if (count($args) >= 5 && is_numeric($args[0])) {
            $opts['beforeMins'] = (int) $args[0];
            $opts['afterMins'] = (int) ($args[1] ?? 0);
            $opts['volLookbackMins'] = (int) ($args[2] ?? 20);
            $opts['pivotLookbackMins'] = (int) ($args[3] ?? 15);
            $opts['fill'] = (string) ($args[4] ?? 'next_open');
        } else {
            // Modern form: single arg can be array OR scalar fill OR numeric minutes
            $arg0 = $args[0] ?? [];
            if (is_array($arg0)) {
                $opts = $arg0;
            } elseif (is_numeric($arg0)) {
                // Treat numeric as "before minutes"
                $opts['beforeMins'] = (int) $arg0;
            } else {
                $s = strtolower(trim((string) $arg0));
                if (in_array($s, ['next_open', 'close'], true)) {
                    $opts['fill'] = $s;
                }
            }
        }

        $beforeMins = (int) ($opts['beforeMins'] ?? 10);
        $afterMins = (int) ($opts['afterMins'] ?? 0); // deprecated in pipeline, but supported
        $volLookbackMins = (int) ($opts['volLookbackMins'] ?? ($opts['volLookback'] ?? 20));
        $pivotLookbackMins = (int) ($opts['pivotLookbackMins'] ?? ($opts['pivotLookback'] ?? 12)); // not used in this v30 logic
        $fillMode = strtolower((string) ($opts['fill'] ?? 'next_open')); // next_open|close

        // Indicator windows
        $entryLookbackMins = (int) ($opts['entryLookbackMins'] ?? 60);
        $atrLen = (int) ($opts['atrLen'] ?? 14);
        $pullbackLookbackMins = (int) ($opts['pullbackLookbackMins'] ?? 12);
        $vwapLookbackMins = (int) ($opts['vwapLookbackMins'] ?? 60);

        // Gate defaults (tunable) – OPTIMAL QUALITY avoiding exhaustion & tight stops
        $gate = [
            'minVolRatio' => (float) ($opts['minVolRatio'] ?? 2.50),  // Strong momentum (2.5x volume minimum)
            'maxVolRatio' => (float) ($opts['maxVolRatio'] ?? 6.00),  // Avoid exhaustion moves (cap at 6x)
            'minAtrPct' => (float) ($opts['minAtrPct'] ?? 0.13),      // Min 0.13% to avoid noise stops
            'maxAtrPct' => (float) ($opts['maxAtrPct'] ?? 0.20),      // Max 0.20% for cleanest movers
            'aboveVwapBps' => (int) ($opts['aboveVwapBps'] ?? 25),    // Must be 0.25% above VWAP
            'pbMaxUnderEma21' => (int) ($opts['pbMaxUnderEma21'] ?? 35), // Very shallow pullback only
        ];

        // Minimum quality score filter
        $minScore = (float) ($opts['minScore'] ?? 2.50);

        // Anti-chase filter (helps reduce losers): entry must not be too far above EMA9
        $maxAboveEma9Bps = (int) ($opts['maxAboveEma9Bps'] ?? 20); // 0.20% tighter

        // Stop logic tunables (wider to reduce false stops)
        $stopBufferBps = (int) ($opts['stopBufferBps'] ?? 20); // 0.20% under pb low (wider)
        $atrStopMult = (float) ($opts['atrStopMult'] ?? 2.00);  // 2.0x ATR multiple (much wider)
        $minStopBps = (int) ($opts['minStopBps'] ?? 15);        // at least 0.15% risk
        $maxStopBps = (int) ($opts['maxStopBps'] ?? 80);        // at most 0.80% risk

        // ----------------------------
        // Define the search window for bars
        // ----------------------------
        $asOfEpoch = strtotime($asOfTsEst) ?: null;
        $signalEpoch = strtotime($signalTsEst) ?: null;

        // Start for indicator history: around signal
        $startBase = $signalTsEst;

        // End for load (support next_open lookahead)
        $endTsForLoad = $asOfTsEst;

        if ($fillMode === 'next_open' && $signalEpoch !== null) {
            $needThrough = date('Y-m-d H:i:s', $signalEpoch + 120); // +2 minutes cushion
            $endTs = strtotime($endTsForLoad);
            if ($endTs === false || $endTs < strtotime($needThrough)) {
                $endTsForLoad = $needThrough;
            }
        }

        $minutesBack = max($entryLookbackMins, $vwapLookbackMins, $volLookbackMins, ($atrLen + 5), $pullbackLookbackMins) + 10;

        $bars = $this->loadBarsBetween($symbol, $assetType, $startBase, $endTsForLoad, $minutesBack);

        if (count($bars) < 25) {
            $reason = 'NotEnoughBars';
            $this->logReject($symbol, 'FIRST_PULLBACK_EMA9', $reason, $asOfTsEst, [
                'bars' => count($bars),
                'signal_ts_est' => $signalTsEst,
                'as_of_ts_est' => $asOfTsEst,
                'end_load_ts_est' => $endTsForLoad,
                'fill' => $fillMode,
            ]);

            return [
                'ok' => false,
                'best_entry' => null,
                'reasons' => [$reason],
                'meta' => ['version' => $this->version],
            ];
        }

        $ctx = $this->computeContext($bars, $volLookbackMins, $atrLen, $pullbackLookbackMins, $vwapLookbackMins);

        // Quality score check (vol_ratio as score)
        $score = (float) ($ctx['vol_ratio'] ?? 0);
        if ($score < $minScore) {
            $reason = 'SCORE_TOO_LOW';
            $this->logReject($symbol, 'FIRST_PULLBACK_EMA9', $reason, $asOfTsEst, [
                'score' => $score,
                'min_required' => $minScore,
            ]);

            return [
                'ok' => false,
                'best_entry' => null,
                'reasons' => [$reason],
                'meta' => ['version' => $this->version],
            ];
        }

        // Gate check
        [$ok, $code, $details] = $this->aPlusGateCheck($ctx, $gate);
        if (! $ok) {
            $reason = 'APlusGateFailed:'.$code;
            $this->logReject($symbol, 'FIRST_PULLBACK_EMA9', $reason, $asOfTsEst, array_merge($ctx, [
                'signal_ts_est' => $signalTsEst,
                'as_of_ts_est' => $asOfTsEst,
                'end_load_ts_est' => $endTsForLoad,
                'fill' => $fillMode,
                'a_plus_details' => $details,
            ]));

            return [
                'ok' => false,
                'best_entry' => null,
                'reasons' => [$reason],
                'meta' => ['version' => $this->version, 'a_plus' => $details],
            ];
        }

        // Pick entry bar based on fill mode relative to signal time
        $picked = $this->pickEntryBar($bars, $signalTsEst, $fillMode);
        if (! $picked) {
            $reason = 'NO_ENTRY_BAR_FOR_FILL';
            $this->logReject($symbol, 'FIRST_PULLBACK_EMA9', $reason, $asOfTsEst, [
                'fill' => $fillMode,
                'signal_ts_est' => $signalTsEst,
                'as_of_ts_est' => $asOfTsEst,
                'end_load_ts_est' => $endTsForLoad,
            ]);

            return [
                'ok' => false,
                'best_entry' => null,
                'reasons' => [$reason],
                'meta' => ['version' => $this->version],
            ];
        }

        $entryTs = $picked['ts'];
        $entryPx = $picked['price'];

        // Enforce "entry must be within before-minutes window ending at asOf"
        if ($asOfEpoch !== null) {
            $entryEpoch = strtotime($entryTs) ?: null;
            if ($entryEpoch !== null) {
                $windowStart = $asOfEpoch - ($beforeMins * 60);
                $windowEnd = $asOfEpoch + max(0, $afterMins) * 60;
                if ($entryEpoch < $windowStart || $entryEpoch > $windowEnd) {
                    $reason = 'ENTRY_OUTSIDE_WINDOW';
                    $this->logReject($symbol, 'FIRST_PULLBACK_EMA9', $reason, $asOfTsEst, [
                        'entry_ts_est' => $entryTs,
                        'window_start' => date('Y-m-d H:i:s', $windowStart),
                        'window_end' => date('Y-m-d H:i:s', $windowEnd),
                        'before' => $beforeMins,
                        'after' => $afterMins,
                    ]);

                    return [
                        'ok' => false,
                        'best_entry' => null,
                        'reasons' => [$reason],
                        'meta' => ['version' => $this->version],
                    ];
                }
            }
        }

        // Anti-chase: entry must not be too far above EMA9 (reduces spike buys -> stop-outs)
        $ema9 = (float) ($ctx['ema9'] ?? 0);
        if ($ema9 > 0) {
            $maxAllowed = $ema9 * (1.0 + ($maxAboveEma9Bps / 10000.0));
            if ($entryPx > $maxAllowed) {
                $reason = 'ENTRY_TOO_FAR_ABOVE_EMA9';
                $this->logReject($symbol, 'FIRST_PULLBACK_EMA9', $reason, $asOfTsEst, [
                    'entry' => $entryPx,
                    'ema9' => $ema9,
                    'maxAllowed' => $maxAllowed,
                    'maxAboveEma9Bps' => $maxAboveEma9Bps,
                ]);

                return [
                    'ok' => false,
                    'best_entry' => null,
                    'reasons' => [$reason],
                    'meta' => ['version' => $this->version],
                ];
            }
        }

        // ----------------------------
        // Improved Stop Logic (PB + ATR blended, with clamps)
        // ----------------------------
        $pbLow = (float) ($ctx['pullback_low'] ?? 0);
        $atrPct = (float) ($ctx['atr_pct'] ?? 0);

        $atrAbs = ($atrPct > 0) ? ($entryPx * ($atrPct / 100.0)) : 0.0;

        $stopFromPb = 0.0;
        if ($pbLow > 0) {
            $stopFromPb = $pbLow * (1.0 - ($stopBufferBps / 10000.0));
        }

        $stopFromAtr = 0.0;
        if ($atrAbs > 0) {
            $stopFromAtr = $entryPx - ($atrAbs * $atrStopMult);
        }

        // Use tighter of the two stops (lower stop price => more room)
        $stop = 0.0;
        if ($stopFromPb > 0 && $stopFromAtr > 0) {
            $stop = min($stopFromPb, $stopFromAtr);
        } else {
            $stop = max($stopFromPb, $stopFromAtr);
        }

        // Clamp stop distance: between minStopBps and maxStopBps
        $minStopPx = $entryPx * (1.0 - ($minStopBps / 10000.0)); // must be at least this far below
        $maxStopPx = $entryPx * (1.0 - ($maxStopBps / 10000.0)); // must not be farther than this

        // If stop is too tight (too close to entry), push it down to minStopPx
        if ($stop > $minStopPx) {
            $stop = $minStopPx;
        }
        // If stop is too wide, pull it up to maxStopPx
        if ($stop < $maxStopPx) {
            $stop = $maxStopPx;
        }

        if ($stop <= 0 || $stop >= $entryPx) {
            $reason = 'INVALID_STOP';
            $this->logReject($symbol, 'FIRST_PULLBACK_EMA9', $reason, $asOfTsEst, [
                'entry' => $entryPx,
                'stop' => $stop,
                'pullback_low' => $pbLow,
                'stop_buffer_bps' => $stopBufferBps,
                'atr_pct' => $atrPct,
                'atr_abs' => $atrAbs,
                'atrStopMult' => $atrStopMult,
                'minStopBps' => $minStopBps,
                'maxStopBps' => $maxStopBps,
            ]);

            return [
                'ok' => false,
                'best_entry' => null,
                'reasons' => [$reason],
                'meta' => ['version' => $this->version],
            ];
        }

        $riskPerShare = $entryPx - $stop;
        $riskPct = ($riskPerShare / $entryPx) * 100.0;

        // Trailing suggestions (keep compatible with your report)
        $atr = ($atrAbs > 0) ? $atrAbs : 0.0;
        $suggestedTrailingStop = $atr * 3.0;
        $suggestedTrailingStopPct = ($entryPx > 0) ? ($suggestedTrailingStop / $entryPx) * 100.0 : 0.0;

        // Scoring: vol_ratio dominates, ATR adds small boost (helps find “bigger” movers)
        $volRatio = (float) ($ctx['vol_ratio'] ?? 0);
        $atrBonus = 0.0;
        if ($atrPct > 0) {
            $atrBonus = min($atrPct, 0.30) / 0.30 * 0.25; // up to +0.25
        }
        $score = $volRatio * (1.0 + $atrBonus);

        // REQUIRED KEYS for TradeAlertWriterV1:
        $bestEntry = [
            'type' => 'FIRST_PULLBACK_EMA9',
            'entry_ts_est' => $entryTs,
            'entry' => $entryPx,
            'stop' => $stop,

            // extras
            'risk_per_share' => $riskPerShare,
            'risk_pct' => $riskPct,
            'score' => $score,
            'vol_ratio' => $ctx['vol_ratio'] ?? null,
            'atr' => $atr,
            'atr_pct' => $ctx['atr_pct'] ?? null,
            'suggested_trailing_stop' => $suggestedTrailingStop,
            'suggested_trailing_stop_pct' => $suggestedTrailingStopPct,
            'ema9' => $ctx['ema9'] ?? null,
            'ema21' => $ctx['ema21'] ?? null,
            'vwap' => $ctx['vwap'] ?? null,
            'pullback_low' => $pbLow,
            'fill' => $fillMode,
            'version' => $this->version,
        ];

        Log::channel('trading')->info('[OneMinuteEntryFinderV30_0] ENTRY', [
            'symbol' => $symbol,
            'asset_type' => $assetType,
            'signal_ts_est' => $signalTsEst,
            'as_of_ts_est' => $asOfTsEst,
            'end_load_ts_est' => $endTsForLoad,
            'before' => $beforeMins,
            'after' => $afterMins,
            'volLookback' => $volLookbackMins,
            'pivotLookback' => $pivotLookbackMins,
        ] + $bestEntry);

        return [
            'ok' => true,
            'best_entry' => $bestEntry,
            'reasons' => [],
            'meta' => [
                'version' => $this->version,
                'ctx' => $ctx,
            ],
        ];
    }

    /**
     * @return array<int,object>
     */
    private function loadBarsBetween(string $symbol, string $assetType, string $startTsEst, string $endTsEst, int $minutesBack): array
    {
        $sql = '
            SELECT
                ts_est,
                price,
                volume
                -- Uncomment if your table has high/low columns:
                -- , NULLIF(high, 0) AS high
                -- , NULLIF(low, 0)  AS low
            FROM one_minute_prices
            WHERE symbol = ?
              AND asset_type = ?
              AND trading_date_est = ?
              AND ts_est BETWEEN (CAST(? AS DATETIME) - INTERVAL ? MINUTE) AND CAST(? AS DATETIME)
            ORDER BY ts_est ASC
        ';

        $tradingDate = substr($startTsEst, 0, 10);

        return $this->dbSelect($sql, [$symbol, $assetType, $tradingDate, $startTsEst, $minutesBack, $endTsEst]);
    }

    /**
     * @param  array<int,object>  $bars
     * @return array{ts:string,price:float}|null
     */
    private function pickEntryBar(array $bars, string $signalTsEst, string $fillMode): ?array
    {
        $fillMode = strtolower(trim($fillMode));

        $signalBar = null;
        $nextBar = null;

        foreach ($bars as $b) {
            $ts = (string) $b->ts_est;

            if ($ts === $signalTsEst) {
                $signalBar = $b;

                continue;
            }
            if ($ts > $signalTsEst) {
                $nextBar = $b;
                break;
            }
        }

        if ($fillMode === 'close') {
            if (! $signalBar) {
                return null;
            }

            return ['ts' => (string) $signalBar->ts_est, 'price' => (float) $signalBar->price];
        }

        // default: next_open
        if (! $nextBar) {
            return null;
        }

        return ['ts' => (string) $nextBar->ts_est, 'price' => (float) $nextBar->price];
    }

    /**
     * @param  array<int,object>  $bars  ascending
     * @return array<string,mixed>
     */
    private function computeContext(array $bars, int $volLookbackMins, int $atrLen, int $pullbackLookbackMins, int $vwapLookbackMins): array
    {
        $n = count($bars);
        $last = $bars[$n - 1];

        $closes = array_map(fn ($b) => (float) $b->price, $bars);

        $ema9 = $this->ema($closes, 9);
        $ema21 = $this->ema($closes, 21);

        $vwap = $this->vwapFromBars($bars, $vwapLookbackMins);
        $vol_ratio = $this->volRatio($bars, $volLookbackMins);
        $atr_pct = $this->atrPct($bars, $atrLen);
        $pullback_low = $this->pullbackLow($bars, $pullbackLookbackMins);

        return [
            'now_est' => (string) $last->ts_est,
            'price' => (float) $last->price,
            'ema9' => (float) $ema9,
            'ema21' => (float) $ema21,
            'vwap' => $vwap,
            'vol_ratio' => $vol_ratio,
            'atr_pct' => $atr_pct,
            'pullback_low' => $pullback_low,
        ];
    }

    /**
     * @param  array<string,mixed>  $ctx
     * @param  array<string,mixed>  $gate
     * @return array{0:bool,1:string,2:array<string,mixed>}
     */
    private function aPlusGateCheck(array $ctx, array $gate): array
    {
        $price = (float) ($ctx['price'] ?? 0);
        $ema9 = (float) ($ctx['ema9'] ?? 0);
        $ema21 = (float) ($ctx['ema21'] ?? 0);
        $vwap = $ctx['vwap'] ?? null;
        $volr = $ctx['vol_ratio'] ?? null;
        $atrp = $ctx['atr_pct'] ?? null;
        $pbLow = $ctx['pullback_low'] ?? null;

        if ($price <= 0 || $ema9 <= 0 || $ema21 <= 0) {
            return [false, 'MISSING_CORE_FIELDS', [
                'price' => $price, 'ema9' => $ema9, 'ema21' => $ema21, 'vwap' => $vwap,
                'vol_ratio' => $volr, 'atr_pct' => $atrp, 'pullback_low' => $pbLow,
            ]];
        }

        $minVolRatio = (float) $gate['minVolRatio'];
        $maxVolRatio = (float) ($gate['maxVolRatio'] ?? 999.0);  // Default to no cap if not set
        $minAtrPct = (float) $gate['minAtrPct'];
        $maxAtrPct = (float) $gate['maxAtrPct'];
        $aboveVwapBps = (int) $gate['aboveVwapBps'];
        $pbMaxUnderEma21 = (int) $gate['pbMaxUnderEma21'];

        if (! is_numeric($volr) || (float) $volr < $minVolRatio) {
            return [false, 'VOL_RATIO_LOW', ['vol_ratio' => (float) $volr, 'min' => $minVolRatio]];
        }

        if ((float) $volr > $maxVolRatio) {
            return [false, 'VOL_EXHAUSTION', ['vol_ratio' => (float) $volr, 'max' => $maxVolRatio]];
        }

        if (! is_numeric($atrp) || (float) $atrp < $minAtrPct) {
            return [false, 'ATR_TOO_LOW', ['atr_pct' => (float) $atrp, 'min' => $minAtrPct]];
        }
        if ((float) $atrp > $maxAtrPct) {
            return [false, 'ATR_TOO_HIGH', ['atr_pct' => (float) $atrp, 'max' => $maxAtrPct]];
        }

        if ($ema9 <= $ema21) {
            return [false, 'EMA_STACK_FAIL', ['ema9' => $ema9, 'ema21' => $ema21]];
        }

        if (! is_numeric($vwap) || (float) $vwap <= 0) {
            return [false, 'VWAP_MISSING', ['vwap' => $vwap]];
        }

        $minAbove = (float) $vwap * (1.0 + ($aboveVwapBps / 10000.0));
        if ($price <= $minAbove) {
            return [false, 'ABOVE_VWAP_FAIL', [
                'price' => $price,
                'vwap' => (float) $vwap,
                'minAbove' => $minAbove,
                'bps' => $aboveVwapBps,
            ]];
        }

        if (! is_numeric($pbLow)) {
            return [false, 'PULLBACK_LOW_MISSING', ['pullback_low' => $pbLow]];
        }

        $minPb = $ema21 * (1.0 - ($pbMaxUnderEma21 / 10000.0));
        if ((float) $pbLow < $minPb) {
            return [false, 'PULLBACK_TOO_DEEP', [
                'pullback_low' => (float) $pbLow,
                'ema21' => $ema21,
                'minAllowed' => $minPb,
                'bps' => $pbMaxUnderEma21,
            ]];
        }

        return [true, 'OK', []];
    }

    /**
     * @param  array<int,float>  $values
     */
    private function ema(array $values, int $period): float
    {
        $n = count($values);
        if ($n === 0) {
            return 0.0;
        }
        if ($n < $period) {
            return array_sum($values) / max(1, $n);
        }

        $k = 2.0 / ($period + 1.0);
        $ema = array_sum(array_slice($values, 0, $period)) / $period;

        for ($i = $period; $i < $n; $i++) {
            $ema = ($values[$i] * $k) + ($ema * (1.0 - $k));
        }

        return (float) $ema;
    }

    /**
     * @param  array<int,object>  $bars
     */
    private function vwapFromBars(array $bars, int $lookbackMins): ?float
    {
        $slice = array_slice($bars, -max(1, $lookbackMins));
        $pv = 0.0;
        $v = 0.0;

        foreach ($slice as $b) {
            $vol = (float) $b->volume;
            $px = (float) $b->price;
            if ($vol <= 0 || $px <= 0) {
                continue;
            }
            $pv += $px * $vol;
            $v += $vol;
        }

        if ($v <= 0) {
            return null;
        }

        return $pv / $v;
    }

    /**
     * @param  array<int,object>  $bars
     */
    private function volRatio(array $bars, int $lookbackMins): float
    {
        $n = count($bars);
        $lastVol = (float) $bars[$n - 1]->volume;

        $slice = array_slice($bars, -max(2, $lookbackMins));
        if (count($slice) >= 2) {
            array_pop($slice);
        }

        $sum = 0.0;
        $cnt = 0;
        foreach ($slice as $b) {
            $vol = (float) $b->volume;
            if ($vol <= 0) {
                continue;
            }
            $sum += $vol;
            $cnt++;
        }

        $avg = ($cnt > 0) ? ($sum / $cnt) : 0.0;
        if ($avg <= 0) {
            return 0.0;
        }

        return $lastVol / $avg;
    }

    /**
     * ATR% (uses high/low if present; otherwise close-to-close fallback).
     *
     * @param  array<int,object>  $bars
     */
    private function atrPct(array $bars, int $len): float
    {
        $n = count($bars);
        if ($n < 3) {
            return 0.0;
        }

        $slice = array_slice($bars, -max($len + 2, 20));

        $trs = [];
        $prevClose = null;

        foreach ($slice as $b) {
            $close = (float) $b->price;
            if ($close <= 0) {
                continue;
            }

            $high = isset($b->high) ? (float) $b->high : 0.0;
            $low = isset($b->low) ? (float) $b->low : 0.0;

            if ($prevClose === null) {
                $prevClose = $close;

                continue;
            }

            if ($high > 0 && $low > 0) {
                $tr = max(
                    $high - $low,
                    abs($high - $prevClose),
                    abs($low - $prevClose)
                );
            } else {
                $tr = abs($close - $prevClose);
            }

            $trs[] = $tr;
            $prevClose = $close;
        }

        if (count($trs) < max(3, (int) floor($len * 0.6))) {
            return 0.0;
        }

        $atr = array_sum(array_slice($trs, -$len)) / min($len, count($trs));
        $lastClose = (float) $bars[$n - 1]->price;
        if ($lastClose <= 0) {
            return 0.0;
        }

        return ($atr / $lastClose) * 100.0;
    }

    /**
     * @param  array<int,object>  $bars
     */
    private function pullbackLow(array $bars, int $lookbackMins): float
    {
        $slice = array_slice($bars, -max(2, $lookbackMins));
        $min = INF;

        foreach ($slice as $b) {
            $px = (float) $b->price;
            if ($px > 0 && $px < $min) {
                $min = $px;
            }
        }

        return is_finite($min) ? $min : 0.0;
    }

    /**
     * @param  array<string,mixed>  $fields
     */
    private function logReject(string $symbol, string $type, string $reason, string $nowEst, array $fields = []): void
    {
        $payload = array_merge([
            'symbol' => $symbol,
            'type' => $type,
            'reason' => $reason,
            'now_est' => $nowEst,
        ], $fields);

        Log::channel('trading')->info('[OneMinuteEntryFinderV30_0] REJECT', $payload);
    }
}
