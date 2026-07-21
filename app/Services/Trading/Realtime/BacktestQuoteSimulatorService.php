<?php

namespace App\Services\Trading\Realtime;

/**
 * Simulates realistic bid/ask quotes from bar data for backtesting.
 *
 * Real market quotes have:
 *  - Bid/ask bracketing the last traded price
 *  - Spread that varies by price level (wider for penny stocks)
 *  - Small random jitter to simulate market noise
 *  - Volume at bid/ask (round lots: 100 shares)
 */
class BacktestQuoteSimulatorService
{
    /**
     * Generate a simulated quote from a 1-minute bar.
     *
     * @param  array  $bar  Must contain 'close', 'high', 'low'
     * @return array{buy: array{symbol:string, bid:float, bid_qty:int}, sell: array{symbol:string, ask:float, ask_qty:int}}
     */
    public function simulateFromBar(array $bar): array
    {
        $close = (float) ($bar['close'] ?? 0);
        $high = (float) ($bar['high'] ?? $close);
        $low = (float) ($bar['low'] ?? $close);
        $symbol = (string) ($bar['symbol'] ?? '');

        // Calculate spread based on price tier (realistic market spreads)
        $spreadPct = match (true) {
            $close <= 1.0 => 0.02,    // Penny stocks: ~2% spread
            $close <= 5.0 => 0.008,    // Low-priced: ~0.8% spread
            $close <= 25.0 => 0.004,   // Mid-low: ~0.4% spread
            $close <= 100.0 => 0.0015, // Mid: ~0.15% spread
            $close <= 500.0 => 0.001,  // Higher: ~0.1% spread
            default => 0.0008,         // High-priced: ~0.08% spread
        };

        // Randomize spread +/- 30% for natural variation
        $spreadPct *= (0.7 + (mt_rand(0, 6000) / 10000));

        $halfSpread = ($close * $spreadPct) / 2;
        $minTick = $this->minTick($close);

        $bid = round(max($low, $close - $halfSpread) / $minTick) * $minTick;
        $ask = round(min($high, $close + $halfSpread) / $minTick) * $minTick;

        // Ensure bid < ask
        if ($bid >= $ask) {
            $ask = round(($close + $halfSpread + $minTick) / $minTick) * $minTick;
        }

        // Random volume at bid/ask (round lots of 100)
        $bidQty = max(100, (int) (round(mt_rand(2, 50) / 2) * 100));
        $askQty = max(100, (int) (round(mt_rand(2, 50) / 2) * 100));

        return [
            'bid' => $bid,
            'ask' => $ask,
            'bid_qty' => $bidQty,
            'ask_qty' => $askQty,
        ];
    }

    /**
     * Generate a simulated quote array compatible with quoteCache format.
     *
     * @param  array  $bar  Bar must contain 'symbol', 'close', 'high', 'low'
     * @param  string  $asOfTsEst  The simulated timestamp
     */
    public function simulateQuote(array $bar, string $asOfTsEst): array
    {
        $q = $this->simulateFromBar($bar);

        return [
            'symbol' => $bar['symbol'],
            'bid' => $q['bid'],
            'ask' => $q['ask'],
            'bid_qty' => $q['bid_qty'],
            'ask_qty' => $q['ask_qty'],
            'ts_est' => $asOfTsEst,
        ];
    }

    private function minTick(float $price): float
    {
        return $price >= 1.0 ? 0.01 : 0.0001;
    }
}
