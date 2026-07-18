<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class CacheTradingDayPrices extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'cache:trading-day-prices 
                           {--asset-type=stock : Asset type to process (stock or crypto)}
                           {--days=15 : Number of trading days to cache}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Cache trading day open/close prices for efficient rising stock calculations';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $assetType = $this->option('asset-type');
        $maxDays = (int) $this->option('days');

        $this->info("Caching trading day prices for {$assetType} assets (last {$maxDays} trading days)...");

        // Build the trading days query with the specified parameters
        // Uses trading_date_est which auto-converts UTC to America/New_York (handles DST)
        $tradingDaysQuery = "
        WITH trading_days AS (
            -- Distinct trading dates based on actual data AND market schedule
            SELECT
                f.trading_date_est AS trading_date,
                ROW_NUMBER() OVER (
                    ORDER BY f.trading_date_est DESC
                ) AS trading_rank
            FROM five_minute_prices f
            JOIN market_schedules ms ON f.trading_date_est = ms.date
            WHERE f.asset_type = ?
              AND ms.market_type = ?
              AND ms.status IN ('open', 'early_close')
            GROUP BY f.trading_date_est
        ),

        selected_days AS (
            -- Keep the N trading days back (1,2,3,5,10,15)
            SELECT trading_date, trading_rank
            FROM trading_days
            WHERE trading_rank <= ?
        ),

        base AS (
            -- All rows for those trading days, uses ts_est (America/New_York with DST)
            SELECT
                f.symbol,
                f.trading_date_est AS trading_date,
                f.trading_time_est AS local_time,
                f.price
            FROM five_minute_prices f
            JOIN selected_days d
              ON f.trading_date_est = d.trading_date
            JOIN market_schedules ms 
              ON f.trading_date_est = ms.date
            WHERE f.asset_type = ?
              AND ms.market_type = ?
              AND ms.status IN ('open', 'early_close')
        ),

        opens AS (
            -- First print at or AFTER 09:30:00 EST = 'open' for the day
            SELECT
                symbol,
                trading_date,
                price AS open_price
            FROM (
                SELECT
                    symbol,
                    trading_date,
                    local_time,
                    price,
                    ROW_NUMBER() OVER (
                        PARTITION BY symbol, trading_date
                        ORDER BY local_time
                    ) AS rn
                FROM base
                WHERE local_time >= '09:30:00'
            ) x
            WHERE rn = 1
        ),

        mids AS (
            -- Price closest to 13:00:00 EST = mid backup (for early close days)
            SELECT
                symbol,
                trading_date,
                price AS mid_price
            FROM (
                SELECT
                    symbol,
                    trading_date,
                    local_time,
                    price,
                    ROW_NUMBER() OVER (
                        PARTITION BY symbol, trading_date
                        ORDER BY ABS(TIME_TO_SEC(local_time) - TIME_TO_SEC('13:00:00'))
                    ) AS rn
                FROM base
            ) x
            WHERE rn = 1
        ),

        closes AS (
            -- Last price between 16:00:00 and 16:30:00 EST = 'close'
            SELECT
                symbol,
                trading_date,
                price AS close_price
            FROM (
                SELECT
                    symbol,
                    trading_date,
                    local_time,
                    price,
                    ROW_NUMBER() OVER (
                        PARTITION BY symbol, trading_date
                        ORDER BY local_time DESC
                    ) AS rn
                FROM base
                WHERE local_time BETWEEN '16:00:00' AND '16:30:00'
            ) x
            WHERE rn = 1
        )

        SELECT
            d.trading_rank       AS days_back,
            d.trading_date,
            o.symbol,
            o.open_price,
            m.mid_price,
            c.close_price
        FROM selected_days d
        JOIN opens  o ON o.trading_date = d.trading_date
        LEFT JOIN mids   m ON m.symbol = o.symbol AND m.trading_date = d.trading_date
        LEFT JOIN closes c ON c.symbol = o.symbol AND c.trading_date = d.trading_date
        ORDER BY d.trading_rank, o.symbol
        ";

        $startTime = microtime(true);

        // Execute the query (parameters: asset_type, asset_type, maxDays, asset_type, asset_type)
        $results = DB::select($tradingDaysQuery, [$assetType, $assetType, $maxDays, $assetType, $assetType]);

        $queryTime = round(microtime(true) - $startTime, 2);
        $this->info("Query executed in {$queryTime} seconds, got ".count($results).' rows');

        // Transform results into a more usable format
        $tradingDayData = [];
        foreach ($results as $row) {
            $tradingDayData[$row->symbol][$row->days_back] = [
                'trading_date' => $row->trading_date,
                'open_price' => (float) $row->open_price,
                'mid_price' => $row->mid_price ? (float) $row->mid_price : null,
                'close_price' => $row->close_price ? (float) $row->close_price : null,
            ];
        }

        // Cache the results for 24 hours
        $cacheKey = "trading_day_prices_{$assetType}";
        Cache::put($cacheKey, $tradingDayData, now()->addHours(24));

        $this->info('Cached trading day data for '.count($tradingDayData).' symbols');
        $this->info("Cache key: {$cacheKey}");

        // Show sample data
        if (! empty($tradingDayData)) {
            $sampleSymbol = array_key_first($tradingDayData);
            $sampleData = $tradingDayData[$sampleSymbol];

            $this->info("\nSample data for {$sampleSymbol}:");
            foreach ($sampleData as $daysBack => $data) {
                $close = $data['close_price'] ? '$'.number_format($data['close_price'], 2) : 'N/A';
                $this->line("  {$daysBack} days back ({$data['trading_date']}): Open \${$data['open_price']}, Close {$close}");
            }
        }

        return Command::SUCCESS;
    }
}
