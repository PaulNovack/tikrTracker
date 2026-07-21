<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE trade_alerts MODIFY COLUMN pipeline_run ENUM('A','B','C','D','E','F','G','H','I','J','K','L','M','N','O','P','Q','R','S','X','BIASED1','MANUAL','EXTERNAL') NOT NULL DEFAULT 'A'");

        if (Schema::hasTable('trade_alerts_unfiltered')) {
            DB::statement("ALTER TABLE trade_alerts_unfiltered MODIFY COLUMN pipeline_run ENUM('A','B','C','D','E','F','G','H','I','J','K','L','M','N','O','P','Q','R','S','X','BIASED1','MANUAL','EXTERNAL') NOT NULL DEFAULT 'A'");
        }

        if (Schema::hasTable('trade_alerts_ml_pick')) {
            DB::statement("ALTER TABLE trade_alerts_ml_pick MODIFY COLUMN pipeline_run ENUM('A','B','C','D','E','F','G','H','I','J','K','L','M','N','O','P','Q','R','S','X','BIASED1','MANUAL','EXTERNAL') NOT NULL");
        }
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE trade_alerts MODIFY COLUMN pipeline_run ENUM('A','B','C','D','E','F','G','H','I','J','K','L','M','N','O','P','Q','R','S','X','BIASED1','MANUAL') NOT NULL DEFAULT 'A'");

        if (Schema::hasTable('trade_alerts_unfiltered')) {
            DB::statement("ALTER TABLE trade_alerts_unfiltered MODIFY COLUMN pipeline_run ENUM('A','B','C','D','E','F','G','H','I','J','K','L','M','N','O','P','Q','R','S','X','BIASED1','MANUAL') NOT NULL DEFAULT 'A'");
        }

        if (Schema::hasTable('trade_alerts_ml_pick')) {
            DB::statement("ALTER TABLE trade_alerts_ml_pick MODIFY COLUMN pipeline_run ENUM('A','B','C','D','E','F','G','H','I','J','K','L','M','N','O','P','Q','R','S','X','BIASED1','MANUAL') NOT NULL");
        }
    }
};
