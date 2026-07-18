<?php

namespace App\Console\Commands\Market;

use App\Models\AssetInfo;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class SoftDeleteLowVolumeAssetsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'market:soft-delete-low-volume-assets
                            {--volume-threshold=25000000 : Daily volume threshold in dollars}
                            {--days=30 : Number of days to calculate average volume}
                            {--dry-run : Show what would be deleted without actually deleting}
                            {--reason= : Reason for deletion to store in reason_for_delete field}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Soft delete assets with average daily trading volume below specified threshold';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $volumeThreshold = (float) $this->option('volume-threshold');
        $days = (int) $this->option('days');
        $dryRun = $this->option('dry-run');
        $reason = $this->option('reason') ?: 'Low trading volume (under $'.number_format($volumeThreshold).' daily)';

        $this->info('🔍 Analyzing assets with average daily volume under $'.number_format($volumeThreshold)." over last {$days} days");

        if ($dryRun) {
            $this->warn('⚠️  DRY RUN MODE - No actual deletions will be performed');
        }

        // Get assets with low trading volume
        $lowVolumeAssets = $this->getLowVolumeAssets($volumeThreshold, $days);

        if ($lowVolumeAssets->isEmpty()) {
            $this->info('✅ No assets found below the volume threshold');

            return Command::SUCCESS;
        }

        $this->info("Found {$lowVolumeAssets->count()} assets below volume threshold:");

        // Show summary by asset type
        $summary = $lowVolumeAssets->groupBy('asset_type')->map->count();
        foreach ($summary as $assetType => $count) {
            $this->line("  - {$assetType}: {$count}");
        }

        // Show some examples
        $this->info("\nExamples of assets to be soft deleted:");
        $examples = $lowVolumeAssets->take(10);
        foreach ($examples as $asset) {
            $volume = $asset->avg_daily_volume ? '$'.number_format($asset->avg_daily_volume) : 'No recent data';
            $this->line("  - {$asset->symbol} ({$asset->asset_type}): {$volume}");
        }

        if ($lowVolumeAssets->count() > 10) {
            $this->line('  ... and '.($lowVolumeAssets->count() - 10).' more');
        }

        if ($dryRun) {
            $this->info("\n✨ Dry run complete. Use without --dry-run to perform actual soft deletion.");

            return Command::SUCCESS;
        }

        // Confirm before proceeding
        if (! $this->confirm("\n⚠️  This will soft delete {$lowVolumeAssets->count()} assets. Continue?")) {
            $this->info('❌ Operation cancelled');

            return Command::FAILURE;
        }

        // Perform soft deletion in batches
        $this->info("\n🗑️  Starting soft deletion...");
        $bar = $this->output->createProgressBar($lowVolumeAssets->count());

        $deleted = 0;
        foreach ($lowVolumeAssets->chunk(100) as $chunk) {
            $ids = $chunk->pluck('id')->toArray();

            AssetInfo::whereIn('id', $ids)->update([
                'deleted_at' => now(),
                'reason_for_delete' => $reason,
                'updated_at' => now(),
            ]);

            $deleted += count($ids);
            $bar->advance(count($ids));
        }

        $bar->finish();

        $this->newLine(2);
        $this->info("✅ Successfully soft deleted {$deleted} assets");
        $this->info("💾 Reason stored: {$reason}");

        return Command::SUCCESS;
    }

    /**
     * Get assets with low trading volume
     */
    private function getLowVolumeAssets(float $volumeThreshold, int $days)
    {
        // Major assets to exclude from deletion (S&P 500 + major crypto)
        $excludeSymbols = [
            // Major crypto
            'BTC', 'ETH', 'BNB', 'XRP', 'ADA', 'SOL', 'DOGE', 'DOT', 'MATIC', 'AVAX',
            // Major S&P 500 stocks (top 50 by market cap)
            'AAPL', 'MSFT', 'GOOGL', 'GOOG', 'AMZN', 'NVDA', 'TSLA', 'META', 'BRK.A', 'BRK.B',
            'UNH', 'JNJ', 'JPM', 'V', 'PG', 'MA', 'HD', 'CVX', 'ABBV', 'PFE',
            'KO', 'AVGO', 'PEP', 'TMO', 'COST', 'MRK', 'BAC', 'ABT', 'ACN', 'NFLX',
            'ADBE', 'CRM', 'VZ', 'CMCSA', 'NKE', 'DHR', 'TXN', 'NEE', 'QCOM', 'PM',
            'RTX', 'HON', 'UNP', 'T', 'IBM', 'AMGN', 'COP', 'LOW', 'AMD', 'SPGI',
        ];

        return DB::table('asset_info as ai')
            ->leftJoin(
                DB::raw("(
                    SELECT symbol, asset_type, AVG(price * volume) as avg_daily_volume
                    FROM daily_prices 
                    WHERE date >= DATE_SUB(CURDATE(), INTERVAL {$days} DAY)
                    GROUP BY symbol, asset_type
                ) as dp"),
                function ($join) {
                    $join->on('ai.symbol', '=', 'dp.symbol')
                        ->on('ai.asset_type', '=', 'dp.asset_type');
                }
            )
            ->whereNull('ai.deleted_at')
            ->whereNotIn('ai.symbol', $excludeSymbols)
            ->where(function ($query) use ($volumeThreshold) {
                $query->whereNull('dp.avg_daily_volume')
                    ->orWhere('dp.avg_daily_volume', '<', $volumeThreshold);
            })
            ->select([
                'ai.id',
                'ai.symbol',
                'ai.asset_type',
                'ai.common_name',
                'dp.avg_daily_volume',
            ])
            ->get();
    }
}
