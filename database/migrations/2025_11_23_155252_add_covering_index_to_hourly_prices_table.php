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
        Schema::table('hourly_prices', function (Blueprint $table) {
            // Add a covering index optimized for the hourly prices list query
            // This index supports filtering by asset_type and sorting by ts (timestamp), symbol
            // Similar optimization to daily_prices for better query performance
            $table->index(['asset_type', 'ts', 'symbol'], 'idx_hourly_prices_type_ts_symbol');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('hourly_prices', function (Blueprint $table) {
            $table->dropIndex('idx_hourly_prices_type_ts_symbol');
        });
    }
};
