<?php

use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        DB::statement("ALTER TABLE trade_alerts MODIFY pipeline_run ENUM('A','B','C','D','E','F','G','H','I','J','K','L','M','N','O','P','Q','R','X','BIASED1') NOT NULL DEFAULT 'A'");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement("ALTER TABLE trade_alerts MODIFY pipeline_run ENUM('A','B','C','D','E','F','G','H','I','J','K','L','M','N','O','P','Q','X','BIASED1') NOT NULL DEFAULT 'A'");
    }
};
