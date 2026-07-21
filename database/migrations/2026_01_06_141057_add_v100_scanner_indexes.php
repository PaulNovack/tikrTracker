<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Add composite index for V100 bottom detection scanner
        // Optimizes: WHERE asset_type = ? AND ts_est <= ? AND ts_est >= ?
        // Used for both symbol discovery and bar fetching
        Schema::table('five_minute_prices', function (Blueprint $table) {
            $table->index(['asset_type', 'ts_est', 'symbol'], 'idx_v100_scanner_lookup');
        });

        // Add covering index for symbol/ts queries used in bottom detection
        // Includes price, volume for covering index benefits
        DB::statement('
            CREATE INDEX idx_v100_symbol_bars 
            ON five_minute_prices (asset_type, symbol, ts_est, price, volume, open, high, low)
        ');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('five_minute_prices', function (Blueprint $table) {
            $table->dropIndex('idx_v100_scanner_lookup');
            $table->dropIndex('idx_v100_symbol_bars');
        });
    }
};
