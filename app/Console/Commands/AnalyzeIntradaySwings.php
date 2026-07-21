<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class AnalyzeIntradaySwings extends Command
{
    protected $signature = 'analyze:intraday-swings {--days=45 : Number of days to look back} {--min-swing=6.0 : Minimum swing percentage to mark as 1_min=1} {--dry-run : Show what would be updated without making changes}';

    protected $description = 'Analyze 45 days of 5-minute price data to identify stocks with significant intraday swings and update 1_min flag';

    public function handle(): int
    {
        $days = (int) $this->option('days');
        $minSwing = (float) $this->option('min-swing');
        $dryRun = $this->option('dry-run');

        $this->info("Analyzing intraday swings over the last {$days} days");
        $this->info("Minimum swing threshold: {$minSwing}%");

        if ($dryRun) {
            $this->info('DRY RUN MODE - No changes will be made');
        }

        // Calculate the date range
        $endDate = now()->format('Y-m-d');
        $startDate = now()->subDays($days)->format('Y-m-d');

        $this->info("Date range: {$startDate} to {$endDate}");

        // Step 1: Get all symbols that have 5-minute data in the period
        $this->info('Getting symbols with 5-minute data...');

        $symbols = DB::table('five_minute_prices')
            ->select('symbol', 'asset_type')
            ->where('trading_date_est', '>=', $startDate)
            ->where('trading_date_est', '<=', $endDate)
            ->where('asset_type', 'stock') // Focus on stocks
            ->groupBy('symbol', 'asset_type')
            ->get();

        $totalSymbols = $symbols->count();
        $this->info("Found {$totalSymbols} symbols to analyze");

        if ($totalSymbols === 0) {
            $this->warn('No symbols found in the specified date range');

            return 0;
        }

        // Step 2: Analyze each symbol for intraday swings
        $bar = $this->output->createProgressBar($totalSymbols);
        $bar->start();

        $symbolsWithSwings = [];
        $symbolsWithoutSwings = [];
        $processedCount = 0;

        foreach ($symbols as $symbolInfo) {
            $hasSignificantSwing = $this->analyzeSymbolSwings(
                $symbolInfo->symbol,
                $symbolInfo->asset_type,
                $startDate,
                $endDate,
                $minSwing
            );

            if ($hasSignificantSwing) {
                $symbolsWithSwings[] = $symbolInfo;
            } else {
                $symbolsWithoutSwings[] = $symbolInfo;
            }

            $processedCount++;
            $bar->advance();

            // Process in batches to avoid memory issues
            if ($processedCount % 100 === 0) {
                $this->info("\nProcessed {$processedCount}/{$totalSymbols} symbols...");
            }
        }

        $bar->finish();

        // Step 3: Report results
        $swingSymbolsCount = count($symbolsWithSwings);
        $noSwingSymbolsCount = count($symbolsWithoutSwings);

        $this->newLine();
        $this->info('Analysis complete:');
        $this->info("- Symbols with {$minSwing}%+ intraday swings: {$swingSymbolsCount}");
        $this->info("- Symbols without significant swings: {$noSwingSymbolsCount}");

        // Step 4: Update the database
        if (! $dryRun) {
            $this->info('Updating asset_info table...');

            // Update symbols with swings to 1_min = 1
            if ($swingSymbolsCount > 0) {
                $swingSymbolsList = collect($symbolsWithSwings)->pluck('symbol')->toArray();
                $updated1 = DB::table('asset_info')
                    ->where('asset_type', 'stock')
                    ->whereIn('symbol', $swingSymbolsList)
                    ->update(['1_min' => 1]);

                $this->info("Updated {$updated1} symbols to 1_min = 1");
            }

            // Update symbols without swings to 1_min = 0
            if ($noSwingSymbolsCount > 0) {
                $noSwingSymbolsList = collect($symbolsWithoutSwings)->pluck('symbol')->toArray();
                $updated0 = DB::table('asset_info')
                    ->where('asset_type', 'stock')
                    ->whereIn('symbol', $noSwingSymbolsList)
                    ->update(['1_min' => 0]);

                $this->info("Updated {$updated0} symbols to 1_min = 0");
            }
        } else {
            $this->info('DRY RUN - Would update:');
            $this->info("- {$swingSymbolsCount} symbols to 1_min = 1");
            $this->info("- {$noSwingSymbolsCount} symbols to 1_min = 0");

            // Show some examples
            if ($swingSymbolsCount > 0) {
                $examples = collect($symbolsWithSwings)->take(5)->pluck('symbol')->join(', ');
                $this->info("Examples of swing symbols: {$examples}");
            }
        }

        $this->info('Command completed successfully');

        return 0;
    }

    /**
     * Analyze a single symbol for intraday swings over the date range
     */
    private function analyzeSymbolSwings(string $symbol, string $assetType, string $startDate, string $endDate, float $minSwing): bool
    {
        // Get daily intraday ranges for this symbol
        $dailySwings = DB::select('
            SELECT 
                trading_date_est,
                MIN(low) as day_low,
                MAX(high) as day_high,
                MIN(CASE WHEN trading_time_est = (
                    SELECT MIN(trading_time_est) 
                    FROM five_minute_prices fp2 
                    WHERE fp2.symbol = fp1.symbol 
                    AND fp2.asset_type = fp1.asset_type 
                    AND fp2.trading_date_est = fp1.trading_date_est
                ) THEN open END) as day_open
            FROM five_minute_prices fp1
            WHERE symbol = ? 
            AND asset_type = ?
            AND trading_date_est >= ? 
            AND trading_date_est <= ?
            AND open > 0
            AND high > 0
            AND low > 0
            GROUP BY trading_date_est
            HAVING day_open IS NOT NULL
        ', [$symbol, $assetType, $startDate, $endDate]);

        // Check if any day had a swing >= minimum threshold
        foreach ($dailySwings as $dayData) {
            if ($dayData->day_open > 0) {
                $swingPercent = (($dayData->day_high - $dayData->day_low) / $dayData->day_open) * 100;

                if ($swingPercent >= $minSwing) {
                    return true;
                }
            }
        }

        return false;
    }
}
