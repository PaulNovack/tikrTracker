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
        Schema::create('latest_stock_quotes', function (Blueprint $table) {
            $table->string('symbol', 32)->primary();
            $table->decimal('bid_price', 18, 6)->nullable();
            $table->decimal('ask_price', 18, 6)->nullable();
            $table->unsignedBigInteger('bid_size')->nullable();
            $table->unsignedBigInteger('ask_size')->nullable();
            $table->string('bid_exchange', 32)->nullable();
            $table->string('ask_exchange', 32)->nullable();
            $table->dateTime('quote_ts_utc', 6)->nullable();
            $table->dateTime('received_at_utc', 6);
            $table->string('feed', 16)->default('sip');
            $table->timestamp('updated_at')->useCurrent()->useCurrentOnUpdate();

            $table->index('quote_ts_utc', 'idx_quote_ts_utc');
            $table->index('received_at_utc', 'idx_received_at_utc');
            $table->index(['bid_price', 'ask_price'], 'idx_bid_ask');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('latest_stock_quotes');
    }
};
