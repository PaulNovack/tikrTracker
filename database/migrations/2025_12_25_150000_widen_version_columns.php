<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('trade_alerts', function (Blueprint $table) {
            $table->string('version', 64)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('trade_alerts', function (Blueprint $table) {
            $table->string('version', 10)->nullable()->change();
        });
    }
};
