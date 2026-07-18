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
        // Add composite index on (asset_type, ts_est) for BestPerformers5mService
        // This dramatically speeds up queries filtering by asset_type and ts_est range
        DB::statement('
            CREATE INDEX idx_fmp_asset_ts_est_range 
            ON five_minute_prices (asset_type, ts_est, symbol, price, volume)
        ');

        DB::statement('
            CREATE INDEX idx_omp_asset_ts_est_range 
            ON one_minute_prices (asset_type, ts_est, symbol, price, volume)
        ');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('DROP INDEX idx_fmp_asset_ts_est_range ON five_minute_prices');
        DB::statement('DROP INDEX idx_omp_asset_ts_est_range ON one_minute_prices');
    }
};
