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
            $table->decimal('atr', 10, 6)->nullable()->after('stop_price');
            $table->decimal('atr_pct', 10, 6)->nullable()->after('atr');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('alpaca_orders', function (Blueprint $table) {
            $table->dropColumn(['atr', 'atr_pct']);
        });
    }
};
