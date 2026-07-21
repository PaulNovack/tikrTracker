<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('real_alpaca_orders', function (Blueprint $table) {
            $table->char('id', 36)->primary();
            $table->string('symbol', 16);
            $table->string('order_description', 64)->nullable();
            $table->string('type', 16)->nullable();
            $table->string('side', 8);
            $table->decimal('qty', 18, 6);
            $table->decimal('filled_qty', 18, 6)->default(0);
            $table->string('currency', 8)->default('USD');
            $table->decimal('avg_fill_price', 18, 6)->nullable();
            $table->decimal('limit_price', 18, 6)->nullable();
            $table->decimal('stop_price', 18, 6)->nullable();
            $table->decimal('total_amount', 18, 6)->nullable();
            $table->string('status', 32);
            $table->string('source', 32)->nullable();
            $table->dateTime('submitted_at')->nullable();
            $table->dateTime('filled_at')->nullable();
            $table->dateTime('expires_at')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('real_alpaca_orders');
    }
};
