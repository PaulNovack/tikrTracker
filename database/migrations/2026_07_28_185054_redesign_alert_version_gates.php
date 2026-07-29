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
        // Drop the old table first
        Schema::dropIfExists('alert_version_gates');

        Schema::create('alert_version_gates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('alert_version_id')->constrained()->cascadeOnDelete();
            $table->enum('timeframe', ['5m', '1m']);
            $table->string('gate_name', 64);
            $table->decimal('threshold_min', 16, 6)->nullable();
            $table->decimal('threshold_max', 16, 6)->nullable();
            $table->boolean('enabled')->default(false);
            $table->timestamps();

            $table->unique(['alert_version_id', 'timeframe', 'gate_name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('alert_version_gates');
    }
};
