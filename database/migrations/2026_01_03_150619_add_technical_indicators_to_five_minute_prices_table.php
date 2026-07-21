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
        Schema::table('five_minute_prices', function (Blueprint $table) {
            // VWAP columns (vwap already exists, add the rest)
            $table->decimal('vwap_dist', 10, 6)->nullable()->after('vwap');
            $table->decimal('vwap_dist_pct', 10, 6)->nullable()->after('vwap_dist');
            $table->boolean('above_vwap')->nullable()->after('vwap_dist_pct');

            // EMA columns
            $table->decimal('ema9', 20, 8)->nullable()->after('above_vwap');
            $table->decimal('ema21', 20, 8)->nullable()->after('ema9');
            $table->decimal('ema9_ema21_spread', 10, 6)->nullable()->after('ema21');
            $table->boolean('ema9_above_ema21')->nullable()->after('ema9_ema21_spread');

            // ATR columns
            $table->decimal('atr', 10, 6)->nullable()->after('ema9_above_ema21');
            $table->decimal('atr_pct', 10, 6)->nullable()->after('atr');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('five_minute_prices', function (Blueprint $table) {
            $table->dropColumn([
                'vwap_dist',
                'vwap_dist_pct',
                'above_vwap',
                'ema9',
                'ema21',
                'ema9_ema21_spread',
                'ema9_above_ema21',
                'atr',
                'atr_pct',
            ]);
        });
    }
};
