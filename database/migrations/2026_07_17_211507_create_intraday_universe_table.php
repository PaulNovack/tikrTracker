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
        Schema::create('intraday_universe', function (Blueprint $table) {
            $table->id();
            $table->string('symbol', 20);
            $table->enum('asset_type', ['stock', 'crypto']);
            $table->decimal('universe_score', 7, 2)->nullable();
            $table->bigInteger('days_seen')->default(0);
            $table->decimal('total_1m_bars', 12, 0)->nullable();
            $table->decimal('avg_bars_per_day', 7, 1)->nullable();
            $table->decimal('avg_price', 10, 2)->nullable();
            $table->decimal('avg_daily_dollar_volume', 16, 0)->nullable();
            $table->decimal('avg_dollar_volume_1m', 12, 0)->nullable();
            $table->decimal('max_dollar_volume_1m', 12, 0)->nullable();
            $table->decimal('avg_volume_1m', 12, 0)->nullable();
            $table->decimal('avg_atr_pct', 9, 4)->nullable();
            $table->decimal('avg_range_1m_pct', 9, 4)->nullable();
            $table->decimal('max_range_1m_pct', 9, 4)->nullable();
            $table->decimal('avg_liquid_minutes_25k_per_day', 7, 1)->nullable();
            $table->decimal('avg_liquid_minutes_50k_per_day', 7, 1)->nullable();
            $table->decimal('avg_liquid_minutes_100k_per_day', 7, 1)->nullable();
            $table->decimal('days_avg_1m_dollar_vol_over_25k', 7, 0)->nullable();
            $table->decimal('days_avg_1m_dollar_vol_over_50k', 7, 0)->nullable();
            $table->decimal('avg_above_vwap_ratio', 6, 3)->nullable();
            $table->decimal('avg_ema_bull_ratio', 6, 3)->nullable();
            $table->timestamps();

            $table->index('symbol', 'idx_symbol');
            $table->index('universe_score', 'idx_universe_score');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('intraday_universe');
    }
};
