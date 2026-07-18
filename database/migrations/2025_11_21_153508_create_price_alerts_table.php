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
        Schema::create('price_alerts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('asset_info_id')->constrained('asset_info')->cascadeOnDelete();
            $table->decimal('base_price', 12, 2);
            $table->enum('alert_type', ['fixed', 'percentage']);
            $table->decimal('threshold_value', 10, 2)->nullable();
            $table->decimal('above_price', 12, 2)->nullable();
            $table->decimal('below_price', 12, 2)->nullable();
            $table->decimal('up_percentage', 5, 2)->nullable();
            $table->decimal('down_percentage', 5, 2)->nullable();
            $table->boolean('enabled')->default(true);
            $table->boolean('up_enabled')->default(true);
            $table->boolean('down_enabled')->default(true);
            $table->boolean('above_triggered')->default(false);
            $table->boolean('below_triggered')->default(false);
            $table->timestamp('last_triggered_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'asset_info_id']);
            $table->unique(['user_id', 'asset_info_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('price_alerts');
    }
};
