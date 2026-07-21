<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('realtime_trade_candidates', function (Blueprint $table) {
            $table->id();

            $table->string('symbol', 20);
            $table->enum('asset_type', ['stock', 'crypto'])->default('stock');

            $table->dateTime('detected_ts_est');
            $table->decimal('detected_price', 20, 8);

            $table->decimal('bid', 20, 8)->nullable();
            $table->decimal('ask', 20, 8)->nullable();
            $table->unsignedBigInteger('bid_qty')->nullable();
            $table->unsignedBigInteger('ask_qty')->nullable();
            $table->decimal('spread_pct', 10, 6)->nullable();

            $table->decimal('partial_open', 20, 8)->nullable();
            $table->decimal('partial_high', 20, 8)->nullable();
            $table->decimal('partial_low', 20, 8)->nullable();
            $table->decimal('partial_close', 20, 8)->nullable();
            $table->unsignedBigInteger('partial_volume')->nullable();

            $table->decimal('vwap', 20, 8)->nullable();
            $table->decimal('vwap_dist_pct', 10, 6)->nullable();

            $table->decimal('return_1m_pct', 10, 6)->nullable();
            $table->decimal('return_3m_pct', 10, 6)->nullable();
            $table->decimal('volume_ratio', 10, 6)->nullable();
            $table->decimal('dollar_volume_1m', 20, 4)->nullable();

            $table->decimal('bid_ask_imbalance', 10, 6)->nullable();
            $table->decimal('early_score', 10, 4)->nullable();

            $table->enum('status', ['watching', 'triggered', 'expired', 'rejected'])->default('watching');
            $table->string('rejection_reason')->nullable();

            $table->unsignedBigInteger('trade_alert_id')->nullable();

            $table->timestamps();

            $table->index(['symbol', 'status', 'detected_ts_est'], 'rt_candidates_symbol_status_ts_idx');
            $table->index(['status', 'detected_ts_est'], 'rt_candidates_status_ts_idx');
            $table->index('trade_alert_id', 'rt_candidates_alert_id_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('realtime_trade_candidates');
    }
};
