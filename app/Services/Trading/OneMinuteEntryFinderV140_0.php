<?php

namespace App\Services\Trading;

use Illuminate\Support\Facades\Log;

/**
 * Version 140.0 - Institutional Entry Finder
 *
 * Entry Philosophy:
 * - Wait for pullbacks to support in strong institutional names
 * - Confirm with volume (institutions show size)
 * - Avoid extended prices (institutions accumulate on dips)
 * - Require room to run (institutions target meaningful gains)
 *
 * Entry Types (long):
 * 1. VWAP_HOLD_CONTINUATION: Pullback to VWAP that holds and bounces with volume
 * 2. HIGHER_LOW_BREAK: Forms higher low structure, breaks micro resistance
 * 3. INSTITUTIONAL_ACCUMULATION: Tight range consolidation above support with sustained volume
 *
 * Key Filters (Institutional Focus):
 * - NOT EXTENDED vs VWAP (max 1.0% above)
 * - ROOM TO RUN (minimum 0.8% to HOD or 1.8x ATR runway)
 * - VOLUME CONFIRMATION (1.3x+ sustained, not panic spikes)
 * - STRUCTURE STOPS (based on support levels, not tight ATR trails)
 * - TIME FILTERS (avoid lunch chop 11:30-2pm)
 * - MINIMUM BAR COUNT (20+ bars = enough data for structure)
 *
 * Compared to v25.2:
 * - Higher volume floor (1.3x vs 1.0x) - institutions show size
 * - Stricter above-VWAP limit (1.0% vs 1.2%) - accumulate on dips
 * - More room needed (0.8% vs 0.6%) - target bigger moves
 * - Higher ATR runway (1.8x vs 1.5x) - need expansion potential
 * - More bars required (20 vs 15) - better structure analysis
 */
class OneMinuteEntryFinderV140_0
{
    use HasPriceTables;

    private string $version = 'v140.0';

    private static array $dbg = [
        'called' => 0,
        'not_enough_bars' => 0,
        'bad_data_extreme_drop' => 0,
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
        return ((string) env('ENTRYFINDER_V140_DEBUG', '0') === '1')
            || (bool) config('trading.v140.debug', false);
    }

    private function maybeLogDebug(): void
    {
        if (! $this->isDebugEnabled()) {
            return;
        }
        if (self::$dbg['called'] > 0 && self::$dbg['called'] % 20000 === 0) {
            Log::info('[EntryFinderV140] debug counters', self::$dbg);
        }
    }

