<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('five_minute_prices', function (Blueprint $table) {
            // Index for date-based queries (gainers/losers optimization)
            // Replaces DATE(ts_est) function calls with trading_date_est column
            $table->index(['trading_date_est', 'asset_type', 'symbol', 'ts_est'], 'idx_fmp_date_asset_symbol_ts');

            // Index for symbol IN + time range queries
            // Optimizes queries with large symbol lists and timestamp filters
            $table->index(['symbol', 'ts_est', 'asset_type', 'price'], 'idx_fmp_symbol_ts_asset_price');

            // Index for volume analysis queries
            // Optimizes AVG(volume) calculations across date ranges
            $table->index(['symbol', 'asset_type', 'ts_est', 'volume'], 'idx_fmp_volume_analysis');

            // Covering index for price analysis (high/low/open queries)
            // Includes all price-related columns to avoid table lookups
            $table->index(['symbol', 'asset_type', 'ts_est', 'price', 'high', 'low', 'open'], 'idx_fmp_price_analysis_covering');

            // Index for asset type + date filtering patterns
            // Optimizes queries filtering by asset_type and date ranges
            $table->index(['asset_type', 'trading_date_est', 'symbol', 'price', 'ts_est'], 'idx_fmp_asset_date_symbol_price');

            // Index for timestamp-based latest price lookups
            // Optimizes MAX(ts) subqueries for latest prices
            $table->index(['symbol', 'asset_type', 'ts', 'price'], 'idx_fmp_latest_price_lookup_opt');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('five_minute_prices', function (Blueprint $table) {
            // Drop the performance indexes in reverse order
            $table->dropIndex('idx_fmp_latest_price_lookup_opt');
            $table->dropIndex('idx_fmp_asset_date_symbol_price');
            $table->dropIndex('idx_fmp_price_analysis_covering');
            $table->dropIndex('idx_fmp_volume_analysis');
            $table->dropIndex('idx_fmp_symbol_ts_asset_price');
            $table->dropIndex('idx_fmp_date_asset_symbol_ts');
        });
    }
};
