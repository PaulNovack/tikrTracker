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
            // Update the enum to include 'disabled' status
            $table->enum('email_status', ['sent', 'failed', 'retry', 'disabled'])->default('sent')->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('alert_logs', function (Blueprint $table) {
            // Revert to original enum values
            $table->enum('email_status', ['sent', 'failed', 'retry'])->default('sent')->change();
        });
    }
};
