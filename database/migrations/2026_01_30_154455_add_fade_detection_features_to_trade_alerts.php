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
            // Fade detection features to identify weak bounces after spikes
            $table->decimal('pct_below_intraday_high', 8, 4)->nullable()->after('atr_pct')
                ->comment('% below intraday high at entry (negative = above high)');
            $table->integer('minutes_since_high')->nullable()->after('pct_below_intraday_high')
                ->comment('Minutes elapsed since intraday high');
            $table->decimal('price_velocity_5min', 8, 4)->nullable()->after('minutes_since_high')
                ->comment('% price change over last 5 minutes (velocity)');
            $table->decimal('price_velocity_10min', 8, 4)->nullable()->after('price_velocity_5min')
                ->comment('% price change over last 10 minutes (velocity)');
            $table->integer('failed_rally_count')->nullable()->after('price_velocity_10min')
                ->comment('Number of failed rally attempts in last 15 minutes');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('trade_alerts', function (Blueprint $table) {
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
