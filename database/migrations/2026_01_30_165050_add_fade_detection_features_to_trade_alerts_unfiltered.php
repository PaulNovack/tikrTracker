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
        Schema::table('trade_alerts_unfiltered', function (Blueprint $table) {
            $table->decimal('pct_below_intraday_high', 8, 4)->nullable();
            $table->integer('minutes_since_high')->nullable();
            $table->decimal('price_velocity_5min', 8, 4)->nullable();
            $table->decimal('price_velocity_10min', 8, 4)->nullable();
            $table->integer('failed_rally_count')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('trade_alerts_unfiltered', function (Blueprint $table) {
            $table->dropColumn([
                'pct_below_intraday_high',
                'minutes_since_high',
                'price_velocity_5min',
                'price_velocity_10min',
                'failed_rally_count',
            ]);
        });
    }
};
