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
        Schema::table('stock_transactions', function (Blueprint $table) {
            // Add fields to support Webull data import
            $table->enum('order_status', ['filled', 'cancelled', 'failed', 'pending'])->default('filled')->after('type');
            $table->enum('time_in_force', ['day', 'gtc', 'ioc', 'fok'])->default('day')->after('order_status');
            $table->timestamp('placed_time')->nullable()->after('transaction_date');
            $table->timestamp('filled_time')->nullable()->after('placed_time');
            $table->decimal('avg_price', 15, 2)->nullable()->comment('Average execution price for partial fills')->after('price_per_share');
            $table->string('broker_order_id')->nullable()->comment('Broker-specific order identifier')->after('notes');
            $table->string('company_name')->nullable()->comment('Full company name')->after('symbol');

            // Add indexes for better performance
            $table->index('order_status');
            $table->index('placed_time');
            $table->index('filled_time');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('stock_transactions', function (Blueprint $table) {
            $table->dropIndex(['order_status']);
            $table->dropIndex(['placed_time']);
            $table->dropIndex(['filled_time']);

            $table->dropColumn([
                'order_status',
                'time_in_force',
                'placed_time',
                'filled_time',
                'avg_price',
                'broker_order_id',
                'company_name',
            ]);
        });
    }
};
