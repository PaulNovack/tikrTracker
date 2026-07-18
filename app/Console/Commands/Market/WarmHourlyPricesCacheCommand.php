<?php

namespace App\Console\Commands\Market;

use App\Models\HourlyPrice;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

class WarmHourlyPricesCacheCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'market:warm-hourly-prices-cache';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Pre-warm the hourly prices cache with most common queries';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->info('Warming hourly prices cache...');

        $assetTypes = ['stock', 'crypto', 'all'];
        $pages = [1, 2, 3]; // Warm first 3 pages

        $warmedCount = 0;

        foreach ($assetTypes as $assetType) {
            foreach ($pages as $page) {
                $cacheKey = sprintf(
                    'hourly-prices:type=%s:symbol=%s:page=%d',
                    $assetType,
                    'all',
                    $page
                );

                $this->info("Warming: {$cacheKey}");

                // Cache for 1 hour since hourly prices update every hour
                // Using optimized index for better performance
                Cache::remember($cacheKey, 3600, function () use ($assetType, $page) {
                    $query = HourlyPrice::query()
                        ->select([
                            'hourly_prices.id',
                            'hourly_prices.symbol',
                            'hourly_prices.asset_type',
                            'hourly_prices.ts',
                            'hourly_prices.price',
                            'asset_info.common_name',
                        ])
                        ->join('asset_info', function ($join) {
                            $join->on('hourly_prices.symbol', '=', 'asset_info.symbol')
                                ->on('hourly_prices.asset_type', '=', 'asset_info.asset_type');
                        });

                    if ($assetType && $assetType !== 'all') {
                        $query->where('hourly_prices.asset_type', $assetType);
                    }

                    return $query->orderBy('hourly_prices.ts', 'desc')
                        ->orderBy('hourly_prices.symbol')
                        ->paginate(25, ['*'], 'page', $page);
                });

                $warmedCount++;
            }
        }

        $this->info("Successfully warmed {$warmedCount} cache entries!");

        return Command::SUCCESS;
    }
}
