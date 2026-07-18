<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Add critical indexes based on actual query patterns:
     * 1. Duplicate checking (side, status, created_at)
     * 2. Capital tracking (side, status, filled_at)
     * 3. Symbol duplicate prevention (symbol, side, status, created_at)
     * 4. P&L matching (symbol, side, status, filled_at)
     * 5. Stop loss queries (order_type, status)
     * 6. Sync monitoring (status, created_at)
     * 7. Alert lookups on notes column
     */
    public function up(): void
    {
        Schema::table('alpaca_orders', function (Blueprint $table) {
            // 1. For duplicate checking queries (most critical)
            // Used by: PlaceAlpacaOrderForHighScoreAlerts, AlpacaTradingStatus
            $table->index(['side', 'status', 'created_at'], 'alpaca_orders_side_status_created_at_index');

            // 2. For capital/P&L calculations with filled_at
            // Used by: AlpacaCapitalInvestedController, AlpacaProfitLossController
            $table->index(['side', 'status', 'filled_at'], 'alpaca_orders_side_status_filled_at_index');

            // 3. For per-symbol duplicate prevention
            // Used by: PlaceAlpacaOrderForHighScoreAlerts (most critical for preventing duplicate buys)
            $table->index(['symbol', 'side', 'status', 'created_at'], 'alpaca_orders_symbol_side_status_created_index');

            // 4. For P&L and exit matching per symbol
            // Used by: AlpacaProfitLossController, VerifyAlpacaOrderFlow
            $table->index(['symbol', 'side', 'status', 'filled_at'], 'alpaca_orders_symbol_side_status_filled_index');

            // 5. For stop loss queries
            // Used by: AlpacaStopLossDuplicationTest, monitoring commands
            $table->index(['order_type', 'status'], 'alpaca_orders_order_type_status_index');

            // 6. For sync and monitoring queries
            // Used by: AlpacaSyncCheck, AlpacaTradingStatus
            $table->index(['status', 'created_at'], 'alpaca_orders_status_created_at_index');

            // 7. For alert_id lookups in notes (using varchar index for LIKE queries)
            // Used by: PlaceAlpacaOrderForHighScoreAlerts
            // Note: Full-text index would be better but requires MyISAM or InnoDB full-text support
            $table->index('notes', 'alpaca_orders_notes_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('alpaca_orders', function (Blueprint $table) {
            $table->dropIndex('alpaca_orders_side_status_created_at_index');
            $table->dropIndex('alpaca_orders_side_status_filled_at_index');
            $table->dropIndex('alpaca_orders_symbol_side_status_created_index');
            $table->dropIndex('alpaca_orders_symbol_side_status_filled_index');
            $table->dropIndex('alpaca_orders_order_type_status_index');
            $table->dropIndex('alpaca_orders_status_created_at_index');
            $table->dropIndex('alpaca_orders_notes_index');
        });
    }
};
