<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Seeds all 20 active alert versions with their complete gate configurations
 * extracted directly from each FiveMinuteSignalScanner class properties.
 */
class AlertVersionSeeder extends Seeder
{
    public function run(): void
    {
        // Truncate existing data for clean re-seed
        DB::table('alert_version_gates')->delete();
        DB::table('alert_versions')->delete();

        // A | v90.1 | Momentum Breakout
        $this->seed('A', 'v90.1', 'MOMENTUM_BREAKOUT', 'move30m*0.4+rvolRatio*0.4+atrPct*0.2', array_merge($this->g5(0.30, 1.5, 2.0, 50000), [
            ['above_vwap', null, null],
            ['ema9_above_ema21', null, null],
            ['green_close', null, null],
            ['green_bar_pct', 50, null],
        ]), $this->g1(50000, 1.2, 0.10, 1.5, 0.75, 1.5));

        // B | v120.0 | Bull Flag Breakout
        $this->seed('B', 'v120.0', 'BULL_FLAG_BREAKOUT', 'move30m*0.5+rvolRatio*0.3+greenDays*0.2', array_merge($this->g5(0.25, 1.4, 1.5, 50000), [
            ['higher_low_count', 2, null],
            ['pullback_depth_pct', null, 45],
            ['above_vwap', null, null],
            ['ema9_above_ema21', null, null],
            ['ema9_slope_positive', null, null],
        ]), $this->g1(50000, 1.1, 0.08, 1.5, 0.7, 1.5));

        // C | v101.0 | VWAP Pullback and Hold
        $this->seed('C', 'v101.0', 'VWAP_PULLBACK_HOLD', 'move30m*0.5+rvolRatio*0.3+atrPct*0.2', array_merge($this->g5(0.20, 1.2, 0.8, 40000), [
            ['above_vwap', null, null],
            ['ema9_above_ema21', null, null],
            ['vwap_distance_min', 0.05, null],
            ['max_above_vwap_pct', null, 1.0],
            ['pullback_depth_pct', null, 60],
        ]), $this->g1(40000, 1.0, 0.08, 1.5, 0.6, 1.2));

        // D | v60.3 | VWAP Reclaim
        $this->seed('D', 'v60.3', 'VWAP_RECLAIM', 'move30m*0.3+rvolRatio*0.5+atrPct*0.2', array_merge($this->g5(0.20, 1.3, 1.0, 40000), [
            ['above_vwap', null, null],
            ['ema9_above_ema21', null, null],
            ['vwap_violation_count', null, 1],
            ['vwap_reclaim_strength_pct', 0.5, null],
            ['vwap_reclaim_wick_below_pct', 0.5, null],
        ]), $this->g1(40000, 1.0, 0.08, 1.0, 0.6, 1.2));

        // E | v400.0 | Opening Range Breakout
        $this->seed('E', 'v400.0', 'ORB_BREAKOUT', 'vwap*0.25+ema_trend*0.20+hh_hl*0.15+support*0.15+demand*0.10+vol*0.10', array_merge($this->g5(0.30, 1.5, 1.5, 75000), [
            ['opening_range_width_pct', 0.20, 3.0],
            ['opening_range_bar_count', 1, 6],
            ['above_vwap', null, null],
            ['ema9_above_ema21', null, null],
        ]), $this->g1(50000, 1.0, 0.08, 1.0, 0.7, 1.2));

        // F | v900.1 | ORB Retest
        $this->seed('F', 'v900.1', 'ORB_RETEST', 'move30m*0.3+rvolRatio*0.3+rsi*0.2+atrPct*0.2', array_merge($this->g5(0.25, 1.3, 1.0, 75000), [
            ['opening_range_width_pct', 0.20, 3.5],
            ['opening_range_bar_count', 1, 8],
            ['or_retest_depth_pct', null, 2.0],
            ['or_hold_close_pct', 0.5, null],
            ['above_vwap', null, null],
        ]), $this->g1(50000, 1.0, 0.08, 1.0, 0.7, 1.2));

        // G | v35.0 | EMA9 Pullback
        $this->seed('G', 'v35.0', 'EMA9_PULLBACK', 'move30m*1.2+min(6,rvolRatio)*1.0+atrPct*0.8', array_merge($this->g5(0.20, 1.2, 1.0, 40000), [
            ['ema9_above_ema21', null, null],
            ['ema9_slope_positive', null, null],
            ['ema_spread_pct', 0.05, null],
            ['pullback_depth_pct', null, 40],
        ]), array_merge($this->g1(40000, 1.0, 0.08, 1.0, 0.6, 1.2), [
            ['ema9_pullback_depth_pct', null, 20],
            ['ema9_reclaim_pct', 0.5, null],
        ]));

        // H | v25.2 | High of Day Breakout
        $this->seed('H', 'v25.2', 'HOD_BREAKOUT', 'move30m*1.2+min(6,rvolRatio)*1.0+atrPct*0.8', array_merge($this->g5(0.20, 1.3, 1.2, 40000), [
            ['dist_to_hod_pct', null, 1.0],
            ['range_contraction', null, null],
            ['closes_near_high_count', 2, null],
            ['green_bar_pct', 40, null],
            ['above_vwap', null, null],
            ['ema9_above_ema21', null, null],
        ]), $this->g1(35000, 1.0, 0.06, 1.5, 0.75, 1.0));

        // I | v17.0 | Relative Strength Momentum
        $this->seed('I', 'v17.0', 'RELATIVE_STRENGTH_MOMENTUM', 'move30m*0.3+rvolRatio*0.3+atrPct*0.2+vol_ratio_1m*0.2', array_merge($this->g5(0.15, 0.60, -0.25, 12000), [
            ['rs_ratio', 1.00, null],
            ['above_vwap', null, null],
            ['ema9_above_ema21', null, null],
        ]), $this->g1(25000, 0.9, 0.04, 2.0, 0.75, 0.9));

        // J | v2000.0 | Gap and Go
        $this->seed('J', 'v2000.0', 'GAP_AND_GO', 'move30m*0.2+rvolRatio*0.2+atrPct*0.1+notional/100000*0.1', array_merge($this->g5(0.25, 1.3, 1.2, 40000), [
            ['yesterday_move_pct', 2.0, null],
            ['move_from_open_pct', 1.5, null],
            ['above_vwap', null, null],
            ['ema9_above_ema21', null, null],
        ]), $this->g1(35000, 1.0, 0.06, 1.5, 0.6, 1.0));

        // K | v1100.0 | Volume Surge Breakout
        $this->seed('K', 'v1100.0', 'VOLUME_SURGE_BREAKOUT', 'move30m*0.4+rvolRatio*0.3+rs_ratio*0.2+atrPct*0.1', array_merge($this->g5(0.25, 1.5, 1.2, 50000), [
            ['breakout_volume_ratio', 1.5, null],
            ['above_vwap', null, null],
            ['ema9_above_ema21', null, null],
            ['green_close', null, null],
        ]), $this->g1(50000, 1.2, 0.10, 1.0, 0.7, 1.2));

        // L | v1600.0 | VWAP Mean Reversion
        $this->seed('L', 'v1600.0', 'VWAP_MEAN_REVERSION', 'move30m*1.2+min(6,rvolRatio)*1.0+atrPct*0.8', array_merge($this->g5(0.12, 0.85, 0.4, 15000), [
            ['max_above_vwap_pct', null, 0.75],
            ['vwap_distance_min', null, 0.3],
            ['distance_from_high_atr', null, 1.2],
        ]), $this->g1(18000, 0.8, 0.06, 1.0, 1.0, 1.0));

        // M | v103.0 | EMA9 / EMA21 Trend Continuation
        $this->seed('M', 'v103.0', 'EMA9_EMA21_TREND_CONTINUATION', 'move30m*0.3+rvolRatio*0.4+atrPct*0.3', array_merge($this->g5(0.20, 1.2, 1.0, 50000), [
            ['ema9_above_ema21', null, null],
            ['ema9_slope_positive', null, null],
            ['ema_spread_pct', 0.05, null],
            ['pullback_depth_pct', null, 30],
            ['above_vwap', null, null],
        ]), array_merge($this->g1(50000, 1.0, 0.08, 1.0, 0.7, 1.2), [
            ['ema9_pullback_depth_pct', null, 20],
            ['ema9_reclaim_pct', 0.5, null],
        ]));

        // N | v1200.0 | Failed Breakdown Reversal
        $this->seed('N', 'v1200.0', 'FAILED_BREAKDOWN_REVERSAL', 'move30m*0.5+rvolRatio*0.3+atrPct*0.2', array_merge($this->g5(0.18, 1.2, 0.8, 30000), [
            ['vwap_reclaim_strength_pct', 0.35, null],
            ['vwap_reclaim_wick_below_pct', 0.35, null],
            ['above_vwap', null, null],
            ['ema9_above_ema21', null, null],
        ]), $this->g1(25000, 0.9, 0.06, 1.2, 0.5, 1.0));

        // O | v1500.0 | Catalyst / News Momentum
        $this->seed('O', 'v1500.0', 'CATALYST_NEWS_MOMENTUM', 'move30m*0.4+rvolRatio*0.3+atrPct*0.3', array_merge($this->g5(0.25, 1.6, 1.2, 40000), [
            ['above_vwap', null, null],
            ['ema9_above_ema21', null, null],
            ['green_close', null, null],
        ]), $this->g1(35000, 1.1, 0.10, 1.25, 0.7, 1.0));

        // P | v140.0 | ML Ensemble / Meta Strategy
        $this->seed('P', 'v140.0', 'ML_ENSEMBLE_META', 'move30m*1.5+min(4,rvolRatio)*0.6+atrPct*1.0+greenDays*2.0', [
            ['notional', 80000, null],
            ['atr_pct', 0.35, null],
            ['rvol_ratio', 1.3, null],
            ['move_30m_pct', 1.2, null],
            ['multi_day_green_count', 2, null],
            ['price', 4.0, null],
        ], [
            ['notional_1m', 70000, null],
            ['vol_ratio_1m', 1.1, null],
            ['body_pct', 0.07, null],
            ['above_vwap_entry_pct', null, 1.2],
            ['room_to_hod_pct', 1.0, null],
            ['min_bars', 15, null],
        ]);

        // Q | v27.0 | VOLUME_FIRST_V27
        $this->seed('Q', 'v27.0', 'VOLUME_FIRST_V27', 'move30m*2.0+rvolRatio*1.0+atrPct*1.0+greenDays*2.0', [
            ['price', 2.0, null],
            ['notional', 30000, null],
            ['atr_pct', 0.15, null],
            ['rvol_ratio', 1.2, 10.0],
            ['move_30m_pct', 0.4, null],
            ['multi_day_green_count', 1, null],
            ['rs_ratio', 1.02, null],
        ], [
            ['notional_1m', 100000, null],
            ['vol_ratio_1m', 1.5, null],
            ['body_pct', 0.10, null],
            ['above_vwap_entry_pct', null, 0.75],
            ['room_to_hod_pct', 0.6, null],
            ['min_bars', 15, null],
        ]);

        // R | rt-v2.0 | REALTIME_V2
        $this->seed('R', 'rt-v2.0', 'REALTIME_V2', 'move30m*0.3+rvolRatio*0.3+atrPct*0.2+vol_ratio_1m*0.2', $this->g5(), $this->g1());

        // S | rt-v1.0 | REALTIME_V1
        $this->seed('S', 'rt-v1.0', 'REALTIME_V1', 'move30m*0.3+rvolRatio*0.3+atrPct*0.2+vol_ratio_1m*0.2', $this->g5(), $this->g1());
    }

