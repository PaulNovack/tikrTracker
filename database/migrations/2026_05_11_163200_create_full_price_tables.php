<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // ── one_minute_prices_full ─────────────────────────────────
        if (! Schema::hasTable('one_minute_prices_full')) {
            Schema::create('one_minute_prices_full', function (Blueprint $table) {
                $table->id();
                $table->string('symbol', 20);
                $table->enum('asset_type', ['stock', 'crypto']);
                $table->dateTime('ts');
                $table->string('source', 50)->nullable();
                $table->decimal('price', 20, 8);
                $table->decimal('open', 20, 8)->nullable();
                $table->decimal('high', 20, 8)->nullable();
                $table->decimal('low', 20, 8)->nullable();
                $table->bigInteger('volume')->nullable();
                $table->decimal('vwap', 20, 8)->nullable();
                $table->decimal('vwap_dist', 20, 8)->nullable();
                $table->decimal('vwap_dist_pct', 20, 8)->nullable();
                $table->tinyInteger('above_vwap')->nullable();
                $table->decimal('ema9', 20, 8)->nullable();
                $table->decimal('ema21', 20, 8)->nullable();
                $table->decimal('ema9_ema21_spread', 20, 8)->nullable();
                $table->tinyInteger('ema9_above_ema21')->nullable();
                $table->decimal('atr', 20, 8)->nullable();
                $table->decimal('atr_pct', 20, 8)->nullable();
                $table->decimal('rsi_14', 20, 8)->nullable();
                $table->decimal('bb_upper', 20, 8)->nullable();
                $table->decimal('bb_middle', 20, 8)->nullable();
                $table->decimal('bb_lower', 20, 8)->nullable();
                $table->decimal('change_from_open', 16, 8)->nullable();
                $table->decimal('relative_volume', 16, 4)->nullable();
                $table->timestamps();

                $table->unique(['symbol', 'asset_type', 'ts'], 'omp_full_symbol_asset_ts_unique');

                if (DB::getDriverName() === 'sqlite') {
                    $table->dateTime('ts_est')->nullable()->storedAs("datetime(ts, '-5 hours')");
                    $table->date('trading_date_est')->nullable()->storedAs("date(ts, '-5 hours')");
                    $table->time('trading_time_est')->nullable()->storedAs("time(ts, '-5 hours')");
                } else {
                    $table->dateTime('ts_est')->nullable()->storedAs('(ts - INTERVAL 5 HOUR)');
                    $table->date('trading_date_est')->nullable()->storedAs('CAST((ts - INTERVAL 5 HOUR) AS DATE)');
                    $table->time('trading_time_est')->nullable()->storedAs('CAST((ts - INTERVAL 5 HOUR) AS TIME)');
                }
            });
        }

        // ── five_minute_prices_full ────────────────────────────────
        if (! Schema::hasTable('five_minute_prices_full')) {
            Schema::create('five_minute_prices_full', function (Blueprint $table) {
                $table->id();
                $table->string('symbol', 20);
                $table->enum('asset_type', ['stock', 'crypto']);
                $table->dateTime('ts');
                $table->string('source', 50)->nullable();
                $table->decimal('price', 20, 8);
                $table->decimal('open', 20, 8)->nullable();
                $table->decimal('high', 20, 8)->nullable();
                $table->decimal('low', 20, 8)->nullable();
                $table->bigInteger('volume')->nullable();
                $table->decimal('vwap', 20, 8)->nullable();
                $table->decimal('vwap_dist', 20, 8)->nullable();
                $table->decimal('vwap_dist_pct', 20, 8)->nullable();
                $table->tinyInteger('above_vwap')->nullable();
                $table->decimal('ema9', 20, 8)->nullable();
                $table->decimal('ema21', 20, 8)->nullable();
                $table->decimal('ema9_ema21_spread', 20, 8)->nullable();
                $table->tinyInteger('ema9_above_ema21')->nullable();
                $table->decimal('atr', 20, 8)->nullable();
                $table->decimal('atr_pct', 20, 8)->nullable();
                $table->decimal('rsi_14', 20, 8)->nullable();
                $table->decimal('bb_upper', 20, 8)->nullable();
                $table->decimal('bb_middle', 20, 8)->nullable();
                $table->decimal('bb_lower', 20, 8)->nullable();
                $table->decimal('change_from_open', 16, 8)->nullable();
                $table->decimal('relative_volume', 16, 4)->nullable();
                $table->timestamps();

                $table->unique(['symbol', 'asset_type', 'ts'], 'fmp_full_symbol_asset_ts_unique');

                if (DB::getDriverName() === 'sqlite') {
                    $table->dateTime('ts_est')->nullable()->storedAs("datetime(ts, '-5 hours')");
                    $table->date('trading_date_est')->nullable()->storedAs("date(ts, '-5 hours')");
                    $table->time('trading_time_est')->nullable()->storedAs("time(ts, '-5 hours')");
                } else {
                    $table->dateTime('ts_est')->nullable()->storedAs('(ts - INTERVAL 5 HOUR)');
                    $table->date('trading_date_est')->nullable()->storedAs('CAST((ts - INTERVAL 5 HOUR) AS DATE)');
                    $table->time('trading_time_est')->nullable()->storedAs('CAST((ts - INTERVAL 5 HOUR) AS TIME)');
                }
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('one_minute_prices_full');
        Schema::dropIfExists('five_minute_prices_full');
    }
};
