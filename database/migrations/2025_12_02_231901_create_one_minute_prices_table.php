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
        Schema::create('one_minute_prices', function (Blueprint $table) {
            $table->id();
            $table->string('symbol', 20);
            $table->enum('asset_type', ['stock', 'crypto']);
            $table->dateTime('ts');
            $table->decimal('price', 20, 8);
            $table->decimal('open', 20, 8)->nullable();
            $table->decimal('high', 20, 8)->nullable();
            $table->decimal('low', 20, 8)->nullable();
            $table->bigInteger('volume')->nullable();
            $table->timestamps();

            // Check if we're using SQLite (test environment) or MySQL (production)
            if (DB::getDriverName() === 'sqlite') {
                // For SQLite, use datetime() function for timezone conversion
                $table->dateTime('ts_est')
                    ->nullable()
                    ->storedAs("datetime(ts, '-5 hours')")
                    ->comment('Timestamp converted to EST (UTC-5)');

                $table->date('trading_date_est')
                    ->nullable()
                    ->storedAs("date(ts, '-5 hours')")
                    ->comment('Trading date in EST timezone');

                $table->time('trading_time_est')
                    ->nullable()
                    ->storedAs("time(ts, '-5 hours')")
                    ->comment('Trading time in EST timezone (time part only)');
            } else {
                // For MySQL, use INTERVAL syntax
                $table->dateTime('ts_est')
                    ->nullable()
                    ->storedAs('(ts - INTERVAL 5 HOUR)')
                    ->comment('Timestamp converted to EST (UTC-5)');

                $table->date('trading_date_est')
                    ->nullable()
                    ->storedAs('CAST((ts - INTERVAL 5 HOUR) AS DATE)')
                    ->comment('Trading date in EST timezone');

                $table->time('trading_time_est')
                    ->nullable()
                    ->storedAs('CAST((ts - INTERVAL 5 HOUR) AS TIME)')
                    ->comment('Trading time in EST timezone (time part only)');
            }

            // Indexes
            $table->unique(['symbol', 'asset_type', 'ts']);
            $table->index('symbol');
            $table->index('asset_type');
            $table->index('ts');
            $table->index(['symbol', 'asset_type', 'ts']);
            $table->index(['ts', 'symbol', 'asset_type'], 'one_minute_prices_ts_symbol_type');
            $table->index(['symbol', 'asset_type', 'trading_date_est', 'ts_est'], 'idx_omp_ts_est');
            $table->index(['trading_date_est', 'asset_type', 'symbol'], 'idx_omp_trading_date_est');
            $table->index(['trading_time_est', 'asset_type', 'symbol'], 'idx_omp_trading_time_est');
            $table->index(['symbol', 'trading_date_est', 'trading_time_est'], 'idx_omp_symbol_datetime_est');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('one_minute_prices');
    }
};
