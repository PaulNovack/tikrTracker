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
        // Add 'N' to pipeline_run enum in trade_alerts table
        DB::statement("ALTER TABLE trade_alerts MODIFY COLUMN pipeline_run ENUM('A', 'B', 'C', 'D', 'E', 'F', 'G', 'H', 'I', 'J', 'K', 'M', 'N', 'BIASED1') DEFAULT NULL");

        // Add 'N' to pipeline_run enum in trade_alerts_unfiltered table if it exists
        $tables = DB::select("SHOW TABLES LIKE 'trade_alerts_unfiltered'");
        if (! empty($tables)) {
            DB::statement("ALTER TABLE trade_alerts_unfiltered MODIFY COLUMN pipeline_run ENUM('A', 'B', 'C', 'D', 'E', 'F', 'G', 'H', 'I', 'J', 'K', 'M', 'N', 'BIASED1') DEFAULT NULL");
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Remove 'N' from pipeline_run enum in trade_alerts table
        DB::statement("ALTER TABLE trade_alerts MODIFY COLUMN pipeline_run ENUM('A', 'B', 'C', 'D', 'E', 'F', 'G', 'H', 'I', 'J', 'K', 'M', 'BIASED1') DEFAULT NULL");

        // Remove 'N' from pipeline_run enum in trade_alerts_unfiltered table if it exists
        $tables = DB::select("SHOW TABLES LIKE 'trade_alerts_unfiltered'");
        if (! empty($tables)) {
            DB::statement("ALTER TABLE trade_alerts_unfiltered MODIFY COLUMN pipeline_run ENUM('A', 'B', 'C', 'D', 'E', 'F', 'G', 'H', 'I', 'J', 'K', 'M', 'BIASED1') DEFAULT NULL");
        }
    }
};
