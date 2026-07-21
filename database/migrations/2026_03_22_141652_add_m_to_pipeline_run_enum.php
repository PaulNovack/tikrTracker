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
        // Add 'M' to pipeline_run enum in trade_alerts table
        DB::statement("ALTER TABLE trade_alerts MODIFY COLUMN pipeline_run ENUM('A','B','C','D','E','F','G','H','I','J','M') NOT NULL DEFAULT 'A'");

        // Add 'M' to pipeline_run enum in trade_alerts_unfiltered table (also includes BIASED1)
        DB::statement("ALTER TABLE trade_alerts_unfiltered MODIFY COLUMN pipeline_run ENUM('A','B','C','D','E','F','G','H','I','J','M','BIASED1') NOT NULL DEFAULT 'A'");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Remove 'M' from pipeline_run enum in trade_alerts table
        DB::statement("ALTER TABLE trade_alerts MODIFY COLUMN pipeline_run ENUM('A','B','C','D','E','F','G','H','I','J') NOT NULL DEFAULT 'A'");

        // Remove 'M' from pipeline_run enum in trade_alerts_unfiltered table (keep BIASED1)
        DB::statement("ALTER TABLE trade_alerts_unfiltered MODIFY COLUMN pipeline_run ENUM('A','B','C','D','E','F','G','H','I','J','BIASED1') NOT NULL DEFAULT 'A'");
    }
};
