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
        Schema::table('alert_versions', function (Blueprint $table) {
            $table->string('entry_finder_class', 255)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('alert_versions', function (Blueprint $table) {
            $table->string('entry_finder_class', 255)->nullable(false)->change();
        });
    }
};
