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
            $table->boolean('is_realtime')->default(false)->after('ml_model_version')
                ->comment('TRUE if alert created in real-time trading, FALSE if backtest');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('trade_alerts', function (Blueprint $table) {
            $table->dropColumn('is_realtime');
        });
    }
};
