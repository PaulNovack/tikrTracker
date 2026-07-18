<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Adds v25.2 Pipeline H ML feature columns to trade_alerts and trade_alerts_unfiltered.
     * These are NULL for all other pipelines — only pipeline H populates them.
     */
    public function up(): void
    {
        $columns = function (Blueprint $table): void {
            // Scanner features (from FiveMinuteSignalScannerV25_2 meta)
            $table->decimal('move_30m_pct', 10, 4)->nullable()->after('failed_rally_count');
            $table->decimal('rvol_5m', 10, 4)->nullable()->after('move_30m_pct');
            $table->decimal('atr_pct_5m', 10, 4)->nullable()->after('rvol_5m');
            $table->decimal('notional_last5m', 18, 2)->nullable()->after('atr_pct_5m');
            $table->decimal('pct_nd', 10, 4)->nullable()->after('notional_last5m');
            $table->decimal('spy_move_30m_pct', 10, 4)->nullable()->after('pct_nd');
            $table->integer('universe_size')->unsigned()->nullable()->after('spy_move_30m_pct');

            // Room-to-run features
            $table->decimal('hod', 20, 8)->nullable()->after('universe_size');
            $table->decimal('room_to_hod_pct', 10, 4)->nullable()->after('hod');
            $table->decimal('room_to_hod_atr', 10, 4)->nullable()->after('room_to_hod_pct');

            // VWAP entry distance
            $table->decimal('above_vwap_entry_pct', 10, 4)->nullable()->after('room_to_hod_atr');

            // Entry quality
            $table->decimal('entry_body_pct', 10, 4)->nullable()->after('above_vwap_entry_pct');
            $table->decimal('entry_close_position', 10, 6)->nullable()->after('entry_body_pct');
            $table->decimal('entry_volume_ratio', 10, 4)->nullable()->after('entry_close_position');
            $table->decimal('entry_notional_1m', 18, 2)->nullable()->after('entry_volume_ratio');

            // Entry score sub-components (from computeEntryScoreComponents)
            $table->decimal('entry_spread_strength', 10, 6)->nullable()->after('entry_notional_1m');
            $table->decimal('entry_vwap_dist_score', 10, 6)->nullable()->after('entry_spread_strength');
            $table->decimal('entry_atr_score', 10, 6)->nullable()->after('entry_vwap_dist_score');
            $table->decimal('entry_vol_score', 10, 6)->nullable()->after('entry_atr_score');
            $table->decimal('entry_candle_score', 10, 6)->nullable()->after('entry_vol_score');
            $table->decimal('entry_time_bonus', 10, 6)->nullable()->after('entry_candle_score');

            // VWAP reclaim specific
            $table->decimal('vwap_reclaim_strength_pct', 10, 4)->nullable()->after('entry_time_bonus');
            $table->decimal('vwap_reclaim_wick_below_pct', 10, 4)->nullable()->after('vwap_reclaim_strength_pct');

            // ORB retest specific
            $table->decimal('or_high_v252', 20, 8)->nullable()->after('vwap_reclaim_wick_below_pct');
            $table->decimal('or_break_distance_pct', 10, 4)->nullable()->after('or_high_v252');
            $table->decimal('or_retest_depth_pct', 10, 4)->nullable()->after('or_break_distance_pct');
            $table->decimal('or_hold_close_pct', 10, 4)->nullable()->after('or_retest_depth_pct');
            $table->integer('bars_since_or_break')->unsigned()->nullable()->after('or_hold_close_pct');

            // EMA9 pullback specific
            $table->decimal('ema9_pullback_depth_pct', 10, 4)->nullable()->after('bars_since_or_break');
            $table->decimal('ema9_reclaim_pct', 10, 4)->nullable()->after('ema9_pullback_depth_pct');
        };

        Schema::table('trade_alerts', $columns);
        Schema::table('trade_alerts_unfiltered', $columns);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $dropColumns = [
            'move_30m_pct', 'rvol_5m', 'atr_pct_5m', 'notional_last5m', 'pct_nd',
            'spy_move_30m_pct', 'universe_size',
            'hod', 'room_to_hod_pct', 'room_to_hod_atr',
            'above_vwap_entry_pct',
            'entry_body_pct', 'entry_close_position', 'entry_volume_ratio', 'entry_notional_1m',
            'entry_spread_strength', 'entry_vwap_dist_score', 'entry_atr_score',
            'entry_vol_score', 'entry_candle_score', 'entry_time_bonus',
            'vwap_reclaim_strength_pct', 'vwap_reclaim_wick_below_pct',
            'or_high_v252', 'or_break_distance_pct', 'or_retest_depth_pct',
            'or_hold_close_pct', 'bars_since_or_break',
            'ema9_pullback_depth_pct', 'ema9_reclaim_pct',
        ];

        Schema::table('trade_alerts', function (Blueprint $table) use ($dropColumns) {
            $table->dropColumn($dropColumns);
        });
        Schema::table('trade_alerts_unfiltered', function (Blueprint $table) use ($dropColumns) {
            $table->dropColumn($dropColumns);
        });
    }
};
