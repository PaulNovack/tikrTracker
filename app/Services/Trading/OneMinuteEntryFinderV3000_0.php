<?php

namespace App\Services\Trading;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Version 3000.0 — Multi-Timeframe EMA Alignment Entry Finder (Strategy 3)
 *
 * Entry type (long):
 * - MTF_EMA_PULLBACK: 1m EMA 9 > EMA 21, price dips to 5m 9 EMA, bounces green
 *
 * Strategy:
 * - On the 5-minute chart, the 9 EMA must be above the 21 EMA (scanner gate).
 * - On the 1-minute chart, the 9 EMA must also be above the 21 EMA.
 * - Enter a long position on the 1-minute chart only when the price dips down
 *   and bounces directly off the 5-minute 9 EMA line.
 * - Exit the trade immediately if a 5-minute candle closes completely below
 *   the 21 EMA.
 *
 * Quality filters:
 * - NOT EXTENDED vs VWAP (avoid chasing)
 * - ROOM TO RUN (HOD distance OR ATR-multiple runway)
 * - STRUCTURE stop (not tiny ATR trail immediately)
 */
class OneMinuteEntryFinderV3000_0
{
    use HasPriceTables;

    private string $version = 'v3000.0';

    private static array $dbg = [
        'called' => 0,
        'not_enough_bars' => 0,
        'time_blocked' => 0,
        'fail_notional_1m' => 0,
        'reject_extended' => 0,
        'reject_no_room' => 0,
        'reject_no_ema_alignment' => 0,
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
        return ((string) env('ENTRYFINDER_V3000_DEBUG', '0') === '1')
            || (bool) config('trading.v3000.debug', false);
    }

    private function maybeLogDebug(): void
    {
        if (! $this->isDebugEnabled()) {
            return;
        }
        if (self::$dbg['called'] > 0 && self::$dbg['called'] % 20000 === 0) {
            Log::info('[EntryFinderV3000] debug counters', self::$dbg);
        }
    }

