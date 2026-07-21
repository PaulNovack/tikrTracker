<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('one_minute_prices_full')) {
            DB::statement('ALTER TABLE one_minute_prices_full ADD INDEX omp_symbol_asset_ts (symbol, asset_type, ts_est)');
        }
        if (Schema::hasTable('five_minute_prices_full')) {
            DB::statement('ALTER TABLE five_minute_prices_full ADD INDEX fmp_symbol_asset_ts (symbol, asset_type, ts_est)');
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('one_minute_prices_full')) {
            DB::statement('ALTER TABLE one_minute_prices_full DROP INDEX omp_symbol_asset_ts');
        }
        if (Schema::hasTable('five_minute_prices_full')) {
            DB::statement('ALTER TABLE five_minute_prices_full DROP INDEX fmp_symbol_asset_ts');
        }
    }
};
