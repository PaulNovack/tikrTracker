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
        Schema::create('market_schedules', function (Blueprint $table) {
            $table->id();
            $table->date('date')->index();
            $table->string('market_type')->default('stock')->comment('stock, crypto, forex, etc.');
            $table->enum('status', ['open', 'closed', 'half_day', 'holiday'])->default('closed');
            $table->string('reason')->nullable()->comment('Holiday name, market closure reason, etc.');
            $table->time('opens_at')->nullable()->comment('Market opening time (UTC)');
            $table->time('closes_at')->nullable()->comment('Market closing time (UTC)');
            $table->boolean('is_early_close')->default(false)->comment('Whether market closes early');
            $table->timestamps();
            $table->unique(['date', 'market_type']);
            $table->index(['market_type', 'date']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('market_schedules');
    }
};
