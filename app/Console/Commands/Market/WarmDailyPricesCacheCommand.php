<?php

namespace App\Console\Commands\Market;

use App\Models\DailyPrice;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

class WarmDailyPricesCacheCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'market:warm-daily-prices-cache';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Pre-warm the daily prices cache with most common queries';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->info('Warming daily prices cache...');

        $assetTypes = ['stock', 'crypto', 'all'];
        $pages = [1, 2, 3]; // Warm first 3 pages

        $warmedCount = 0;

        foreach ($assetTypes as $assetType) {
            foreach ($pages as $page) {
                $cacheKey = sprintf(
                    'daily-prices:type=%s:symbol=%s:page=%d',
                    $assetType,
                    'all',
                    $page
                );

                $this->info("Warming: {$cacheKey}");

                // Cache for 24 hours since daily prices only update once per day after market close
                // Using optimized index for better performance
                Cache::remember($cacheKey, 86400, function () use ($assetType, $page) {
                    $query = DailyPrice::query()
                        ->select([
                            'daily_prices.id',
                            'daily_prices.symbol',
                            'daily_prices.asset_type',
                            'daily_prices.date',
                            'daily_prices.open',
                            'daily_prices.high',
                            'daily_prices.low',
                            'daily_prices.price',
                            'daily_prices.volume',
                            'asset_info.common_name',
                        ])
                        ->join('asset_info', function ($join) {
                            $join->on('daily_prices.symbol', '=', 'asset_info.symbol')
                                ->on('daily_prices.asset_type', '=', 'asset_info.asset_type');
                        });

                    if ($assetType && $assetType !== 'all') {
                        $query->where('daily_prices.asset_type', $assetType);
                    }

                    return $query->orderBy('daily_prices.date', 'desc')
                        ->orderBy('daily_prices.symbol')
                        ->paginate(50, ['*'], 'page', $page);
                });

                $warmedCount++;
            }
        }

        $this->info("Successfully warmed {$warmedCount} cache entries!");

        return Command::SUCCESS;
    }
}
