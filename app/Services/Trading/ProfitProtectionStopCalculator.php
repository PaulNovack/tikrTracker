<?php

namespace App\Services\Trading;

/**
 * Tiered profit-protection trailing stop calculator.
 *
 * Tiers (based on profit % from entry):
 *  +0.75% → tighten stop to -0.25% from entry (limit max loss)
 *  +1.25% → lock in +0.50% gain from entry
 *  +2.00% → lock in +1.00% gain from entry
 *  >+2.00% → trail by max(1.0%, 2× ATR) below the session high
 *
 * Stops only ever move UP — never retract.
 * Enable via AUTO_ALPACA_PROFIT_PROTECTION_ENABLED=true in .env.
 */
class ProfitProtectionStopCalculator
{
    public static function isEnabled(): bool
    {
        return (bool) config('trading.auto_alpaca_orders.profit_protection_enabled', false);
    }

    /**
     * Calculate the new stop price given current session context.
     *
     * @param  float  $entryPrice  The fill / entry price for this trade.
     * @param  float  $sessionHighPrice  The highest price reached since entry (or current price for live).
     * @param  float  $atrPct  ATR expressed as a % of price (e.g. 0.8 = 0.8%).
     * @param  float  $currentStop  The current (active) stop price — result is always ≥ this.
     * @return float The new stop price (already rounded to 2 dp).
     */
    public static function calculateStop(
        float $entryPrice,
        float $sessionHighPrice,
        float $atrPct,
        float $currentStop,
    ): float {
        $profitPct = $entryPrice > 0
            ? (($sessionHighPrice - $entryPrice) / $entryPrice) * 100.0
            : 0.0;

        $proposedStop = $currentStop;

        if ($profitPct >= 2.0) {
            // Trail by max(1.0%, 2× ATR) below session high, floor at +1.00% from entry.
            $trailPct = max(1.0, 2.0 * $atrPct);
            $trailedStop = $sessionHighPrice * (1.0 - $trailPct / 100.0);
            $floorStop = $entryPrice * 1.01;                   // always lock at least +1%
            $proposedStop = max($currentStop, $trailedStop, $floorStop);
        } elseif ($profitPct >= 1.25) {
            // Lock in +0.50% from entry.
            $proposedStop = max($currentStop, $entryPrice * 1.005);
        } elseif ($profitPct >= 0.75) {
            // Tighten stop to -0.25% from entry (cap max loss at 0.25%).
            $proposedStop = max($currentStop, $entryPrice * 0.9975);
        }

        return round($proposedStop, 2);
    }

    /**
     * The lowest profit % that triggers a stop adjustment.
     * Callers can use this as the activation threshold instead of the
     * legacy 1% fixed threshold.
     */
    public static function activationThresholdPct(): float
    {
        return 0.75;
    }
}
