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
        if (Schema::hasTable('stock_transactions')) {
            return;
        }

        Schema::create('stock_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('stock_buy_id')->nullable()->constrained('stock_transactions')->nullOnDelete();
            $table->enum('type', ['buy', 'sell']);
            $table->enum('exit_reason', ['manual', 'stop_loss', 'break_even', 'trailing_stop', 'take_profit'])->nullable();
            $table->string('symbol');
            $table->decimal('quantity', 15, 8);
            $table->decimal('price_per_share', 15, 2);
            $table->decimal('current_price_per_share', 15, 2)->nullable();
            $table->decimal('stop_loss', 10, 2)->nullable();
            $table->decimal('break_even', 10, 2)->nullable();
            $table->decimal('trailing', 10, 2)->nullable();
            $table->decimal('highest_price_reached', 15, 2)->nullable();
            $table->decimal('sell_price_per_share', 15, 2)->nullable();
            $table->decimal('fee', 10, 2)->default(0);
            $table->decimal('total_amount', 15, 2);
            $table->decimal('realized_profit_loss', 15, 2)->nullable();
            $table->timestamp('transaction_date');
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'symbol']);
            $table->index('transaction_date');
            $table->index('stock_buy_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('stock_transactions');
    }
};
