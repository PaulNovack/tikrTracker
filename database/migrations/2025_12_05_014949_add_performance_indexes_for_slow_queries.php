<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        DB::connection('mysql')->statement('SET SESSION sql_mode = ""');

        // 1. CRITICAL: Hourly prices covering index for the slowest queries (88+ seconds)
        // This covers the JOIN + WHERE + ORDER BY pattern that's killing performance
        DB::connection('mysql')->statement('
            CREATE INDEX idx_hourly_covering_join_sort 
            ON hourly_prices (asset_type, ts DESC, symbol ASC, id, price)
        ');

        // 2. Five minute prices covering index for correlated subqueries
        // Optimizes the MAX(ts) subquery pattern that's taking 12+ seconds
        DB::connection('mysql')->statement('
            CREATE INDEX idx_fmp_ts_range_covering 
            ON five_minute_prices (asset_type, ts, symbol, price, open)
        ');

        // 3. Five minute prices trading time analysis index
        // For the complex CTE queries with GROUP_CONCAT operations (5-8 seconds)
        DB::connection('mysql')->statement('
            CREATE INDEX idx_fmp_trading_analysis 
            ON five_minute_prices (trading_date_est, asset_type, trading_time_est, symbol, price)
        ');

        // 4. Optimize five_minute_prices volume calculations
        // For the COUNT queries with AVG(volume * price) calculations
        DB::connection('mysql')->statement('
            CREATE INDEX idx_fmp_volume_calc 
            ON five_minute_prices (asset_type, ts_est, symbol, volume, price)
        ');

        // 5. Asset info covering index for JOIN optimization
        // To make the hourly_prices + asset_info JOINs faster
        DB::connection('mysql')->statement('
            CREATE INDEX idx_asset_info_join_covering 
            ON asset_info (symbol, asset_type, common_name, deleted_at, over_1mil)
        ');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::connection('mysql')->statement('DROP INDEX IF EXISTS idx_hourly_covering_join_sort ON hourly_prices');
        DB::connection('mysql')->statement('DROP INDEX IF EXISTS idx_fmp_ts_range_covering ON five_minute_prices');
        DB::connection('mysql')->statement('DROP INDEX IF EXISTS idx_fmp_trading_analysis ON five_minute_prices');
        DB::connection('mysql')->statement('DROP INDEX IF EXISTS idx_fmp_volume_calc ON five_minute_prices');
        DB::connection('mysql')->statement('DROP INDEX IF EXISTS idx_asset_info_join_covering ON asset_info');
    }
};
