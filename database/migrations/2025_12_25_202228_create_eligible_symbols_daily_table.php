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
        Schema::create('eligible_symbols_daily', function (Blueprint $table) {
            $table->date('trading_date_est');
            $table->string('symbol', 20);
            $table->enum('asset_type', ['stock', 'crypto'])->default('stock');

            // Diagnostics (useful for analysis)
            $table->decimal('avg_range_3d', 8, 4);
            $table->decimal('avg_range_10d', 8, 4);
            $table->decimal('green_ratio_7d', 6, 3);
            $table->integer('intraday_big_days_5d');

            $table->timestamp('created_at')->useCurrent();

            // Composite primary key
            $table->primary(['trading_date_est', 'asset_type', 'symbol']);

            // Indexes
            $table->index(['trading_date_est', 'asset_type'], 'idx_eligible_date_type');
            $table->index('symbol', 'idx_eligible_symbol');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('eligible_symbols_daily');
    }
};
