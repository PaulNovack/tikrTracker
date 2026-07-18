<?php

use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Drop and recreate trading_date_est and trading_time_est to use CONVERT_TZ
        // instead of fixed INTERVAL 5 HOUR offset
        DB::statement('
            ALTER TABLE five_minute_prices
            DROP COLUMN trading_date_est,
            DROP COLUMN trading_time_est
        ');

        DB::statement("
            ALTER TABLE five_minute_prices
            ADD COLUMN trading_date_est DATE
                GENERATED ALWAYS AS (CAST(CONVERT_TZ(ts, 'UTC', 'America/New_York') AS DATE))
                STORED
                COMMENT 'Trading date in America/New_York timezone'
                AFTER updated_at
        ");

        DB::statement("
            ALTER TABLE five_minute_prices
            ADD COLUMN trading_time_est TIME
                GENERATED ALWAYS AS (CAST(CONVERT_TZ(ts, 'UTC', 'America/New_York') AS TIME))
                STORED
                COMMENT 'Trading time in America/New_York timezone (time part only)'
                AFTER trading_date_est
        ");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Revert to the old fixed offset logic
        DB::statement('
            ALTER TABLE five_minute_prices
            DROP COLUMN trading_date_est,
            DROP COLUMN trading_time_est
        ');

        DB::statement('
            ALTER TABLE five_minute_prices
            ADD COLUMN trading_date_est DATE
                GENERATED ALWAYS AS (CAST((ts - INTERVAL 5 HOUR) AS DATE))
                STORED
                COMMENT \'Trading date in EST timezone\'
                AFTER updated_at
        ');

        DB::statement('
            ALTER TABLE five_minute_prices
            ADD COLUMN trading_time_est TIME
                GENERATED ALWAYS AS (CAST((ts - INTERVAL 5 HOUR) AS TIME))
                STORED
                COMMENT \'Trading time in EST timezone (time part only)\'
                AFTER trading_date_est
        ');
    }
};
