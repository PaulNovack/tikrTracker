<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

class StockPriceService
{
    /**
     * Get the latest one minute price for a symbol.
     */
    public function getLatestPrice(string $symbol, string $assetType = 'stock'): ?array
    {
        $symbol = strtoupper(trim($symbol));

        $latestPrice = DB::table('one_minute_prices')
            ->where('asset_type', $assetType)
            ->where('symbol', $symbol)
            ->orderBy('ts_est', 'desc')
            ->first(['symbol', 'price', 'ts_est', 'volume']);

        if (! $latestPrice) {
            return null;
        }

        return [
            'symbol' => $latestPrice->symbol,
            'price' => (float) $latestPrice->price,
            'timestamp' => $latestPrice->ts_est,
            'volume' => (int) $latestPrice->volume,
        ];
    }

    /**
     * Calculate max shares that can be purchased with given amount.
     */
    public function calculateMaxShares(float $amount, float $price): int
    {
        if ($price <= 0) {
            return 0;
        }

        return (int) floor($amount / $price);
    }
}
