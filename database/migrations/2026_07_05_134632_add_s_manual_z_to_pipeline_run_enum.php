<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE trade_alerts MODIFY COLUMN pipeline_run ENUM('A','B','C','D','E','F','G','H','I','J','K','L','M','N','O','P','Q','R','S','X','Z','MANUAL','BIASED1') NOT NULL DEFAULT 'A'");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE trade_alerts MODIFY COLUMN pipeline_run ENUM('A','B','C','D','E','F','G','H','I','J','K','L','M','N','O','P','Q','R','X','BIASED1') NOT NULL DEFAULT 'A'");
    }
};
