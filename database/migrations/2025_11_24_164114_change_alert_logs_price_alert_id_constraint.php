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
        Schema::table('alert_logs', function (Blueprint $table) {
            // Drop existing foreign key constraint first
            $table->dropForeign(['price_alert_id']);

            // Make price_alert_id nullable so alert logs can persist when alerts are deleted
            $table->foreignId('price_alert_id')->nullable()->change();

            // Re-add the foreign key with nullOnDelete instead of cascadeOnDelete
            $table->foreign('price_alert_id')
                ->references('id')
                ->on('price_alerts')
                ->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('alert_logs', function (Blueprint $table) {
            // Drop the new constraint
            $table->dropForeign(['price_alert_id']);

            // Revert back to NOT NULL
            $table->foreignId('price_alert_id')->nullable(false)->change();

            // Re-add the original cascadeOnDelete constraint
            $table->foreign('price_alert_id')
                ->references('id')
                ->on('price_alerts')
                ->cascadeOnDelete();
        });
    }
};
