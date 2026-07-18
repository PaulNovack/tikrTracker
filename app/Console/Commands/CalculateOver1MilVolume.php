<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class CalculateOver1MilVolume extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'stock:calculate-over-1mil-volume {--days=5 : Number of days to look back for volume calculation}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Calculate and update the over_1mil column for stocks with >$1M average daily trading volume';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $days = $this->option('days');

        $this->info("Calculating average daily trading volume for stocks over the last {$days} days...");

        // First, reset all stocks to false
        DB::table('asset_info')
            ->where('asset_type', 'stock')
            ->update(['over_1mil' => false]);

        $this->info('Reset all stocks to over_1mil = false');

        // Calculate average daily dollar volume from 5-minute data
        $query = "
            SELECT 
                fmp.symbol,
                COUNT(DISTINCT DATE(fmp.ts_est)) as trading_days,
                AVG(fmp.price * fmp.volume) as avg_dollar_volume_per_5min,
                SUM(fmp.price * fmp.volume) / COUNT(DISTINCT DATE(fmp.ts_est)) as daily_avg_dollar_volume,
                COUNT(*) as total_records
            FROM five_minute_prices fmp
            JOIN asset_info ai ON fmp.symbol = ai.symbol AND ai.asset_type = 'stock'
            WHERE fmp.ts_est >= DATE_SUB(NOW(), INTERVAL ? DAY)
              AND fmp.volume > 0
              AND fmp.price > 0
            GROUP BY fmp.symbol
            HAVING trading_days >= 2  -- At least 2 trading days
               AND daily_avg_dollar_volume >= 1000000  -- Over $1M daily volume
            ORDER BY daily_avg_dollar_volume DESC
        ";

        $results = DB::select($query, [$days]);

        $this->info('Found '.count($results).' stocks with over $1M average daily trading volume');

        if (empty($results)) {
            $this->warn('No stocks found with sufficient trading volume. Check your data.');

            return 1;
        }

        // Show top 10 by volume
        $this->info("\nTop 10 stocks by daily trading volume:");
        $this->table(
            ['Symbol', 'Trading Days', 'Daily Avg Volume ($)', 'Records'],
            collect($results)->take(10)->map(function ($row) {
                return [
                    $row->symbol,
                    $row->trading_days,
                    '$'.number_format($row->daily_avg_dollar_volume, 0),
                    number_format($row->total_records),
                ];
            })->toArray()
        );

        // Update the over_1mil column for qualifying stocks
        $symbols = collect($results)->pluck('symbol')->toArray();

        $updated = DB::table('asset_info')
            ->whereIn('symbol', $symbols)
            ->where('asset_type', 'stock')
            ->update(['over_1mil' => true]);

        $this->info("\nUpdated {$updated} stocks with over_1mil = true");

        // Summary statistics
        $totalStocks = DB::table('asset_info')->where('asset_type', 'stock')->count();
        $over1MilStocks = DB::table('asset_info')->where('asset_type', 'stock')->where('over_1mil', true)->count();
        $percentage = round(($over1MilStocks / $totalStocks) * 100, 2);

        $this->info("\n=== SUMMARY ===");
        $this->info('Total stocks: '.number_format($totalStocks));
        $this->info('Stocks over $1M daily volume: '.number_format($over1MilStocks));
        $this->info("Percentage: {$percentage}%");

        return 0;
    }
}
