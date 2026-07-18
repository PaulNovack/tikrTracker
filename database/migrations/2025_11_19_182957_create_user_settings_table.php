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
        Schema::create('user_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->decimal('stop_loss_percent', 5, 2)->default(0.025)->comment('Default stop loss percentage');
            $table->decimal('break_even', 5, 2)->default(0.04)->comment('Break even percentage');
            $table->decimal('trailing', 5, 2)->default(0.08)->comment('Trailing stop percentage');
            $table->timestamps();

            $table->unique('user_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('user_settings');
    }
};
