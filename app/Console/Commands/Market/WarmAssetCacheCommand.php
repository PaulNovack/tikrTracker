<?php

namespace App\Console\Commands\Market;

use App\Models\AssetInfo;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

class WarmAssetCacheCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'cache:warm-assets {--asset-type=stock : Asset type to warm (stock, crypto, or all)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Warm asset page caches using file cache driver';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $assetType = $this->option('asset-type');

        $this->info('=== Asset Cache Warming ===');
        $this->info("Cache Driver: {$this->getCacheDriver()}");
        $this->info("Asset Type: {$assetType}");
        $this->newLine();

        // Get assets to warm
        $query = AssetInfo::query();

        if ($assetType !== 'all') {
            $query->where('asset_type', $assetType);
        }

        $assets = $query->whereNull('deleted_at')
            ->orderBy('symbol')
            ->get();

        if ($assets->isEmpty()) {
            $this->warn("No assets found for type: {$assetType}");

            return self::FAILURE;
        }

        $this->info("Found {$assets->count()} assets to warm");
        $this->newLine();

        return $this->warmCaches($assets);
    }

    /**
     * Get the current cache driver.
     */
    private function getCacheDriver(): string
    {
        return config('cache.default') ?: 'file';
    }

    /**
     * Warm caches for all assets using file cache.
     */
    private function warmCaches($assets): int
    {
        $this->info('Warming caches using file cache driver...');
        $bar = $this->output->createProgressBar($assets->count());
        $bar->start();

        $successful = 0;
        $failed = [];

        foreach ($assets as $asset) {
            try {
                $this->warmAssetCache($asset);
                $successful++;
                $bar->advance();
            } catch (\Exception $e) {
                $failed[] = [
                    'symbol' => $asset->symbol,
                    'error' => $e->getMessage(),
                ];
                $bar->advance();
            }
        }

        $bar->finish();
        $this->newLine();
        $this->newLine();

        if ($failed) {
            $this->warn('Failed to warm '.count($failed).' assets:');
            foreach ($failed as $item) {
                $error = $item['error'] ?? 'Unknown error';
                $this->line("  - {$item['symbol']}: {$error}");
            }
        }

        $this->info("✓ Successfully warmed {$successful} asset caches");

        return self::SUCCESS;
    }

    /**
     * Warm cache for a single asset.
     */
    private function warmAssetCache(AssetInfo $asset): void
    {
        // Load fresh asset with relations
        $assetInfo = AssetInfo::with([
            'dailyPrices',
            'hourlyPrices',
        ])->findOrFail($asset->id);

        // Warm daily prices cache (24 hour TTL)
        $cacheKey = 'asset-daily-prices:'.$assetInfo->id.':'.$assetInfo->symbol.':'.$assetInfo->asset_type;
        Cache::remember($cacheKey, 86400, function () use ($assetInfo) {
            return $assetInfo->dailyPrices()
                ->orderBy('date', 'desc')
                ->get(['date', 'price', 'volume']);
        });

        // Warm hourly data caches (1 hour TTL for real-time updates)
        $cacheKeyHourly1M = "asset-hourly-1M:{$assetInfo->id}:{$assetInfo->symbol}:{$assetInfo->asset_type}";
        Cache::remember($cacheKeyHourly1M, 3600, function () use ($assetInfo) {
            return $assetInfo->hourlyPrices()
                ->where('ts', '>=', now('UTC')->subMonth())
                ->orderBy('ts', 'asc')
                ->limit(1000)
                ->get(['ts as time', 'price']);
        });

        $cacheKeyHourly3M = "asset-hourly-3M:{$assetInfo->id}:{$assetInfo->symbol}:{$assetInfo->asset_type}";
        Cache::remember($cacheKeyHourly3M, 3600, function () use ($assetInfo) {
            return $assetInfo->hourlyPrices()
                ->where('ts', '>=', now('UTC')->subMonths(3))
                ->orderBy('ts', 'asc')
                ->limit(2500)
                ->get(['ts as time', 'price']);
        });

        $cacheKeyHourly6M = "asset-hourly-6M:{$assetInfo->id}:{$assetInfo->symbol}:{$assetInfo->asset_type}";
        Cache::remember($cacheKeyHourly6M, 3600, function () use ($assetInfo) {
            return $assetInfo->hourlyPrices()
                ->where('ts', '>=', now('UTC')->subMonths(6))
                ->orderBy('ts', 'asc')
                ->limit(5000)
                ->get(['ts as time', 'price']);
        });
    }
}
