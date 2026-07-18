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
        Schema::create('alpaca_orders', function (Blueprint $table) {
            $table->id();
            $table->char('alpaca_order_id', 36)->unique()->comment('UUID from Alpaca: order.id');
            $table->char('client_order_id', 36)->nullable()->index()->comment('order.client_order_id');

            $table->char('account_id', 36)->nullable()->comment('alpaca account id');
            $table->string('symbol', 16);
            $table->string('asset_class', 16)->nullable();

            $table->string('order_class', 16)->nullable()->comment('simple, bracket, oco, oto');
            $table->string('order_type', 16)->nullable()->comment('market, limit, stop, stop_limit, trailing_stop');
            $table->string('side', 8)->comment('buy/sell');
            $table->string('time_in_force', 8)->nullable()->comment('day, gtc, opg, cls, ioc, fok');

            $table->decimal('qty', 18, 6)->nullable();
            $table->decimal('notional', 18, 6)->nullable();
            $table->decimal('filled_qty', 18, 6)->default(0);
            $table->decimal('filled_avg_price', 18, 6)->nullable();

            $table->decimal('limit_price', 18, 6)->nullable();
            $table->decimal('stop_price', 18, 6)->nullable();
            $table->decimal('trail_price', 18, 6)->nullable();
            $table->decimal('trail_percent', 10, 4)->nullable();

            $table->string('status', 32)->comment('pending_new, accepted, filled, canceled, rejected');

            $table->dateTime('submitted_at', 6)->nullable();
            $table->dateTime('created_at', 6)->nullable();
            $table->dateTime('updated_at', 6)->nullable();
            $table->dateTime('filled_at', 6)->nullable();
            $table->dateTime('canceled_at', 6)->nullable();
            $table->dateTime('expired_at', 6)->nullable();

            $table->char('replaces_alpaca_order_id', 36)->nullable();
            $table->char('replaced_by_alpaca_order_id', 36)->nullable();
            $table->char('parent_alpaca_order_id', 36)->nullable()->index()->comment('for bracket legs');

            $table->string('position_intent', 32)->nullable()->comment('buy_to_open, etc.');
            $table->boolean('extended_hours')->default(false);

            $table->json('raw_json')->nullable()->comment('store full payload for safety/debug');
            $table->string('notes', 255)->nullable();

            $table->timestamp('inserted_at')->useCurrent();
            $table->timestamp('last_seen_at')->nullable();

            // Indexes
            $table->index(['symbol', 'created_at']);
            $table->index(['status', 'updated_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('alpaca_orders');
    }
};
