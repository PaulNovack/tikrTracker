<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class GenerateDailyPricesFromIntraday extends Command
{
    protected $signature = 'market:generate-daily-prices
        {--date= : Specific date to generate (YYYY-MM-DD), defaults to most recent trading day}
        {--days=1 : Number of days back to generate}';

    protected $description = 'Generate daily_prices from five_minute_prices data (Alpaca-based)';

    public function handle(): int
    {
        $specificDate = $this->option('date');
        $daysBack = (int) $this->option('days');

        if ($specificDate) {
            $dates = [$specificDate];
            $this->info("Generating daily_prices for {$specificDate}...");
        } else {
            // Get the last N trading days from five_minute_prices
            $dates = DB::table('five_minute_prices')
                ->select('trading_date_est')
                ->where('asset_type', 'stock')
                ->distinct()
                ->orderBy('trading_date_est', 'desc')
                ->limit($daysBack)
                ->pluck('trading_date_est')
                ->toArray();

            if (empty($dates)) {
                $this->error('No trading dates found in five_minute_prices');

                return self::FAILURE;
            }

            $this->info("Generating daily_prices for last {$daysBack} trading days...");
        }

        $totalInserted = 0;
        $totalUpdated = 0;

        foreach ($dates as $date) {
            $this->line("Processing {$date}...");

            // Check how many symbols have data for this date
            $symbolCount = DB::table('five_minute_prices')
                ->where('trading_date_est', $date)
                ->where('asset_type', 'stock')
                ->distinct('symbol')
                ->count('symbol');

            $this->line("  Found {$symbolCount} symbols with 5-minute data");

            // First, try to update existing records (optimized with joins instead of correlated subqueries)
            $updated = DB::update("
                UPDATE daily_prices dp
                INNER JOIN (
                    SELECT 
                        f.symbol,
                        f.asset_type,
                        f.trading_date_est as date,
                        MAX(f.high) as high_price,
                        MIN(f.low) as low_price,
                        SUM(f.volume) as total_volume,
                        MAX(CASE WHEN f.ts_est = max_ts.max_time THEN f.price END) as close_price,
                        MAX(CASE WHEN f.ts_est = min_ts.min_time THEN f.open END) as open_price
                    FROM five_minute_prices f
                    INNER JOIN (
                        SELECT symbol, MAX(ts_est) as max_time
                        FROM five_minute_prices
                        WHERE trading_date_est = ? AND asset_type = 'stock'
                        GROUP BY symbol
                    ) max_ts ON f.symbol = max_ts.symbol
                    INNER JOIN (
                        SELECT symbol, MIN(ts_est) as min_time
                        FROM five_minute_prices
                        WHERE trading_date_est = ? AND asset_type = 'stock'
                        GROUP BY symbol
                    ) min_ts ON f.symbol = min_ts.symbol
                    WHERE f.trading_date_est = ?
                    AND f.asset_type = 'stock'
                    GROUP BY f.symbol, f.asset_type, f.trading_date_est
                    HAVING close_price IS NOT NULL
                ) src ON dp.symbol = src.symbol 
                    AND dp.asset_type = src.asset_type 
                    AND dp.date = src.date
                SET 
                    dp.price = src.close_price,
                    dp.open = src.open_price,
                    dp.high = src.high_price,
                    dp.low = src.low_price,
                    dp.volume = src.total_volume,
                    dp.updated_at = NOW()
            ", [$date, $date, $date]);

            $totalUpdated += $updated;
            $this->line("  Updated {$updated} existing daily_prices records");

            // Then insert new records that don't exist (optimized with joins)
            $inserted = DB::insert("
                INSERT INTO daily_prices (symbol, asset_type, date, price, open, high, low, volume, created_at, updated_at)
                SELECT 
                    f.symbol,
                    f.asset_type,
                    f.trading_date_est as date,
                    MAX(CASE WHEN f.ts_est = max_ts.max_time THEN f.price END) as close_price,
                    MAX(CASE WHEN f.ts_est = min_ts.min_time THEN f.open END) as open_price,
                    MAX(f.high) as high_price,
                    MIN(f.low) as low_price,
                    SUM(f.volume) as total_volume,
                    NOW() as created_at,
                    NOW() as updated_at
                FROM five_minute_prices f
                INNER JOIN (
                    SELECT symbol, MAX(ts_est) as max_time
                    FROM five_minute_prices
                    WHERE trading_date_est = ? AND asset_type = 'stock'
                    GROUP BY symbol
                ) max_ts ON f.symbol = max_ts.symbol
                INNER JOIN (
                    SELECT symbol, MIN(ts_est) as min_time
                    FROM five_minute_prices
                    WHERE trading_date_est = ? AND asset_type = 'stock'
                    GROUP BY symbol
                ) min_ts ON f.symbol = min_ts.symbol
                WHERE f.trading_date_est = ?
                AND f.asset_type = 'stock'
                AND NOT EXISTS (
                    SELECT 1 FROM daily_prices dp2
                    WHERE dp2.symbol = f.symbol
                    AND dp2.asset_type = f.asset_type
                    AND dp2.date = ?
                )
                GROUP BY f.symbol, f.asset_type, f.trading_date_est
                HAVING close_price IS NOT NULL
            ", [$date, $date, $date, $date]);

            $totalInserted += $inserted;
            $this->line("  Inserted {$inserted} new daily_prices records");
        }

        $this->newLine();
        $this->info('✓ Complete!');
        $this->line("  Total updated: {$totalUpdated}");
        $this->line("  Total inserted: {$totalInserted}");

        return self::SUCCESS;
    }
}
