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
        Schema::table('trade_alerts', function (Blueprint $table) {
            $table->decimal('rsi_14_1m', 5, 2)->nullable()->after('atr_pct')->comment('RSI(14) calculated from 1-minute bars at entry time');
            $table->index('rsi_14_1m');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('trade_alerts', function (Blueprint $table) {
            $table->dropIndex(['rsi_14_1m']);
            $table->dropColumn('rsi_14_1m');
        });
    }
};
