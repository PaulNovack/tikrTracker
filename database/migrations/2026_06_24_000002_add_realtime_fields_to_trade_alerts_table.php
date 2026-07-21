<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table(config('trading_realtime.tables.trade_alerts', 'trade_alerts'), function (Blueprint $table) {
            $table->unsignedBigInteger('realtime_candidate_id')->nullable()->after('id');

            $table->dateTime('signal_detected_at_est')->nullable();
            $table->dateTime('entry_detected_at_est')->nullable();
            $table->dateTime('ml_scored_at_est')->nullable();
            $table->dateTime('order_submitted_at_est')->nullable();

            $table->integer('alert_age_seconds')->nullable();
            $table->integer('quote_age_seconds')->nullable();

            $table->decimal('move_since_candidate_pct', 10, 6)->nullable();
            $table->decimal('move_since_entry_pct', 10, 6)->nullable();

            $table->decimal('current_bid', 20, 8)->nullable();
            $table->decimal('current_ask', 20, 8)->nullable();
            $table->unsignedBigInteger('current_bid_qty')->nullable();
            $table->unsignedBigInteger('current_ask_qty')->nullable();
            $table->decimal('current_spread_pct', 10, 6)->nullable();

            $table->index('realtime_candidate_id', 'trade_alerts_rt_candidate_idx');
            $table->index('entry_detected_at_est', 'trade_alerts_entry_detected_idx');
        });
    }

    public function down(): void
    {
        Schema::table(config('trading_realtime.tables.trade_alerts', 'trade_alerts'), function (Blueprint $table) {
            $table->dropIndex('trade_alerts_rt_candidate_idx');
            $table->dropIndex('trade_alerts_entry_detected_idx');

            $table->dropColumn([
                'realtime_candidate_id',
                'signal_detected_at_est',
                'entry_detected_at_est',
                'ml_scored_at_est',
                'order_submitted_at_est',
                'alert_age_seconds',
                'quote_age_seconds',
                'move_since_candidate_pct',
                'move_since_entry_pct',
                'current_bid',
                'current_ask',
                'current_bid_qty',
                'current_ask_qty',
                'current_spread_pct',
            ]);
        });
    }
};
