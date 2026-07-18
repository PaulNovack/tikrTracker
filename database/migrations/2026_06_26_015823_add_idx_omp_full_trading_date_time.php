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
        Schema::table('one_minute_prices_full', function (Blueprint $table) {
            $table->index(
                ['trading_date_est', 'trading_time_est'],
                'idx_omp_full_trading_date_time'
            );
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('one_minute_prices_full', function (Blueprint $table) {
            $table->dropIndex('idx_omp_full_trading_date_time');
        });
    }
};
