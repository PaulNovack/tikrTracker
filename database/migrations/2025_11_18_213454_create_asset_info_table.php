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
        Schema::create('asset_info', function (Blueprint $table) {
            $table->id();
            $table->string('symbol', 20);
            $table->enum('asset_type', ['stock', 'crypto']);
            $table->string('sector', 100)->nullable();
            $table->string('common_name');
            $table->text('description')->nullable();
            $table->string('reason_for_delete', 255)->nullable();
            $table->softDeletes();
            $table->timestamps();

            $table->unique(['symbol', 'asset_type']);
            $table->index('symbol');
            $table->index('asset_type');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('asset_info');
    }
};
