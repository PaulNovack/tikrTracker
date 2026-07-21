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
        // Add F, G, H, I to pipeline_run enum in trade_alerts table
        DB::statement("ALTER TABLE trade_alerts MODIFY COLUMN pipeline_run ENUM('A','B','C','D','E','F','G','H','I') NOT NULL DEFAULT 'A'");

        // Add F, G, H, I to pipeline_run enum in trade_alerts_unfiltered table
        DB::statement("ALTER TABLE trade_alerts_unfiltered MODIFY COLUMN pipeline_run ENUM('A','B','C','D','E','F','G','H','I') NOT NULL DEFAULT 'A'");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Remove F, G, H, I from pipeline_run enum in trade_alerts table
        DB::statement("ALTER TABLE trade_alerts MODIFY COLUMN pipeline_run ENUM('A','B','C','D','E') NOT NULL DEFAULT 'A'");

        // Remove F, G, H, I from pipeline_run enum in trade_alerts_unfiltered table
        DB::statement("ALTER TABLE trade_alerts_unfiltered MODIFY COLUMN pipeline_run ENUM('A','B','C','D','E') NOT NULL DEFAULT 'A'");
    }
};
