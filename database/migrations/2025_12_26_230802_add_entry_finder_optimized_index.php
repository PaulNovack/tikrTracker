<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Adds optimized covering index for OneMinuteEntryFinderV10_6 queries.
     * Query pattern: WHERE asset_type = ? AND symbol = ? AND ts_est >= ? AND ts_est <= ?
     * This index ordering (asset_type, symbol, ts_est) matches the WHERE clause exactly.
     */
    public function up(): void
    {
        Schema::table('one_minute_prices', function (Blueprint $table) {
            // Covering index optimized for single-symbol date-range queries
            // Used by OneMinuteEntryFinderV10_6 for pattern detection
            $table->index(
                ['asset_type', 'symbol', 'ts_est', 'open', 'high', 'low', 'price', 'volume'],
                'idx_omp_entry_finder_covering'
            );
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('one_minute_prices', function (Blueprint $table) {
            $table->dropIndex('idx_omp_entry_finder_covering');
        });
    }
};
