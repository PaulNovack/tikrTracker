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
            // v830 feature: 5-day price trend (percentage change over 5 trading days)
            $table->decimal('daily_trend_5d_pct', 8, 2)->nullable()->after('atr_pct');

            // v830 feature: Position in 60-minute range (0.0 = at low, 1.0 = at high)
            $table->decimal('range_position_60m', 8, 6)->nullable()->after('daily_trend_5d_pct');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('trade_alerts', function (Blueprint $table) {
            $table->dropColumn(['daily_trend_5d_pct', 'range_position_60m']);
        });
    }
};
