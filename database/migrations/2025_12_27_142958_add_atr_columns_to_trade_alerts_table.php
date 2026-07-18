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
            $table->decimal('atr', 10, 6)->nullable()->after('vol_ratio');
            $table->decimal('atr_pct', 10, 6)->nullable()->after('atr');
            $table->decimal('suggested_trailing_stop', 10, 6)->nullable()->after('atr_pct');
            $table->decimal('suggested_trailing_stop_pct', 10, 6)->nullable()->after('suggested_trailing_stop');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('trade_alerts', function (Blueprint $table) {
            $table->dropColumn(['atr', 'atr_pct', 'suggested_trailing_stop', 'suggested_trailing_stop_pct']);
        });
    }
};
