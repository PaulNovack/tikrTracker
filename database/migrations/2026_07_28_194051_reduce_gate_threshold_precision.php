<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('alert_version_gates', function (Blueprint $table) {
            $table->decimal('threshold_min', 12, 3)->nullable()->change();
            $table->decimal('threshold_max', 12, 3)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('alert_version_gates', function (Blueprint $table) {
            $table->decimal('threshold_min', 16, 6)->nullable()->change();
            $table->decimal('threshold_max', 16, 6)->nullable()->change();
        });
    }
};
