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
        // Helper to check if index exists
        $hasIndex = function (string $table, string $index): bool {
            $conn = DB::connection()->getName();
            $database = DB::connection()->getDatabaseName();

            $result = DB::select(
                'SELECT COUNT(*) as count 
                 FROM information_schema.statistics 
                 WHERE table_schema = ? 
                 AND table_name = ? 
                 AND index_name = ?',
                [$database, $table, $index]
            );

            return $result[0]->count > 0;
        };

        Schema::table('one_minute_prices', function (Blueprint $table) use ($hasIndex) {
            // Drop duplicate indexes only if they exist
            if ($hasIndex('one_minute_prices', 'idx_omp_type_ts_symbol')) {
                $table->dropIndex('idx_omp_type_ts_symbol');
            }

            if ($hasIndex('one_minute_prices', 'idx_omp_asset_ts_symbol')) {
                $table->dropIndex('idx_omp_asset_ts_symbol');
            }

            // Single column indexes that are redundant with composite indexes
            if ($hasIndex('one_minute_prices', 'one_minute_prices_asset_type_index')) {
                $table->dropIndex('one_minute_prices_asset_type_index');
            }

            if ($hasIndex('one_minute_prices', 'one_minute_prices_symbol_index')) {
                $table->dropIndex('one_minute_prices_symbol_index');
            }
        });

        // Add optimized index for v50.0 scanner query only if it doesn't exist
        if (! $hasIndex('one_minute_prices', 'idx_omp_v50_scanner')) {
            DB::statement('
                CREATE INDEX idx_omp_v50_scanner 
                ON one_minute_prices (
                    asset_type, 
                    trading_date_est, 
                    ts_est,
                    ema9_above_ema21,
                    above_vwap
                )
            ');
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('one_minute_prices', function (Blueprint $table) {
            // Drop v50 scanner index
            $table->dropIndex('idx_omp_v50_scanner');

            // Restore dropped indexes
            $table->index(['asset_type', 'ts_est', 'symbol'], 'idx_omp_type_ts_symbol');
            $table->index(['asset_type', 'ts_est', 'symbol'], 'idx_omp_asset_ts_symbol');
            $table->index(['asset_type'], 'one_minute_prices_asset_type_index');
            $table->index(['symbol'], 'one_minute_prices_symbol_index');
        });
    }
};
