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
        // Optimized composite indexes for price queries filtered by symbol with date/time ranges
        // These follow the "equality, range" pattern for optimal index usage

        Schema::table('daily_prices', function (Blueprint $table) {
            // Index for queries: where symbol = ? and date >= ? and date <= ?
            $table->index(['symbol', 'date'], 'daily_prices_symbol_date');
            // Index for queries: where symbol = ? and asset_type = ? and date >= ? and date <= ?
            $table->index(['symbol', 'asset_type', 'date'], 'daily_prices_symbol_type_date');
            // Index for queries: where symbol = ? and date >= ? order by date desc
            $table->index(['symbol', 'date'], 'daily_prices_symbol_date_desc');
        });

        Schema::table('hourly_prices', function (Blueprint $table) {
            // Index for queries: where symbol = ? and ts >= ? and ts <= ?
            $table->index(['symbol', 'ts'], 'hourly_prices_symbol_ts');
            // Index for queries: where symbol = ? and asset_type = ? and ts >= ? and ts <= ?
            $table->index(['symbol', 'asset_type', 'ts'], 'hourly_prices_symbol_type_ts');
        });

        Schema::table('five_minute_prices', function (Blueprint $table) {
            // Index for queries: where symbol = ? and ts >= ? and ts <= ?
            $table->index(['symbol', 'ts'], 'five_minute_prices_symbol_ts');
            // Index for queries: where symbol = ? and asset_type = ? and ts >= ? and ts <= ?
            $table->index(['symbol', 'asset_type', 'ts'], 'five_minute_prices_symbol_type_ts');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('daily_prices', function (Blueprint $table) {
            $table->dropIndex('daily_prices_symbol_date');
            $table->dropIndex('daily_prices_symbol_type_date');
            $table->dropIndex('daily_prices_symbol_date_desc');
        });

        Schema::table('hourly_prices', function (Blueprint $table) {
            $table->dropIndex('hourly_prices_symbol_ts');
            $table->dropIndex('hourly_prices_symbol_type_ts');
        });

        Schema::table('five_minute_prices', function (Blueprint $table) {
            $table->dropIndex('five_minute_prices_symbol_ts');
            $table->dropIndex('five_minute_prices_symbol_type_ts');
        });
    }
};
