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
        Schema::create('alert_versions', function (Blueprint $table) {
            $table->id();
            $table->string('pipeline_letter', 2);
            $table->string('version_string', 20);
            $table->string('signal_type', 50);
            $table->string('scanner_score_formula', 255)->nullable();
            $table->boolean('enabled')->default(true);
            $table->timestamps();

            $table->unique(['pipeline_letter', 'version_string']);
        });

        Schema::create('alert_version_gates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('alert_version_id')->constrained()->cascadeOnDelete();
            $table->enum('timeframe', ['5m', '1m']);
            $table->string('gate_name', 64);
            $table->decimal('threshold', 16, 6)->nullable();
            $table->enum('operator', ['>=', '<=', '>', '<', '==', 'bool'])->default('>=');
            $table->boolean('enabled')->default(true);
            $table->timestamps();

            $table->unique(['alert_version_id', 'timeframe', 'gate_name']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('alert_versions');
    }
};
