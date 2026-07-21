<?php

namespace App\Services\Trading\Filters;

use Carbon\CarbonImmutable;

class APlusEma9PullbackFilter
{
    public function passes(array $ctx): bool
    {
        // Required keys in $ctx:
        // now_est, price, atr_pct, vol_ratio, ema9, ema21, ema9_10m_ago,
        // close, vwap, prev_close, dollar_vol_5m,
        // pullback_low, entry_risk_pct

        // 1) Time window
        $t = CarbonImmutable::parse($ctx['now_est']);
        $hhmm = (int) $t->format('Hi');
        if ($hhmm < 940 || $hhmm > 1445) {
            return false;
        }

        // 2) Liquidity
        if ($ctx['price'] < 1.00 || $ctx['price'] > 250.00) {
            return false;
        }
        if (($ctx['dollar_vol_5m'] ?? 0) < 1_000_000) {
            return false;
        }

        // 3) Controlled volatility
        if (($ctx['atr_pct'] ?? 999) > 0.40) {
            return false;
        }

        // 4) Relative volume
        if (($ctx['vol_ratio'] ?? 0) < 2.0) {
            return false;
        }

        // 5) Trend alignment
        if (($ctx['ema9'] ?? 0) <= ($ctx['ema21'] ?? 0)) {
            return false;
        }
        if (($ctx['ema9'] ?? 0) <= ($ctx['ema9_10m_ago'] ?? 0)) {
            return false;
        }

        // 6) Entry trigger: reclaim EMA9 AND (VWAP ok or reclaim)
        $close = $ctx['close'];
        $ema9 = $ctx['ema9'];
        $vwap = $ctx['vwap'];
        $prevClose = $ctx['prev_close'] ?? $close;

        $reclaimEma9 = $close > $ema9;
        if (! $reclaimEma9) {
            return false;
        }

        $aboveVwap = $close >= $vwap;
        $vwapReclaim = ($prevClose < $vwap) && ($close > $vwap);
        if (! ($aboveVwap || $vwapReclaim)) {
            return false;
        }

        // 7) Structural stop sanity (reject wide / sloppy)
        if (($ctx['entry_risk_pct'] ?? 999) > 0.80) {
            return false;
        }

        // 8) Pullback low must exist and be reasonable
        if (! isset($ctx['pullback_low'])) {
            return false;
        }

        return true;
    }
}
