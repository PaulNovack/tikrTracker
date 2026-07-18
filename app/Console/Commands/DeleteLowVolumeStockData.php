<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class DeleteLowVolumeStockData extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'data:delete-low-volume-stocks {--confirm : Confirm the deletion}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Delete ALL five minute stock data (for fresh reload from Yahoo) and hourly data with less than 7 months history';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->info('Preparing to delete stock data for fresh reload...');

        // Get ALL stock symbols from five_minute_prices for deletion
        $fiveMinSymbolsToDelete = DB::select("
            SELECT 
                symbol,
                COUNT(DISTINCT trading_date_est) as trading_days,
                COUNT(*) as total_records
            FROM five_minute_prices 
            WHERE asset_type = 'stock'
            GROUP BY symbol, asset_type 
            ORDER BY symbol ASC
        ");

        // Get symbols with less than 7 months (approx 150 trading days) for hourly_prices
        $hourlySymbolsToDelete = DB::select("
            SELECT 
                symbol,
                COUNT(DISTINCT DATE(ts)) as trading_days,
                COUNT(*) as total_records
            FROM hourly_prices 
            WHERE asset_type = 'stock'
            GROUP BY symbol, asset_type 
            HAVING COUNT(DISTINCT DATE(ts)) < 150
            ORDER BY trading_days ASC, symbol ASC
        ");

        $totalFiveMinSymbols = count($fiveMinSymbolsToDelete);
        $totalHourlySymbols = count($hourlySymbolsToDelete);
        $totalFiveMinRecords = array_sum(array_column($fiveMinSymbolsToDelete, 'total_records'));
        $totalHourlyRecords = array_sum(array_column($hourlySymbolsToDelete, 'total_records'));

        $this->info('═══════════════════════════════════════════════');
        $this->info('FIVE MINUTE DATA (ALL STOCK DATA - FRESH RELOAD):');
        $this->info("Found {$totalFiveMinSymbols} stock symbols");
        $this->info("Total records to be deleted: {$totalFiveMinRecords}");

        $this->info('═══════════════════════════════════════════════');
        $this->info('HOURLY DATA (< 150 trading days / ~7 months):');
        $this->info("Found {$totalHourlySymbols} stock symbols");
        $this->info("Total records to be deleted: {$totalHourlyRecords}");
        $this->info('═══════════════════════════════════════════════');

        if (empty($fiveMinSymbolsToDelete) && empty($hourlySymbolsToDelete)) {
            $this->info('No stock data found to delete.');

            return Command::SUCCESS;
        }

        // Show sample of five minute symbols
        if (! empty($fiveMinSymbolsToDelete)) {
            $this->info('Sample Five Minute symbols (ALL will be deleted):');
            $this->table(
                ['Symbol', 'Trading Days', 'Records'],
                array_slice(array_map(function ($item) {
                    return [$item->symbol, $item->trading_days, $item->total_records];
                }, $fiveMinSymbolsToDelete), 0, 10)
            );
            if ($totalFiveMinSymbols > 10) {
                $this->info('... and '.($totalFiveMinSymbols - 10).' more symbols');
            }
        }

        // Show sample of hourly symbols
        if (! empty($hourlySymbolsToDelete)) {
            $this->info('Sample Hourly symbols to delete:');
            $this->table(
                ['Symbol', 'Trading Days', 'Records'],
                array_slice(array_map(function ($item) {
                    return [$item->symbol, $item->trading_days, $item->total_records];
                }, $hourlySymbolsToDelete), 0, 10)
            );
            if ($totalHourlySymbols > 10) {
                $this->info('... and '.($totalHourlySymbols - 10).' more symbols');
            }
        }

        if (! $this->option('confirm')) {
            $this->warn('This is a dry run. Use --confirm to actually delete the data.');
            $this->info('After deletion, you can reload fresh 60-day five-minute data from Yahoo Finance.');

            return Command::SUCCESS;
        }

        if (! $this->confirm('Are you sure you want to delete this data? This will remove ALL five-minute stock data for fresh reload.')) {
            $this->info('Deletion cancelled.');

            return Command::SUCCESS;
        }

        // Delete ALL five minute stock data
        if (! empty($fiveMinSymbolsToDelete)) {
            $this->info('Deleting ALL five minute stock data...');

            // More efficient: delete all at once instead of per symbol
            $deletedFiveMinRecords = DB::table('five_minute_prices')
                ->where('asset_type', 'stock')
                ->delete();

            $this->info("Deleted {$deletedFiveMinRecords} five minute records for ALL stock symbols.");
        }

        // Delete hourly data for low-volume symbols
        if (! empty($hourlySymbolsToDelete)) {
            $this->info('Deleting low-volume hourly data...');
            $hourlyBar = $this->output->createProgressBar($totalHourlySymbols);

            $deletedHourlyRecords = 0;
            foreach ($hourlySymbolsToDelete as $symbolData) {
                $deleted = DB::table('hourly_prices')
                    ->where('asset_type', 'stock')
                    ->where('symbol', $symbolData->symbol)
                    ->delete();

                $deletedHourlyRecords += $deleted;
                $hourlyBar->advance();
            }

            $hourlyBar->finish();
            $this->newLine();
            $this->info("Deleted {$deletedHourlyRecords} hourly records for {$totalHourlySymbols} symbols.");
        }

        $totalDeletedRecords = ($deletedFiveMinRecords ?? 0) + ($deletedHourlyRecords ?? 0);

        $this->info('═══════════════════════════════════════════════');
        $this->info('CLEANUP COMPLETE!');
        $this->info("Total records deleted: {$totalDeletedRecords}");
        $this->info('═══════════════════════════════════════════════');
        $this->warn('NEXT STEP: Reload five-minute data from Yahoo Finance (60 days)');
        $this->info('Run your Yahoo Finance scripts to get fresh 60-day historical data.');
        $this->info('═══════════════════════════════════════════════');

        return Command::SUCCESS;
    }
}
