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
        Schema::create('traffic_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('ip_address', 45); // IPv6 support
            $table->string('method', 10); // GET, POST, PUT, PATCH, DELETE
            $table->text('url');
            $table->text('full_url')->nullable();
            $table->string('route_name')->nullable();
            $table->string('controller_action')->nullable();
            $table->json('query_params')->nullable();
            $table->json('post_data')->nullable();
            $table->text('user_agent')->nullable();
            $table->string('referer')->nullable();
            $table->json('headers')->nullable(); // Request headers
            $table->integer('status_code')->nullable();
            $table->unsignedInteger('duration_ms')->nullable(); // Request duration in milliseconds
            $table->timestamp('request_start')->nullable();
            $table->timestamp('request_end')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'request_start']);
            $table->index('ip_address');
            $table->index('method');
            $table->index('request_start');
            $table->index('status_code');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('traffic_logs');
    }
};
