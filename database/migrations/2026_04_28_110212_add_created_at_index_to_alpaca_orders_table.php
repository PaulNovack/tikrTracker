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
        Schema::table('alpaca_orders', function (Blueprint $table) {
            // Standalone index so date-range-only queries (the orders page filter)
            // can use an index seek instead of a full table scan as data grows.
            // Existing composite indexes all start with symbol/side/status,
            // making them unusable for a bare created_at range.
            $table->index('created_at', 'alpaca_orders_created_at_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('alpaca_orders', function (Blueprint $table) {
            $table->dropIndex('alpaca_orders_created_at_index');
        });
    }
};
