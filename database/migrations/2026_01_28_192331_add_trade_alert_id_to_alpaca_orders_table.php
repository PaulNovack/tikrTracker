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
        Schema::table('alpaca_orders', function (Blueprint $table) {
            $table->unsignedBigInteger('trade_alert_id')->nullable()->after('id');
            $table->foreign('trade_alert_id')->references('id')->on('trade_alerts')->onDelete('set null');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('alpaca_orders', function (Blueprint $table) {
            $table->dropForeign(['trade_alert_id']);
            $table->dropColumn('trade_alert_id');
        });
    }
};
