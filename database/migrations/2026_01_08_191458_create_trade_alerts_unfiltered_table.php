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
        Schema::create('trade_alerts_unfiltered', function (Blueprint $table) {
            $table->id();
            $table->string('symbol', 20);
            $table->enum('asset_type', ['stock', 'crypto']);

            // "pipeline run time" (as-of) in NY market time
            $table->date('trading_date_est')->index();
            $table->dateTime('as_of_ts_est')->index();

            // signal + entry
            $table->string('signal_type', 50)->index();
            $table->dateTime('signal_ts_est')->index();
            $table->time('time_of_day')->nullable()->index()->comment('Hour:minute of signal time for filtering morning vs afternoon patterns');

            $table->string('entry_type', 50)->index();
            $table->dateTime('entry_ts_est')->index();
            $table->decimal('entry', 20, 8);
            $table->decimal('stop', 20, 8);

            // scoring + metadata
            $table->decimal('risk_pct', 8, 3)->nullable();
            $table->decimal('risk_per_share', 20, 8)->nullable();
            $table->decimal('score', 10, 3)->nullable();
            $table->decimal('vol_ratio', 10, 3)->nullable();

            // 5-minute pattern analysis
            $table->tinyInteger('five_min_directional_changes')->nullable();
            $table->decimal('five_min_green_bar_pct', 4, 1)->nullable();
            $table->decimal('five_min_net_progress', 4, 3)->nullable();
            $table->integer('consolidation_bars')->nullable()->comment('Number of bars in consolidation before breakout');
            $table->decimal('breakout_volume_ratio', 10, 3)->nullable()->comment('Volume ratio on breakout bar vs average');

            // technical indicators
            $table->decimal('atr', 10, 6)->nullable();
            $table->decimal('atr_pct', 10, 6)->nullable();
            $table->decimal('rsi_14_1m', 5, 2)->nullable()->index()->comment('RSI(14) calculated from 1-minute bars at entry time');
            $table->decimal('suggested_trailing_stop', 10, 6)->nullable();
            $table->decimal('suggested_trailing_stop_pct', 10, 6)->nullable();

            // targets and exit data
            $table->json('targets')->nullable();
            $table->decimal('exit_price', 12, 4)->nullable();
            $table->timestamp('exit_ts_est')->nullable();
            $table->string('exit_reason', 20)->nullable()->index();
            $table->decimal('pnl_percent', 8, 2)->nullable()->index();
            $table->decimal('pnl_dollar', 12, 4)->nullable();
            $table->decimal('max_adverse_excursion', 10, 4)->nullable()->comment('Largest unrealized loss during trade (for stop optimization)');
            $table->integer('hold_time_minutes')->nullable()->comment('Duration of trade in minutes');
            $table->decimal('r_multiple', 8, 2)->nullable()->index();
            $table->string('target_hit', 10)->nullable();

            // analysis tracking
            $table->boolean('analyzed')->default(false)->index();
            $table->timestamp('analyzed_at')->nullable();

            // metadata
            $table->json('meta')->nullable();
            $table->string('dedupe_key', 120)->unique();
            $table->string('version', 64)->nullable()->index();
            $table->enum('pipeline_run', ['A', 'B', 'C', 'D', 'E'])->default('A')->index();

            $table->timestamps();

            // Composite indexes
            $table->index(['asset_type', 'symbol', 'as_of_ts_est']);
            $table->index(['signal_type', 'version'], 'idx_signal_type_version_unfiltered');
            $table->index(['analyzed', 'version', 'signal_type'], 'idx_analyzed_version_signal_unfiltered');
            $table->index(['trading_date_est', 'version'], 'idx_trading_date_version_unfiltered');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('trade_alerts_unfiltered');
    }
};
