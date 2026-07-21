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
        Schema::create('trade_alerts_ml_pick', function (Blueprint $table) {
            $table->id();

            $table->string('symbol', 20);
            $table->enum('asset_type', ['stock', 'crypto']);

            // "pipeline run time" (as-of) in NY market time
            $table->date('trading_date_est')->index();
            $table->dateTime('as_of_ts_est')->index();

            // signal + entry
            $table->string('signal_type', 50)->index();
            $table->dateTime('signal_ts_est')->index();
            $table->time('time_of_day')->nullable()->index();

            $table->string('entry_type', 50)->index();
            $table->dateTime('entry_ts_est')->index();
            $table->decimal('entry', 20, 8);
            $table->decimal('stop', 20, 8);

            // scoring + metadata
            $table->decimal('risk_pct', 8, 3)->nullable();
            $table->decimal('risk_per_share', 20, 8)->nullable();
            $table->decimal('score', 10, 3)->nullable();
            $table->decimal('vol_ratio', 10, 3)->nullable();
            $table->decimal('avg_dollar_volume_per_minute', 15, 2)->nullable();
            $table->decimal('calculated_position_size', 10, 2)->nullable();

            // Pattern quality metrics
            $table->tinyInteger('five_min_directional_changes')->nullable();
            $table->decimal('five_min_green_bar_pct', 4, 1)->nullable();
            $table->decimal('five_min_net_progress', 4, 3)->nullable();
            $table->integer('consolidation_bars')->nullable();
            $table->decimal('breakout_volume_ratio', 10, 3)->nullable();

            // Volatility & position sizing
            $table->decimal('atr', 10, 6)->nullable();
            $table->decimal('atr_pct', 10, 6)->nullable();

            // Context & momentum indicators
            $table->decimal('daily_trend_5d_pct', 8, 2)->nullable();
            $table->decimal('range_position_60m', 8, 6)->nullable();
            $table->decimal('pct_below_intraday_high', 8, 4)->nullable();
            $table->integer('minutes_since_high')->nullable();
            $table->decimal('price_velocity_5min', 8, 4)->nullable();
            $table->decimal('price_velocity_10min', 8, 4)->nullable();
            $table->integer('failed_rally_count')->nullable();
            $table->decimal('rsi_14_1m', 5, 2)->nullable()->index();

            // Trailing stop suggestions
            $table->decimal('suggested_trailing_stop', 10, 6)->nullable();
            $table->decimal('suggested_trailing_stop_pct', 10, 6)->nullable();

            $table->json('targets')->nullable();

            // Exit tracking fields
            $table->decimal('exit_price', 12, 4)->nullable();
            $table->timestamp('exit_ts_est')->nullable();
            $table->string('exit_reason', 20)->nullable()->index();
            $table->decimal('pnl_percent', 8, 2)->nullable()->index();
            $table->decimal('pnl_dollar', 12, 4)->nullable();
            $table->decimal('max_adverse_excursion', 10, 4)->nullable();
            $table->integer('hold_time_minutes')->nullable();
            $table->decimal('r_multiple', 8, 2)->nullable()->index();
            $table->string('target_hit', 10)->nullable();
            $table->boolean('analyzed')->default(false)->index();
            $table->timestamp('analyzed_at')->nullable();

            $table->json('meta')->nullable();

            // de-dupe so you don't spam alerts every minute
            $table->string('dedupe_key', 120)->unique();

            $table->string('version', 64)->nullable()->index();
            $table->enum('pipeline_run', ['A', 'B', 'C', 'D', 'E', 'F', 'G', 'H', 'I', 'J', 'M', 'N'])->index();

            $table->timestamps();

            // ML scoring fields
            $table->decimal('ml_win_prob', 10, 6)->nullable()->index();
            $table->timestamp('ml_scored_at')->nullable()->index();
            $table->string('ml_model_version', 64)->nullable()->index();

            $table->boolean('blacklisted')->default(false)->index();

            $table->index(['asset_type', 'symbol', 'as_of_ts_est']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('trade_alerts_ml_pick');
    }
};
