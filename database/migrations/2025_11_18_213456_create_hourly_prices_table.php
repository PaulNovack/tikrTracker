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
        Schema::create('hourly_prices', function (Blueprint $table) {
            $table->id();
            $table->string('symbol', 20);
            $table->enum('asset_type', ['stock', 'crypto']);
            $table->dateTime('ts');
            $table->decimal('price', 20, 8);
            $table->bigInteger('volume')->nullable();
            $table->timestamps();

            $table->unique(['symbol', 'asset_type', 'ts']);
            $table->index('symbol');
            $table->index('asset_type');
            $table->index('ts');
            $table->index(['symbol', 'asset_type', 'ts']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('hourly_prices');
    }
};
