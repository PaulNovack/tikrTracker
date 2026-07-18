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
        Schema::create('disclaimer_acceptances', function (Blueprint $table) {
            $table->id();
            $table->string('ip_address', 45); // Support both IPv4 and IPv6
            $table->string('user_agent', 500)->nullable();
            $table->boolean('disclaimer_accepted')->default(false);
            $table->boolean('cookies_accepted')->default(false);
            $table->timestamp('disclaimer_accepted_at')->nullable();
            $table->timestamp('cookies_accepted_at')->nullable();
            $table->timestamp('last_access_at')->nullable();
            $table->integer('access_count')->default(0);
            $table->timestamps();

            // Indexes for performance
            $table->index('ip_address', 'disc_ip_idx');
            $table->index(['ip_address', 'disclaimer_accepted', 'cookies_accepted'], 'disc_acceptance_idx');
            $table->index('last_access_at', 'disc_last_access_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('disclaimer_acceptances');
    }
};
