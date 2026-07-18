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
        Schema::create('webull_tokens', function (Blueprint $table) {
            $table->id();
            $table->string('environment')->unique(); // 'DEV' or 'PROD'
            $table->text('token');
            $table->string('status')->default('PENDING'); // PENDING, NORMAL, INVALID, EXPIRED
            $table->timestamp('expires_at');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('webull_tokens');
    }
};
