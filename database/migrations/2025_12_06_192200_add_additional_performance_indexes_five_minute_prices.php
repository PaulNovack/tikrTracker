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
            // Index for correlated subqueries that find MIN/MAX ts within date ranges
            // Covers: ts = (SELECT MIN/MAX(fp2.ts) FROM five_minute_prices fp2 WHERE fp2.symbol = symbol AND fp2.asset_type = asset_type AND fp2.ts >= X AND fp2.ts <= Y)
            $table->index(['symbol', 'asset_type', 'ts'], 'idx_symbol_asset_ts_minmax');

            // Index for volume-based analytics with timestamp filtering
            // Covers: queries that GROUP BY symbol, calculate volume stats, filter by recent timestamps, ORDER BY latest_data
            $table->index(['ts', 'symbol', 'volume'], 'idx_ts_symbol_volume_analytics');

            // Index specifically for asset_info joins with null check optimization
            // Covers: JOIN asset_info ai ON fmp.symbol = ai.symbol AND fmp.asset_type = ai.asset_type WHERE ai.deleted_at IS NULL
            $table->index(['symbol', 'asset_type'], 'idx_symbol_asset_for_joins');

            // Compound index for time-range filtering with trading_time_est
            // Covers: WHERE trading_time_est IN ('09:30:00', '12:55:00', '15:55:00') AND trading_date_est IN (dates) AND asset_type = X
            $table->index(['asset_type', 'trading_time_est', 'trading_date_est'], 'idx_asset_tradetime_tradedate');

            // Index for date-based price queries with ordering
            // Covers: WHERE ts >= date AND ts <= date AND asset_type = X ORDER BY symbol, ts
            $table->index(['asset_type', 'ts', 'symbol'], 'idx_asset_ts_symbol_order');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('five_minute_prices', function (Blueprint $table) {
            $table->dropIndex('idx_symbol_asset_ts_minmax');
            $table->dropIndex('idx_ts_symbol_volume_analytics');
            $table->dropIndex('idx_symbol_asset_for_joins');
            $table->dropIndex('idx_asset_tradetime_tradedate');
            $table->dropIndex('idx_asset_ts_symbol_order');
        });
    }
};
