<?php

namespace App\Services\Trading;

use Illuminate\Support\Facades\Log;

/**
 * Version 25.2 - Entry Finder (Quality-first)
 *
 * Entry types (long):
 * - VWAP_RECLAIM_STRONG: cross + body + volume, not extended, room to run
 * - ORB_RETEST: break OR high then retest/hold with volume
 * - EMA9_PULLBACK: trend + pullback to EMA9 + reclaim with volume
 *
 * Key quality filters:
 * - NOT EXTENDED vs VWAP (avoid chasing)
 * - ROOM TO RUN (HOD distance OR ATR-multiple runway)
 * - STRUCTURE stop (not tiny ATR trail immediately)
 *  .env settings for v25.0:
 *   scanner gates
 *   TRADING_V25_MIN_NOTIONAL_5M=300000
 *   TRADING_V25_MIN_ATR_PCT_5M=0.80
 *   TRADING_V25_MIN_RVOL_5M=2.0
 *   TRADING_V25_MIN_MOVE_30M_PCT=1.2
 *   TRADING_V25_MIN_RS_MULT_VS_SPY=1.10
 *
 *   entry gates
 *   TRADING_V25_MIN_NOTIONAL_1M=80000
 *   TRADING_V25_MIN_VOL_RATIO_1M=2.0
 *   TRADING_V25_MAX_ABOVE_VWAP_ENTRY_PCT=0.80
 *   TRADING_V25_MIN_ROOM_TO_RUN_PCT=1.0
 *   TRADING_V25_ROOM_ATR_MULT=2.0
 *   TRADING_V25_ALLOW_LUNCH=0
 *   ATR Multiplier: Uses AUTO_ALPACA_STOP_LOSS_ATR_MULTIPLIER (2.0)
 */
class OneMinuteEntryFinderV35_0
{
    use HasPriceTables;

    private string $version = 'v35.0';

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

