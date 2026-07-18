<?php

namespace App\Services\Trading;

/**
 * Version 25.2 - One-Minute Entry Finder (Refined Quality-First)
 *
 * Extends AbstractOneMinuteEntryFinder with improved entry logic:
 * - Tighter VWAP extension threshold
 * - Dynamic ATR-based stop placement
 * - Volume surge confirmation on entry bar
 * - Optional trend alignment check (EMA alignment)
 *
 * Entry types (long):
 * - VWAP_RECLAIM: cross above VWAP + body confirmation + volume surge
 * - ORB_RETEST: break OR high then retest/hold with volume
 * - EMA9_PULLBACK: trend + pullback to EMA9 + reclaim
 *
 * .env settings for v25.2:
 *   TRADING_V25_2_MIN_NOTIONAL_1M=100000
 *   TRADING_V25_2_MIN_VOL_RATIO_1M=2.5
 *   TRADING_V25_2_MAX_ABOVE_VWAP_ENTRY_PCT=0.60
 *   TRADING_V25_2_MIN_ROOM_TO_RUN_PCT=0.8
 *   TRADING_V25_2_ROOM_ATR_MULT=2.5
 *   TRADING_V25_2_ALLOW_LUNCH=0
 *   TRADING_V25_2_MIN_BARS=90
 *   TRADING_V25_2_MIN_BODY_PCT=0.40
 *   TRADING_V25_2_REQUIRE_TREND_ALIGN=false
 */
class OneMinuteEntryFinderV25_2 extends AbstractOneMinuteEntryFinder
{
    public function __construct()
    {
        $this->version = 'v25.2';
    }

    public function getVersion(): string
    {
        return $this->version;
    }

    public function getName(): string
    {
        return 'Quality-First Entry v25.2';
    }

    public function entryConfig(): array
    {
        return [
            'version' => $this->version,
            'min_notional_1m' => (int) config('trading.v25_2.min_notional_1m', 100000),
            'min_vol_ratio_1m' => (float) config('trading.v25_2.min_vol_ratio_1m', 2.5),
            'max_above_vwap_entry_pct' => (float) config('trading.v25_2.max_above_vwap_entry_pct', 0.60),
            'min_room_to_run_pct' => (float) config('trading.v25_2.min_room_to_run_pct', 0.8),
            'room_atr_mult' => (float) config('trading.v25_2.room_atr_mult', 2.5),
            'allow_lunch' => (bool) config('trading.v25_2.allow_lunch', false),
            'min_bars' => (int) config('trading.v25_2.min_bars', 90),
            'min_body_pct' => (float) config('trading.v25_2.min_body_pct', 0.40),
            'require_trend_align' => (bool) config('trading.v25_2.require_trend_align', false),
            'atr_multiplier' => (float) config('trading.auto_alpaca_stop_loss_atr_multiplier', 2.0),
        ];
    }

