<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class CleanCorruptedPriceDataCommand extends Command
{
    protected $signature = 'market:clean-corrupted-price-data {--dry-run : Show what would be deleted without actually deleting} {--symbol= : Specific symbol to clean}';

    protected $description = 'Clean corrupted price data with unrealistic high values';

    public function handle(): int
    {
        $isDryRun = $this->option('dry-run');
        $symbol = $this->option('symbol');

        $this->info('🔍 Scanning for corrupted price data...');

        // Define reasonable thresholds for different types of assets
        $maxReasonablePrice = 30; // $30 per share threshold for cleaning obvious corrupted data

        $query = DB::table('daily_prices')
            ->where(function ($q) use ($maxReasonablePrice) {
                $q->where('high', '>', $maxReasonablePrice)
                    ->orWhere('low', '>', $maxReasonablePrice)
                    ->orWhere('price', '>', $maxReasonablePrice)
                    ->orWhere('open', '>', $maxReasonablePrice);
            })
            ->where('asset_type', 'stock'); // Only clean stock data for now

        if ($symbol) {
            $query->where('symbol', $symbol);
        }

        $corruptedRecords = $query->get();

        if ($corruptedRecords->isEmpty()) {
            $this->info('✅ No corrupted price data found.');

            return 0;
        }

        $this->info("Found {$corruptedRecords->count()} corrupted price records:");

        // Group by symbol and show summary
        $summary = $corruptedRecords->groupBy('symbol')->map(function ($records, $symbol) {
            return [
                'symbol' => $symbol,
                'count' => $records->count(),
                'max_price' => $records->max('high'),
                'date_range' => $records->min('date').' to '.$records->max('date'),
            ];
        });

        foreach ($summary as $info) {
            $this->line("  - {$info['symbol']}: {$info['count']} records, max price: $".$info['max_price'].", dates: {$info['date_range']}");
        }

        if ($isDryRun) {
            $this->warn('🔍 DRY RUN: No data was actually deleted.');

            return 0;
        }

        if (! $this->confirm('Delete these corrupted price records?')) {
            $this->info('Cancelled.');

            return 0;
        }

        // Delete the corrupted records
        $deletedCount = DB::table('daily_prices')
            ->where(function ($q) use ($maxReasonablePrice) {
                $q->where('high', '>', $maxReasonablePrice)
                    ->orWhere('low', '>', $maxReasonablePrice)
                    ->orWhere('price', '>', $maxReasonablePrice)
                    ->orWhere('open', '>', $maxReasonablePrice);
            })
            ->where('asset_type', 'stock');

        if ($symbol) {
            $deletedCount->where('symbol', $symbol);
        }

        $deletedCount = $deletedCount->delete();

        $this->info("✅ Successfully deleted {$deletedCount} corrupted price records.");

        return 0;
    }
}
