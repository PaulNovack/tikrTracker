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
        Schema::create('last_4_1_min_up', function (Blueprint $table) {
            $table->id();

            $table->string('symbol', 20);
            $table->enum('asset_type', ['stock', 'crypto'])->default('stock');
            $table->dateTime('streak_start_ts_est');
            $table->dateTime('streak_end_ts_est');
            $table->decimal('bar_1_price', 20, 8)->comment('Oldest bar in the 4-bar streak');
            $table->decimal('bar_2_price', 20, 8);
            $table->decimal('bar_3_price', 20, 8);
            $table->decimal('bar_4_price', 20, 8)->comment('Most recent bar (highest price)');
            $table->decimal('total_pct_change', 6, 3)->comment('Pct change from bar_1 to bar_4');
            $table->dateTime('scanned_at');

            $table->timestamps();

            $table->unique(['symbol', 'streak_end_ts_est'], 'l4u_symbol_streak_end_unique');
            $table->index('symbol', 'l4u_symbol_idx');
            $table->index('scanned_at', 'l4u_scanned_at_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('last_4_1_min_up');
    }
};
