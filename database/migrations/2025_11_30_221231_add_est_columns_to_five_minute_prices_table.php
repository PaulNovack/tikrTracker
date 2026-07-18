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
        // Check if we're using SQLite (test environment) or MySQL (production)
        if (DB::getDriverName() === 'sqlite') {
            // For SQLite, use datetime() function for timezone conversion
            Schema::table('five_minute_prices', function (Blueprint $table) {
                // Add computed column for EST timezone conversion (UTC - 5 hours)
                $table->dateTime('ts_est')
                    ->nullable()
                    ->storedAs("datetime(ts, '-5 hours')")
                    ->comment('Timestamp converted to EST (UTC-5)');

                // Add computed column for trading date in EST timezone
                $table->date('trading_date_est')
                    ->nullable()
                    ->storedAs("date(ts, '-5 hours')")
                    ->comment('Trading date in EST timezone');

                // Add computed column for trading time in EST timezone (time part only)
                $table->time('trading_time_est')
                    ->nullable()
                    ->storedAs("time(ts, '-5 hours')")
                    ->comment('Trading time in EST timezone (time part only)');
            });
        } else {
            // For MySQL, use INTERVAL syntax
            Schema::table('five_minute_prices', function (Blueprint $table) {
                // Add computed column for EST timezone conversion (UTC - 5 hours)
                $table->dateTime('ts_est')
                    ->nullable()
                    ->storedAs('(ts - INTERVAL 5 HOUR)')
                    ->comment('Timestamp converted to EST (UTC-5)');

                // Add computed column for trading date in EST timezone
                $table->date('trading_date_est')
                    ->nullable()
                    ->storedAs('CAST((ts - INTERVAL 5 HOUR) AS DATE)')
                    ->comment('Trading date in EST timezone');

                // Add computed column for trading time in EST timezone (time part only)
                $table->time('trading_time_est')
                    ->nullable()
                    ->storedAs('CAST((ts - INTERVAL 5 HOUR) AS TIME)')
                    ->comment('Trading time in EST timezone (time part only)');
            });
        }

        // Add indexes for the new EST columns for performance
        Schema::table('five_minute_prices', function (Blueprint $table) {
            $table->index(['symbol', 'asset_type', 'trading_date_est', 'ts_est'], 'idx_fmp_ts_est');
            $table->index(['trading_date_est', 'asset_type', 'symbol'], 'idx_fmp_trading_date_est');
            $table->index(['trading_time_est', 'asset_type', 'symbol'], 'idx_fmp_trading_time_est');
            $table->index(['symbol', 'trading_date_est', 'trading_time_est'], 'idx_fmp_symbol_datetime_est');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('five_minute_prices', function (Blueprint $table) {
            // Drop indexes first
            $table->dropIndex('idx_fmp_ts_est');
            $table->dropIndex('idx_fmp_trading_date_est');
            $table->dropIndex('idx_fmp_trading_time_est');
            $table->dropIndex('idx_fmp_symbol_datetime_est');

            // Drop the generated columns
            $table->dropColumn(['ts_est', 'trading_date_est', 'trading_time_est']);
        });
    }
};
