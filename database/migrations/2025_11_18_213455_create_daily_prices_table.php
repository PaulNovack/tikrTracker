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
        Schema::create('daily_prices', function (Blueprint $table) {
            $table->id();
            $table->string('symbol', 20);
            $table->enum('asset_type', ['stock', 'crypto']);
            $table->date('date');
            $table->decimal('price', 20, 8);
            $table->decimal('open', 20, 8)->nullable();
            $table->decimal('high', 20, 8)->nullable();
            $table->decimal('low', 20, 8)->nullable();
            $table->bigInteger('volume')->nullable();
            $table->timestamps();

            $table->unique(['symbol', 'asset_type', 'date']);
            $table->index('symbol');
            $table->index('asset_type');
            $table->index('date');
            $table->index(['symbol', 'asset_type', 'date']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('daily_prices');
    }
};
