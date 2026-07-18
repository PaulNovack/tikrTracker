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
        Schema::table('trade_alerts_unfiltered', function (Blueprint $table) {
            // When no_filter_finder=true, these 1-minute finder fields will be NULL
            // because we bypass the finder and create SCANNER_5MIN entries directly
            $table->decimal('entry', 10, 2)->nullable()->change();
            $table->decimal('stop', 10, 2)->nullable()->change();
            $table->decimal('risk_pct', 10, 2)->nullable()->change();
            $table->decimal('risk_per_share', 10, 4)->nullable()->change();
            $table->decimal('vol_ratio', 10, 2)->nullable()->change();
            $table->decimal('atr', 10, 4)->nullable()->change();
            $table->decimal('atr_pct', 10, 2)->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('trade_alerts_unfiltered', function (Blueprint $table) {
            $table->decimal('entry', 10, 2)->nullable(false)->change();
            $table->decimal('stop', 10, 2)->nullable(false)->change();
            $table->decimal('risk_pct', 10, 2)->nullable(false)->change();
            $table->decimal('risk_per_share', 10, 4)->nullable(false)->change();
            $table->decimal('vol_ratio', 10, 2)->nullable(false)->change();
            $table->decimal('atr', 10, 4)->nullable(false)->change();
            $table->decimal('atr_pct', 10, 2)->nullable(false)->change();
        });
    }
};
