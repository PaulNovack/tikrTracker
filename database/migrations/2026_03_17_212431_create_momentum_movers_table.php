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
        Schema::create('momentum_movers', function (Blueprint $table) {
            $table->id();
            $table->string('symbol', 10);
            $table->date('trading_date_est');
            $table->time('scan_time_est');

            // Scanner qualification metrics
            $table->decimal('change_from_open', 10, 4); // % change from open (3%+ criteria)
            $table->decimal('relative_volume', 10, 4); // Relative to avg volume (1.5x+ criteria)
            $table->bigInteger('current_volume');
            $table->decimal('price', 10, 4);
            $table->decimal('atr', 10, 4)->nullable();

            // Consolidation pattern detection
            $table->integer('consolidation_bars')->nullable(); // # of bars in consolidation
            $table->decimal('consolidation_high', 10, 4)->nullable();
            $table->decimal('consolidation_low', 10, 4)->nullable();
            $table->decimal('consolidation_range_pct', 10, 4)->nullable(); // Range as % of price
            $table->boolean('is_consolidating')->default(false);

            // Entry trigger tracking
            $table->boolean('breakout_triggered')->default(false);
            $table->decimal('breakout_price', 10, 4)->nullable();
            $table->time('breakout_time_est')->nullable();
            $table->enum('breakout_direction', ['long', 'short'])->nullable();

            // Risk management
            $table->decimal('stop_loss', 10, 4)->nullable();
            $table->decimal('profit_target', 10, 4)->nullable();

            $table->timestamps();

            // Indexes for fast queries
            $table->index(['trading_date_est', 'scan_time_est']);
            $table->index(['symbol', 'trading_date_est']);
            $table->index(['trading_date_est', 'is_consolidating']);
            $table->index(['trading_date_est', 'breakout_triggered']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('momentum_movers');
    }
};
