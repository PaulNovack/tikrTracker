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
        Schema::table('daily_prices', function (Blueprint $table) {
            // Composite index for date range queries
            $table->index(['date', 'symbol', 'asset_type'], 'daily_prices_date_symbol_type');
        });

        Schema::table('hourly_prices', function (Blueprint $table) {
            // Composite index for timestamp range queries
            $table->index(['ts', 'symbol', 'asset_type'], 'hourly_prices_ts_symbol_type');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('daily_prices', function (Blueprint $table) {
            $table->dropIndex('daily_prices_date_symbol_type');
        });

        Schema::table('hourly_prices', function (Blueprint $table) {
            $table->dropIndex('hourly_prices_ts_symbol_type');
        });
    }
};
