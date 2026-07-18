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
        Schema::create('symbol_failures', function (Blueprint $table) {
            $table->id();
            $table->string('symbol', 20)->index();
            $table->enum('asset_type', ['stock', 'crypto'])->index();
            $table->enum('failure_type', ['delisted', 'no_data', 'api_error', 'data_quality', 'consecutive_failures'])->index();
            $table->text('error_message')->nullable();
            $table->integer('consecutive_count')->default(1)->index();
            $table->timestamp('first_failure_at')->index();
            $table->timestamp('last_failure_at')->index();
            $table->boolean('auto_blacklisted')->default(false)->index();
            $table->timestamp('blacklisted_at')->nullable()->index();
            $table->timestamps();

            // Indexes for efficient querying
            $table->index(['symbol', 'asset_type']);
            $table->index(['failure_type', 'consecutive_count']);
            $table->index(['auto_blacklisted', 'consecutive_count']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('symbol_failures');
    }
};
