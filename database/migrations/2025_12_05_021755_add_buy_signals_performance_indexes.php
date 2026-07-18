<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations for buy signals performance optimization.
     * These indexes are specifically designed for the buy signals queries that need to:
     * 1. Filter by asset_type = 'stock'
     * 2. Filter by ts_est <= simTime
     * 3. Filter by symbol IN (list of symbols)
     * 4. Order by symbol, ts_est DESC
     * 5. Limit per symbol
     */
    public function up(): void
    {
        Schema::table('five_minute_prices', function (Blueprint $table) {
            // Covering index optimized for buy signals batch queries
            // Covers: WHERE asset_type = 'stock' AND ts_est <= ? AND symbol IN (...)
            // ORDER BY symbol, ts_est DESC
            $table->index(['asset_type', 'ts_est', 'symbol', 'price', 'open', 'high', 'low', 'volume'],
                'idx_fmp_buy_signals_batch_covering');
        });

        Schema::table('one_minute_prices', function (Blueprint $table) {
            // Covering index optimized for buy signals batch queries
            // Covers: WHERE asset_type = 'stock' AND ts_est <= ? AND symbol IN (...)
            // ORDER BY symbol, ts_est DESC
            $table->index(['asset_type', 'ts_est', 'symbol', 'price', 'open', 'high', 'low', 'volume'],
                'idx_omp_buy_signals_batch_covering');
        });

        Schema::table('asset_info', function (Blueprint $table) {
            // Covering index for active symbols query
            // Covers: WHERE asset_type = 'stock' AND over_1mil = true AND deleted_at IS NULL
            // ORDER BY symbol
            $table->index(['asset_type', 'over_1mil', 'deleted_at', 'symbol', 'id'],
                'idx_asset_info_active_symbols_covering');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('five_minute_prices', function (Blueprint $table) {
            $table->dropIndex('idx_fmp_buy_signals_batch_covering');
        });

        Schema::table('one_minute_prices', function (Blueprint $table) {
            $table->dropIndex('idx_omp_buy_signals_batch_covering');
        });

        Schema::table('asset_info', function (Blueprint $table) {
            $table->dropIndex('idx_asset_info_active_symbols_covering');
        });
    }
};
