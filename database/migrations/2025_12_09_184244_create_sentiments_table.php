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
        Schema::create('sentiments', function (Blueprint $table) {
            $table->id();
            $table->string('symbol', 10)->index();
            $table->text('sentiment_text');
            $table->enum('sentiment_type', ['positive', 'negative', 'neutral'])->default('positive');
            $table->decimal('confidence_score', 3, 2)->nullable(); // 0.00 to 1.00
            $table->date('sentiment_date')->index();
            $table->timestamps();

            // Index for efficient queries
            $table->index(['symbol', 'sentiment_date']);
            $table->index(['sentiment_date', 'sentiment_type']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sentiments');
    }
};
