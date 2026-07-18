<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('trade_signals', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->string('symbol', 20)->index();
            $table->enum('asset_type', ['stock', 'crypto'])->index();

            // When we evaluated (EST) - align with your ts_est usage
            $table->dateTime('asof_ts_est')->index();

            // Pattern identifier
            $table->string('pattern', 64)->index();

            // Setup keys (neckline, trendlines, etc.)
            $table->json('levels_json');

            // Scoring + status
            $table->decimal('score', 8, 3)->default(0);
            $table->boolean('triggered')->default(false);
            $table->dateTime('trigger_ts_est')->nullable();

            // Optional details
            $table->string('timeframe', 8)->default('5m'); // "5m" setup, maybe "1m" trigger
            $table->text('notes')->nullable();

            $table->timestamps();

            // Prevent duplicates for same scan moment
            $table->unique(['symbol', 'asset_type', 'asof_ts_est', 'pattern'], 'uniq_trade_signals_scan');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trade_signals');
    }
};
