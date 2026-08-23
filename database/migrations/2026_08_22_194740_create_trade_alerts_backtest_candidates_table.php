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
        if (Schema::hasTable('trade_alerts_backtest_candidates')) {
            Schema::table('trade_alerts_backtest_candidates', function (Blueprint $table) {
                $table->index(['asset_type', 'symbol', 'as_of_ts_est'], 'trade_alerts_backtest_candidates_asset_symbol_asof_idx');
            });

            return;
        }

        Schema::create('trade_alerts_backtest_candidates', function (Blueprint $table) {
            $table->id();

            $table->string('symbol', 20)->index();
            $table->enum('asset_type', ['stock', 'crypto']);

            $table->date('trading_date_est')->index();
            $table->dateTime('as_of_ts_est')->index();

            $table->string('signal_type', 50)->index();
            $table->dateTime('signal_ts_est')->index();
            $table->time('time_of_day')->nullable()->index();

            $table->string('entry_type', 50)->index();
            $table->dateTime('entry_ts_est')->index();
            $table->decimal('entry', 20, 8)->nullable();
            $table->decimal('stop', 20, 8)->nullable();

            $table->decimal('risk_pct', 8, 3)->nullable();
            $table->decimal('risk_per_share', 20, 8)->nullable();
            $table->decimal('score', 10, 3)->nullable();
            $table->decimal('vol_ratio', 10, 3)->nullable();
            $table->decimal('avg_dollar_volume_per_minute', 15, 2)->nullable();
            $table->decimal('calculated_position_size', 10, 2)->nullable();

            $table->tinyInteger('five_min_directional_changes')->nullable();
            $table->decimal('five_min_green_bar_pct', 4, 1)->nullable();
            $table->decimal('five_min_net_progress', 4, 3)->nullable();
            $table->integer('consolidation_bars')->nullable();
            $table->decimal('breakout_volume_ratio', 10, 3)->nullable();

            $table->decimal('atr', 10, 6)->nullable();
            $table->decimal('atr_pct', 10, 6)->nullable();
            $table->decimal('daily_trend_5d_pct', 8, 2)->nullable();
            $table->decimal('range_position_60m', 8, 6)->nullable();
            $table->decimal('rsi_14_1m', 5, 2)->nullable()->index();
            $table->decimal('suggested_trailing_stop', 10, 6)->nullable();
            $table->decimal('suggested_trailing_stop_pct', 10, 6)->nullable();

            $table->json('targets')->nullable();

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

            $table->boolean('passed_gates')->default(false)->index();
            $table->string('failed_timeframe', 2)->nullable()->index();
            $table->string('failed_gate', 64)->nullable()->index();
            $table->string('failure_reason', 64)->nullable();
            $table->decimal('failure_value', 20, 8)->nullable();
            $table->decimal('failure_min', 20, 8)->nullable();
            $table->decimal('failure_max', 20, 8)->nullable();
            $table->json('gate_values')->nullable();

            $table->json('meta')->nullable();
            $table->string('dedupe_key', 120)->unique();
            $table->string('version', 64)->nullable()->index();
            $table->string('pipeline_run', 2)->index();

            $table->timestamps();

            $table->index(['asset_type', 'symbol', 'as_of_ts_est'], 'trade_alerts_backtest_candidates_asset_symbol_asof_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('trade_alerts_backtest_candidates');
    }
};
