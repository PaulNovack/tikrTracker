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
        Schema::create('sql_logs', function (Blueprint $table) {
            $table->id();
            $table->longText('query');
            $table->json('bindings')->nullable();
            $table->decimal('execution_time_ms', 8, 2)->default(0); // milliseconds with 2 decimal places
            $table->string('connection')->default('mysql');
            $table->string('request_path')->nullable();
            $table->string('http_method')->nullable();
            $table->string('user_id')->nullable();
            $table->text('stack_trace')->nullable();
            $table->timestamps();

            $table->index('created_at');
            $table->index('execution_time_ms');
            $table->index('connection');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sql_logs');
    }
};
