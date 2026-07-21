<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('trade_alerts', function (Blueprint $table) {
            $table->id();

            $table->string('symbol', 20);
            $table->enum('asset_type', ['stock', 'crypto']);

            // "pipeline run time" (as-of) in NY market time
            $table->date('trading_date_est')->index();
            $table->dateTime('as_of_ts_est')->index();

            // signal + entry
            $table->string('signal_type', 50)->index(); // e.g. VWAP_RECLAIM_5M
            $table->dateTime('signal_ts_est')->index();

            $table->string('entry_type', 50)->index();  // e.g. VWAP_RECLAIM_1M, PIVOT_HIGH_BREAK_1M
            $table->dateTime('entry_ts_est')->index();
            $table->decimal('entry', 20, 8);
            $table->decimal('stop', 20, 8);

            // scoring + metadata
            $table->decimal('risk_pct', 8, 3)->nullable();
            $table->decimal('risk_per_share', 20, 8)->nullable();
            $table->decimal('score', 10, 3)->nullable();
            $table->decimal('vol_ratio', 10, 3)->nullable();

            $table->json('targets')->nullable();
            $table->json('meta')->nullable(); // store anything else (vwap, pivot_high, notes, etc.)

            // de-dupe so you don't spam alerts every minute
            $table->string('dedupe_key', 120)->unique();

            $table->timestamps();

            $table->index(['asset_type', 'symbol', 'as_of_ts_est']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trade_alerts');
    }
};
