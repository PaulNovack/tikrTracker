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
        Schema::create('ml_probability_calibration', function (Blueprint $table) {
            $table->id();
            $table->string('pipeline');
            $table->string('version');
            $table->decimal('bucket_min', 8, 4)->nullable();
            $table->decimal('bucket_max', 8, 4)->nullable();
            $table->string('bucket_label');
            $table->unsignedInteger('rows');
            $table->decimal('win_rate', 8, 6);
            $table->decimal('avg_pnl', 10, 4);
            $table->decimal('median_pnl', 10, 4);
            $table->timestamps();

            $table->unique(['pipeline', 'version', 'bucket_label']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ml_probability_calibration');
    }
};
