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
            $table->decimal('avg_dollar_volume_per_minute', 15, 2)
                ->nullable()
                ->after('vol_ratio')
                ->comment('Average dollar volume per minute (volume * price) for the trading day');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('trade_alerts', function (Blueprint $table) {
            $table->dropColumn('avg_dollar_volume_per_minute');
        });
    }
};
