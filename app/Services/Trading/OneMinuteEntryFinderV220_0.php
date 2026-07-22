<?php

namespace App\Services\Trading;

/**
 * Version 220.0 - Three Advancing White Soldiers Entry Finder
 *
 * Finds the optimal 1-minute entry point for stocks that have triggered
 * the CDL3WHITESOLDIERS (Three Advancing White Soldiers) pattern.
 *
 * Entry types (long):
 * - VWAP_RECLAIM: price crosses above VWAP with body confirmation + volume surge
 * - PULLBACK_RECLAIM: pullback to EMA9 then reclaim with volume
 * - BREAKOUT: breaks above recent high with high relative volume
 *
 * Only returns bullish entries.
 */
class OneMinuteEntryFinderV220_0 extends AbstractOneMinuteEntryFinder
{
    public function __construct()
    {
        $this->version = 'v220.0';
    }

    public function getVersion(): string
    {
        return $this->version;
    }

    public function getName(): string
    {
        return 'Three White Soldiers Entry v220.0';
    }

    public function entryConfig(): array
    {
        return [
            'version' => $this->version,
            'min_notional_1m' => (int) config('trading.v220.min_notional_1m', 50000),
            'min_vol_ratio_1m' => (float) config('trading.v220.min_vol_ratio_1m', 1.2),
            'max_above_vwap_entry_pct' => (float) config('trading.v220.max_above_vwap_entry_pct', 0.80),
            'min_room_to_run_pct' => (float) config('trading.v220.min_room_to_run_pct', 0.5),
            'room_atr_mult' => (float) config('trading.v220.room_atr_mult', 2.0),
            'allow_lunch' => (bool) config('trading.v220.allow_lunch', false),
            'min_bars' => (int) config('trading.v220.min_bars', 60),
            'min_body_pct' => (float) config('trading.v220.min_body_pct', 0.30),
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

        $cumPV = 0.0;
        $cumV = 0.0;
        $ema9 = null;
        $ema21 = null;
        $k9 = 2.0 / 10;
        $k21 = 2.0 / 22;
        $hod = 0.0;
        $trValues = [];
        $normBars = [];

        foreach ($bars as $i => $r) {
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
            $ema9 = ($ema9 === null) ? $c : (($c * $k9) + ($ema9 * (1 - $k9)));
            $ema21 = ($ema21 === null) ? $c : (($c * $k21) + ($ema21 * (1 - $k21)));
            if ($i > 0) {
                $prevC = (float) $bars[$i - 1]->close;
                $trValues[] = max($h - $l, abs($h - $prevC), abs($l - $prevC));
            }
            $normBars[] = ['close' => $c, 'open' => $o, 'high' => $h, 'low' => $l, 'volume' => $v, 'vwap' => $vwap, 'ema9' => $ema9, 'ema21' => $ema21, 'hod' => $hod];
        }

        $bc = count($normBars);
        $last = $normBars[$bc - 1];
        $atr = 0.0;
        if (count($trValues) >= 14) {
            $atr = array_sum(array_slice($trValues, -14)) / 14;
        } elseif (count($trValues) > 0) {
            $atr = array_sum($trValues) / count($trValues);
        }

        $notional1m = $last['close'] * $last['volume'];
        if ($notional1m < $cfg['min_notional_1m']) {
            return null;
        }

        $volSlice = array_slice($normBars, max(0, $bc - 21), min(20, $bc));
        $avgVolOther = count($volSlice) > 1 ? (array_sum(array_column($volSlice, 'volume')) - $last['volume']) / (count($volSlice) - 1) : $last['volume'];
        $volRatio = $avgVolOther > 0 ? $last['volume'] / $avgVolOther : 1.0;
        if ($volRatio < $cfg['min_vol_ratio_1m']) {
            return null;
        }

        $aboveVwapPct = (($last['close'] - $last['vwap']) / $last['vwap']) * 100.0;
        if ($aboveVwapPct > $cfg['max_above_vwap_entry_pct']) {
            return null;
        }

        $roomToHod = (($last['hod'] - $last['close']) / $last['close']) * 100.0;
        $roomAtr = $atr > 0 ? ($atr / $last['close']) * 100.0 * $cfg['room_atr_mult'] : 0;
        $room = max($roomToHod, $roomAtr);
        if ($room < $cfg['min_room_to_run_pct']) {
            return null;
        }

        $bodyPct = abs($last['close'] - $last['open']) / max($last['high'] - $last['low'], 0.01);
        $entryType = 'PULLBACK_RECLAIM';

        if ($bc >= 2) {
            $prev = $normBars[$bc - 2];
            if ($prev['close'] <= $prev['vwap'] && $last['close'] > $last['vwap'] && $bodyPct > $cfg['min_body_pct']) {
                $entryType = 'VWAP_RECLAIM';
            }
        }

        if ($bc >= 3) {
            $prev2 = $normBars[$bc - 3];
            $prev2High = max($prev2['high'], $normBars[$bc - 2]['high']);
            if ($last['close'] > $prev2High && $volRatio >= $cfg['min_vol_ratio_1m']) {
                $entryType = 'BREAKOUT';
            }
        }

        $atrStopPrice = $last['close'] - ($atr * $cfg['atr_multiplier']);
        $stopPrice = max($atrStopPrice, $last['low'] * 0.995);

        $risk = $last['close'] - $stopPrice;
        $riskPct = ($last['close'] > 0) ? ($risk / $last['close']) * 100.0 : 0.0;
        $trailPct = max(0.7, min(1.0, ($atr * $cfg['atr_multiplier'] / $last['close']) * 100.0));
        $trail = $last['close'] * ($trailPct / 100.0);

        $score = ($volRatio * 1.0) + max(0.0, 1.5 - abs($aboveVwapPct)) + ($bodyPct * 40.0);

        $choppiness = [];
        $fiveMinBars = $this->fetchFiveMinuteBarsForAnalysis($symbol, $assetType, $marketOpen, $asOfTsEst);
        if (count($fiveMinBars) >= 6) {
            $recent5Min = array_slice($fiveMinBars, -12);
            $choppiness = $this->calculate5MinChoppiness($recent5Min);
        }

        return [
            'entry_price' => round($last['close'], 2),
            'stop_loss' => round($stopPrice, 2),
            'entry_type' => $entryType,
            'entry_ts_est' => $asOfTsEst,
            'score' => round($score, 3),
            'risk_pct' => round($riskPct, 3),
            'risk_per_share' => round($risk, 6),
            'atr_pct' => $last['close'] > 0 ? round(($atr / $last['close']) * 100.0, 3) : 0.0,
            'suggested_trailing_stop' => round($trail, 6),
            'suggested_trailing_stop_pct' => round($trailPct, 3),
            'targets' => [
                '1R' => round($last['close'] + 1.0 * $risk, 6),
                '2R' => round($last['close'] + 2.0 * $risk, 6),
                '3R' => round($last['close'] + 3.0 * $risk, 6),
            ],
            'vwap' => round($last['vwap'], 2),
            'ema9' => round((float) $last['ema9'], 2),
            'hod' => round($last['hod'], 2),
            'body_pct' => round($bodyPct, 4),
            'vol_ratio' => round($volRatio, 2),
            'atr' => round($atr, 2),
            'above_vwap_pct' => round($aboveVwapPct, 3),
            'room_to_run_pct' => round($room, 3),
            'five_min_directional_changes' => $choppiness['directional_changes'] ?? null,
            'five_min_green_bar_pct' => isset($choppiness['green_bar_pct']) ? round($choppiness['green_bar_pct'], 1) : null,
            'five_min_net_progress' => $choppiness['net_progress'] ?? null,
        ];
    }
}
