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
            // Change from decimal(5,2) to decimal(8,2) to support larger percentages
            $table->decimal('trigger_percentage', 8, 2)->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('alert_logs', function (Blueprint $table) {
            // Revert back to decimal(5,2)
            $table->decimal('trigger_percentage', 5, 2)->change();
        });
    }
};
