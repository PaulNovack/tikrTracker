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
        Schema::create('circuit_breaker_events', function (Blueprint $table) {
            $table->id();
            $table->string('symbol', 20);
            $table->integer('losing_stops_count');
            $table->integer('window_minutes');
            $table->integer('pause_minutes');
            $table->timestamp('tripped_at');
            $table->timestamp('pause_expires_at');
            $table->boolean('is_paper')->default(false);
            $table->timestamps();

            $table->index('tripped_at');
            $table->index('pause_expires_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('circuit_breaker_events');
    }
};