    protected function doFindBestLong(
        string $symbol,
        string $assetType,
        string $signalTsEst,
        string $asOfTsEst,
    ): ?array {
        $cfg = $this->entryConfig();

        // Time window
        if (! $this->isAllowedTime($asOfTsEst, $cfg['allow_lunch'])) {
            return null;
        }

        $tradeDate = substr($signalTsEst, 0, 10);
        $marketOpen = $tradeDate.' 09:30:00';

        $bars = $this->fetchOneMinuteBars($symbol, $assetType, $marketOpen, $asOfTsEst);
        if (! $bars || count($bars) < $cfg['min_bars']) {
            return null;
        }

        if (! $this->hasValidPriceData($bars)) {
            return null;
        }

        $cumPV = 0.0; $cumV = 0.0;
        $ema9 = null; $ema21 = null;
        $k9 = 2.0 / 10; $k21 = 2.0 / 22;
        $hod = 0.0; $orHigh = null; $orCount = 0;
        $trValues = [];
        $normBars = [];

        foreach ($bars as $i => $r) {
            $o = (float) $r->open;
            $h = (float) $r->high;
            $l = (float) $r->low;
            $c = (float) $r->close;
            $v = (float) $r->volume;

            if ($h > $hod) $hod = $h;
            $typ = ($h + $l + $c) / 3.0;
            if ($v > 0) { $cumPV += $typ * $v; $cumV += $v; }
            $vwap = ($cumV > 0) ? ($cumPV / $cumV) : $c;
            $ema9 = ($ema9 === null) ? $c : (($c * $k9) + ($ema9 * (1 - $k9)));
            $ema21 = ($ema21 === null) ? $c : (($c * $k21) + ($ema21 * (1 - $k21)));
            if ($orCount < 5 && $orHigh === null) { $orHigh = $h; $orCount++; }
            elseif ($orCount < 5) { $orHigh = max($orHigh, $h); $orCount++; }
            if ($i > 0) {
                $prevC = (float) $bars[$i - 1]->close;
                $trValues[] = max($h - $l, abs($h - $prevC), abs($l - $prevC));
            }
            $normBars[] = ['close' => $c, 'open' => $o, 'high' => $h, 'low' => $l, 'volume' => $v, 'vwap' => $vwap, 'ema9' => $ema9, 'ema21' => $ema21, 'hod' => $hod, 'orHigh' => $orHigh];
        }

        $bc = count($normBars);
        $last = $normBars[$bc - 1];
        $atr = 0.0;
        if (count($trValues) >= 14) { $atr = array_sum(array_slice($trValues, -14)) / 14; }
        elseif (count($trValues) > 0) { $atr = array_sum($trValues) / count($trValues); }

        $notional1m = $last['close'] * $last['volume'];
        if ($notional1m < $cfg['min_notional_1m']) return null;

        $volSlice = array_slice($normBars, max(0, $bc - 21), min(20, $bc));
        $avgVolOther = count($volSlice) > 1 ? (array_sum(array_column($volSlice, 'volume')) - $last['volume']) / (count($volSlice) - 1) : $last['volume'];
        $volRatio = $avgVolOther > 0 ? $last['volume'] / $avgVolOther : 1.0;
        if ($volRatio < $cfg['min_vol_ratio_1m']) return null;

        if ($cfg['require_trend_align'] && $last['ema9'] <= $last['ema21']) return null;

        $aboveVwapPct = (($last['close'] - $last['vwap']) / $last['vwap']) * 100.0;
        if ($aboveVwapPct > $cfg['max_above_vwap_entry_pct']) return null;

        $roomToHod = (($last['hod'] - $last['close']) / $last['close']) * 100.0;
        $roomAtr = $atr > 0 ? ($atr / $last['close']) * 100.0 * $cfg['room_atr_mult'] : 0;
        $room = max($roomToHod, $roomAtr);
        if ($room < $cfg['min_room_to_run_pct']) return null;

        $bodyPct = abs($last['close'] - $last['open']) / max($last['high'] - $last['low'], 0.01);
        $entryType = 'EMA9_PULLBACK';
        if ($bc >= 2) {
            $prev = $normBars[$bc - 2];
            if ($prev['close'] <= $prev['vwap'] && $last['close'] > $last['vwap'] && $bodyPct > $cfg['min_body_pct']) $entryType = 'VWAP_RECLAIM';
        }
        if ($last['orHigh'] > 0 && $last['low'] <= $last['orHigh'] && $last['close'] > $last['orHigh'] && $volRatio >= $cfg['min_vol_ratio_1m']) $entryType = 'ORB_RETEST';

        $atrStopPrice = $last['close'] - ($atr * $cfg['atr_multiplier']);
        $stopPrice = max($atrStopPrice, $last['low'] * 0.995);

        return [
            'entry_price' => round($last['close'], 2),
            'stop_loss' => round($stopPrice, 2),
            'entry_type' => $entryType,
            'vwap' => round($last['vwap'], 2),
            'ema9' => round((float) $last['ema9'], 2),
            'hod' => round($last['hod'], 2),
            'body_pct' => round($bodyPct, 4),
            'vol_ratio' => round($volRatio, 2),
            'atr' => round($atr, 2),
            'above_vwap_pct' => round($aboveVwapPct, 3),
            'room_to_run_pct' => round($room, 3),
        ];
    }
}
