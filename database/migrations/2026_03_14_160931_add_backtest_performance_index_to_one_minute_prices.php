<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Add covering index for backtest performance queries
        // Query pattern: WHERE asset_type = ? AND symbol = ? AND ts_est > ? AND ts_est <= ?
        // Covering columns: open, high, low, price (close) for OHLC data
        DB::statement('
            CREATE INDEX idx_backtest_performance_covering 
            ON one_minute_prices (asset_type, symbol, ts_est, open, high, low, price)
        ');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS idx_backtest_performance_covering ON one_minute_prices');
    }
};