    /**
     * REQUIRED by Pipeline:
     * Must return ['ok'=>1, 'best_entry'=> [...]].
     */
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
        return ((string) env('ENTRYFINDER_V25_DEBUG', '0') === '1')
            || (bool) config('trading.v25.debug', false);
    }

    private function maybeLogDebug(): void
    {
        if (! $this->isDebugEnabled()) {
            return;
        }
        if (self::$dbg['called'] > 0 && self::$dbg['called'] % 20000 === 0) {
            Log::info('[EntryFinderV25] debug counters', self::$dbg);
        }
    }

    private function findEntry(string $symbol, string $assetType, string $signalTsEst, string $asOfTsEst): ?array
    {
        $cfg = (array) config('trading.v25.entry', []);

        // --- Time window (avoid lunch chop) ---
        $allowLunch = (bool) ($cfg['allow_lunch'] ?? false);
        if (! $allowLunch && ! $this->isAllowedTime($asOfTsEst)) {
            self::$dbg['time_blocked']++;

            return null;
        }

        // --- Risk/quality gates ---
        // min_bars: IEX free tier starts 2-4 min late, so 60 would block until 10:30+. Use 15 as floor.
        $minBars = (int) ($cfg['min_bars'] ?? 15);
        $minNotional1m = (float) ($cfg['min_notional_1m'] ?? 50000.0);  // last 1m bar notional floor (lowered from 80k)
        $maxAboveVwapPct = (float) ($cfg['max_above_vwap_entry_pct'] ?? 1.2); // don't chase >1.2% above VWAP (loosened from 0.8%)
        $minRoomPct = (float) ($cfg['min_room_to_run_pct'] ?? 0.6);      // must have >=0.6% room to HOD or runway (lowered from 1.0%)
        $roomAtrMult = (float) ($cfg['room_atr_mult'] ?? 1.5);           // OR (HOD-entry) >= ATR*mult (lowered from 2.0)
        $minVolRatio = (float) ($cfg['min_vol_ratio_1m'] ?? 1.0);        // vs avg last 20m (IEX free: panic bars inflate avg; 1.0 = at least avg)
        $volLookback = (int) ($cfg['vol_lookback_1m'] ?? 20);
        // min_body_pct_1m: reversal cross bars are often small — 0.05% allows 1-cent body on a $20 stock
        $minBodyPct = (float) ($cfg['min_body_pct_1m'] ?? 0.05);
        // max_entry_age_minutes: reject entries older than this vs asOf (avoids acting on 9:40 crosses at 10:30)
        $maxEntryAgeMinutes = (int) ($cfg['max_entry_age_minutes'] ?? 12);

        $emaFast = (int) ($cfg['ema_fast'] ?? 9);
        $emaSlow = (int) ($cfg['ema_slow'] ?? 21);
        $atrPeriod = (int) ($cfg['atr_period_1m'] ?? 14);

        // analysis_lookback_minutes=90 so idxStart always reaches back to market open,
        // capturing VWAP crosses that happened early in the session.
        $beforeMinutes = (int) ($cfg['analysis_lookback_minutes'] ?? 90);

        // Market open derived from signal date
        $tradeDate = substr($signalTsEst, 0, 10);
        $marketOpen = $tradeDate.' 09:30:00';

        $analysisEnd = $asOfTsEst;
        $analysisStart = date('Y-m-d H:i:s', strtotime($asOfTsEst." -{$beforeMinutes} minutes"));
        if ($analysisStart < $marketOpen) {
            $analysisStart = $marketOpen;
        }

        // Load from market open to now (needed for VWAP + HOD)
        $bars = $this->dbSelect('
            SELECT ts_est, `open`, high, low, price AS close, volume
            FROM one_minute_prices
            WHERE asset_type = ?
              AND symbol = ?
              AND trading_date_est = ?
              AND ts_est >= ?
              AND ts_est <= ?
            ORDER BY ts_est ASC
        ', [$assetType, $symbol, $tradeDate, $marketOpen, $analysisEnd]);

        if (! $bars || count($bars) < $minBars) {
            self::$dbg['not_enough_bars']++;

            return null;
        }

        // Validate data quality: reject if extreme price drops (reverse splits, bad data)
        // Check for >50% drop bar-to-bar (indicates corporate action or data error)
        for ($i = 1; $i < count($bars); $i++) {
            $prevClose = (float) $bars[$i - 1]->close;
            $currentOpen = (float) $bars[$i]->open;

            if ($prevClose > 0) {
                $dropPct = (($currentOpen - $prevClose) / $prevClose) * 100.0;
                if ($dropPct < -50.0) {
                    self::$dbg['bad_data_extreme_drop'] = (self::$dbg['bad_data_extreme_drop'] ?? 0) + 1;

                    return null; // Skip symbol - likely reverse split or bad data
                }
            }
        }

        // Get 5-minute bars for choppiness detection (use full data window from market open)
        $fiveMinBars = $this->dbSelect('
            SELECT ts_est, open, high, low, price
            FROM five_minute_prices
            WHERE asset_type = ?
              AND symbol = ?
              AND trading_date_est = ?
              AND ts_est >= ?
              AND ts_est <= ?
            ORDER BY ts_est ASC
        ', [$assetType, $symbol, $tradeDate, $marketOpen, $analysisEnd]);

        // Calculate choppiness (log only, no filtering for v25.0)
        $choppiness = [];
        if (count($fiveMinBars) >= 6) {
            $recent5MinBars = array_slice($fiveMinBars, -12);
            $choppiness = $this->calculate5MinChoppiness($recent5MinBars);
        }

        // Build normalized series + VWAP + EMA + HOD + OR high(5m)
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
            $ts = (string) $r->ts_est;
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

            // Opening Range high: first 5 minutes
            if ($orCount < 5) {
                $orCount++;
                $orHigh = ($orHigh === null) ? $h : max($orHigh, $h);
            }

            $norm[] = [
                'ts' => $ts,
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

        // Focus only on recent window (entries must be fresh)
        $recent = array_values(array_filter($norm, fn ($b) => $b['ts'] >= $analysisStart));
        if (count($recent) < 10) {
            self::$dbg['not_enough_bars']++;

            return null;
        }

        $atr = $this->atr($norm, $atrPeriod);
        $atrPct = ($this->lastClose($norm) > 0) ? ($atr / $this->lastClose($norm)) * 100.0 : 0.0;

        // Average volume baseline (recent)
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

        // Find index of first bar >= analysisStart in full norm
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

            // strong candle body
            $bodyPct = ($c['open'] > 0) ? abs($c['close'] - $c['open']) / $c['open'] * 100.0 : 0.0;
            if ($c['close'] <= $c['open'] || $bodyPct < $minBodyPct) {
                continue;
            }

            // volume confirmation
            $base = $volAvg($i);
            $vr = ($base > 0) ? ($c['volume'] / $base) : 0.0;
            if ($vr < $minVolRatio) {
                continue;
            }

            $entry = $c['close']; // close fill by default
            $notional1m = $entry * (float) $c['volume'];
            if ($notional1m < $minNotional1m) {
                self::$dbg['fail_notional_1m']++;

                continue;
            }

            // not extended vs VWAP
            $aboveVwapPct = ($c['vwap'] > 0) ? (($entry - $c['vwap']) / $c['vwap']) * 100.0 : 999;
            if ($aboveVwapPct > $maxAboveVwapPct) {
                self::$dbg['reject_extended']++;

                continue;
            }

            // room to run
            if (! $this->hasRoomToRun($entry, $c['hod'], $atr, $minRoomPct, $roomAtrMult)) {
                self::$dbg['reject_no_room']++;

                continue;
            }

            $stop = min($c['low'], $c['vwap'] * 0.997); // structure-ish: below reclaim low or VWAP buffer

            // Use standardized 0-100 entry score with sub-components
            $barObj = (object) $c;
            $sc = $this->computeEntryScoreComponents($barObj);
            $score = $sc['score'];

            // Entry quality features
            $entryClosePosition = ($c['high'] > $c['low']) ? (($c['close'] - $c['low']) / ($c['high'] - $c['low'])) : null;
            $roomToHodPct = ($entry > 0) ? (($c['hod'] - $entry) / $entry) * 100.0 : null;
            $roomToHodAtr = ($atr > 0 && $roomToHodPct !== null) ? (($c['hod'] - $entry) / $atr) : null;
            $vwapReclaimStrengthPct = ($c['vwap'] > 0) ? (($c['close'] - $c['vwap']) / $c['vwap']) * 100.0 : null;
            $vwapWickBelowPct = ($c['vwap'] > 0) ? (($c['vwap'] - $c['low']) / $c['vwap']) * 100.0 : null;

            // Only accept entries from within the last $maxEntryAgeMinutes; continue scanning for a fresher cross if stale.
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
                    'or_high' => $c['or_high'],
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

            // must have broken OR high earlier in the window
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

            // retest/hold: current low near OR high, close back above
            $touch = abs($c['low'] - $orHigh) / $orHigh <= 0.004; // within 0.4%
            $hold = $c['close'] > $orHigh * 1.000; // closes above
            if (! $touch || ! $hold) {
                continue;
            }

            // confirm green and volume
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

            // not extended vs VWAP (ORB can be a bit above VWAP; still cap)
            $aboveVwapPct = ($c['vwap'] > 0) ? (($entry - $c['vwap']) / $c['vwap']) * 100.0 : 999;
            if ($aboveVwapPct > max(1.2, $maxAboveVwapPct)) {
                self::$dbg['reject_extended']++;

                continue;
            }

            if (! $this->hasRoomToRun($entry, $c['hod'], $atr, $minRoomPct, $roomAtrMult)) {
                self::$dbg['reject_no_room']++;

                continue;
            }

            $stop = min($c['low'], $orHigh * 0.996); // below retest low / OR buffer

            // Use standardized 0-100 entry score with sub-components
            $barObj = (object) $c;
            $sc = $this->computeEntryScoreComponents($barObj);
            $score = $sc['score'];

            // ORB-specific quality features
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
                    'or_high' => round($orHigh, 6),
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

        // ---------- Candidate 3: EMA9 pullback reclaim in trend ----------
        for ($i = max(10, $idxStart); $i < count($norm); $i++) {
            $p = $norm[$i - 1];
            $c = $norm[$i];

            // trend filter: EMA fast > EMA slow, price above VWAP
            if (! ($c['ema_f'] > $c['ema_s'])) {
                continue;
            }
            if (! ($c['close'] > $c['vwap'])) {
                continue;
            }

            // pullback: prev low near ema_f then reclaim close above ema_f
            $near = ($c['ema_f'] > 0) ? (abs($p['low'] - $p['ema_f']) / $p['ema_f'] <= 0.004) : false;
            $reclaim = $c['close'] > $c['ema_f'];
            if (! $near || ! $reclaim) {
                continue;
            }

            // green + body
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

            // not extended vs VWAP
            $aboveVwapPct = ($c['vwap'] > 0) ? (($entry - $c['vwap']) / $c['vwap']) * 100.0 : 999;
            if ($aboveVwapPct > $maxAboveVwapPct) {
                self::$dbg['reject_extended']++;

                continue;
            }

            if (! $this->hasRoomToRun($entry, $c['hod'], $atr, $minRoomPct, $roomAtrMult)) {
                self::$dbg['reject_no_room']++;

                continue;
            }

            $stop = min($c['low'], $c['ema_s'] * 0.996); // below pullback low / slow ema buffer

            // Use standardized 0-100 entry score with sub-components
            $barObj = (object) $c;
            $sc = $this->computeEntryScoreComponents($barObj);
            $score = $sc['score'];

            // EMA9 pullback-specific quality features
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

        if (empty($candidates)) {
            return null;
        }

        // v25.2: LOOSENED FILTER - Allow more entry types and scores
        // Only reject entries after 3:30pm (power hour cutoff)
        $candidates = array_filter($candidates, function ($c) {
            $hour = isset($c['entry_ts_est']) ? (int) substr($c['entry_ts_est'], 11, 2) : 9;
            $minute = isset($c['entry_ts_est']) ? (int) substr($c['entry_ts_est'], 14, 2) : 30;
            $timeDecimal = $hour + ($minute / 60.0);

            // No entries after 3:30pm
            if ($timeDecimal >= 15.5) {
                return false;
            }

            return true;
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

        $maxSignalAgeMinutes = (int) config('trading.v25.scanner.active_window_minutes', 6);
        $signalAgeSeconds = $asOfEpoch - strtotime($signalTsEst);
        if ($signalAgeSeconds < 0 || ($maxSignalAgeMinutes > 0 && $signalAgeSeconds > ($maxSignalAgeMinutes * 60))) {
            return null;
        }

        $best['entry_age_seconds'] = $entryAgeSeconds;
        $best['signal_age_seconds'] = $signalAgeSeconds;

        // Enforce the configured stop range
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

        // risk info + targets
        $risk = max(1e-9, (float) $best['entry'] - (float) $best['stop']);
        $riskPct = ((float) $best['entry'] > 0) ? ($risk / (float) $best['entry']) * 100.0 : 0.0;
        $best['risk_per_share'] = round($risk, 6);
        $best['risk_pct'] = round($riskPct, 3);
        $best['targets'] = [
            '1R' => round((float) $best['entry'] + 1.0 * $risk, 6),
            '2R' => round((float) $best['entry'] + 2.0 * $risk, 6),
            '3R' => round((float) $best['entry'] + 3.0 * $risk, 6),
        ];

        // Suggested trail (apply AFTER +1R in your executor/backtester)
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

        // ATR runway alternative (helps when HOD is recent / not far)
        if ($atr > 0 && ($hod - $entry) >= ($atr * $roomAtrMult)) {
            return true;
        }

        return false;
    }

    private function isAllowedTime(string $tsEst): bool
    {
        // Allow 09:35–11:15 and 14:00–15:55 (ET-ish ts_est)
        $hh = (int) substr($tsEst, 11, 2);
        $mm = (int) substr($tsEst, 14, 2);
        $t = $hh + $mm / 60.0;

        $a = ($t >= 9.58 && $t <= 11.25);  // 9:35 to 11:15
        $b = ($t >= 14.00 && $t <= 15.92); // 14:00 to 15:55

        return $a || $b;
    }

    private function timeMultiplier(string $tsEst): float
    {
        // Boost open + power hour, de-emphasize mid-day
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
            'green_bar_pct' => count($fiveMinBars) > 0 ? ($greenBars / count($fiveMinBars)) * 100.0 : 0.0,
            'net_progress' => round($netProgress, 3),
        ];
    }

    /**
     * Standardized entry score formula (0-100) with sub-component breakdown.
     * Returns all intermediate scores so they can be stored as first-class ML features.
     *
     * @return array ['score'=>float, 'spread_strength'=>float, 'vwap_dist_score'=>float,
     *               'atr_score'=>float, 'vol_score'=>float, 'candle_score'=>float, 'time_bonus'=>float]
     */
    private function computeEntryScoreComponents(object $b): array
    {
        $price = (float) ($b->close ?? $b->price ?? 0);
        if ($price <= 0) {
            return [
                'score' => 0.0,
                'spread_strength' => 0.0,
                'vwap_dist_score' => 0.0,
                'atr_score' => 0.0,
                'vol_score' => 0.0,
                'candle_score' => 0.0,
                'time_bonus' => 0.0,
            ];
        }

        // Handle array-style bars from v25.2
        if (is_array($b)) {
            $b = (object) $b;
        }

        $ema9 = (float) ($b->ema_f ?? $b->ema9 ?? 0);
        $ema21 = (float) ($b->ema_s ?? $b->ema21 ?? 0);
        $emaSpread = $ema9 - $ema21;
        $spreadFrac = $emaSpread / $price;
        $spread_strength = $this->clamp(($spreadFrac - 0.0005) / (0.0030 - 0.0005));

        $vwap = (float) ($b->vwap ?? 0);
        $vwap_dist_pct = $vwap > 0 ? (($price - $vwap) / $vwap) * 100 : 0;
        $vwap_dist_score = max(0.0, 1.0 - (abs($vwap_dist_pct - 0.15) / 0.30));

        $atr_pct = (float) ($b->atr_pct ?? 0);
        $atr_low_ok = $this->clamp(($atr_pct - 0.08) / (0.20 - 0.08));
        $atr_high_pen = $this->clamp(($atr_pct - 0.50) / (1.50 - 0.50));
        $atr_score = $atr_low_ok * (1.0 - $atr_high_pen);

        $volume = (float) ($b->volume ?? 0);
        $avg_vol = (float) ($b->avg_vol_20 ?? 1);
        $vol_ratio = $avg_vol > 0 ? $volume / $avg_vol : 0.0;
        $vol_score = $this->clamp(($vol_ratio - 0.8) / (2.5 - 0.8));

        $high = (float) ($b->high ?? 0);
        $low = (float) ($b->low ?? 0);
        $candle_score = 0.0;
        if ($high > $low) {
            $pos = ($price - $low) / ($high - $low);
            $candle_score = $this->clamp(($pos - 0.45) / (0.80 - 0.45));
        }

        $ema9_above_ema21 = $ema9 > $ema21 ? 1.0 : 0.0;
        $above_vwap = $price > $vwap ? 1.0 : 0.0;

        $ts = (string) ($b->ts_est ?? $b->ts ?? '');
        $time_bonus = 0.0;
        if ($ts) {
            $timeStr = strlen($ts) >= 19 ? substr($ts, 11, 8) : $ts;
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

        return [
            'score' => round($final, 2),
            'spread_strength' => round($spread_strength, 6),
            'vwap_dist_score' => round($vwap_dist_score, 6),
            'atr_score' => round($atr_score, 6),
            'vol_score' => round($vol_score, 6),
            'candle_score' => round($candle_score, 6),
            'time_bonus' => round($time_bonus, 6),
        ];
    }

    /**
     * Standardized entry score formula (0-100)
     * Same formula used across all pipelines for ML training consistency
     */
    private function computeEntryScore(object $b): float
    {
        return $this->computeEntryScoreComponents($b)['score'];
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
