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
            $table->decimal('atr_pct', 10, 6)->nullable()->after('vwap_dist_pct')->comment('ATR% at detection — volatility gate (Pipeline H min 0.25%)');
            $table->decimal('rvol', 10, 4)->nullable()->after('atr_pct')->comment('Relative volume vs 20-bar avg at detection (Pipeline H min 1.5x)');
            $table->decimal('move_30m_pct', 10, 4)->nullable()->after('rvol')->comment('30-minute price move % at detection (Pipeline H min 0.5%)');
            $table->tinyInteger('ema9_above_ema21')->nullable()->after('move_30m_pct')->comment('1 if EMA9 > EMA21 (uptrend) at detection');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('realtime_trade_candidates', function (Blueprint $table) {
            $table->dropColumn(['atr_pct', 'rvol', 'move_30m_pct', 'ema9_above_ema21']);
        });
    }
};
