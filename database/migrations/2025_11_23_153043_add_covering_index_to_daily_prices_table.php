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
            // Add a covering index optimized for the daily prices list query
            // This index supports filtering by asset_type and sorting by date, symbol
            // The index includes symbol for the JOIN and allows the query to use index for sorting
            $table->index(['asset_type', 'date', 'symbol'], 'idx_daily_prices_type_date_symbol');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('daily_prices', function (Blueprint $table) {
            $table->dropIndex('idx_daily_prices_type_date_symbol');
        });
    }
};
