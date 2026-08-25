<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        DB::statement('ALTER TABLE trade_alerts_backtest_candidates MODIFY five_min_green_bar_pct DECIMAL(6, 2) NULL');
        DB::statement('ALTER TABLE trade_alerts_backtest_candidates MODIFY five_min_net_progress DECIMAL(8, 4) NULL');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('ALTER TABLE trade_alerts_backtest_candidates MODIFY five_min_green_bar_pct DECIMAL(4, 1) NULL');
        DB::statement('ALTER TABLE trade_alerts_backtest_candidates MODIFY five_min_net_progress DECIMAL(4, 3) NULL');
    }
};
