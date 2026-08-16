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
        Schema::create('gate_version_snapshots', function (Blueprint $table) {
            $table->id();
            $table->string('snapshot_name');
            $table->string('pipeline_letter', 2);
            $table->string('version_string', 20);
            $table->string('signal_type', 50)->nullable();
            $table->string('scanner_score_formula', 255)->nullable();
            $table->boolean('enabled')->default(true);
            // Full snapshot of the gates (both timeframes) as JSON.
            $table->json('gates_data');
            $table->timestamp('snapshot_at');
            $table->timestamps();

            $table->index('pipeline_letter');
            $table->index('snapshot_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('gate_version_snapshots');
    }
};
