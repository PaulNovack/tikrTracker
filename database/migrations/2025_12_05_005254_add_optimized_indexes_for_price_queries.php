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
        // Add optimized indexes for the most common query patterns
        Schema::table('one_minute_prices', function (Blueprint $table) {
            // For queries like: WHERE symbol = ? AND asset_type = ? AND ts_est <= ? ORDER BY ts_est DESC
            $table->index(['symbol', 'asset_type', 'ts_est'], 'idx_omp_symbol_type_ts_est_opt');
        });

        Schema::table('five_minute_prices', function (Blueprint $table) {
            // Same optimization for five minute prices
            $table->index(['symbol', 'asset_type', 'ts_est'], 'idx_fmp_symbol_type_ts_est_opt');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('one_minute_prices', function (Blueprint $table) {
            $table->dropIndex('idx_omp_symbol_type_ts_est_opt');
        });

        Schema::table('five_minute_prices', function (Blueprint $table) {
            $table->dropIndex('idx_fmp_symbol_type_ts_est_opt');
        });
    }
};
