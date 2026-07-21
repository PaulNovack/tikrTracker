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
        Schema::table('buy_zone_alerts', function (Blueprint $table) {
            // Change timestamp columns to dateTime to prevent auto-conversion
            // timestamp auto-converts to/from UTC, dateTime stores exactly what you give it
            $table->dateTime('analysis_ts_est')->change();
            $table->dateTime('exit_ts_est')->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('buy_zone_alerts', function (Blueprint $table) {
            $table->timestamp('analysis_ts_est')->change();
            $table->timestamp('exit_ts_est')->nullable()->change();
        });
    }
};
