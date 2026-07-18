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
            // Composite index for ORDER BY symbol ASC, asset_type ASC, date DESC with date filtering
            // Covers: WHERE date >= X ORDER BY symbol ASC, asset_type ASC, date DESC
            $table->index(['symbol', 'asset_type', 'date'], 'idx_daily_symbol_asset_date_order');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('daily_prices', function (Blueprint $table) {
            $table->dropIndex('idx_daily_symbol_asset_date_order');
        });
    }
};
