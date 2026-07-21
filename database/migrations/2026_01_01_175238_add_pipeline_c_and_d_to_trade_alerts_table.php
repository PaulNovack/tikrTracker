<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // MySQL doesn't support ALTER COLUMN for ENUMs through Blueprint,
        // so we need to use raw SQL
        DB::statement("ALTER TABLE trade_alerts MODIFY COLUMN pipeline_run ENUM('A','B','C','D') NOT NULL DEFAULT 'A'");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Revert back to original enum values
        DB::statement("ALTER TABLE trade_alerts MODIFY COLUMN pipeline_run ENUM('A','B') NOT NULL DEFAULT 'A'");
    }
};
