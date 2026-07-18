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
        Schema::table('trade_alerts', function (Blueprint $table) {
            $table->tinyInteger('passed_ml')
                ->default(0)
                ->after('ml_win_prob')
                ->comment('0=not yet scored or ML<global min, 1=ML score >= pipeline threshold and passed');
        });
    }

    public function down(): void
    {
        Schema::table('trade_alerts', function (Blueprint $table) {
            $table->dropColumn('passed_ml');
        });
    }
};