    private function findEntry(string $symbol, string $assetType, string $signalTsEst, string $asOfTsEst): ?array
    {
        $cfg = (array) config('trading.v140.entry', []);

        // --- Time window (stricter than v25.2 - avoid lunch) ---
        $allowLunch = (bool) ($cfg['allow_lunch'] ?? false);
        if (! $allowLunch && ! $this->isAllowedTime($asOfTsEst)) {
            self::$dbg['time_blocked']++;

            return null;
        }

        // --- Quality gates (institutional focus) ---
        $minBars = (int) ($cfg['min_bars'] ?? 20);
        $minNotional1m = (float) ($cfg['min_notional_1m'] ?? 90000.0);
        $maxAboveVwapPct = (float) ($cfg['max_above_vwap_entry_pct'] ?? 1.0);
        $minRoomPct = (float) ($cfg['min_room_to_run_pct'] ?? 0.8);
        $roomAtrMult = (float) ($cfg['room_atr_mult'] ?? 1.8);
        $minVolRatio = (float) ($cfg['min_vol_ratio_1m'] ?? 1.3);
        $volLookback = (int) ($cfg['vol_lookback_1m'] ?? 20);
        $minBodyPct = (float) ($cfg['min_body_pct_1m'] ?? 0.08);
        $maxEntryAgeMinutes = (int) ($cfg['max_entry_age_minutes'] ?? 12);

        $emaFast = (int) ($cfg['ema_fast'] ?? 9);
        $emaSlow = (int) ($cfg['ema_slow'] ?? 21);
        $atrPeriod = (int) ($cfg['atr_period_1m'] ?? 14);

        $beforeMinutes = (int) ($cfg['analysis_lookback_minutes'] ?? 90);

        // Market open
        $tradeDate = substr($signalTsEst, 0, 10);
        $marketOpen = $tradeDate.' 09:30:00';

        $analysisEnd = $asOfTsEst;
        $analysisStart = date('Y-m-d H:i:s', strtotime($asOfTsEst." -{$beforeMinutes} minutes"));
        if ($analysisStart < $marketOpen) {
            $analysisStart = $marketOpen;
        }

        // Load bars from market open to now
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

        // Data quality check: reject extreme drops (reverse splits, bad data)
        for ($i = 1; $i < count($bars); $i++) {
            $prevClose = (float) $bars[$i - 1]->close;
            $currentOpen = (float) $bars[$i]->open;

            if ($prevClose > 0) {
                $dropPct = (($currentOpen - $prevClose) / $prevClose) * 100.0;
                if ($dropPct < -50.0) {
                    self::$dbg['bad_data_extreme_drop']++;

                    return null;
                }
            }
        }

        // Build normalized series with VWAP, EMA, HOD
        $norm = [];
        $cumPV = 0.0;
        $cumV = 0.0;

        $emaF = null;
        $emaS = null;
        $kF = 2.0 / ($emaFast + 1);
        $kS = 2.0 / ($emaSlow + 1);

        $hod = 0.0;

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
            ];
        }

        // Focus on recent window
        $recent = array_values(array_filter($norm, fn ($b) => $b['ts'] >= $analysisStart));
        if (count($recent) < 10) {
            self::$dbg['not_enough_bars']++;

            return null;
        }

        $atr = $this->atr($norm, $atrPeriod);
        $atrPct = ($this->lastClose($norm) > 0) ? ($atr / $this->lastClose($norm)) * 100.0 : 0.0;

        // Volume baseline
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

        // ========== Entry Type 1: VWAP Hold Continuation ==========
        // Price pulls back to VWAP, holds, and bounces with sustained volume
        for ($i = max(10, $idxStart); $i < count($norm); $i++) {
            $p1 = $norm[$i - 2] ?? null;
            $p = $norm[$i - 1];
            $c = $norm[$i];

            if (! $p1) {
                continue;
            }

            // Pattern: was above VWAP → dips to/near VWAP → bounces back above
            $wasAbove = $p1['close'] > $p1['vwap'] * 1.002;
            $touchedVwap = $p['low'] <= $p['vwap'] * 1.003 && $p['close'] >= $p['vwap'] * 0.997;
            $bounceBack = $c['close'] > $c['vwap'] * 1.001;

            if (! ($wasAbove && $touchedVwap && $bounceBack)) {
                continue;
            }

            // Green candle with body
            $bodyPct = ($c['open'] > 0) ? abs($c['close'] - $c['open']) / $c['open'] * 100.0 : 0.0;
            if ($c['close'] <= $c['open'] || $bodyPct < $minBodyPct) {
                continue;
            }

            // Sustained volume (not spike)
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

            // Not extended
            $aboveVwapPct = ($c['vwap'] > 0) ? (($entry - $c['vwap']) / $c['vwap']) * 100.0 : 999;
            if ($aboveVwapPct > $maxAboveVwapPct) {
                self::$dbg['reject_extended']++;

                continue;
            }

            // Room to run
            if (! $this->hasRoomToRun($entry, $c['hod'], $atr, $minRoomPct, $roomAtrMult)) {
                self::$dbg['reject_no_room']++;

                continue;
            }

            $stop = min($p['low'], $c['vwap'] * 0.996); // Below dip low or VWAP support

            $barObj = (object) $c;
            $sc = $this->computeEntryScoreComponents($barObj);
            $score = $sc['score'];

            $entryClosePosition = ($c['high'] > $c['low']) ? (($c['close'] - $c['low']) / ($c['high'] - $c['low'])) : null;
            $roomToHodPct = ($entry > 0) ? (($c['hod'] - $entry) / $entry) * 100.0 : null;
            $roomToHodAtr = ($atr > 0 && $roomToHodPct !== null) ? (($c['hod'] - $entry) / $atr) : null;
            $vwapHoldDepthPct = ($p['vwap'] > 0) ? (($p['low'] - $p['vwap']) / $p['vwap']) * 100.0 : null;

            if ($maxEntryAgeMinutes > 0 && ($asOfEpoch - strtotime($c['ts'])) > ($maxEntryAgeMinutes * 60)) {
                continue;
            }

            $candidates[] = $this->packEntry(
                'VWAP_HOLD_CONTINUATION',
                $c['ts'],
                $entry,
                $stop,
                $score,
                $atr,
                $atrPct,
                [
                    'vol_ratio' => round($vr, 3),
                    'above_vwap_pct' => round($aboveVwapPct, 3),
                    'hod' => $c['hod'],
                    'room_to_hod_pct' => $roomToHodPct !== null ? round($roomToHodPct, 4) : null,
                    'room_to_hod_atr' => $roomToHodAtr !== null ? round($roomToHodAtr, 4) : null,
                    'entry_body_pct' => round($bodyPct, 4),
                    'entry_close_position' => $entryClosePosition !== null ? round($entryClosePosition, 6) : null,
                    'entry_volume_ratio' => round($vr, 4),
                    'entry_notional_1m' => round($notional1m, 2),
                    'vwap_hold_depth_pct' => $vwapHoldDepthPct !== null ? round($vwapHoldDepthPct, 4) : null,
                    'entry_spread_strength' => $sc['spread_strength'],
                    'entry_vwap_dist_score' => $sc['vwap_dist_score'],
                    'entry_atr_score' => $sc['atr_score'],
                    'entry_vol_score' => $sc['vol_score'],
                    'entry_candle_score' => $sc['candle_score'],
                    'entry_time_bonus' => $sc['time_bonus'],
                ]
            );
            break;
        }

        // ========== Entry Type 2: Higher Low Break ==========
        // Forms higher low structure, breaks micro resistance with volume
        for ($i = max(15, $idxStart); $i < count($norm); $i++) {
            $c = $norm[$i];

            // Find recent swing low (lowest low in last 5-10 bars)
            $lookbackBars = min(10, $i - $idxStart);
            if ($lookbackBars < 5) {
                continue;
            }

            $swingLow = null;
            $swingLowIdx = null;
            for ($j = $i - $lookbackBars; $j < $i; $j++) {
                if ($swingLow === null || $norm[$j]['low'] < $swingLow) {
                    $swingLow = $norm[$j]['low'];
                    $swingLowIdx = $j;
                }
            }

            if ($swingLowIdx === null || $swingLowIdx >= $i - 2) {
                continue; // Swing low too recent
            }

            // Current bar forms higher low (low > swing low)
            $higherLow = $c['low'] > $swingLow * 1.002;
            if (! $higherLow) {
                continue;
            }

            // Breaks above recent resistance (high of bars since swing low)
            $resistance = null;
            for ($j = $swingLowIdx; $j < $i; $j++) {
                if ($resistance === null || $norm[$j]['high'] > $resistance) {
                    $resistance = $norm[$j]['high'];
                }
            }

            $breaksResistance = $c['close'] > $resistance * 1.001;
            if (! $breaksResistance) {
                continue;
            }

            // Green candle with body
            $bodyPct = ($c['open'] > 0) ? abs($c['close'] - $c['open']) / $c['open'] * 100.0 : 0.0;
            if ($c['close'] <= $c['open'] || $bodyPct < $minBodyPct) {
                continue;
            }

            // Volume confirmation
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

            // Not extended vs VWAP
            $aboveVwapPct = ($c['vwap'] > 0) ? (($entry - $c['vwap']) / $c['vwap']) * 100.0 : 999;
            if ($aboveVwapPct > $maxAboveVwapPct) {
                self::$dbg['reject_extended']++;

                continue;
            }

            // Room to run
            if (! $this->hasRoomToRun($entry, $c['hod'], $atr, $minRoomPct, $roomAtrMult)) {
                self::$dbg['reject_no_room']++;

                continue;
            }

            $stop = max($swingLow * 0.998, $c['low'] * 0.995); // Below structure low

            $barObj = (object) $c;
            $sc = $this->computeEntryScoreComponents($barObj);
            $score = $sc['score'];

            $entryClosePosition = ($c['high'] > $c['low']) ? (($c['close'] - $c['low']) / ($c['high'] - $c['low'])) : null;
            $roomToHodPct = ($entry > 0) ? (($c['hod'] - $entry) / $entry) * 100.0 : null;
            $roomToHodAtr = ($atr > 0 && $roomToHodPct !== null) ? (($c['hod'] - $entry) / $atr) : null;
            $higherLowPct = $swingLow > 0 ? (($c['low'] - $swingLow) / $swingLow) * 100.0 : null;

            if ($maxEntryAgeMinutes > 0 && ($asOfEpoch - strtotime($c['ts'])) > ($maxEntryAgeMinutes * 60)) {
                continue;
            }

            $candidates[] = $this->packEntry(
                'HIGHER_LOW_BREAK',
                $c['ts'],
                $entry,
                $stop,
                $score,
                $atr,
                $atrPct,
                [
                    'vol_ratio' => round($vr, 3),
                    'above_vwap_pct' => round($aboveVwapPct, 3),
                    'hod' => $c['hod'],
                    'room_to_hod_pct' => $roomToHodPct !== null ? round($roomToHodPct, 4) : null,
                    'room_to_hod_atr' => $roomToHodAtr !== null ? round($roomToHodAtr, 4) : null,
                    'entry_body_pct' => round($bodyPct, 4),
                    'entry_close_position' => $entryClosePosition !== null ? round($entryClosePosition, 6) : null,
                    'entry_volume_ratio' => round($vr, 4),
                    'entry_notional_1m' => round($notional1m, 2),
                    'higher_low_pct' => $higherLowPct !== null ? round($higherLowPct, 4) : null,
                    'swing_low' => $swingLow !== null ? round($swingLow, 6) : null,
                    'resistance_broken' => $resistance !== null ? round($resistance, 6) : null,
                    'entry_spread_strength' => $sc['spread_strength'],
                    'entry_vwap_dist_score' => $sc['vwap_dist_score'],
                    'entry_atr_score' => $sc['atr_score'],
                    'entry_vol_score' => $sc['vol_score'],
                    'entry_candle_score' => $sc['candle_score'],
                    'entry_time_bonus' => $sc['time_bonus'],
                ]
            );
            break;
        }

        // ========== Entry Type 3: Institutional Accumulation ==========
        // Tight consolidation above VWAP/EMA21 with sustained volume
        for ($i = max(20, $idxStart); $i < count($norm); $i++) {
            $c = $norm[$i];

            // Must be in trend (EMA9 > EMA21) and above VWAP
            if (! ($c['ema_f'] > $c['ema_s'] && $c['close'] > $c['vwap'])) {
                continue;
            }

            // Tight consolidation: last 5-8 bars within narrow range
            $consolidationBars = 6;
            if ($i < $consolidationBars) {
                continue;
            }

            $recentBars = array_slice($norm, $i - $consolidationBars, $consolidationBars);
            $rangeHigh = max(array_column($recentBars, 'high'));
            $rangeLow = min(array_column($recentBars, 'low'));
            $rangeSize = ($rangeLow > 0) ? (($rangeHigh - $rangeLow) / $rangeLow) * 100.0 : 999;

            // Range must be tight (< 0.5%) = consolidation
            if ($rangeSize > 0.5) {
                continue;
            }

            // Break above consolidation range with volume
            $breaksHigh = $c['close'] > $rangeHigh * 1.001;
            if (! $breaksHigh) {
                continue;
            }

            // Green candle
            $bodyPct = ($c['open'] > 0) ? abs($c['close'] - $c['open']) / $c['open'] * 100.0 : 0.0;
            if ($c['close'] <= $c['open'] || $bodyPct < $minBodyPct) {
                continue;
            }

            // Sustained volume during consolidation (institutions accumulating)
            $consolidationVolAvg = 0.0;
            foreach ($recentBars as $b) {
                $consolidationVolAvg += $b['volume'];
            }
            $consolidationVolAvg /= count($recentBars);

            $baselineVol = $volAvg($i);
            $consolidationVolRatio = ($baselineVol > 0) ? ($consolidationVolAvg / $baselineVol) : 0.0;

            // Consolidation volume should be elevated (institutions present)
            if ($consolidationVolRatio < 1.2) {
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

            // Not extended
            $aboveVwapPct = ($c['vwap'] > 0) ? (($entry - $c['vwap']) / $c['vwap']) * 100.0 : 999;
            if ($aboveVwapPct > $maxAboveVwapPct) {
                self::$dbg['reject_extended']++;

                continue;
            }

            // Room to run
            if (! $this->hasRoomToRun($entry, $c['hod'], $atr, $minRoomPct, $roomAtrMult)) {
                self::$dbg['reject_no_room']++;

                continue;
            }

            $stop = min($rangeLow * 0.998, $c['ema_s'] * 0.996); // Below consolidation or EMA21

            $barObj = (object) $c;
            $sc = $this->computeEntryScoreComponents($barObj);
            $score = $sc['score'];

            $entryClosePosition = ($c['high'] > $c['low']) ? (($c['close'] - $c['low']) / ($c['high'] - $c['low'])) : null;
            $roomToHodPct = ($entry > 0) ? (($c['hod'] - $entry) / $entry) * 100.0 : null;
            $roomToHodAtr = ($atr > 0 && $roomToHodPct !== null) ? (($c['hod'] - $entry) / $atr) : null;

            if ($maxEntryAgeMinutes > 0 && ($asOfEpoch - strtotime($c['ts'])) > ($maxEntryAgeMinutes * 60)) {
                continue;
            }

            $candidates[] = $this->packEntry(
                'INSTITUTIONAL_ACCUMULATION',
                $c['ts'],
                $entry,
                $stop,
                $score,
                $atr,
                $atrPct,
                [
                    'vol_ratio' => round($vr, 3),
                    'above_vwap_pct' => round($aboveVwapPct, 3),
                    'hod' => $c['hod'],
                    'room_to_hod_pct' => $roomToHodPct !== null ? round($roomToHodPct, 4) : null,
                    'room_to_hod_atr' => $roomToHodAtr !== null ? round($roomToHodAtr, 4) : null,
                    'entry_body_pct' => round($bodyPct, 4),
                    'entry_close_position' => $entryClosePosition !== null ? round($entryClosePosition, 6) : null,
                    'entry_volume_ratio' => round($vr, 4),
                    'entry_notional_1m' => round($notional1m, 2),
                    'consolidation_range_pct' => round($rangeSize, 4),
                    'consolidation_vol_ratio' => round($consolidationVolRatio, 4),
                    'consolidation_bars' => $consolidationBars,
                    'entry_spread_strength' => $sc['spread_strength'],
                    'entry_vwap_dist_score' => $sc['vwap_dist_score'],
                    'entry_atr_score' => $sc['atr_score'],
                    'entry_vol_score' => $sc['vol_score'],
                    'entry_candle_score' => $sc['candle_score'],
                    'entry_time_bonus' => $sc['time_bonus'],
                ]
            );
            break;
        }

        if (empty($candidates)) {
            return null;
        }

        // Filter by time (no entries after 3:30pm)
        $candidates = array_filter($candidates, function ($c) {
            $hour = isset($c['entry_ts_est']) ? (int) substr($c['entry_ts_est'], 11, 2) : 9;
            $minute = isset($c['entry_ts_est']) ? (int) substr($c['entry_ts_est'], 14, 2) : 30;
            $timeDecimal = $hour + ($minute / 60.0);

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

        $maxSignalAgeMinutes = (int) config('trading.v140.scanner.active_window_minutes', 8);
        $signalAgeSeconds = $asOfEpoch - strtotime($signalTsEst);
        if ($signalAgeSeconds < 0 || ($maxSignalAgeMinutes > 0 && $signalAgeSeconds > ($maxSignalAgeMinutes * 60))) {
            return null;
        }

        $best['entry_age_seconds'] = $entryAgeSeconds;
        $best['signal_age_seconds'] = $signalAgeSeconds;

        // Enforce the configured stop range (institutional risk management)
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

        // Risk + targets
        $risk = max(1e-9, (float) $best['entry'] - (float) $best['stop']);
        $riskPct = ((float) $best['entry'] > 0) ? ($risk / (float) $best['entry']) * 100.0 : 0.0;
        $best['risk_per_share'] = round($risk, 6);
        $best['risk_pct'] = round($riskPct, 3);
        $best['targets'] = [
            '1R' => round((float) $best['entry'] + 1.0 * $risk, 6),
            '2R' => round((float) $best['entry'] + 2.0 * $risk, 6),
            '3R' => round((float) $best['entry'] + 3.0 * $risk, 6),
        ];

        // Suggested trail (using live trading settings)
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

        if ($atr > 0 && ($hod - $entry) >= ($atr * $roomAtrMult)) {
            return true;
        }

        return false;
    }

    private function isAllowedTime(string $tsEst): bool
    {
        // Stricter: 09:35–11:30 and 14:00–15:55 (avoid lunch 11:30-2pm)
        $hh = (int) substr($tsEst, 11, 2);
        $mm = (int) substr($tsEst, 14, 2);
        $t = $hh + $mm / 60.0;

        $a = ($t >= 9.58 && $t <= 11.50);  // 9:35 to 11:30
        $b = ($t >= 14.00 && $t <= 15.92); // 14:00 to 15:55

        return $a || $b;
    }

    /**
     * Standardized entry score formula (0-100) with sub-component breakdown.
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
