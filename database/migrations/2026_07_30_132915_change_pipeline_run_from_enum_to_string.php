<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE trade_alerts MODIFY COLUMN pipeline_run VARCHAR(50) NOT NULL DEFAULT 'A'");

        if (Schema::hasTable('trade_alerts_unfiltered')) {
            DB::statement("ALTER TABLE trade_alerts_unfiltered MODIFY COLUMN pipeline_run VARCHAR(50) NOT NULL DEFAULT 'A'");
        }

        if (Schema::hasTable('trade_alerts_ml_pick')) {
            DB::statement("ALTER TABLE trade_alerts_ml_pick MODIFY COLUMN pipeline_run VARCHAR(50) NOT NULL");
        }
    }

    public function down(): void
    {
        $enumValues = "'A','B','C','D','E','F','G','H','I','J','K','L','M','N','O','P','Q','R','S','X','BIASED1','MANUAL','EXTERNAL'";

        DB::statement("ALTER TABLE trade_alerts MODIFY COLUMN pipeline_run ENUM({$enumValues}) NOT NULL DEFAULT 'A'");

        if (Schema::hasTable('trade_alerts_unfiltered')) {
            DB::statement("ALTER TABLE trade_alerts_unfiltered MODIFY COLUMN pipeline_run ENUM({$enumValues}) NOT NULL DEFAULT 'A'");
        }

        if (Schema::hasTable('trade_alerts_ml_pick')) {
            DB::statement("ALTER TABLE trade_alerts_ml_pick MODIFY COLUMN pipeline_run ENUM({$enumValues}) NOT NULL");
        }
    }
};
