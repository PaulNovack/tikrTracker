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
        Schema::create('alert_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('price_alert_id')->constrained('price_alerts')->cascadeOnDelete();
            $table->string('symbol'); // Stock/crypto symbol (e.g., GOOGL, BTC)
            $table->enum('direction', ['up', 'down']); // Alert triggered direction
            $table->decimal('trigger_price', 10, 2); // The threshold price that was crossed
            $table->decimal('current_price', 10, 2); // The actual price at trigger time
            $table->decimal('trigger_percentage', 5, 2); // Percentage change that triggered alert
            $table->enum('email_status', ['sent', 'failed', 'retry'])->default('sent');
            $table->text('email_error')->nullable(); // Error message if email failed
            $table->timestamp('sent_at')->useCurrent();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('alert_logs');
    }
};
