<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Rename existing tables, then recreate with CONVERT_TZ for DST support.
     */
    public function up(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            return;
        }

        $this->info('Step 1: Checking current table state...');

        // Check which tables exist
        $fiveMinExists = Schema::hasTable('five_minute_prices');
        $fiveMinDataExists = Schema::hasTable('five_minute_prices_data');
        $oneMinExists = Schema::hasTable('one_minute_prices');
        $oneMinDataExists = Schema::hasTable('one_minute_prices_data');

        // Only rename if _data tables don't already exist
        if ($fiveMinExists && ! $fiveMinDataExists) {
            $this->info('Renaming five_minute_prices to five_minute_prices_data...');
            DB::statement('RENAME TABLE five_minute_prices TO five_minute_prices_data');
            $fiveMinDataExists = true;
            $fiveMinExists = false;
        }

        if ($oneMinExists && ! $oneMinDataExists) {
            $this->info('Renaming one_minute_prices to one_minute_prices_data...');
            DB::statement('RENAME TABLE one_minute_prices TO one_minute_prices_data');
            $oneMinDataExists = true;
            $oneMinExists = false;
        }

        // Only create five_minute_prices if it doesn't exist
        if (! $fiveMinExists && $fiveMinDataExists) {
            $this->info('Step 2: Creating five_minute_prices with DST-aware columns...');
            DB::statement('
                CREATE TABLE five_minute_prices LIKE five_minute_prices_data
            ');

            // Drop the old ts_est columns and recreate with CONVERT_TZ
            DB::statement('ALTER TABLE five_minute_prices DROP COLUMN ts_est');
            DB::statement('ALTER TABLE five_minute_prices DROP COLUMN trading_date_est');
            DB::statement('ALTER TABLE five_minute_prices DROP COLUMN trading_time_est');

            DB::statement("
                ALTER TABLE five_minute_prices
                ADD COLUMN ts_est DATETIME
                GENERATED ALWAYS AS (CONVERT_TZ(ts, 'UTC', 'America/New_York'))
                STORED
                COMMENT 'Timestamp converted to America/New_York (handles EST/EDT)'
            ");

            DB::statement("
                ALTER TABLE five_minute_prices
                ADD COLUMN trading_date_est DATE
                GENERATED ALWAYS AS (CAST(CONVERT_TZ(ts, 'UTC', 'America/New_York') AS DATE))
                STORED
                COMMENT 'Trading date in America/New_York timezone'
            ");

            DB::statement("
                ALTER TABLE five_minute_prices
                ADD COLUMN trading_time_est TIME
                GENERATED ALWAYS AS (CAST(CONVERT_TZ(ts, 'UTC', 'America/New_York') AS TIME))
                STORED
                COMMENT 'Trading time in America/New_York timezone (time part only)'
            ");
        }

        // Only create one_minute_prices if it doesn't exist
        if (! $oneMinExists && $oneMinDataExists) {
            $this->info('Step 3: Creating one_minute_prices with DST-aware columns...');
            DB::statement('
                CREATE TABLE one_minute_prices LIKE one_minute_prices_data
            ');

            // Drop the old ts_est columns and recreate with CONVERT_TZ
            DB::statement('ALTER TABLE one_minute_prices DROP COLUMN ts_est');
            DB::statement('ALTER TABLE one_minute_prices DROP COLUMN trading_date_est');
            DB::statement('ALTER TABLE one_minute_prices DROP COLUMN trading_time_est');

            DB::statement("
                ALTER TABLE one_minute_prices
                ADD COLUMN ts_est DATETIME
                GENERATED ALWAYS AS (CONVERT_TZ(ts, 'UTC', 'America/New_York'))
                STORED
                COMMENT 'Timestamp converted to America/New_York (handles EST/EDT)'
            ");

            DB::statement("
                ALTER TABLE one_minute_prices
                ADD COLUMN trading_date_est DATE
                GENERATED ALWAYS AS (CAST(CONVERT_TZ(ts, 'UTC', 'America/New_York') AS DATE))
                STORED
                COMMENT 'Trading date in America/New_York timezone'
            ");

            DB::statement("
                ALTER TABLE one_minute_prices
                ADD COLUMN trading_time_est TIME
                GENERATED ALWAYS AS (CAST(CONVERT_TZ(ts, 'UTC', 'America/New_York') AS TIME))
                STORED
                COMMENT 'Trading time in America/New_York timezone (time part only)'
            ");
        }

        $this->info('Migration complete! Tables created, copy data separately with command.');
    }

    private function info(string $message): void
    {
        if (method_exists($this, 'command') && $this->command) {
            $this->command->info($message);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            return;
        }

        DB::statement('DROP TABLE IF EXISTS five_minute_prices');
        DB::statement('DROP TABLE IF EXISTS one_minute_prices');
        DB::statement('RENAME TABLE five_minute_prices_data TO five_minute_prices');
        DB::statement('RENAME TABLE one_minute_prices_data TO one_minute_prices');
    }
};
