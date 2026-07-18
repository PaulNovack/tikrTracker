<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const ENUM_VALUES = "'A','B','C','D','E','F','G','H','I','J','K','L','M','N','O','P','Q','X','BIASED1'";

    public function up(): void
    {
        DB::statement('ALTER TABLE trade_alerts MODIFY COLUMN pipeline_run ENUM('.self::ENUM_VALUES.") NOT NULL DEFAULT 'A'");

        if (Schema::hasTable('trade_alerts_unfiltered')) {
            DB::statement('ALTER TABLE trade_alerts_unfiltered MODIFY COLUMN pipeline_run ENUM('.self::ENUM_VALUES.") NOT NULL DEFAULT 'A'");
        }

        if (Schema::hasTable('trade_alerts_ml_pick')) {
            DB::statement('ALTER TABLE trade_alerts_ml_pick MODIFY COLUMN pipeline_run ENUM('.self::ENUM_VALUES.') NOT NULL');
        }
    }

    public function down(): void
    {
        $oldValues = "'A','B','C','D','E','F','G','H','I','J','K','L','M','N','O','X','BIASED1'";

        DB::statement("ALTER TABLE trade_alerts MODIFY COLUMN pipeline_run ENUM({$oldValues}) NOT NULL DEFAULT 'A'");

        if (Schema::hasTable('trade_alerts_unfiltered')) {
            DB::statement("ALTER TABLE trade_alerts_unfiltered MODIFY COLUMN pipeline_run ENUM({$oldValues}) NOT NULL DEFAULT 'A'");
        }

        if (Schema::hasTable('trade_alerts_ml_pick')) {
            DB::statement("ALTER TABLE trade_alerts_ml_pick MODIFY COLUMN pipeline_run ENUM({$oldValues}) NOT NULL");
        }
    }
};
