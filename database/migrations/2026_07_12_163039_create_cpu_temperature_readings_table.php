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
        Schema::create('cpu_temperature_readings', function (Blueprint $table) {
            $table->id();

            $table->dateTime('refreshed_at');
            $table->string('sensor_section', 120);
            $table->string('sensor_label', 120);
            $table->decimal('temperature_celsius', 5, 1);
            $table->text('raw_reading')->nullable();

            $table->timestamps();

            $table->index(['refreshed_at', 'sensor_section'], 'cpu_temp_refreshed_section_idx');
            $table->index(['sensor_section', 'sensor_label'], 'cpu_temp_sensor_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('cpu_temperature_readings');
    }
};
