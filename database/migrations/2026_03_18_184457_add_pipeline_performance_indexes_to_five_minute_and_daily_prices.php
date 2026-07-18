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
            // Composite index for Pipeline H & J queries filtering by symbol, asset_type, ts_est
            // Covers: WHERE symbol = ? AND asset_type = ? AND ts_est <= ? AND ts_est >= ?
            $table->index(['symbol', 'asset_type', 'ts_est'], 'idx_5m_symbol_asset_ts');

            // Composite index for Pipeline J queries filtering by asset_type, trading_date, time window
            // Covers: WHERE asset_type = ? AND trading_date_est = ? AND trading_time_est BETWEEN ? AND ?
            $table->index(['asset_type', 'trading_date_est', 'trading_time_est', 'ts_est'], 'idx_5m_asset_date_time_ts');

            // Composite index for trading date and time queries with ts_est for sorting
            // Covers window function partitioning and time-based filtering
            $table->index(['trading_date_est', 'symbol', 'asset_type', 'ts_est'], 'idx_5m_date_symbol_asset_ts');
        });

        Schema::table('daily_prices', function (Blueprint $table) {
            // Composite index for Pipeline J queries joining daily_prices
            // Covers: JOIN daily_prices ON symbol = ? AND asset_type = ? AND date = ?
            $table->index(['symbol', 'asset_type', 'date'], 'idx_daily_symbol_asset_date');

            // Additional index for date-based lookups (LAG operations)
            // Covers: WHERE date = DATE_SUB(?, INTERVAL 5 DAY)
            $table->index(['asset_type', 'date', 'symbol'], 'idx_daily_asset_date_symbol');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('five_minute_prices', function (Blueprint $table) {
            $table->dropIndex('idx_5m_symbol_asset_ts');
            $table->dropIndex('idx_5m_asset_date_time_ts');
            $table->dropIndex('idx_5m_date_symbol_asset_ts');
        });

        Schema::table('daily_prices', function (Blueprint $table) {
            $table->dropIndex('idx_daily_symbol_asset_date');
            $table->dropIndex('idx_daily_asset_date_symbol');
        });
    }
};
