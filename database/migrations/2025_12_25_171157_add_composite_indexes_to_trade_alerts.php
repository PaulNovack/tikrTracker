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
            // Composite index for filtering by signal_type and version
            $table->index(['signal_type', 'version'], 'idx_signal_type_version');

            // Composite index for filtering analyzed alerts by version
            $table->index(['analyzed', 'version', 'signal_type'], 'idx_analyzed_version_signal');

            // Composite index for date-based queries with version
            $table->index(['trading_date_est', 'version'], 'idx_trading_date_version');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('trade_alerts', function (Blueprint $table) {
            $table->dropIndex('idx_signal_type_version');
            $table->dropIndex('idx_analyzed_version_signal');
            $table->dropIndex('idx_trading_date_version');
        });
    }
};
