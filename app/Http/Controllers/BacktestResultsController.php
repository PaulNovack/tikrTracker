<?php

namespace App\Http\Controllers;

use App\Services\AtrPerformanceService;
use App\Services\TradingSettingService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class BacktestResultsController extends Controller
{
    public function __construct(private readonly AtrPerformanceService $atrPerformanceService) {}

    /**
     * Try to load scanner name directly from the scanner class.
     * Converts version string (e.g., "v140.0") to class name (e.g., "FiveMinuteSignalScannerV140_0")
     */
    private function getScannerNameFromClass(string $version): ?string
    {
        // Remove 'v' prefix and replace dots with underscores
        $versionClean = str_replace(['.', '-'], '_', ltrim($version, 'v'));
        $className = "App\\Services\\Trading\\FiveMinuteSignalScannerV{$versionClean}";

        if (class_exists($className)) {
            try {
                $scanner = app($className);
                if (method_exists($scanner, 'getName')) {
                    return $scanner->getName();
                }
            } catch (\Throwable $e) {
                // Fall back to hardcoded array
            }
        }

        return null;
    }

    /**
     * Map of version string => human-readable scanner name.
     * Used as fallback for versions without scanner classes.
     *
     * @return array<string, string>
     */
    private function scannerNames(): array
    {
        return [
            'v1.0' => 'Biased Scanner',
            'v1.0-biased' => 'Biased Scanner',
            'v14.0' => 'Intraday Swing',
            'v16.0' => 'Base Pattern',
            'v17.0' => 'Base Pattern',
            'v18.0' => 'Earnings Momentum',
            'v20.0' => 'Alligator Wake-Up',
            'v21.0' => 'Alligator Wake-Up',
            'v22.0' => 'Alligator Wake-Up',
            'v25.0' => 'Institutional Fade Detection',
            'v25.1' => 'Institutional Fade Detection',
            'v25.2' => 'Quality-First',
            'v26.0' => 'Hybrid',
            'v26.1' => 'Institutional Fade Detection',
            'v30.0' => 'Momentum 5M',
            'v31.0' => 'Momentum 5M',
            'v40.0' => 'Runner Momentum',
            'v40.1' => 'Runner Momentum',
            'v50.0' => 'Entry Score Based',
            'v60.0' => 'Hybrid Breakout',
            'v60.1' => 'Hybrid Breakout',
            'v60.2' => 'Hybrid Breakout',
            'v60.3' => 'Hybrid Breakout',
            'v70.0' => 'RSI Momentum Divergence',
            'v80.1' => 'Multi-Timeframe Confirmation',
            'v90.0' => 'Momentum Continuation',
            'v90.1' => 'Momentum Continuation',
            'v100.0' => 'Bottom Detection',
            'v120.0' => 'Elite Multi-Day Momentum',
            'v130.0' => 'Elite Momentum Extended',
            'v140.0' => 'Institutional Follow-Through',
            'v200.0' => 'TPB Trend-Pullback-Breakout',
            'v210.0' => 'Oversold Bounce',
            'v300.0' => 'Reversal Reclaim',
            'v400.0' => 'Multi-Day Pattern Continuation',
            'v600.0' => 'Hybrid Big-Move Breakout',
            'v700.0' => 'Risk-Off Winners',
            'v800.0' => 'Mean Reversion / Fade',
            'v810.0' => 'Market Scan Daily Upswing',
            'v820.0' => 'Pattern-Based Fade Detection',
            'v830.0' => 'Intraday Breakout/Reversal',
            'v900.0' => 'Momentum Continuation',
            'v900.1' => 'Risk-Off Winners',
            'v1100.0' => 'Scarcity Leader (RS vs SPY)',
            'v1200.0' => 'Two-Bar Momentum',
            'v1400.0' => 'Tight Stops Clean Trend',
            'v1500.0' => 'Opening Range Breakout',
            'v1600.0' => 'Early Momentum Pre-Breakout',
            'v2000.0' => 'Market Movers Universe',
            'v2000.1' => 'Market Movers Universe',
            'v3000.0' => 'Multi-TF EMA Alignment',
            'rt-v1.0' => 'Realtime',
            'rt-v1.1' => 'Realtime',
        ];
    }

    /** Resolve "Pipeline X — Name (vX.X)" label for a given version string. */
    private function pipelineLabel(string $version): string
    {
        // Try loading from scanner class first
        $name = $this->getScannerNameFromClass($version);

        // Fall back to hardcoded array
        if (! $name) {
            $name = $this->scannerNames()[$version] ?? '';
        }

        return $name ? "{$name} ({$version})" : "({$version})";
    }

    public function index(Request $request): Response
    {
        header('X-Debug-Skip: SQL-FILTER-v99');
        // Default to ALL, allow selection of A-S or ALL
        $pipeline = $request->query('pipeline', 'ALL');

        // Get version for selected pipeline
        $version = match ($pipeline) {
            'ALL' => 'All Pipelines',
            'B' => config('app.trade_alert_b_version', 'v19.0'),
            'C' => config('app.trade_alert_c_version', 'v25.0'),
            'D' => config('app.trade_alert_d_version', 'v26.0'),
            'E' => config('app.trade_alert_e_version', 'v100.0'),
            'F' => config('app.trade_alert_f_version', 'v70.0'),
            'G' => config('app.trade_alert_g_version', 'v80.1'),
            'H' => config('app.trade_alert_h_version', 'v26.1'),
            'I' => config('app.trade_alert_i_version', 'v14.0'),
            'J' => config('app.trade_alert_j_version', 'v2000.0'),
            'K' => config('app.trade_alert_k_version', 'v1100.0'),
            'L' => config('app.trade_alert_l_version', 'v1600.0'),
            'M' => config('app.trade_alert_m_version', 'v1.0'),
            'N' => config('app.trade_alert_n_version', 'v1200.0'),
            'O' => config('app.trade_alert_o_version', 'v1500.0'),
            'P' => config('app.trade_alert_p_version', 'v140.0'),
            'Q' => config('app.trade_alert_q_version', 'v27.0'),
            'R' => config('app.trade_alert_r_version', 'rt-v1.0'),
            'S' => config('app.trade_alert_s_version', 'rt-vwap-reversal-v1.0'),
            'BIASED1' => 'v1.0-biased',
            'MANUAL' => 'MANUAL',
            'EXTERNAL' => config('app.trade_alert_external_version', 'external'),
            default => config('app.trade_alert_a_version', 'v17.0'),
        };

        $today = Carbon::today('America/New_York')->toDateString();
        $startDate = $request->query('start_date') ?: $today;
        $endDate = $request->query('end_date') ?: $today;
        $entryType = $request->query('entry_type');
        $firstOnly = $request->query('first_only', 'false') === 'true';
        $hideBlacklisted = $request->query('hide_blacklisted', 'true') === 'true';
        $useFullTables = $request->query('use_full_tables', 'false') === 'true';
        $useTimeSlots = $request->query('use_time_slots', 'false') === 'true';
        \Log::info("BacktestResults(filtered): useTimeSlots={$useTimeSlots} pipeline={$pipeline}", ['query' => $request->all()]);
        $mlMinPct = (float) $request->query('ml_min', 0);
        $performanceData = $this->atrPerformanceService->analyzeVersion($version, $pipeline, $startDate, $endDate, $entryType, 'trade_alerts', $firstOnly, $hideBlacklisted, $mlMinPct, $useFullTables, $useTimeSlots);

        return Inertia::render('BacktestResults/index', [
            'pipeline' => $pipeline,
            'version' => $version,
            'scannerNames' => $this->scannerNames(),
            'pipelineAVersion' => config('app.trade_alert_a_version', 'v17.0'),
            'pipelineALabel' => $this->pipelineLabel(config('app.trade_alert_a_version', 'v17.0')),
            'pipelineBVersion' => config('app.trade_alert_b_version', 'v19.0'),
            'pipelineBLabel' => $this->pipelineLabel(config('app.trade_alert_b_version', 'v19.0')),
            'pipelineCVersion' => config('app.trade_alert_c_version', 'v25.0'),
            'pipelineCLabel' => $this->pipelineLabel(config('app.trade_alert_c_version', 'v25.0')),
            'pipelineDVersion' => config('app.trade_alert_d_version', 'v26.0'),
            'pipelineDLabel' => $this->pipelineLabel(config('app.trade_alert_d_version', 'v26.0')),
            'pipelineEVersion' => config('app.trade_alert_e_version', 'v100.0'),
            'pipelineELabel' => $this->pipelineLabel(config('app.trade_alert_e_version', 'v100.0')),
            'pipelineFVersion' => config('app.trade_alert_f_version', 'v70.0'),
            'pipelineFLabel' => $this->pipelineLabel(config('app.trade_alert_f_version', 'v70.0')),
            'pipelineGVersion' => config('app.trade_alert_g_version', 'v80.1'),
            'pipelineGLabel' => $this->pipelineLabel(config('app.trade_alert_g_version', 'v80.1')),
            'pipelineHVersion' => config('app.trade_alert_h_version', 'v26.1'),
            'pipelineHLabel' => $this->pipelineLabel(config('app.trade_alert_h_version', 'v26.1')),
            'pipelineIVersion' => config('app.trade_alert_i_version', 'v14.0'),
            'pipelineILabel' => $this->pipelineLabel(config('app.trade_alert_i_version', 'v14.0')),
            'pipelineJVersion' => config('app.trade_alert_j_version', 'v2000.0'),
            'pipelineJLabel' => $this->pipelineLabel(config('app.trade_alert_j_version', 'v2000.0')),
            'pipelineKVersion' => config('app.trade_alert_k_version', 'v1100.0'),
            'pipelineKLabel' => $this->pipelineLabel(config('app.trade_alert_k_version', 'v1100.0')),
            'pipelineLVersion' => config('app.trade_alert_l_version', 'v1600.0'),
            'pipelineLLabel' => $this->pipelineLabel(config('app.trade_alert_l_version', 'v1600.0')),
            'pipelineMVersion' => config('app.trade_alert_m_version', 'v1.0'),
            'pipelineMLabel' => $this->pipelineLabel(config('app.trade_alert_m_version', 'v1.0')),
            'pipelineNVersion' => config('app.trade_alert_n_version', 'v1200.0'),
            'pipelineNLabel' => $this->pipelineLabel(config('app.trade_alert_n_version', 'v1200.0')),
            'pipelineOVersion' => config('app.trade_alert_o_version', 'v1500.0'),
            'pipelineOLabel' => $this->pipelineLabel(config('app.trade_alert_o_version', 'v1500.0')),
            'pipelinePVersion' => config('app.trade_alert_p_version', 'v140.0'),
            'pipelinePLabel' => $this->pipelineLabel(config('app.trade_alert_p_version', 'v140.0')),
            'pipelineQVersion' => config('app.trade_alert_q_version', 'v27.0'),
            'pipelineQLabel' => $this->pipelineLabel(config('app.trade_alert_q_version', 'v27.0')),
            'pipelineRVersion' => config('app.trade_alert_r_version', 'rt-v1.0'),
            'pipelineRLabel' => $this->pipelineLabel(config('app.trade_alert_r_version', 'rt-v1.0')),
            'pipelineSVersion' => config('app.trade_alert_s_version', 'rt-vwap-reversal-v1.0'),
            'pipelineSLabel' => $this->pipelineLabel(config('app.trade_alert_s_version', 'rt-vwap-reversal-v1.0')),
            'pipelineBiased1Version' => 'v1.0-biased',
            'pipelineBiased1Label' => $this->pipelineLabel('v1.0-biased'),
            'atrMultiplier' => (float) env('AUTO_ALPACA_STOP_LOSS_ATR_MULTIPLIER', 4.0),
            'atrMinPct' => (float) env('AUTO_ALPACA_STOP_LOSS_ATR_MIN_PCT', 1.00),
            'atrMaxPct' => (float) env('AUTO_ALPACA_STOP_LOSS_ATR_MAX_PCT', 2.00),
            'summary' => $performanceData['summary'],
            'trades' => $performanceData['trades'],
            'targetBreakdown' => $performanceData['target_breakdown'],
            'availableEntryTypes' => $performanceData['available_entry_types'],
            'selectedEntryType' => $entryType,
            'isUnfiltered' => false,
            'firstOnly' => $firstOnly,
            'hideBlacklisted' => $hideBlacklisted,
            'useFullTables' => $useFullTables,
            'pipelineMlThresholds' => $this->pipelineMlThresholds(),
        ]);
    }

    public function unfilteredIndex(Request $request): Response
    {
        // Default to ALL, allow selection of A-S or ALL
        $pipeline = $request->query('pipeline', 'ALL');

        // Get version for selected pipeline
        $version = match ($pipeline) {
            'ALL' => 'All Pipelines',
            'B' => config('app.trade_alert_b_version', 'v19.0'),
            'C' => config('app.trade_alert_c_version', 'v25.0'),
            'D' => config('app.trade_alert_d_version', 'v26.0'),
            'E' => config('app.trade_alert_e_version', 'v100.0'),
            'F' => config('app.trade_alert_f_version', 'v70.0'),
            'G' => config('app.trade_alert_g_version', 'v80.1'),
            'H' => config('app.trade_alert_h_version', 'v26.1'),
            'I' => config('app.trade_alert_i_version', 'v14.0'),
            'J' => config('app.trade_alert_j_version', 'v2000.0'),
            'K' => config('app.trade_alert_k_version', 'v1100.0'),
            'L' => config('app.trade_alert_l_version', 'v1600.0'),
            'M' => config('app.trade_alert_m_version', 'v1.0'),
            'N' => config('app.trade_alert_n_version', 'v1200.0'),
            'O' => config('app.trade_alert_o_version', 'v1500.0'),
            'P' => config('app.trade_alert_p_version', 'v140.0'),
            'Q' => config('app.trade_alert_q_version', 'v27.0'),
            'R' => config('app.trade_alert_r_version', 'rt-v1.0'),
            'S' => config('app.trade_alert_s_version', 'rt-vwap-reversal-v1.0'),
            'BIASED1' => 'v1.0-biased',
            'MANUAL' => 'MANUAL',
            'EXTERNAL' => config('app.trade_alert_external_version', 'external'),
            default => config('app.trade_alert_a_version', 'v17.0'),
        };

        $today = Carbon::today('America/New_York')->toDateString();
        $startDate = $request->query('start_date') ?: $today;
        $endDate = $request->query('end_date') ?: $today;
        $entryType = $request->query('entry_type');
        $firstOnly = $request->query('first_only', 'false') === 'true';
        $hideBlacklisted = $request->query('hide_blacklisted', 'true') === 'true';
        $useFullTables = $request->query('use_full_tables', 'false') === 'true';
        $useTimeSlots = $request->query('use_time_slots', 'false') === 'true';
        \Log::info("BacktestResults(unfiltered): useTimeSlots={$useTimeSlots} pipeline={$pipeline}", ['query' => $request->all()]);
        $mlMinPct = (float) $request->query('ml_min', 0);
        $performanceData = $this->atrPerformanceService->analyzeVersion($version, $pipeline, $startDate, $endDate, $entryType, 'trade_alerts_unfiltered', $firstOnly, $hideBlacklisted, $mlMinPct, $useFullTables, $useTimeSlots);

        return Inertia::render('BacktestResults/index', [
            'pipeline' => $pipeline,
            'version' => $version,
            'scannerNames' => $this->scannerNames(),
            'pipelineAVersion' => config('app.trade_alert_a_version', 'v17.0'),
            'pipelineALabel' => $this->pipelineLabel(config('app.trade_alert_a_version', 'v17.0')),
            'pipelineBVersion' => config('app.trade_alert_b_version', 'v19.0'),
            'pipelineBLabel' => $this->pipelineLabel(config('app.trade_alert_b_version', 'v19.0')),
            'pipelineCVersion' => config('app.trade_alert_c_version', 'v25.0'),
            'pipelineCLabel' => $this->pipelineLabel(config('app.trade_alert_c_version', 'v25.0')),
            'pipelineDVersion' => config('app.trade_alert_d_version', 'v26.0'),
            'pipelineDLabel' => $this->pipelineLabel(config('app.trade_alert_d_version', 'v26.0')),
            'pipelineEVersion' => config('app.trade_alert_e_version', 'v100.0'),
            'pipelineELabel' => $this->pipelineLabel(config('app.trade_alert_e_version', 'v100.0')),
            'pipelineFVersion' => config('app.trade_alert_f_version', 'v70.0'),
            'pipelineFLabel' => $this->pipelineLabel(config('app.trade_alert_f_version', 'v70.0')),
            'pipelineGVersion' => config('app.trade_alert_g_version', 'v80.1'),
            'pipelineGLabel' => $this->pipelineLabel(config('app.trade_alert_g_version', 'v80.1')),
            'pipelineHVersion' => config('app.trade_alert_h_version', 'v26.1'),
            'pipelineHLabel' => $this->pipelineLabel(config('app.trade_alert_h_version', 'v26.1')),
            'pipelineIVersion' => config('app.trade_alert_i_version', 'v14.0'),
            'pipelineILabel' => $this->pipelineLabel(config('app.trade_alert_i_version', 'v14.0')),
            'pipelineJVersion' => config('app.trade_alert_j_version', 'v2000.0'),
            'pipelineJLabel' => $this->pipelineLabel(config('app.trade_alert_j_version', 'v2000.0')),
            'pipelineKVersion' => config('app.trade_alert_k_version', 'v1100.0'),
            'pipelineKLabel' => $this->pipelineLabel(config('app.trade_alert_k_version', 'v1100.0')),
            'pipelineLVersion' => config('app.trade_alert_l_version', 'v1600.0'),
            'pipelineLLabel' => $this->pipelineLabel(config('app.trade_alert_l_version', 'v1600.0')),
            'pipelineMVersion' => config('app.trade_alert_m_version', 'v1.0'),
            'pipelineMLabel' => $this->pipelineLabel(config('app.trade_alert_m_version', 'v1.0')),
            'pipelineNVersion' => config('app.trade_alert_n_version', 'v1200.0'),
            'pipelineNLabel' => $this->pipelineLabel(config('app.trade_alert_n_version', 'v1200.0')),
            'pipelineOVersion' => config('app.trade_alert_o_version', 'v1500.0'),
            'pipelineOLabel' => $this->pipelineLabel(config('app.trade_alert_o_version', 'v1500.0')),
            'pipelinePVersion' => config('app.trade_alert_p_version', 'v140.0'),
            'pipelinePLabel' => $this->pipelineLabel(config('app.trade_alert_p_version', 'v140.0')),
            'pipelineQVersion' => config('app.trade_alert_q_version', 'v27.0'),
            'pipelineQLabel' => $this->pipelineLabel(config('app.trade_alert_q_version', 'v27.0')),
            'pipelineRVersion' => config('app.trade_alert_r_version', 'rt-v1.0'),
            'pipelineRLabel' => $this->pipelineLabel(config('app.trade_alert_r_version', 'rt-v1.0')),
            'pipelineSVersion' => config('app.trade_alert_s_version', 'rt-vwap-reversal-v1.0'),
            'pipelineSLabel' => $this->pipelineLabel(config('app.trade_alert_s_version', 'rt-vwap-reversal-v1.0')),
            'pipelineBiased1Version' => 'v1.0-biased',
            'pipelineBiased1Label' => $this->pipelineLabel('v1.0-biased'),
            'atrMultiplier' => (float) env('AUTO_ALPACA_STOP_LOSS_ATR_MULTIPLIER', 4.0),
            'atrMinPct' => (float) env('AUTO_ALPACA_STOP_LOSS_ATR_MIN_PCT', 1.00),
            'atrMaxPct' => (float) env('AUTO_ALPACA_STOP_LOSS_ATR_MAX_PCT', 2.00),
            'summary' => $performanceData['summary'],
            'trades' => $performanceData['trades'],
            'targetBreakdown' => $performanceData['target_breakdown'],
            'availableEntryTypes' => $performanceData['available_entry_types'],
            'selectedEntryType' => $entryType,
            'isUnfiltered' => true,
            'firstOnly' => $firstOnly,
            'hideBlacklisted' => $hideBlacklisted,
            'useFullTables' => $useFullTables,
            'pipelineMlThresholds' => $this->pipelineMlThresholds(),
        ]);
    }

    /**
     * Returns a map of pipeline letter => configured ML threshold (0–1).
     * Falls back to the global default when no pipeline-specific value is set.
     *
     * @return array<string, float>
     */
    private function pipelineMlThresholds(): array
    {
        return TradingSettingService::getAllPipelineMlThresholds();
    }
}
