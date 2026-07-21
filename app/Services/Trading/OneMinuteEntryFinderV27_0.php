<?php

namespace App\Services\Trading;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Version 27.0 - Entry Finder (Volume-First)
 *
 * Entry types (long):
 * - VWAP_RECLAIM_STRONG: cross + body + volume, not extended, room to run
 * - ORB_RETEST: break OR high then retest/hold with volume
 * - ORB_BREAKOUT: break OR high with volume, no pullback needed (NEW)
 * - EMA9_PULLBACK: trend + pullback to EMA9 + reclaim with volume
 *
 * Key changes vs v25.2:
 * - Tighter entry quality: higher notional ($100k), body pct (0.10%), vol ratio (1.5x)
 * - Tighter max above VWAP (0.75%) to avoid chasing
 * - New ORB_BREAKOUT pattern for earlier entries
 * - Slightly looser room-to-run (0.6% min room, 1.5x ATR mult) to allow more entries
 */
class OneMinuteEntryFinderV27_0
{
    use HasPriceTables;

    private string $version = 'v27.0';

    private static array $dbg = [
        'called' => 0,
        'not_enough_bars' => 0,
        'time_blocked' => 0,
        'fail_notional_1m' => 0,
        'reject_extended' => 0,
        'reject_no_room' => 0,
        'returned' => 0,
    ];

    public function getVersion(): string
    {
        return $this->version;
    }

