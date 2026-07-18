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
        Schema::create('buy_window_signals', function (Blueprint $table) {
            $table->id();
            $table->string('symbol', 20)->index();
            $table->foreignId('asset_id')->nullable()->constrained('asset_info')->onDelete('cascade');
            $table->timestamp('signal_time')->index(); // When the signal was generated
            $table->string('asset_type', 20)->default('stock');

            // Signal scoring
            $table->integer('score')->index();

            // Price data
            $table->decimal('last_price', 12, 4);
            $table->decimal('range_pct', 8, 4);
            $table->decimal('pullback_pct', 8, 4);

            // Volume analysis
            $table->decimal('volume_surge', 12, 2);

            // Technical indicators
            $table->decimal('vwap', 12, 4);
            $table->decimal('ma10', 12, 4);
            $table->decimal('ma30', 12, 4);

            // Metadata
            $table->json('reasons'); // Array of scoring reasons
            $table->integer('lookback_minutes')->default(90);
            $table->boolean('is_optimal_time')->default(false); // 10:15 AM flag

            $table->timestamps();

            // Deduplication: Prevent duplicate signals for same symbol within same minute
            $table->unique(['symbol', 'signal_time', 'asset_type'], 'unique_signal');

            // Performance indexes
            $table->index(['signal_time', 'score']);
            $table->index(['asset_type', 'score']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('buy_window_signals');
    }
};