    private function findEntry(string $symbol, string $assetType, string $signalTsEst, string $asOfTsEst): ?array
    {
        $cfg = (array) config('trading.v3000.entry', []);

        // --- Time window (avoid lunch chop) ---
        $allowLunch = (bool) ($cfg['allow_lunch'] ?? false);
        if (! $allowLunch && ! $this->isAllowedTime($asOfTsEst)) {
            self::$dbg['time_blocked']++;

            return null;
        }

        // --- Risk/quality gates ---
        $minBars = (int) ($cfg['min_bars'] ?? 15);
        $minNotional1m = (float) ($cfg['min_notional_1m'] ?? 50000.0);
        $maxAboveVwapPct = (float) ($cfg['max_above_vwap_entry_pct'] ?? 1.2);
        $minRoomPct = (float) ($cfg['min_room_to_run_pct'] ?? 0.6);
        $roomAtrMult = (float) ($cfg['room_atr_mult'] ?? 1.5);
        $minVolRatio = (float) ($cfg['min_vol_ratio_1m'] ?? 1.0);
        $volLookback = (int) ($cfg['vol_lookback_1m'] ?? 20);
        $minBodyPct = (float) ($cfg['min_body_pct_1m'] ?? 0.05);
        $maxEntryAgeMinutes = (int) ($cfg['max_entry_age_minutes'] ?? 12);

        // Strategy-3 specific: pullback depth tolerance vs 5m EMA9
        $pullbackMaxDepthPct = (float) ($cfg['pullback_max_depth_pct'] ?? 0.4);
        $reclaimMinStrengthPct = (float) ($cfg['reclaim_min_strength_pct'] ?? 0.05);

        $emaFast = (int) ($cfg['ema_fast'] ?? 9);
        $emaSlow = (int) ($cfg['ema_slow'] ?? 21);
        $atrPeriod = (int) ($cfg['atr_period_1m'] ?? 14);

        $beforeMinutes = (int) ($cfg['analysis_lookback_minutes'] ?? 90);

        $tradeDate = substr($signalTsEst, 0, 10);
        $marketOpen = $tradeDate.' 09:30:00';

        $analysisEnd = $asOfTsEst;
        $analysisStart = date('Y-m-d H:i:s', strtotime($asOfTsEst." -{$beforeMinutes} minutes"));
        if ($analysisStart < $marketOpen) {
            $analysisStart = $marketOpen;
        }

        $asOfEpochRaw = strtotime($asOfTsEst);
        $asOfEpoch = $asOfEpochRaw !== false
            ? strtotime(date('Y-m-d H:i:00', $asOfEpochRaw))
            : false;
        if ($asOfEpoch === false) {
            return null;
        }

        // Load 1-minute bars from market open to now
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

        // Validate data quality
        for ($i = 1; $i < count($bars); $i++) {
            $prevClose = (float) $bars[$i - 1]->close;
            $currentOpen = (float) $bars[$i]->open;
            if ($prevClose > 0) {
                $dropPct = (($currentOpen - $prevClose) / $prevClose) * 100.0;
                if ($dropPct < -50.0) {
                    return null;
                }
            }
        }

        // Load 5-minute bars for 5m EMA9/EMA21 values (needed for pullback entry) and choppiness
        $fiveMinBars = $this->dbSelect('
            SELECT ts_est, open, high, low, price, ema9, ema21
            FROM five_minute_prices
            WHERE asset_type = ?
              AND symbol = ?
              AND trading_date_est = ?
              AND ts_est >= ?
              AND ts_est <= ?
            ORDER BY ts_est ASC
        ', [$assetType, $symbol, $tradeDate, $marketOpen, $analysisEnd]);

        // Build normalized 1m series + VWAP + EMA + HOD + 5m EMA values
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

            // Find the 5m EMA9/EMA21 that applies at this 1m timestamp
            $ema9_5m = null;
            $ema21_5m = null;
            foreach ($fiveMinBars as $fm) {
                if ((string) $fm->ts_est <= $ts) {
                    $ema9_5m = (float) ($fm->ema9 ?? 0);
                    $ema21_5m = (float) ($fm->ema21 ?? 0);
                } else {
                    break;
                }
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
                'ema9_5m' => $ema9_5m,
                'ema21_5m' => $ema21_5m,
                'hod' => $hod,
            ];
        }

        $normCount = count($norm);
        $idxStart = $this->firstIndexAtOrAfter($norm, $analysisStart);
        if (($normCount - $idxStart) < 10) {
            self::$dbg['not_enough_bars']++;

            return null;
        }

        $atr = $this->atr($norm, $atrPeriod);
        $lastClose = $this->lastClose($norm);
        $atrPct = ($lastClose > 0) ? ($atr / $lastClose) * 100.0 : 0.0;

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

        // Do not scan old bars that can no longer pass the final max_entry_age_minutes gate.
        $freshCutoffTs = $maxEntryAgeMinutes > 0
            ? date('Y-m-d H:i:s', $asOfEpoch - ($maxEntryAgeMinutes * 60))
            : $analysisStart;
        if ($freshCutoffTs < $analysisStart) {
            $freshCutoffTs = $analysisStart;
        }

        $candidates = [];

        // ---------- Candidate: MTF_EMA_PULLBACK (Strategy 3) ----------
        // Enter on 1m pullback to 5m 9 EMA with both EMAs aligned.
        for ($i = max(10, $idxStart); $i < count($norm); $i++) {
            $p = $norm[$i - 1];
            $c = $norm[$i];

            // ── 1. 1m EMA alignment: 9 EMA must be above 21 EMA ──
            if (! ($c['ema_f'] > $c['ema_s'])) {
                continue;
            }

            // ── 2. 5m EMA alignment: 9 EMA must be above 21 EMA ──
            if ($c['ema9_5m'] <= 0 || $c['ema21_5m'] <= 0) {
                continue;
            }
            if (! ($c['ema9_5m'] > $c['ema21_5m'])) {
                self::$dbg['reject_no_ema_alignment']++;

                continue;
            }

            // ── 3. Price above VWAP ──
            if (! ($c['close'] > $c['vwap'])) {
                continue;
            }

            // ── 4. Pullback: previous bar's low touched near the 5m 9 EMA ──
            $prevLow = (float) $p['low'];
            $ema9_5m = (float) $c['ema9_5m'];
            $distancePct = abs($prevLow - $ema9_5m) / $ema9_5m * 100.0;
            if ($distancePct > $pullbackMaxDepthPct) {
                continue;
            }

            // ── 5. Bounce: current bar closes green above the 5m 9 EMA ──
            if ($c['close'] <= $c['open']) {
                continue;
            }
            if ($c['close'] <= $ema9_5m) {
                continue;
            }

            $bodyPct = ($c['open'] > 0) ? abs($c['close'] - $c['open']) / $c['open'] * 100.0 : 0.0;
            if ($bodyPct < $minBodyPct) {
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

            // Stop: below pullback low or 5m 21 EMA (strategy exit trigger)
            $stop = min($c['low'], (float) $c['ema21_5m'] * 0.996);

            $barObj = (object) $c;
            $sc = $this->computeEntryScoreComponents($barObj);
            $score = $sc['score'];

            $entryClosePosition = ($c['high'] > $c['low']) ? (($c['close'] - $c['low']) / ($c['high'] - $c['low'])) : null;
            $roomToHodPct = ($entry > 0) ? (($c['hod'] - $entry) / $entry) * 100.0 : null;
            $roomToHodAtr = ($atr > 0 && $roomToHodPct !== null) ? (($c['hod'] - $entry) / $atr) : null;
            $ema9PullbackDepthPct = ($ema9_5m > 0) ? (($prevLow - $ema9_5m) / $ema9_5m) * 100.0 : null;
            $ema9ReclaimPct = ($ema9_5m > 0) ? (($c['close'] - $ema9_5m) / $ema9_5m) * 100.0 : null;

            if ($maxEntryAgeMinutes > 0 && ($asOfEpoch - strtotime($c['ts'])) > ($maxEntryAgeMinutes * 60)) {
                continue;
            }

            // 5m choppiness
            $choppiness = [];
            if (count($fiveMinBars) >= 6) {
                $recent5MinBars = array_slice($fiveMinBars, -12);
                $choppiness = $this->calculate5MinChoppiness($recent5MinBars);
            }

            $candidates[] = $this->packEntry(
                'MTF_EMA_PULLBACK',
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
                    'ema9_pullback_depth_pct' => $ema9PullbackDepthPct !== null ? round($ema9PullbackDepthPct, 4) : null,
                    'ema9_reclaim_pct' => $ema9ReclaimPct !== null ? round($ema9ReclaimPct, 4) : null,
                    'ema9_5m' => round($ema9_5m, 6),
                    'ema21_5m' => round((float) $c['ema21_5m'], 6),
                    'five_min_directional_changes' => $choppiness['directional_changes'] ?? null,
                    'five_min_green_bar_pct' => isset($choppiness['green_bar_pct']) ? round($choppiness['green_bar_pct'], 1) : null,
                    'five_min_net_progress' => isset($choppiness['net_progress']) ? round($choppiness['net_progress'], 3) : null,
                    'vwap_reclaim_strength_pct' => null,
                    'vwap_reclaim_wick_below_pct' => null,
                    'or_high_v252' => null,
                    'or_break_distance_pct' => null,
                    'or_retest_depth_pct' => null,
                    'or_hold_close_pct' => null,
                    'bars_since_or_break' => null,
                ]
            );
            break;
        }

        if (empty($candidates)) {
            return null;
        }

        // Time filter: no entries after 3:30pm.
        $candidates = array_values(array_filter($candidates, function ($c) {
            $hour = isset($c['entry_ts_est']) ? (int) substr($c['entry_ts_est'], 11, 2) : 9;
            $minute = isset($c['entry_ts_est']) ? (int) substr($c['entry_ts_est'], 14, 2) : 30;
            $timeDecimal = $hour + ($minute / 60.0);

            return $timeDecimal < 15.5;
        }));

        if (empty($candidates)) {
            return null;
        }

        usort($candidates, fn ($a, $b) => (($b['score'] ?? 0) <=> ($a['score'] ?? 0)));
        $best = $candidates[0];

        $entryAgeSeconds = $asOfEpoch - strtotime((string) $best['entry_ts_est']);
        if ($entryAgeSeconds < 0 || ($maxEntryAgeMinutes > 0 && $entryAgeSeconds > ($maxEntryAgeMinutes * 60))) {
            return null;
        }

        $maxSignalAgeMinutes = (int) config('trading.v3000.scanner.active_window_minutes', 6);
        $signalAgeSeconds = $asOfEpoch - strtotime($signalTsEst);
        if ($signalAgeSeconds < 0 || ($maxSignalAgeMinutes > 0 && $signalAgeSeconds > ($maxSignalAgeMinutes * 60))) {
            return null;
        }

        $best['entry_age_seconds'] = $entryAgeSeconds;
        $best['signal_age_seconds'] = $signalAgeSeconds;

        // Enforce the configured stop range.
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

        // Risk + targets.
        $risk = max(1e-9, (float) $best['entry'] - (float) $best['stop']);
        $riskPct = ((float) $best['entry'] > 0) ? ($risk / (float) $best['entry']) * 100.0 : 0.0;
        $best['risk_per_share'] = round($risk, 6);
        $best['risk_pct'] = round($riskPct, 3);
        $best['targets'] = [
            '1R' => round((float) $best['entry'] + 1.0 * $risk, 6),
            '2R' => round((float) $best['entry'] + 2.0 * $risk, 6),
            '3R' => round((float) $best['entry'] + 3.0 * $risk, 6),
        ];

        // ATR trailing stop suggestion.
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

        // Only run the 5m query after a valid candidate exists.
        $choppiness = $this->loadRecent5MinChoppiness($symbol, $assetType, $tradeDate, $marketOpen, $analysisEnd);
        $best['five_min_directional_changes'] = $choppiness['directional_changes'] ?? null;
        $best['five_min_green_bar_pct'] = isset($choppiness['green_bar_pct']) ? round($choppiness['green_bar_pct'], 1) : null;
        $best['five_min_net_progress'] = isset($choppiness['net_progress']) ? round($choppiness['net_progress'], 3) : null;

        return $best;
    }