    public function findBestLong(string $symbol, string $assetType, string $signalTsEst, string $asOfTsEst, ...$rest): array
    {
        self::$dbg['called']++;

        $entry = $this->findEntry($symbol, $assetType, $signalTsEst, $asOfTsEst);

        if ($entry === null) {
            $this->maybeLogDebug();

            return ['ok' => 0, 'best_entry' => null, 'reason' => 'no_entry'];
        }

        self::$dbg['returned']++;
        $this->maybeLogDebug();

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

    public function findBestShort(string $symbol, string $assetType, string $signalTsEst, string $asOfTsEst, ...$rest): array
    {
        return ['ok' => 0, 'best_entry' => null, 'reason' => 'short_not_implemented'];
    }

    private function isDebugEnabled(): bool
    {
        return ((string) env('ENTRYFINDER_V27_DEBUG', '0') === '1')
            || (bool) config('trading.v27.debug', false);
    }

    private function maybeLogDebug(): void
    {
        if (! $this->isDebugEnabled()) {
            return;
        }
        if (self::$dbg['called'] > 0 && self::$dbg['called'] % 20000 === 0) {
            Log::info('[EntryFinderV27] debug counters', self::$dbg);
        }
    }

    private function findEntry(string $symbol, string $assetType, string $signalTsEst, string $asOfTsEst): ?array
    {
        $cfg = (array) config('trading.v27.entry', []);

        // --- Time window ---
        $allowLunch = (bool) ($cfg['allow_lunch'] ?? false);
        if (! $allowLunch && ! $this->isAllowedTime($asOfTsEst)) {
            self::$dbg['time_blocked']++;

            return null;
        }

        // --- Risk/quality gates ---
        $minBars = (int) ($cfg['min_bars'] ?? 15);
        $minNotional1m = (float) ($cfg['min_notional_1m'] ?? 50000.0);
        $maxAboveVwapPct = (float) ($cfg['max_above_vwap_entry_pct'] ?? 1.2);
        $minRoomPct = (float) ($cfg['min_room_to_run_pct'] ?? 0.4);
        $roomAtrMult = (float) ($cfg['room_atr_mult'] ?? 1.0);
        $minVolRatio = (float) ($cfg['min_vol_ratio_1m'] ?? 1.0);
        $volLookback = (int) ($cfg['vol_lookback_1m'] ?? 20);
        $minBodyPct = (float) ($cfg['min_body_pct_1m'] ?? 0.10);
        $maxEntryAgeMinutes = (int) ($cfg['max_entry_age_minutes'] ?? 12);

        $emaFast = (int) ($cfg['ema_fast'] ?? 9);
        $emaSlow = (int) ($cfg['ema_slow'] ?? 21);
        $atrPeriod = (int) ($cfg['atr_period_1m'] ?? 14);
        $beforeMinutes = (int) ($cfg['analysis_lookback_minutes'] ?? 90);

        $tradeDate = substr($signalTsEst, 0, 10);
        $marketOpen = $tradeDate.' 09:30:00';
        $analysisStart = date('Y-m-d H:i:s', strtotime($asOfTsEst." -{$beforeMinutes} minutes"));
        if ($analysisStart < $marketOpen) {
            $analysisStart = $marketOpen;
        }

        // Cache 1m bars per (symbol, asset_type, trading_date) for 60 seconds.
        // During backtests data never changes, so caching eliminates redundant
        // queries for symbols that the scanner signals repeatedly.
        $cacheKey1m = "entry_finder_v27:1m:{$assetType}:{$symbol}:{$tradeDate}";
        $bars = Cache::remember($cacheKey1m, 60, function () use ($assetType, $symbol, $tradeDate, $marketOpen, $asOfTsEst) {
            return $this->dbSelect('
                SELECT ts_est, `open`, high, low, price AS close, volume
                FROM one_minute_prices
                WHERE asset_type = ?
                  AND symbol = ?
                  AND trading_date_est = ?
                  AND ts_est >= ?
                  AND ts_est <= ?
                ORDER BY ts_est ASC
            ', [$assetType, $symbol, $tradeDate, $marketOpen, $asOfTsEst]);
        });

        if (! $bars || count($bars) < $minBars) {
            self::$dbg['not_enough_bars']++;

            return null;
        }

        // Validate data quality
        for ($i = 1; $i < count($bars); $i++) {
            $prevClose = (float) $bars[$i - 1]->close;
            $currentOpen = (float) $bars[$i]->open;
            if ($prevClose > 0) {
                $dropPct = (($currentOpen - $prevClose) / $prevClose) * 100.0;
                if ($dropPct < -50.0) {
                    self::$dbg['bad_data_extreme_drop'] = (self::$dbg['bad_data_extreme_drop'] ?? 0) + 1;

                    return null;
                }
            }
        }

        // Cache 5m bars per (symbol, asset_type, trading_date) for 60 seconds
        $cacheKey5m = "entry_finder_v27:5m:{$assetType}:{$symbol}:{$tradeDate}";
        $fiveMinBars = Cache::remember($cacheKey5m, 60, function () use ($assetType, $symbol, $tradeDate, $marketOpen, $asOfTsEst) {
            return $this->dbSelect('
                SELECT ts_est, open, high, low, price
                FROM five_minute_prices
                WHERE asset_type = ?
                  AND symbol = ?
                  AND trading_date_est = ?
                  AND ts_est >= ?
                  AND ts_est <= ?
                ORDER BY ts_est ASC
            ', [$assetType, $symbol, $tradeDate, $marketOpen, $asOfTsEst]);
        });

        $choppiness = [];
        if (count($fiveMinBars) >= 6) {
            $recent5MinBars = array_slice($fiveMinBars, -12);
            $choppiness = $this->calculate5MinChoppiness($recent5MinBars);
        }

        // Build normalized series
        $norm = [];
        $cumPV = 0.0;
        $cumV = 0.0;
        $emaF = null;
        $emaS = null;
        $kF = 2.0 / ($emaFast + 1);
        $kS = 2.0 / ($emaSlow + 1);
        $hod = 0.0;
        $orHigh = null;
        $orCount = 0;

        foreach ($bars as $r) {
            $o = (float) $r->open;
            $h = (float) $r->high;
            $l = (float) $r->low;
            $c = (float) $r->close;
            $v = (float) $r->volume;

            if ($h > $hod) {
                $hod = $h;
            }

            $typ = ($h + $l + $c) / 3.0;
            if ($v > 0) {
                $cumPV += $typ * $v;
                $cumV += $v;
            }
            $vwap = ($cumV > 0) ? ($cumPV / $cumV) : $c;

            $emaF = ($emaF === null) ? $c : (($c * $kF) + ($emaF * (1 - $kF)));
            $emaS = ($emaS === null) ? $c : (($c * $kS) + ($emaS * (1 - $kS)));

            if ($orCount < 5) {
                $orCount++;
                $orHigh = ($orHigh === null) ? $h : max($orHigh, $h);
            }

            $norm[] = [
                'ts' => (string) $r->ts_est,
                'open' => $o,
                'high' => $h,
                'low' => $l,
                'close' => $c,
                'volume' => $v,
                'vwap' => $vwap,
                'ema_f' => $emaF,
                'ema_s' => $emaS,
                'hod' => $hod,
                'or_high' => $orHigh,
            ];
        }

        $recent = array_values(array_filter($norm, fn ($b) => $b['ts'] >= $analysisStart));
        if (count($recent) < 10) {
            self::$dbg['not_enough_bars']++;

            return null;
        }

        $atr = $this->atr($norm, $atrPeriod);
        $atrPct = ($this->lastClose($norm) > 0) ? ($atr / $this->lastClose($norm)) * 100.0 : 0.0;

        $volAvg = function (int $idx) use ($norm, $volLookback): float {
            $start = max(0, $idx - $volLookback);
            if ($start >= $idx) {
                return 0.0;
            }
            $slice = array_slice($norm, $start, $idx - $start);
            $sum = 0.0;
            $n = 0;
            foreach ($slice as $b) {
                $sum += (float) $b['volume'];
                $n++;
            }

            return $n > 0 ? $sum / $n : 0.0;
        };

        $idxStart = 0;
        for ($i = 0; $i < count($norm); $i++) {
            if ($norm[$i]['ts'] >= $analysisStart) {
                $idxStart = $i;
                break;
            }
        }

        $asOfEpochRaw = strtotime($asOfTsEst);
        $asOfEpoch = $asOfEpochRaw !== false
            ? strtotime(date('Y-m-d H:i:00', $asOfEpochRaw))
            : false;
        if ($asOfEpoch === false) {
            return null;
        }
        $candidates = [];

        // ---------- Candidate 1: VWAP reclaim (strong) ----------
        for ($i = max(1, $idxStart); $i < count($norm); $i++) {
            $p = $norm[$i - 1];
            $c = $norm[$i];

            $isCrossUp = ($p['close'] < $p['vwap']) && ($c['close'] > $c['vwap']);
            if (! $isCrossUp) {
                continue;
            }

            $bodyPct = ($c['open'] > 0) ? abs($c['close'] - $c['open']) / $c['open'] * 100.0 : 0.0;
            if ($c['close'] <= $c['open'] || $bodyPct < $minBodyPct) {
                continue;
            }

            $base = $volAvg($i);
            $vr = ($base > 0) ? ($c['volume'] / $base) : 0.0;
            if ($vr < $minVolRatio) {
                continue;
            }

            $entry = $c['close'];
            $notional1m = $entry * (float) $c['volume'];
            if ($notional1m < $minNotional1m) {
                self::$dbg['fail_notional_1m']++;

                continue;
            }

            $aboveVwapPct = ($c['vwap'] > 0) ? (($entry - $c['vwap']) / $c['vwap']) * 100.0 : 999;
            if ($aboveVwapPct > $maxAboveVwapPct) {
                self::$dbg['reject_extended']++;

                continue;
            }

            if (! $this->hasRoomToRun($entry, $c['hod'], $atr, $minRoomPct, $roomAtrMult)) {
                self::$dbg['reject_no_room']++;

                continue;
            }

            $stop = min($c['low'], $c['vwap'] * 0.997);

            $barObj = (object) $c;
            $sc = $this->computeEntryScoreComponents($barObj);
            $score = $sc['score'];

            $entryClosePosition = ($c['high'] > $c['low']) ? (($c['close'] - $c['low']) / ($c['high'] - $c['low'])) : null;
            $roomToHodPct = ($entry > 0) ? (($c['hod'] - $entry) / $entry) * 100.0 : null;
            $roomToHodAtr = ($atr > 0 && $roomToHodPct !== null) ? (($c['hod'] - $entry) / $atr) : null;
            $vwapReclaimStrengthPct = ($c['vwap'] > 0) ? (($c['close'] - $c['vwap']) / $c['vwap']) * 100.0 : null;
            $vwapWickBelowPct = ($c['vwap'] > 0) ? (($c['vwap'] - $c['low']) / $c['vwap']) * 100.0 : null;

            if ($maxEntryAgeMinutes > 0 && ($asOfEpoch - strtotime($c['ts'])) > ($maxEntryAgeMinutes * 60)) {
                continue;
            }

            $candidates[] = $this->packEntry(
                'VWAP_RECLAIM_STRONG',
                $c['ts'],
                $entry,
                $stop,
                $score,
                $atr,
                $atrPct,
                [
                    'vol_ratio' => round($vr, 3),
                    'above_vwap_pct' => round($aboveVwapPct, 3),
                    'above_vwap_entry_pct' => round($aboveVwapPct, 4),
                    'hod' => $c['hod'],
                    'room_to_hod_pct' => $roomToHodPct !== null ? round($roomToHodPct, 4) : null,
                    'room_to_hod_atr' => $roomToHodAtr !== null ? round($roomToHodAtr, 4) : null,
                    'entry_body_pct' => round($bodyPct, 4),
                    'entry_close_position' => $entryClosePosition !== null ? round($entryClosePosition, 6) : null,
                    'entry_volume_ratio' => round($vr, 4),
                    'entry_notional_1m' => round($notional1m, 2),
                    'entry_spread_strength' => $sc['spread_strength'],
                    'entry_vwap_dist_score' => $sc['vwap_dist_score'],
                    'entry_atr_score' => $sc['atr_score'],
                    'entry_vol_score' => $sc['vol_score'],
                    'entry_candle_score' => $sc['candle_score'],
                    'entry_time_bonus' => $sc['time_bonus'],
                    'vwap_reclaim_strength_pct' => $vwapReclaimStrengthPct !== null ? round($vwapReclaimStrengthPct, 4) : null,
                    'vwap_reclaim_wick_below_pct' => $vwapWickBelowPct !== null ? round($vwapWickBelowPct, 4) : null,
                    'five_min_directional_changes' => $choppiness['directional_changes'] ?? null,
                    'five_min_green_bar_pct' => isset($choppiness['green_bar_pct']) ? round($choppiness['green_bar_pct'], 1) : null,
                    'five_min_net_progress' => isset($choppiness['net_progress']) ? round($choppiness['net_progress'], 3) : null,
                ]
            );
            break;
        }

        // ---------- Candidate 2: ORB retest (break + hold) ----------
        for ($i = max(6, $idxStart); $i < count($norm); $i++) {
            $c = $norm[$i];
            $orHigh = $c['or_high'];
            if (! $orHigh) {
                continue;
            }

            $broken = false;
            $breakIdx = null;
            for ($j = max(5, $i - 12); $j < $i; $j++) {
                if ($norm[$j]['close'] > $orHigh * 1.001) {
                    $broken = true;
                    $breakIdx = $j;
                    break;
                }
            }
            if (! $broken) {
                continue;
            }

            $touch = abs($c['low'] - $orHigh) / $orHigh <= 0.004;
            $hold = $c['close'] > $orHigh * 1.000;
            if (! $touch || ! $hold) {
                continue;
            }

            $bodyPct = ($c['open'] > 0) ? abs($c['close'] - $c['open']) / $c['open'] * 100.0 : 0.0;
            if ($c['close'] <= $c['open'] || $bodyPct < max($minBodyPct, 0.10)) {
                continue;
            }

            $base = $volAvg($i);
            $vr = ($base > 0) ? ($c['volume'] / $base) : 0.0;
            if ($vr < max(1.4, $minVolRatio)) {
                continue;
            }

            $entry = $c['close'];
            $notional1m = $entry * (float) $c['volume'];
            if ($notional1m < $minNotional1m) {
                self::$dbg['fail_notional_1m']++;

                continue;
            }

            $aboveVwapPct = ($c['vwap'] > 0) ? (($entry - $c['vwap']) / $c['vwap']) * 100.0 : 999;
            if ($aboveVwapPct > max(1.2, $maxAboveVwapPct)) {
                self::$dbg['reject_extended']++;

                continue;
            }

            if (! $this->hasRoomToRun($entry, $c['hod'], $atr, $minRoomPct, $roomAtrMult)) {
                self::$dbg['reject_no_room']++;

                continue;
            }

            $stop = min($c['low'], $orHigh * 0.996);

            $barObj = (object) $c;
            $sc = $this->computeEntryScoreComponents($barObj);
            $score = $sc['score'];

            $entryClosePosition = ($c['high'] > $c['low']) ? (($c['close'] - $c['low']) / ($c['high'] - $c['low'])) : null;
            $roomToHodPct = ($entry > 0) ? (($c['hod'] - $entry) / $entry) * 100.0 : null;
            $roomToHodAtr = ($atr > 0 && $roomToHodPct !== null) ? (($c['hod'] - $entry) / $atr) : null;
            $orBreakDistancePct = $orHigh > 0 ? (($entry - $orHigh) / $orHigh) * 100.0 : null;
            $orRetestDepthPct = $orHigh > 0 ? (($c['low'] - $orHigh) / $orHigh) * 100.0 : null;
            $orHoldClosePct = $orHigh > 0 ? (($c['close'] - $orHigh) / $orHigh) * 100.0 : null;
            $barsSinceOrBreak = ($breakIdx !== null) ? ($i - $breakIdx) : null;

            if ($maxEntryAgeMinutes > 0 && ($asOfEpoch - strtotime($c['ts'])) > ($maxEntryAgeMinutes * 60)) {
                continue;
            }

            $candidates[] = $this->packEntry(
                'ORB_RETEST',
                $c['ts'],
                $entry,
                $stop,
                $score,
                $atr,
                $atrPct,
                [
                    'vol_ratio' => round($vr, 3),
                    'above_vwap_pct' => round($aboveVwapPct, 3),
                    'above_vwap_entry_pct' => round($aboveVwapPct, 4),
                    'hod' => $c['hod'],
                    'room_to_hod_pct' => $roomToHodPct !== null ? round($roomToHodPct, 4) : null,
                    'room_to_hod_atr' => $roomToHodAtr !== null ? round($roomToHodAtr, 4) : null,
                    'entry_body_pct' => round($bodyPct, 4),
                    'entry_close_position' => $entryClosePosition !== null ? round($entryClosePosition, 6) : null,
                    'entry_volume_ratio' => round($vr, 4),
                    'entry_notional_1m' => round($notional1m, 2),
                    'entry_spread_strength' => $sc['spread_strength'],
                    'entry_vwap_dist_score' => $sc['vwap_dist_score'],
                    'entry_atr_score' => $sc['atr_score'],
                    'entry_vol_score' => $sc['vol_score'],
                    'entry_candle_score' => $sc['candle_score'],
                    'entry_time_bonus' => $sc['time_bonus'],
                    'or_high_v252' => round($orHigh, 8),
                    'or_break_distance_pct' => $orBreakDistancePct !== null ? round($orBreakDistancePct, 4) : null,
                    'or_retest_depth_pct' => $orRetestDepthPct !== null ? round($orRetestDepthPct, 4) : null,
                    'or_hold_close_pct' => $orHoldClosePct !== null ? round($orHoldClosePct, 4) : null,
                    'bars_since_or_break' => $barsSinceOrBreak,
                ]
            );
            break;
        }

        // ---------- Candidate 3: ORB breakout (NEW - earlier entry, no pullback) ----------
        if (empty($candidates)) {
            for ($i = max(6, $idxStart); $i < count($norm); $i++) {
                $c = $norm[$i];
                $orHigh = $c['or_high'];
                if (! $orHigh) {
                    continue;
                }

                // Fresh break above OR high with volume
                $breakPct = ($c['close'] - $orHigh) / $orHigh * 100.0;
                if ($breakPct < 0.15 || $breakPct > 2.0) {
                    continue;
                }

                // Confirm: green candle, body, volume
                $bodyPct = ($c['open'] > 0) ? abs($c['close'] - $c['open']) / $c['open'] * 100.0 : 0.0;
                if ($c['close'] <= $c['open'] || $bodyPct < $minBodyPct) {
                    continue;
                }

                $base = $volAvg($i);
                $vr = ($base > 0) ? ($c['volume'] / $base) : 0.0;
                if ($vr < $minVolRatio) {
                    continue;
                }

                $entry = $c['close'];
                $notional1m = $entry * (float) $c['volume'];
                if ($notional1m < $minNotional1m) {
                    self::$dbg['fail_notional_1m']++;

                    continue;
                }

                $aboveVwapPct = ($c['vwap'] > 0) ? (($entry - $c['vwap']) / $c['vwap']) * 100.0 : 999;
                if ($aboveVwapPct > $maxAboveVwapPct) {
                    self::$dbg['reject_extended']++;

                    continue;
                }

                if (! $this->hasRoomToRun($entry, $c['hod'], $atr, $minRoomPct, $roomAtrMult)) {
                    self::$dbg['reject_no_room']++;

                    continue;
                }

                // Check that we haven't had a retest already (avoid duplicates with ORB_RETEST)
                $hadRetest = false;
                for ($j = max(5, $i - 10); $j < $i; $j++) {
                    if ($norm[$j]['close'] > $orHigh && $norm[$j]['low'] <= $orHigh * 1.002) {
                        $hadRetest = true;
                        break;
                    }
                }
                if ($hadRetest) {
                    continue;
                }

                $stop = min($c['low'], $orHigh * 0.995);

                $barObj = (object) $c;
                $sc = $this->computeEntryScoreComponents($barObj);
                $score = $sc['score'];

                $entryClosePosition = ($c['high'] > $c['low']) ? (($c['close'] - $c['low']) / ($c['high'] - $c['low'])) : null;
                $roomToHodPct = ($entry > 0) ? (($c['hod'] - $entry) / $entry) * 100.0 : null;
                $roomToHodAtr = ($atr > 0 && $roomToHodPct !== null) ? (($c['hod'] - $entry) / $atr) : null;
                $orBreakDistancePct = $orHigh > 0 ? (($entry - $orHigh) / $orHigh) * 100.0 : null;

                if ($maxEntryAgeMinutes > 0 && ($asOfEpoch - strtotime($c['ts'])) > ($maxEntryAgeMinutes * 60)) {
                    continue;
                }

                $candidates[] = $this->packEntry(
                    'ORB_BREAKOUT',
                    $c['ts'],
                    $entry,
                    $stop,
                    $score,
                    $atr,
                    $atrPct,
                    [
                        'vol_ratio' => round($vr, 3),
                        'above_vwap_pct' => round($aboveVwapPct, 3),
                        'above_vwap_entry_pct' => round($aboveVwapPct, 4),
                        'hod' => $c['hod'],
                        'room_to_hod_pct' => $roomToHodPct !== null ? round($roomToHodPct, 4) : null,
                        'room_to_hod_atr' => $roomToHodAtr !== null ? round($roomToHodAtr, 4) : null,
                        'entry_body_pct' => round($bodyPct, 4),
                        'entry_close_position' => $entryClosePosition !== null ? round($entryClosePosition, 6) : null,
                        'entry_volume_ratio' => round($vr, 4),
                        'entry_notional_1m' => round($notional1m, 2),
                        'entry_spread_strength' => $sc['spread_strength'],
                        'entry_vwap_dist_score' => $sc['vwap_dist_score'],
                        'entry_atr_score' => $sc['atr_score'],
                        'entry_vol_score' => $sc['vol_score'],
                        'entry_candle_score' => $sc['candle_score'],
                        'entry_time_bonus' => $sc['time_bonus'],
                        'or_high_v252' => round($orHigh, 8),
                        'or_break_distance_pct' => $orBreakDistancePct !== null ? round($orBreakDistancePct, 4) : null,
                    ]
                );
                break;
            }
        }

        // ---------- Candidate 4: EMA9 pullback reclaim in trend ----------
        if (empty($candidates)) {
            for ($i = max(10, $idxStart); $i < count($norm); $i++) {
                $p = $norm[$i - 1];
                $c = $norm[$i];

                if (! ($c['ema_f'] > $c['ema_s'])) {
                    continue;
                }
                if (! ($c['close'] > $c['vwap'])) {
                    continue;
                }

                $near = ($c['ema_f'] > 0) ? (abs($p['low'] - $p['ema_f']) / $p['ema_f'] <= 0.004) : false;
                $reclaim = $c['close'] > $c['ema_f'];
                if (! $near || ! $reclaim) {
                    continue;
                }

                $bodyPct = ($c['open'] > 0) ? abs($c['close'] - $c['open']) / $c['open'] * 100.0 : 0.0;
                if ($c['close'] <= $c['open'] || $bodyPct < $minBodyPct) {
                    continue;
                }

                $base = $volAvg($i);
                $vr = ($base > 0) ? ($c['volume'] / $base) : 0.0;
                if ($vr < $minVolRatio) {
                    continue;
                }

                $entry = $c['close'];
                $notional1m = $entry * (float) $c['volume'];
                if ($notional1m < $minNotional1m) {
                    self::$dbg['fail_notional_1m']++;

                    continue;
                }

                $aboveVwapPct = ($c['vwap'] > 0) ? (($entry - $c['vwap']) / $c['vwap']) * 100.0 : 999;
                if ($aboveVwapPct > $maxAboveVwapPct) {
                    self::$dbg['reject_extended']++;

                    continue;
                }

                if (! $this->hasRoomToRun($entry, $c['hod'], $atr, $minRoomPct, $roomAtrMult)) {
                    self::$dbg['reject_no_room']++;

                    continue;
                }

                $stop = min($c['low'], $c['ema_s'] * 0.996);

                $barObj = (object) $c;
                $sc = $this->computeEntryScoreComponents($barObj);
                $score = $sc['score'];

                $entryClosePosition = ($c['high'] > $c['low']) ? (($c['close'] - $c['low']) / ($c['high'] - $c['low'])) : null;
                $roomToHodPct = ($entry > 0) ? (($c['hod'] - $entry) / $entry) * 100.0 : null;
                $roomToHodAtr = ($atr > 0 && $roomToHodPct !== null) ? (($c['hod'] - $entry) / $atr) : null;
                $ema9PullbackDepthPct = ($p['ema_f'] > 0) ? (($p['low'] - $p['ema_f']) / $p['ema_f']) * 100.0 : null;
                $ema9ReclaimPct = ($c['ema_f'] > 0) ? (($c['close'] - $c['ema_f']) / $c['ema_f']) * 100.0 : null;

                if ($maxEntryAgeMinutes > 0 && ($asOfEpoch - strtotime($c['ts'])) > ($maxEntryAgeMinutes * 60)) {
                    continue;
                }

                $candidates[] = $this->packEntry(
                    'EMA9_PULLBACK',
                    $c['ts'],
                    $entry,
                    $stop,
                    $score,
                    $atr,
                    $atrPct,
                    [
                        'vol_ratio' => round($vr, 3),
                        'ema_f' => round($c['ema_f'], 6),
                        'ema_s' => round($c['ema_s'], 6),
                        'above_vwap_pct' => round($aboveVwapPct, 3),
                        'above_vwap_entry_pct' => round($aboveVwapPct, 4),
                        'hod' => $c['hod'],
                        'room_to_hod_pct' => $roomToHodPct !== null ? round($roomToHodPct, 4) : null,
                        'room_to_hod_atr' => $roomToHodAtr !== null ? round($roomToHodAtr, 4) : null,
                        'entry_body_pct' => round($bodyPct, 4),
                        'entry_close_position' => $entryClosePosition !== null ? round($entryClosePosition, 6) : null,
                        'entry_volume_ratio' => round($vr, 4),
                        'entry_notional_1m' => round($notional1m, 2),
                        'entry_spread_strength' => $sc['spread_strength'],
                        'entry_vwap_dist_score' => $sc['vwap_dist_score'],
                        'entry_atr_score' => $sc['atr_score'],
                        'entry_vol_score' => $sc['vol_score'],
                        'entry_candle_score' => $sc['candle_score'],
                        'entry_time_bonus' => $sc['time_bonus'],
                        'ema9_pullback_depth_pct' => $ema9PullbackDepthPct !== null ? round($ema9PullbackDepthPct, 4) : null,
                        'ema9_reclaim_pct' => $ema9ReclaimPct !== null ? round($ema9ReclaimPct, 4) : null,
                        'five_min_directional_changes' => $choppiness['directional_changes'] ?? null,
                        'five_min_green_bar_pct' => isset($choppiness['green_bar_pct']) ? round($choppiness['green_bar_pct'], 1) : null,
                        'five_min_net_progress' => isset($choppiness['net_progress']) ? round($choppiness['net_progress'], 3) : null,
                    ]
                );
                break;
            }
        }

        if (empty($candidates)) {
            return null;
        }

        // Filter by time
        $candidates = array_filter($candidates, function ($c) {
            $hour = isset($c['entry_ts_est']) ? (int) substr($c['entry_ts_est'], 11, 2) : 9;
            $minute = isset($c['entry_ts_est']) ? (int) substr($c['entry_ts_est'], 14, 2) : 30;
            $timeDecimal = $hour + ($minute / 60.0);

            return $timeDecimal < 15.5;
        });

        if (empty($candidates)) {
            return null;
        }

        usort($candidates, fn ($a, $b) => (($b['score'] ?? 0) <=> ($a['score'] ?? 0)));
        $best = $candidates[0];

        $entryAgeSeconds = $asOfEpoch - strtotime((string) $best['entry_ts_est']);
        if ($entryAgeSeconds < 0 || ($maxEntryAgeMinutes > 0 && $entryAgeSeconds > ($maxEntryAgeMinutes * 60))) {
            return null;
        }

        $maxSignalAgeMinutes = (int) config('trading.v27.scanner.active_window_minutes', 8);
        $signalAgeSeconds = $asOfEpoch - strtotime($signalTsEst);
        if ($signalAgeSeconds < 0 || ($maxSignalAgeMinutes > 0 && $signalAgeSeconds > ($maxSignalAgeMinutes * 60))) {
            return null;
        }

        $best['entry_age_seconds'] = $entryAgeSeconds;
        $best['signal_age_seconds'] = $signalAgeSeconds;

        // Enforce the configured stop range (wider for more entries)
        $minStopPct = \App\Services\TradingSettingService::getStopLossAtrMinPct();
        $maxStopPct = \App\Services\TradingSettingService::getStopLossAtrMaxPct();
        $minStop = (float) $best['entry'] * (1 - ($maxStopPct / 100));
        $maxStop = (float) $best['entry'] * (1 - ($minStopPct / 100));

        $currentStop = (float) $best['stop'];
        if ($currentStop < $minStop) {
            $best['stop'] = $minStop;
        } elseif ($currentStop > $maxStop) {
            $best['stop'] = $maxStop;
        }

        $risk = max(1e-9, (float) $best['entry'] - (float) $best['stop']);
        $riskPct = ((float) $best['entry'] > 0) ? ($risk / (float) $best['entry']) * 100.0 : 0.0;
        $best['risk_per_share'] = round($risk, 6);
        $best['risk_pct'] = round($riskPct, 3);
        $best['targets'] = [
            '1R' => round((float) $best['entry'] + 1.0 * $risk, 6),
            '2R' => round((float) $best['entry'] + 2.0 * $risk, 6),
            '3R' => round((float) $best['entry'] + 3.0 * $risk, 6),
        ];

        $atrMultiplier = \App\Services\TradingSettingService::getStopLossAtrMultiplier();
        $calculatedPct = ((float) $best['entry'] > 0)
            ? (($atr * $atrMultiplier) / (float) $best['entry']) * 100.0
            : $minStopPct;
        $trailPct = max($minStopPct, min($maxStopPct, $calculatedPct));
        $trail = (float) $best['entry'] * ($trailPct / 100.0);
        $best['atr'] = round($atr, 6);
        $best['atr_pct'] = round($atrPct, 3);
        $best['suggested_trailing_stop'] = round($trail, 6);
        $best['suggested_trailing_stop_pct'] = round($trailPct, 3);

        return $best;
    }

    private function packEntry(
        string $type,
        string $tsEst,
        float $entry,
        float $stop,
        float $score,
        float $atr,
        float $atrPct,
        array $meta = []
    ): array {
        return array_merge([
            'type' => $type,
            'entry_ts_est' => $tsEst,
            'entry' => round($entry, 6),
            'stop' => round($stop, 6),
            'score' => round($score, 3),
        ], $meta);
    }

    private function lastClose(array $norm): float
    {
        $last = $norm[count($norm) - 1] ?? null;

        return $last ? (float) $last['close'] : 0.0;
    }

    private function atr(array $norm, int $period = 14): float
    {
        if (count($norm) < $period + 2) {
            return 0.0;
        }

        $trs = [];
        for ($i = 1; $i < count($norm); $i++) {
            $prevClose = (float) $norm[$i - 1]['close'];
            $high = (float) $norm[$i]['high'];
            $low = (float) $norm[$i]['low'];

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

    private function hasRoomToRun(float $entry, float $hod, float $atr, float $minRoomPct, float $roomAtrMult): bool
    {
        if ($entry <= 0) {
            return false;
        }
        $roomPct = (($hod - $entry) / $entry) * 100.0;
        if ($roomPct >= $minRoomPct) {
            return true;
        }

        return $atr > 0 && ($hod - $entry) >= ($atr * $roomAtrMult);
    }

    private function isAllowedTime(string $tsEst): bool
    {
        $hh = (int) substr($tsEst, 11, 2);
        $mm = (int) substr($tsEst, 14, 2);
        $t = $hh + $mm / 60.0;

        $a = ($t >= 9.58 && $t <= 11.25);
        $b = ($t >= 14.00 && $t <= 15.92);

        return $a || $b;
    }

    private function timeMultiplier(string $tsEst): float
    {
        $hh = (int) substr($tsEst, 11, 2);
        $mm = (int) substr($tsEst, 14, 2);
        $t = $hh + $mm / 60.0;

        if ($t >= 9.50 && $t < 10.50) {
            return 1.15;
        }
        if ($t >= 15.00 && $t < 16.00) {
            return 1.10;
        }
        if ($t >= 11.00 && $t < 14.00) {
            return 0.85;
        }

        return 1.0;
    }

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
            'green_bar_pct' => count($fiveMinBars) > 0 ? ($greenBars / count($fiveMinBars)) * 100.0 : 0.0,
            'net_progress' => round($netProgress, 3),
        ];
    }

    private function computeEntryScoreComponents(object $b): array
    {
        $price = (float) ($b->close ?? $b->price ?? 0);
        if ($price <= 0) {
            return ['score' => 0.0, 'spread_strength' => 0.0, 'vwap_dist_score' => 0.0,
                'atr_score' => 0.0, 'vol_score' => 0.0, 'candle_score' => 0.0, 'time_bonus' => 0.0, ];
        }

        // EMA9 / EMA21 spread (trend quality)
        $emaF = (float) ($b->ema_f ?? 0);
        $emaS = (float) ($b->ema_s ?? 0);
        $spreadRaw = ($emaS > 0) ? ($emaF - $emaS) / $emaS : 0;
        $spreadStrength = max(0, min(1, ($spreadRaw - 0.0005) / (0.0030 - 0.0005)));

        // VWAP distance (sweet spot 0.05%–0.30% above)
        $vwapPct = (float) ($b->vwap ?? 0);
        $vwapDist = ($vwapPct > 0) ? ($price - $vwapPct) / $vwapPct * 100.0 : 0;
        $vwapDistRaw = abs($vwapDist) / 100.0;
        $vwapDistScore = max(0, 1 - ($vwapDistRaw / 0.0030));

        // ATR% at entry (sweet spot 0.08%–0.50%)
        $atrPct = (float) ($b->atr_pct ?? 0);
        $atrScore = max(0, min(1, ($atrPct - 0.08) / (0.20 - 0.08))) * (1 - max(0, min(1, ($atrPct - 0.50) / (1.50 - 0.50))));

        // Volume ratio
        $volRatio = (float) ($b->volume_ratio ?? 0);
        $volScore = max(0, min(1, ($volRatio - 0.8) / (2.5 - 0.8)));

        // Candle body quality
        $open = (float) ($b->open ?? 0);
        $close = (float) ($b->close ?? 0);
        $high = (float) ($b->high ?? 0);
        $low = (float) ($b->low ?? 0);
        $bodyPct = ($open > 0) ? abs($close - $open) / $open * 100.0 : 0;
        $candleScore = max(0, min(1, ($bodyPct - 0.05) / (0.30 - 0.05)));

        // Upper wick penalty
        $totalRange = ($high - $low);
        if ($totalRange > 0) {
            $upperWick = $close > $open ? $high - $close : $high - $open;
            $upperWickPct = $upperWick / $totalRange;
            if ($upperWickPct > 0.50) {
                $candleScore *= 0.6;
            }
        }

        // Time bonus (prefer early entries)
        $timeBonus = 0;
        $hour = (int) substr((string) ($b->ts ?? ''), 11, 2);
        if ($hour >= 9 && $hour < 10) {
            $timeBonus = 10;
        } elseif ($hour >= 10 && $hour < 11) {
            $timeBonus = 5;
        }

        $rawScore = 100 * (
            (0.30 * (0.70 * ($b->ema9_above_ema21 ?? 0) + 0.30 * $spreadStrength))
            + (0.25 * ((int) ($b->above_vwap ?? 0) * $vwapDistScore))
            + (0.10 * $atrScore)
            + (0.20 * $volScore)
            + (0.15 * $candleScore)
        ) + $timeBonus;

        $finalScore = max(0, min(100, $rawScore));

        return [
            'score' => $finalScore,
            'spread_strength' => round($spreadStrength, 6),
            'vwap_dist_score' => round($vwapDistScore, 6),
            'atr_score' => round($atrScore, 6),
            'vol_score' => round($volScore, 6),
            'candle_score' => round($candleScore, 6),
            'time_bonus' => $timeBonus,
        ];
    }
}
