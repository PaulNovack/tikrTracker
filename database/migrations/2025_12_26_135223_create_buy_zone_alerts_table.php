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
        Schema::create('buy_zone_alerts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('asset_id')->nullable()->index();
            $table->string('symbol', 20)->index();
            $table->string('asset_type', 20)->default('stock')->index();

            // Buy zone metrics
            $table->timestamp('analysis_ts_est')->index();
            $table->decimal('high_7d', 10, 4);
            $table->decimal('low_7d', 10, 4);
            $table->decimal('dist_from_7d_high_pct', 8, 4);
            $table->decimal('retracement_pct', 8, 4);
            $table->decimal('rvol', 8, 4)->nullable();
            $table->string('ema_state', 50)->nullable();

            // Entry details
            $table->decimal('entry_price', 10, 4);
            $table->decimal('stop_price', 10, 4);
            $table->decimal('risk_per_share', 10, 4);
            $table->decimal('risk_pct', 8, 4);
            $table->boolean('stop_viable_1pct')->default(false);
            $table->integer('recommended_shares')->default(0);
            $table->decimal('position_notional', 12, 2)->default(0);

            // P&L tracking (filled after backtest)
            $table->decimal('exit_price', 10, 4)->nullable();
            $table->timestamp('exit_ts_est')->nullable();
            $table->string('exit_reason', 50)->nullable();
            $table->decimal('pnl_percent', 8, 4)->nullable();
            $table->decimal('pnl_dollars', 10, 2)->nullable();
            $table->decimal('risk_adjusted_return', 8, 4)->nullable();
            $table->boolean('is_winner')->nullable()->index();
            $table->decimal('highest_price', 10, 4)->nullable();
            $table->decimal('mae_pct', 8, 4)->nullable(); // Maximum Adverse Excursion

            $table->timestamps();

            $table->index(['analysis_ts_est', 'symbol']);
            $table->index(['is_winner', 'analysis_ts_est']);

            $table->foreign('asset_id')
                ->references('id')
                ->on('asset_info')
                ->onDelete('set null');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('buy_zone_alerts');
    }
};