    // ── Generic 5m gates ──
    private function g5(float $atr = 0.20, float $rvol = 1.2, float $move = 0.3, float $notional = 30000): array
    {
        return [
            ['notional', $notional, null],
            ['atr_pct', $atr, null],
            ['rvol_ratio', $rvol, null],
            ['move_30m_pct', $move, null],
            ['price', 2.0, null],
        ];
    }

    // ── Generic 1m gates ──
    private function g1(float $notional = 50000, float $vol = 1.0, float $body = 0.05, float $vwapMax = 3.0, float $room = 0.5, float $roomAtr = 1.5, int $minBars = 15): array
    {
        return [
            ['notional_1m', $notional, null],
            ['vol_ratio_1m', $vol, null],
            ['body_pct', $body, null],
            ['above_vwap_entry_pct', null, $vwapMax],
            ['room_to_hod_pct', $room, null],
            ['room_atr_mult', $roomAtr, null],
            ['min_bars', $minBars, null],
            ['time_blocked', null, 0],
            ['extreme_drop', null, 0],
        ];
    }

    // ── Insert ──
    private function seed(string $letter, string $version, string $signalType, ?string $formula, array $gates5m, array $gates1m): void
    {
        $vid = DB::table('alert_versions')->insertGetId([
            'pipeline_letter' => $letter,
            'version_string' => $version,
            'signal_type' => $signalType,
            'scanner_score_formula' => $formula,
            'enabled' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $now = now();
        foreach ($gates5m as [$name, $min, $max]) {
            DB::table('alert_version_gates')->insert([
                'alert_version_id' => $vid, 'timeframe' => '5m', 'gate_name' => $name,
                'threshold_min' => $min, 'threshold_max' => $max, 'enabled' => true,
                'created_at' => $now, 'updated_at' => $now,
            ]);
        }
        foreach ($gates1m as [$name, $min, $max]) {
            DB::table('alert_version_gates')->insert([
                'alert_version_id' => $vid, 'timeframe' => '1m', 'gate_name' => $name,
                'threshold_min' => $min, 'threshold_max' => $max, 'enabled' => true,
                'created_at' => $now, 'updated_at' => $now,
            ]);
        }
    }
}
