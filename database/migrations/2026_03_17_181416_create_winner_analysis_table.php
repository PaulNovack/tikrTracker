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
        Schema::create('winner_analysis', function (Blueprint $table) {
            $table->id();
            $table->string('symbol', 10)->index();
            $table->string('asset_type', 20)->default('stock');
            $table->date('trading_date_est')->index();

            // Price action metrics
            $table->decimal('open_price', 12, 8)->nullable();
            $table->decimal('low_price', 12, 8);
            $table->decimal('high_price', 12, 8);
            $table->decimal('close_price', 12, 8)->nullable();
            $table->decimal('range_pct', 8, 3); // Daily range percentage
            $table->decimal('gain_from_open_pct', 8, 3)->nullable(); // High vs open

            // Timing
            $table->time('time_of_low')->nullable();
            $table->time('time_of_high')->nullable();
            $table->time('optimal_entry_time')->nullable();
            $table->integer('minutes_low_to_high')->nullable();

            // Volume analysis
            $table->bigInteger('total_volume');
            $table->decimal('volume_ratio', 8, 2)->nullable(); // vs avg volume
            $table->decimal('avg_dollar_volume_per_minute', 15, 2)->nullable();

            // Technical indicators (at optimal entry)
            $table->decimal('rsi_14', 8, 3)->nullable();
            $table->decimal('ema9', 12, 8)->nullable();
            $table->decimal('ema21', 12, 8)->nullable();
            $table->decimal('atr', 12, 8)->nullable();
            $table->decimal('atr_pct', 8, 3)->nullable();
            $table->decimal('vwap', 12, 8)->nullable();
            $table->decimal('bb_upper', 12, 8)->nullable();
            $table->decimal('bb_lower', 12, 8)->nullable();

            // Entry/exit analysis
            $table->decimal('optimal_entry', 12, 8)->nullable();
            $table->decimal('optimal_stop', 12, 8)->nullable();
            $table->decimal('risk_pct', 8, 3)->nullable();
            $table->decimal('reward_pct', 8, 3)->nullable();
            $table->decimal('risk_reward_ratio', 8, 2)->nullable();

            // Pattern characteristics
            $table->integer('consolidation_bars_before_move')->nullable();
            $table->boolean('had_pullback_entry')->default(false);
            $table->boolean('broke_previous_high')->default(false);
            $table->decimal('daily_trend_5d_pct', 8, 3)->nullable();
            $table->decimal('daily_trend_10d_pct', 8, 3)->nullable();

            // Why missed
            $table->text('missed_reasons')->nullable(); // JSON or text explaining why pipelines missed it
            $table->text('pattern_notes')->nullable(); // Qualitative observations

            // Analysis metadata
            $table->boolean('manually_reviewed')->default(false);
            $table->timestamp('reviewed_at')->nullable();

            $table->timestamps();

            $table->index(['trading_date_est', 'range_pct']);
            $table->index(['trading_date_est', 'volume_ratio']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('winner_analysis');
    }
};