    /** @return array<int, object> */
    private function loadOneMinuteBars(string $symbol, string $assetType, string $tradeDate, string $marketOpen, string $analysisEnd): array
    {
        $key = implode('|', [$this->oneMinuteTable, $assetType, $symbol, $tradeDate, $marketOpen, $analysisEnd]);
        if (isset(self::$oneMinuteBarsCache[$key])) {
            return self::$oneMinuteBarsCache[$key];
        }

        $table = $this->safeTable($this->oneMinuteTable);
        $bars = DB::select("
            SELECT ts_est, `open`, high, low, price AS close, volume
            FROM {$table}
            WHERE asset_type = ?
              AND symbol = ?
              AND trading_date_est = ?
              AND ts_est >= ?
              AND ts_est <= ?
            ORDER BY ts_est ASC
        ", [$assetType, $symbol, $tradeDate, $marketOpen, $analysisEnd]);

        if (count(self::$oneMinuteBarsCache) >= self::$maxLocalCacheKeys) {
            array_shift(self::$oneMinuteBarsCache);
        }
        self::$oneMinuteBarsCache[$key] = $bars;

        return $bars;
    }

    /** @return array<string, mixed> */
    private function loadRecent5MinChoppiness(string $symbol, string $assetType, string $tradeDate, string $marketOpen, string $analysisEnd): array
    {
        $table = $this->safeTable($this->fiveMinuteTable);
        $bars = DB::select("
            SELECT ts_est, `open`, high, low, price
            FROM {$table}
            WHERE asset_type = ?
              AND symbol = ?
              AND trading_date_est = ?
              AND ts_est >= ?
              AND ts_est <= ?
            ORDER BY ts_est DESC
            LIMIT 12
        ", [$assetType, $symbol, $tradeDate, $marketOpen, $analysisEnd]);

        if (count($bars) < 2) {
            return [];
        }

        return $this->calculate5MinChoppiness(array_reverse($bars));
    }

    private function firstIndexAtOrAfter(array $norm, string $ts): int
    {
        $lo = 0;
        $hi = count($norm);
        while ($lo < $hi) {
            $mid = intdiv($lo + $hi, 2);
            if ((string) $norm[$mid]['ts'] < $ts) {
                $lo = $mid + 1;
            } else {
                $hi = $mid;
            }
        }

        return $lo;
    }

    private function rollingAvgVolume(array $volumePrefix, int $idx, int $lookback): float
    {
        $start = max(0, $idx - max(1, $lookback));
        $n = $idx - $start;
        if ($n <= 0) {
            return 0.0;
        }

        return ((float) $volumePrefix[$idx] - (float) $volumePrefix[$start]) / $n;
    }

    private function safeTable(string $table): string
    {
        return '`'.str_replace('`', '', $table).'`';
    }

    // ─── Helper methods (same contract as V25_2) ────────────────────────────

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

            $trs[] = max(
                $high - $low,
                abs($high - $prevClose),
                abs($low - $prevClose)
            );
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
        $hh = (int) substr($tsEst, 11, 2);
        $mm = (int) substr($tsEst, 14, 2);
        $t = $hh + $mm / 60.0;

        $a = ($t >= 9.58 && $t <= 11.25);  // approx. 9:35 to 11:15
        $b = ($t >= 14.00 && $t <= 15.92); // 14:00 to 15:55

        return $a || $b;
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

    /**
     * Standardized entry score formula (0-100) with sub-component breakdown.
     * Same formula as V25_2 for ML consistency.
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
