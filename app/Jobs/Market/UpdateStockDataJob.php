<?php

namespace App\Jobs\Market;

use App\Models\AssetInfo;
use App\Services\Market\MarketDataFetcher;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;

class UpdateStockDataJob implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public string $symbol,
        public string $assetType,
        private ?MarketDataFetcher $fetcher = null,
    ) {}

    public function handle(): void
    {
        // Find or create the asset
        $asset = AssetInfo::firstOrCreate(
            [
                'symbol' => $this->symbol,
                'asset_type' => $this->assetType,
            ],
            [
                'common_name' => $this->symbol,
                'asset_type' => $this->assetType,
            ]
        );

        // Fetch market data for this specific symbol only
        $this->fetchMarketData($asset);

        // Invalidate chart data caches when new prices are fetched
        $this->invalidateChartCaches($asset);
    }

    private function fetchMarketData(AssetInfo $asset): void
    {
        $fetcher = $this->fetcher ?? new MarketDataFetcher;
        $success = $fetcher->fetchForSymbol($this->symbol, $this->assetType);

        if ($success) {
            \Log::info("Market data successfully fetched for {$this->symbol} ({$this->assetType})");
        } else {
            \Log::error("Failed to fetch market data for {$this->symbol} ({$this->assetType})");
        }
    }

    private function invalidateChartCaches(AssetInfo $asset): void
    {
        // Invalidate chart data cache
        Cache::forget("asset-chart-data:{$asset->id}:{$asset->symbol}:{$asset->asset_type}");

        // Invalidate latest price cache
        Cache::forget("asset-latest-price:{$asset->id}:{$asset->symbol}:{$asset->asset_type}");

        // Invalidate daily prices cache
        Cache::forget("asset-daily-prices:{$asset->id}:{$asset->symbol}:{$asset->asset_type}");

        // Invalidate MAX chart data cache
        Cache::forget("asset-max-chart-data:{$asset->id}:{$asset->symbol}:{$asset->asset_type}");

        \Log::debug("Invalidated caches for {$asset->symbol} ({$asset->asset_type})");
    }
}
