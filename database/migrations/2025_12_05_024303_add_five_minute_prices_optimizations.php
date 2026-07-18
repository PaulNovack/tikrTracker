<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Add indexes to optimize the specific slow query patterns identified in sql_logs

        // 1. Optimize DISTINCT trading_date_est queries (496ms -> much faster)
        // This query: SELECT DISTINCT trading_date_est FROM five_minute_prices WHERE asset_type = 'stock' AND trading_date_est IS NOT NULL ORDER BY trading_date_est DESC
        Schema::table('five_minute_prices', function (Blueprint $table) {
            $table->index(['asset_type', 'trading_date_est'], 'idx_fmp_asset_trading_date_distinct');
        });

        // 2. Optimize latest price queries with symbol/ts combination (210ms -> much faster)
        // This query pattern: WHERE symbol IN (...) AND (symbol, ts) IN (SELECT symbol, MAX(ts) FROM...)
        Schema::table('five_minute_prices', function (Blueprint $table) {
            $table->index(['symbol', 'ts', 'asset_type'], 'idx_fmp_latest_price_lookup');
        });

        // 3. Create a covering index specifically for the watch analysis pattern
        // Includes price and asset_type in the index to make it a covering index
        DB::statement('
            CREATE INDEX idx_fmp_symbol_ts_covering 
            ON five_minute_prices (symbol, ts, price, asset_type)
        ');

        // 4. Add an index to optimize trading_date_est + asset_type filtering for rising analysis
        Schema::table('five_minute_prices', function (Blueprint $table) {
            $table->index(['trading_date_est', 'asset_type', 'trading_time_est', 'symbol'], 'idx_fmp_rising_analysis_opt');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('five_minute_prices', function (Blueprint $table) {
            $table->dropIndex('idx_fmp_asset_trading_date_distinct');
            $table->dropIndex('idx_fmp_latest_price_lookup');
            $table->dropIndex('idx_fmp_symbol_ts_covering');
            $table->dropIndex('idx_fmp_rising_analysis_opt');
        });
    }
};
