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

        // A | v90.1 | MOMENTUM_BREAKOUT — entry score based → computed from gates
        $this->seed('A', 'v90.1', 'MOMENTUM_BREAKOUT', 'move30m*0.4+rvolRatio*0.4+atrPct*0.2', [
            ['entry_score_min', 93, 100],
            ['yesterday_move_pct', 5.0, null],
            ['yesterday_vol_mult', 1.5, null],
        ], $this->g1());

        // B | v120.0 | ELITE_MOMENTUM_CONTINUATION — multi-day momentum
        $this->seed('B', 'v120.0', 'ELITE_MOMENTUM_CONTINUATION', 'move30m*0.5+rvolRatio*0.3+greenDays*0.2', [
            ['entry_score_min', 70, 95],
            ['multi_day_green_count', 2, null],
            ['yesterday_move_pct', 2.0, null],
            ['require_vol_increase', null, null],
        ], $this->g1());

        // C | v101.0 | MOMENTUM_ACCELERATION_SURGE_5M_V101
        $this->seed('C', 'v101.0', 'MOMENTUM_ACCELERATION_SURGE_5M_V101', 'move30m*0.5+rvolRatio*0.3+atrPct*0.2', [
            ['notional', 100000, null],
            ['atr_pct', 0.45, null],
            ['rvol_ratio', 1.50, null],
            ['move_30m_pct', 0.35, null],
            ['above_vwap', null, null],
            ['ema9_above_ema21', null, null],
        ], $this->g1());

        // D | v60.3 | HYBRID_MOMO_ENTRY_SCORE
        $this->seed('D', 'v60.3', 'HYBRID_MOMO_ENTRY_SCORE', 'move30m*0.3+rvolRatio*0.5+atrPct*0.2', [
            ['entry_score_min', 80, 98],
            ['notional', 30000, null],
        ], $this->g1());

        // E | v400.0 | TREND_CONTINUATION — multi-day pattern
        $this->seed('E', 'v400.0', 'TREND_CONTINUATION', 'vwap*0.25+ema_trend*0.20+hh_hl*0.15+support*0.15+demand*0.10+vol*0.10', [
            ['atr_pct', 2.0, null],
            ['rvol_ratio', 2.5, null],
            ['pullback_depth_pct', null, 60],
            ['higher_low_count', 3, null],
            ['above_vwap', null, null],
            ['ema9_above_ema21', null, null],
            ['ema9_slope_positive', null, null],
            ['vwap_violation_count', null, 0],
            ['closes_near_high_count', 5, null],
        ], $this->g1());

        // F | v900.1 | MOMENTUM_CONTINUATION_SETUP
        $this->seed('F', 'v900.1', 'MOMENTUM_CONTINUATION_SETUP', 'move30m*0.3+rvolRatio*0.3+rsi*0.2+atrPct*0.2', [
            ['price', 3.0, 500],
            ['entry_score_min', 40, 100],
            ['yesterday_move_pct', -5.0, null],
            ['move_from_open_pct', 2.0, null],
            ['rsi', 60, null],
            ['rvol_ratio', 2.0, null],
        ], $this->g1());

        // G | v35.0 | MOMO_5M_V35 — same family as H, looser
        $this->seed('G', 'v35.0', 'MOMO_5M_V35', 'move30m*1.2+min(6,rvolRatio)*1.0+atrPct*0.8', [
            ['notional', 75000, null],
            ['atr_pct', 0.35, null],
            ['rvol_ratio', 2.0, null],
            ['move_30m_pct', 1.2, null],
            ['rs_ratio', 1.20, null],
            ['price', 2.0, null],
        ], $this->g1(80000, 1.0, 0.05, 0.90, 0.6, 1.5));

        // H | v25.2 | MOMO_5M_V25 — quality-first (tightest defaults)
        $this->seed('H', 'v25.2', 'MOMO_5M_V25', 'move30m*1.2+min(6,rvolRatio)*1.0+atrPct*0.8', [
            ['notional', 75000, null],
            ['atr_pct', 0.35, null],
            ['rvol_ratio', 2.0, null],
            ['move_30m_pct', 1.2, null],
            ['rs_ratio', 1.20, null],
            ['price', 2.0, null],
        ], [
            ['notional_1m', 100000, null],
            ['vol_ratio_1m', 2.5, null],
            ['body_pct', 0.40, null],
            ['above_vwap_entry_pct', null, 0.60],
            ['room_to_hod_pct', 0.8, null],
            ['room_atr_mult', 2.5, null],
            ['min_bars', 90, null],
        ]);

        // I | v17.0 | MOMO_5M — legacy adaptive volume
        $this->seed('I', 'v17.0', 'MOMO_5M', 'move30m*0.3+rvolRatio*0.3+atrPct*0.2+vol_ratio_1m*0.2', [
            ['notional', 20000, null],
            ['atr_pct', 0.15, null],
            ['rvol_ratio', 1.0, null],
            ['move_30m_pct', 1.2, null],
            ['price', 1.0, null],
            ['rs_ratio', 1.1, null],
        ], $this->g1(35000, 1.0, 0.03, 3.0, 0.35, 1.0));

        // J | v2000.0 | MOMO_5D_UNIVERSE — market movers with sensible gates
        $this->seed('J', 'v2000.0', 'MOMO_5D_UNIVERSE', 'move30m*0.2+rvolRatio*0.2+atrPct*0.1+notional/100000*0.1', [
            ['notional', 50000, null],
            ['atr_pct', 0.30, null],
            ['rvol_ratio', 1.5, null],
            ['move_30m_pct', 0.4, null],
            ['price', 3.0, null],
        ], $this->g1(50000, 1.5, 0.03, 3.0, 0.5, 1.5));

        // K | v1100.0 | SCARCITY_LEADER
        $this->seed('K', 'v1100.0', 'SCARCITY_LEADER', 'move30m*0.4+rvolRatio*0.3+rs_ratio*0.2+atrPct*0.1', [
            ['market_weakness', null, null],
            ['benchmark_below_vwap', null, null],
            ['price', 2.0, 80.0],
            ['green_close', null, null],
            ['above_vwap', null, null],
            ['ema9_above_ema21', null, null],
            ['ema_spread_pct', 0.08, null],
            ['rs_ratio', 1.10, null],
            ['notional', 2500, null],
            ['distance_from_high_atr', null, 1.0],
            ['range_contraction', null, null],
            ['move_30m_pct', 2.5, null],
            ['vwap_distance_min', 0.15, null],
            ['max_above_vwap_pct', null, 3.0],
        ], $this->g1(50000, 1.8, 0.05, 3.0, 0.5, 1.5));

        // L | v1600.0 | MOMO_5M_V1600 — quality-first extended
        $this->seed('L', 'v1600.0', 'MOMO_5M_V1600', 'move30m*1.2+min(6,rvolRatio)*1.0+atrPct*0.8', [
            ['notional', 150000, null],
            ['atr_pct', 0.55, null],
            ['rvol_ratio', 1.25, null],
            ['move_30m_pct', 0.45, null],
            ['price', 2.0, null],
        ], $this->g1(80000, 1.0, 0.05, 0.90, 0.6, 1.5));

        // M | v103.0 | ORB_RETEST_SETUP_5M_V103_0
        $this->seed('M', 'v103.0', 'ORB_RETEST_SETUP_5M_V103_0', 'move30m*0.3+rvolRatio*0.4+atrPct*0.3', [
            ['notional', 75000, null],
            ['atr_pct', 0.25, 4.50],
            ['rvol_ratio', 1.15, null],
            ['move_30m_pct', 0.35, null],
            ['above_vwap_pct', null, 2.10],
            ['ema_spread_pct', 0.00, null],
            ['opening_range_width_pct', 0.20, 4.50],
            ['opening_range_bar_count', 3, null],
        ], $this->g1(50000, 1.15, 0.03, 2.10, 0.5, 1.5));

        // N | v1200.0 | TWO_BAR_MOMENTUM — 3-bar gain ≥ 4%, 2 consecutive rising bars
        $this->seed('N', 'v1200.0', 'TWO_BAR_MOMENTUM', 'move30m*0.5+rvolRatio*0.3+atrPct*0.2', [
            ['price', 5.0, 100],
            ['rvol_ratio', 1.2, null],
            ['three_bar_gain_pct', 4.0, null],  // (close[0]-open[2])/open[2] — the actual gate
        ], $this->g1());

        // O | v1500.0 | ORB_BREAKOUT
        $this->seed('O', 'v1500.0', 'ORB_BREAKOUT', 'move30m*0.4+rvolRatio*0.3+atrPct*0.3', [
            ['price', 5.0, 100],
            ['rvol_ratio', 1.5, null],
            ['atr_pct', 1.0, 4.0],
            ['move_30m_pct', 2.0, null],
        ], $this->g1());

        // P | v140.0 | INSTITUTIONAL_V140
        $this->seed('P', 'v140.0', 'INSTITUTIONAL_V140', 'move30m*1.5+min(4,rvolRatio)*0.6+atrPct*1.0+greenDays*2.0', [
            ['notional', 100000, null],
            ['atr_pct', 0.40, null],
            ['rvol_ratio', 1.5, null],
            ['move_30m_pct', 1.5, null],
            ['multi_day_green_count', 3, null],
            ['price', 5.0, null],
        ], [
            ['notional_1m', 90000, null],
            ['vol_ratio_1m', 1.3, null],
            ['body_pct', 0.08, null],
            ['above_vwap_entry_pct', null, 1.0],
            ['room_to_hod_pct', 0.8, null],
            ['min_bars', 20, null],
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
