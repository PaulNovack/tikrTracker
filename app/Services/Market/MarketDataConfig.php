<?php

namespace App\Services\Market;

use App\Models\AssetInfo;

class MarketDataConfig
{
    /**
     * Get tracked crypto assets from database.
     * Returns array of crypto assets with CoinGecko ID extracted from description.
     */
    public static function getTrackedAssets(): array
    {
        return AssetInfo::where('asset_type', 'crypto')
            ->whereNull('deleted_at')
            ->orderBy('symbol')
            ->get()
            ->map(function ($asset) {
                // Extract CoinGecko ID from description if present
                $coinGeckoId = null;
                if (preg_match('/\(ID: ([^)]+)\)/', $asset->description ?? '', $matches)) {
                    $coinGeckoId = $matches[1];
                }

                return [
                    'type' => 'crypto',
                    'symbol' => $asset->symbol,
                    'id' => $coinGeckoId,
                ];
            })
            ->toArray();
    }

    /**
     * Get tracked stock symbols from database.
     */
    public static function getTrackedStocks(): array
    {
        return AssetInfo::where('asset_type', 'stock')
            ->whereNull('deleted_at')
            ->orderBy('symbol')
            ->get()
            ->map(function ($asset) {
                return [
                    'type' => 'stock',
                    'symbol' => $asset->symbol,
                ];
            })
            ->toArray();
    }

    /**
     * Calculate average of array values.
     */
    public static function avg(array $values): float
    {
        $values = array_values(array_filter($values, static fn ($v) => $v !== null));
        $count = count($values);

        if ($count === 0) {
            return 0.0;
        }

        return array_sum($values) / $count;
    }
}
