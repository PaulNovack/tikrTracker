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
        // Add 'O' to pipeline_run enum in trade_alerts table
        DB::statement("ALTER TABLE trade_alerts MODIFY COLUMN pipeline_run ENUM('A','B','C','D','E','F','G','H','I','J','K','M','N','O','BIASED1') NOT NULL DEFAULT 'A'");

        // Add 'O' to pipeline_run enum in trade_alerts_unfiltered table
        DB::statement("ALTER TABLE trade_alerts_unfiltered MODIFY COLUMN pipeline_run ENUM('A','B','C','D','E','F','G','H','I','J','K','M','N','O','BIASED1') NOT NULL DEFAULT 'A'");

        // Add 'O' to pipeline_run enum in trade_alerts_ml_pick table
        DB::statement("ALTER TABLE trade_alerts_ml_pick MODIFY COLUMN pipeline_run ENUM('A','B','C','D','E','F','G','H','I','J','K','M','N','O','BIASED1') NOT NULL");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Remove 'O' from pipeline_run enum in trade_alerts table
        DB::statement("ALTER TABLE trade_alerts MODIFY COLUMN pipeline_run ENUM('A','B','C','D','E','F','G','H','I','J','K','M','N','BIASED1') NOT NULL DEFAULT 'A'");

        // Remove 'O' from pipeline_run enum in trade_alerts_unfiltered table
        DB::statement("ALTER TABLE trade_alerts_unfiltered MODIFY COLUMN pipeline_run ENUM('A','B','C','D','E','F','G','H','I','J','K','M','N','BIASED1') NOT NULL DEFAULT 'A'");

        // Remove 'O' from pipeline_run enum in trade_alerts_ml_pick table
        DB::statement("ALTER TABLE trade_alerts_ml_pick MODIFY COLUMN pipeline_run ENUM('A','B','C','D','E','F','G','H','I','J','K','M','N','BIASED1') NOT NULL");
    }
};
