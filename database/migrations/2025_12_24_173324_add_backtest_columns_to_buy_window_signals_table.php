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
        Schema::table('buy_window_signals', function (Blueprint $table) {
            $table->decimal('backtest_stop_price', 12, 4)->nullable()->after('last_price');
            $table->decimal('backtest_exit_price', 12, 4)->nullable()->after('backtest_stop_price');
            $table->string('backtest_exit_type', 10)->nullable()->after('backtest_exit_price');
            $table->timestamp('backtest_exit_time')->nullable()->after('backtest_exit_type');
            $table->decimal('backtest_pl_dollars', 12, 4)->nullable()->after('backtest_exit_time');
            $table->decimal('backtest_pl_pct', 8, 4)->nullable()->after('backtest_pl_dollars');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('buy_window_signals', function (Blueprint $table) {
            $table->dropColumn([
                'backtest_stop_price',
                'backtest_exit_price',
                'backtest_exit_type',
                'backtest_exit_time',
                'backtest_pl_dollars',
                'backtest_pl_pct',
            ]);
        });
    }
};
