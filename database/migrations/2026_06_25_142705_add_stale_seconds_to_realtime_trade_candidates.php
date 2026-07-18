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
        Schema::table('realtime_trade_candidates', function (Blueprint $table) {
            $table->integer('stale_seconds')->default(0)->after('status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('realtime_trade_candidates', function (Blueprint $table) {
            $table->dropColumn('stale_seconds');
        });
    }
};
