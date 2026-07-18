<?php

namespace App\Http\Controllers;

use App\Models\CircuitBreakerEvent;
use App\Models\Setting;
use App\Services\Trading\TradeAlertWriterV1;
use App\Services\TradingSettingService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Inertia\Response;

class TradingSettingsController extends Controller
{
    /** @var list<string> */
    private const PIPELINES = ['a', 'b', 'c', 'd', 'e', 'f', 'g', 'h', 'i', 'j', 'k', 'l', 'm', 'n', 'o', 'p', 'q', 'r', 's', 'x', 'manual', 'external'];

    /** @var list<string> */
    private const MAX_AGE_PIPELINES = ['a', 'b', 'c', 'd', 'e', 'f', 'g', 'h', 'i', 'j', 'k', 'l', 'm', 'n', 'o', 'p', 'q', 'r', 's', 'manual', 'external'];

    /**
     * Show the trading settings page.
     */
    public function edit(): Response
    {
        abort_unless(auth()->user()?->isAdmin(), 403);

        return Inertia::render('trading-settings/index', [
            'isPaperTrading' => (bool) config('alpaca.paper_trading', true),
            'settings' => [
                'orders_enabled' => TradingSettingService::isOrdersEnabled(),
                'daily_loss_limit' => TradingSettingService::getDailyLossLimit(),
                'consecutive_loss_days' => TradingSettingService::getConsecutiveLossDays(),
                'intraday_halt_pre_11am' => TradingSettingService::getIntradayHaltLimitPre11am(),
                'intraday_halt_11am_1pm' => TradingSettingService::getIntradayHaltLimit11amTo1pm(),
                'intraday_halt_post_1pm' => TradingSettingService::getIntradayHaltLimitPost1pm(),
                'paper_resume_min_profit' => TradingSettingService::getPaperResumeMinProfit(),
                'paper_bypass_ml_threshold' => TradingSettingService::isPaperBypassMlThreshold(),
                'nightly_analyze_thresholds' => TradingSettingService::isNightlyAnalyzeThresholdsEnabled(),
                'max_spread_pct' => TradingSettingService::getMaxSpreadPct(),
                'max_quote_age_seconds' => TradingSettingService::getMaxQuoteAgeSeconds(),
                'retrade_symbol_wait_minutes' => TradingSettingService::getRetradeSymbolWaitMinutes(),
                'max_age_minutes' => TradingSettingService::getGlobalMaxAgeMinutes(),
                'skip_next_alert_after_ml_passed_minutes' => TradingSettingService::getSkipNextAlertAfterMlPassedMinutes(),
                'circuit_breaker_enabled' => TradingSettingService::isCircuitBreakerEnabled(),
                'circuit_breaker_stops_threshold' => TradingSettingService::getCircuitBreakerStopsThreshold(),
                'circuit_breaker_window_minutes' => TradingSettingService::getCircuitBreakerWindowMinutes(),
                'circuit_breaker_pause_minutes' => TradingSettingService::getCircuitBreakerPauseMinutes(),
                'max_position_pct_of_liquidity' => TradingSettingService::getMaxPositionPctOfLiquidity(),
                'min_position_size' => TradingSettingService::getMinPositionSize(),
                'max_position_size' => TradingSettingService::getMaxPositionSize(),
                'min_dollar_volume_per_minute' => TradingSettingService::getMinDollarVolumePerMinute(),
                'position_slippage_rule' => TradingSettingService::getPositionSizeSlippageRuleConfig(),
            ],
            'positionSizingStatus' => $this->buildPositionSizingStatus(),
            'stopLossSettings' => [
                'mode' => TradingSettingService::getStopLossMode(),
                'profit_protection_enabled' => TradingSettingService::isProfitProtectionEnabled(),
                'fixed_pct' => TradingSettingService::getStopLossFixedPct(),
                'atr_multiplier' => TradingSettingService::getStopLossAtrMultiplier(),
                'atr_min_pct' => TradingSettingService::getStopLossAtrMinPct(),
                'atr_max_pct' => TradingSettingService::getStopLossAtrMaxPct(),
            ],
            'limitOrderSettings' => [
                'use_limit_orders' => TradingSettingService::isUseLimitOrdersEnabled(),
                'slippage_pct' => TradingSettingService::getLimitSlippagePct(),
                'slippage_pct_stale_price' => TradingSettingService::getLimitSlippagePctStalePrice(),
                'partial_fill_stop_timeout_minutes' => TradingSettingService::getPartialFillStopTimeoutMinutes(),
                'pipeline_overrides' => TradingSettingService::getAllPipelineSlippageOverrides(),
            ],
            'tradingHours' => [
                'start_time' => TradingSettingService::getTradingStartTime(),
                'end_time' => TradingSettingService::getTradingEndTime(),
            ],
            'staleRescoreSettings' => [
                'enabled' => TradingSettingService::isStaleRescoreEnabled(),
                'paper_only' => TradingSettingService::isStaleRescorePaperOnly(),
                'max_age_minutes' => TradingSettingService::getStaleRescoreMaxAgeMinutes(),
            ],
            'benchmarkVwapGate' => [
                'enabled' => TradingSettingService::isBenchmarkVwapGateEnabled(),
                'symbol' => TradingSettingService::getBenchmarkSymbol(),
                'max_pct_below_high' => TradingSettingService::getBenchmarkMaxPctBelowHigh(),
                'pipeline_overrides' => TradingSettingService::getAllPipelineBenchmarkVwapGateOverrides(),
            ],
            'pipelines' => collect(self::PIPELINES)->mapWithKeys(fn ($p) => [
                $p => [
                    'run_cron' => TradingSettingService::isPipelineRunCronEnabled($p),
                    'live_rescore_enabled' => TradingSettingService::getPipelineLiveRescoreOverride($p),
                ],
            ])->all(),
            'pipelineDisplayNames' => TradingSettingService::getAllPipelineDisplayNames(),
            'pipelineAucValues' => $this->pipelineAucValues(),
            'precisionAtK' => TradingSettingService::getAllPrecisionAtK(),
            'pipelineMlUpdatedAt' => TradingSettingService::getAllPipelineMlUpdatedAt(),
            'pipelineMinAuc' => TradingSettingService::getMinAuc(),
            'pipelineMinPrecisionAtK' => TradingSettingService::getMinPrecisionAt10(),
            'maxAgeSettings' => collect(self::MAX_AGE_PIPELINES)->mapWithKeys(fn ($p) => [
                $p => TradingSettingService::getPipelineMaxAgeMinutes($p),
            ])->all(),
            'mlThresholds' => collect(self::PIPELINES)->mapWithKeys(fn ($p) => [
                $p => TradingSettingService::getPipelineMlThreshold($p),
            ])->all(),
            'timeSlots' => TradingSettingService::getTimeSlots(),
            'realtimeSlots' => TradingSettingService::getRealtimeSlots(),
            'circuitBreakerEvents' => CircuitBreakerEvent::query()
                ->orderByDesc('tripped_at')
                ->limit(20)
                ->get(['id', 'symbol', 'losing_stops_count', 'window_minutes', 'pause_minutes', 'tripped_at', 'pause_expires_at', 'is_paper'])
                ->map(fn (CircuitBreakerEvent $e) => [
                    'id' => $e->id,
                    'symbol' => $e->symbol,
                    'losing_stops_count' => $e->losing_stops_count,
                    'window_minutes' => $e->window_minutes,
                    'pause_minutes' => $e->pause_minutes,
                    'tripped_at' => $e->tripped_at->toISOString(),
                    'pause_expires_at' => $e->pause_expires_at->toISOString(),
                    'is_paper' => $e->is_paper,
                    'is_active' => $e->isActive(),
                ]),
            'realtimeSettings' => [
                'max_quote_age_seconds' => TradingSettingService::getMaxQuoteAgeSeconds(),
                'max_spread_pct' => TradingSettingService::getMaxSpreadPct(),
                'candidate_ttl_seconds' => (int) config('trading_realtime.candidate_ttl_seconds', 300),
                'early_score_min' => TradingSettingService::getRealtimeEarlyScoreMin(),
                'min_dollar_volume_1m' => TradingSettingService::getRealtimeMinDollarVolume1m(),
                'min_rvol' => TradingSettingService::getRealtimeMinRvol(),
                'min_atr_pct' => TradingSettingService::getRealtimeMinAtrPct(),
                'min_move_30m_pct' => TradingSettingService::getRealtimeMinMove30mPct(),
                'max_vwap_extension_pct' => TradingSettingService::getRealtimeMaxVwapExtensionPct(),
                'max_entry_age_seconds' => (int) config('trading_realtime.max_entry_age_seconds', 60),
                'skip_first_minutes' => TradingSettingService::getRealtimeSkipFirstMinutes(),
                // Entry trigger gates (DB-backed)
                'entry_candidate_max_age_seconds' => TradingSettingService::getRealtimeEntryCandidateMaxAgeSeconds(),
                'entry_final_score_min' => TradingSettingService::getRealtimeEntryFinalScoreMin(),
                'entry_min_price' => TradingSettingService::getRealtimeEntryMinPrice(),
                'entry_max_price' => TradingSettingService::getRealtimeEntryMaxPrice(),
                'entry_require_vwap' => TradingSettingService::isRealtimeEntryRequireVwap(),
                'entry_return_1m_min_pct' => TradingSettingService::getRealtimeEntryReturn1mMinPct(),
                'entry_return_3m_min_pct' => TradingSettingService::getRealtimeEntryReturn3mMinPct(),
                'entry_volume_ratio_min' => TradingSettingService::getRealtimeEntryVolumeRatioMin(),
                'entry_min_dollar_volume_1m' => TradingSettingService::getRealtimeEntryMinDollarVolume1m(),
                'max_move_since_candidate_pct' => TradingSettingService::getRealtimeMaxMoveSinceCandidatePct(),
                'entry_above_candidate_min_pct' => TradingSettingService::getRealtimeEntryAboveCandidateMinPct(),
                'entry_close_position_min' => TradingSettingService::getRealtimeEntryClosePositionMin(),
                'entry_upper_wick_max' => TradingSettingService::getRealtimeEntryUpperWickMax(),
                'entry_bid_ask_imbalance_min' => TradingSettingService::getRealtimeEntryBidAskImbalanceMin(),
                'entry_require_ema9_above_ema21' => TradingSettingService::isRealtimeEntryRequireEma9AboveEma21(),
                // Momentum Continuation Finder settings
                'consolidation_max_range_pct' => (float) config('trading_realtime.consolidation_max_range_pct', 0.8),
                'breakout_min_vol_ratio' => (float) config('trading_realtime.breakout_min_vol_ratio', 1.30),
                'max_vwap_extension_pct_finder' => (float) config('trading_realtime.max_vwap_extension_pct_finder', 1.75),
                'structure_lookback_bars' => (int) config('trading_realtime.structure_lookback_bars', 5),
                'consolidation_bar_count' => (int) config('trading_realtime.consolidation_bar_count', 3),
            ],
            'benchmarkVwapBars' => DB::connection('mysql')
                ->table('five_minute_prices')
                ->where('symbol', TradingSettingService::getBenchmarkSymbol())
                ->where('asset_type', 'stock')
                ->where('trading_date_est', Carbon::today('America/New_York')->toDateString())
                ->whereNotNull('vwap')
                ->orderBy('ts_est')
                ->get(['ts_est', 'price', 'vwap', 'above_vwap', 'vwap_dist_pct']),
        ]);
    }

    /**
     * Read AUC values from the settings DB table (seeded from training logs).
     *
     * @return array<string, float|null>
     */
    private function pipelineAucValues(): array
    {
        return TradingSettingService::getAllPipelineAuc();
    }

    /**
     * Update global + circuit breaker settings.
     */
    public function update(Request $request): RedirectResponse
    {
        abort_unless(auth()->user()?->isAdmin(), 403);

        $validated = $request->validate([
            'orders_enabled' => ['required', 'boolean'],
            'daily_loss_limit' => ['required', 'numeric', 'max:0'],
            'consecutive_loss_days' => ['required', 'integer', 'min:1', 'max:14'],
            'intraday_halt_pre_11am' => ['required', 'numeric', 'max:0'],
            'intraday_halt_11am_1pm' => ['required', 'numeric', 'max:0'],
            'intraday_halt_post_1pm' => ['required', 'numeric', 'max:0'],
            'paper_resume_min_profit' => ['required', 'numeric', 'min:0'],
            'paper_bypass_ml_threshold' => ['required', 'boolean'],
            'nightly_analyze_thresholds' => ['required', 'boolean'],
            'max_spread_pct' => ['required', 'numeric', 'min:0.01', 'max:100'],
            'max_quote_age_seconds' => ['required', 'integer', 'min:1', 'max:3600'],
            'retrade_symbol_wait_minutes' => ['required', 'integer', 'min:0', 'max:480'],
            'max_age_minutes' => ['required', 'integer', 'min:1', 'max:120'],
            'skip_next_alert_after_ml_passed_minutes' => ['required', 'integer', 'min:0', 'max:480'],
            'circuit_breaker_enabled' => ['required', 'boolean'],
            'circuit_breaker_stops_threshold' => ['required', 'integer', 'min:1', 'max:20'],
            'circuit_breaker_window_minutes' => ['required', 'integer', 'min:1', 'max:120'],
            'circuit_breaker_pause_minutes' => ['required', 'integer', 'min:1', 'max:480'],
            'max_position_pct_of_liquidity' => ['required', 'numeric', 'min:0', 'max:100'],
            'min_position_size' => ['required', 'numeric', 'min:1', 'max:1000000'],
            'max_position_size' => ['required', 'numeric', 'min:1', 'max:10000000'],
            'min_dollar_volume_per_minute' => ['required', 'numeric', 'min:0', 'max:1000000000'],
            'position_slippage_rule.enabled' => ['required', 'boolean'],
            'position_slippage_rule.window_days' => ['required', 'integer', 'min:1', 'max:365'],
            'position_slippage_rule.min_samples' => ['required', 'integer', 'min:1', 'max:10000'],
            'position_slippage_rule.cache_seconds' => ['required', 'integer', 'min:10', 'max:3600'],
            'position_slippage_rule.include_paper_orders' => ['required', 'boolean'],
            'position_slippage_rule.low_liquidity_pct' => ['required', 'numeric', 'min:0', 'max:100'],
            'position_slippage_rule.medium_liquidity_pct' => ['required', 'numeric', 'min:0', 'max:100'],
            'position_slippage_rule.high_liquidity_pct' => ['required', 'numeric', 'min:0', 'max:100'],
            'position_slippage_rule.medium_risk_avg_slippage_pct' => ['required', 'numeric', 'min:0', 'max:10'],
            'position_slippage_rule.medium_risk_worst_slippage_pct' => ['required', 'numeric', 'min:0', 'max:10'],
            'position_slippage_rule.high_risk_avg_slippage_pct' => ['required', 'numeric', 'min:0', 'max:10'],
            'position_slippage_rule.high_risk_worst_slippage_pct' => ['required', 'numeric', 'min:0', 'max:10'],
            'position_slippage_rule.min_liquidity_pct' => ['required', 'numeric', 'min:0', 'max:100'],
            'position_slippage_rule.max_liquidity_pct' => ['required', 'numeric', 'min:0', 'max:100'],
        ]);

        $positionRule = TradingSettingService::getPositionSizeSlippageRuleConfig();

        $before = [
            'orders_enabled' => TradingSettingService::isOrdersEnabled(),
            'daily_loss_limit' => TradingSettingService::getDailyLossLimit(),
            'consecutive_loss_days' => TradingSettingService::getConsecutiveLossDays(),
            'intraday_halt_pre_11am' => TradingSettingService::getIntradayHaltLimitPre11am(),
            'intraday_halt_11am_1pm' => TradingSettingService::getIntradayHaltLimit11amTo1pm(),
            'intraday_halt_post_1pm' => TradingSettingService::getIntradayHaltLimitPost1pm(),
            'paper_resume_min_profit' => TradingSettingService::getPaperResumeMinProfit(),
            'paper_bypass_ml_threshold' => TradingSettingService::isPaperBypassMlThreshold(),
            'nightly_analyze_thresholds' => TradingSettingService::isNightlyAnalyzeThresholdsEnabled(),
            'max_spread_pct' => TradingSettingService::getMaxSpreadPct(),
            'max_quote_age_seconds' => TradingSettingService::getMaxQuoteAgeSeconds(),
            'circuit_breaker_enabled' => TradingSettingService::isCircuitBreakerEnabled(),
            'circuit_breaker_stops_threshold' => TradingSettingService::getCircuitBreakerStopsThreshold(),
            'circuit_breaker_window_minutes' => TradingSettingService::getCircuitBreakerWindowMinutes(),
            'circuit_breaker_pause_minutes' => TradingSettingService::getCircuitBreakerPauseMinutes(),
            'max_position_pct_of_liquidity' => TradingSettingService::getMaxPositionPctOfLiquidity(),
            'min_position_size' => TradingSettingService::getMinPositionSize(),
            'max_position_size' => TradingSettingService::getMaxPositionSize(),
            'min_dollar_volume_per_minute' => TradingSettingService::getMinDollarVolumePerMinute(),
            'position_slippage_rule_enabled' => (bool) $positionRule['enabled'],
            'position_slippage_rule_window_days' => (int) $positionRule['window_days'],
            'position_slippage_rule_min_samples' => (int) $positionRule['min_samples'],
            'position_slippage_rule_cache_seconds' => (int) $positionRule['cache_seconds'],
            'position_slippage_rule_include_paper_orders' => (bool) $positionRule['include_paper_orders'],
            'position_slippage_rule_low_liquidity_pct' => (float) $positionRule['low_liquidity_pct'],
            'position_slippage_rule_medium_liquidity_pct' => (float) $positionRule['medium_liquidity_pct'],
            'position_slippage_rule_high_liquidity_pct' => (float) $positionRule['high_liquidity_pct'],
            'position_slippage_rule_medium_risk_avg_slippage_pct' => (float) $positionRule['medium_risk_avg_slippage_pct'],
            'position_slippage_rule_medium_risk_worst_slippage_pct' => (float) $positionRule['medium_risk_worst_slippage_pct'],
            'position_slippage_rule_high_risk_avg_slippage_pct' => (float) $positionRule['high_risk_avg_slippage_pct'],
            'position_slippage_rule_high_risk_worst_slippage_pct' => (float) $positionRule['high_risk_worst_slippage_pct'],
            'position_slippage_rule_min_liquidity_pct' => (float) $positionRule['min_liquidity_pct'],
            'position_slippage_rule_max_liquidity_pct' => (float) $positionRule['max_liquidity_pct'],
        ];

        TradingSettingService::set('trading.orders_enabled', $validated['orders_enabled'] ? '1' : '0');
        TradingSettingService::set('trading.daily_loss_limit', (string) $validated['daily_loss_limit']);
        TradingSettingService::set('trading.consecutive_loss_days', (string) $validated['consecutive_loss_days']);
        TradingSettingService::set('trading.intraday_loss_halt_limit_pre_11am', (string) $validated['intraday_halt_pre_11am']);
        TradingSettingService::set('trading.intraday_loss_halt_limit_11am_1pm', (string) $validated['intraday_halt_11am_1pm']);
        TradingSettingService::set('trading.intraday_loss_halt_limit_post_1pm', (string) $validated['intraday_halt_post_1pm']);
        TradingSettingService::set('trading.paper_resume_min_profit', (string) $validated['paper_resume_min_profit']);
        TradingSettingService::set('trading.paper_bypass_ml_threshold', $validated['paper_bypass_ml_threshold'] ? '1' : '0');
        TradingSettingService::set('trading.nightly_analyze_thresholds', $validated['nightly_analyze_thresholds'] ? '1' : '0');
        TradingSettingService::set('trading.auto_alpaca_orders.max_spread_pct', (string) $validated['max_spread_pct']);
        TradingSettingService::set('trading.auto_alpaca_orders.max_quote_age_seconds', (string) $validated['max_quote_age_seconds']);
        TradingSettingService::set('trading.retrade_symbol_wait_minutes', (string) $validated['retrade_symbol_wait_minutes']);
        TradingSettingService::set('trading.max_age_minutes', (string) $validated['max_age_minutes']);
        TradingSettingService::set('trading.skip_next_alert_after_ml_passed_minutes', (string) $validated['skip_next_alert_after_ml_passed_minutes']);
        TradingSettingService::set('trading.circuit_breaker.enabled', $validated['circuit_breaker_enabled'] ? '1' : '0');
        TradingSettingService::set('trading.circuit_breaker.stops_threshold', (string) $validated['circuit_breaker_stops_threshold']);
        TradingSettingService::set('trading.circuit_breaker.window_minutes', (string) $validated['circuit_breaker_window_minutes']);
        TradingSettingService::set('trading.circuit_breaker.pause_minutes', (string) $validated['circuit_breaker_pause_minutes']);
        TradingSettingService::set('trading.position.max_position_pct_of_liquidity', (string) $validated['max_position_pct_of_liquidity']);
        TradingSettingService::set('trading.position.min_position_size', (string) $validated['min_position_size']);
        TradingSettingService::set('trading.position.max_position_size', (string) $validated['max_position_size']);
        TradingSettingService::set('trading.position.min_dollar_volume_per_minute', (string) $validated['min_dollar_volume_per_minute']);
        TradingSettingService::set('trading.position.slippage_rule.enabled', $validated['position_slippage_rule']['enabled'] ? '1' : '0');
        TradingSettingService::set('trading.position.slippage_rule.window_days', (string) $validated['position_slippage_rule']['window_days']);
        TradingSettingService::set('trading.position.slippage_rule.min_samples', (string) $validated['position_slippage_rule']['min_samples']);
        TradingSettingService::set('trading.position.slippage_rule.cache_seconds', (string) $validated['position_slippage_rule']['cache_seconds']);
        TradingSettingService::set('trading.position.slippage_rule.include_paper_orders', $validated['position_slippage_rule']['include_paper_orders'] ? '1' : '0');
        TradingSettingService::set('trading.position.slippage_rule.low_liquidity_pct', (string) $validated['position_slippage_rule']['low_liquidity_pct']);
        TradingSettingService::set('trading.position.slippage_rule.medium_liquidity_pct', (string) $validated['position_slippage_rule']['medium_liquidity_pct']);
        TradingSettingService::set('trading.position.slippage_rule.high_liquidity_pct', (string) $validated['position_slippage_rule']['high_liquidity_pct']);
        TradingSettingService::set('trading.position.slippage_rule.medium_risk_avg_slippage_pct', (string) $validated['position_slippage_rule']['medium_risk_avg_slippage_pct']);
        TradingSettingService::set('trading.position.slippage_rule.medium_risk_worst_slippage_pct', (string) $validated['position_slippage_rule']['medium_risk_worst_slippage_pct']);
        TradingSettingService::set('trading.position.slippage_rule.high_risk_avg_slippage_pct', (string) $validated['position_slippage_rule']['high_risk_avg_slippage_pct']);
        TradingSettingService::set('trading.position.slippage_rule.high_risk_worst_slippage_pct', (string) $validated['position_slippage_rule']['high_risk_worst_slippage_pct']);
        TradingSettingService::set('trading.position.slippage_rule.min_liquidity_pct', (string) $validated['position_slippage_rule']['min_liquidity_pct']);
        TradingSettingService::set('trading.position.slippage_rule.max_liquidity_pct', (string) $validated['position_slippage_rule']['max_liquidity_pct']);

        $after = [
            'orders_enabled' => $validated['orders_enabled'],
            'daily_loss_limit' => $validated['daily_loss_limit'],
            'consecutive_loss_days' => $validated['consecutive_loss_days'],
            'intraday_halt_pre_11am' => $validated['intraday_halt_pre_11am'],
            'intraday_halt_11am_1pm' => $validated['intraday_halt_11am_1pm'],
            'intraday_halt_post_1pm' => $validated['intraday_halt_post_1pm'],
            'paper_resume_min_profit' => $validated['paper_resume_min_profit'],
            'paper_bypass_ml_threshold' => $validated['paper_bypass_ml_threshold'],
            'nightly_analyze_thresholds' => $validated['nightly_analyze_thresholds'],
            'max_spread_pct' => $validated['max_spread_pct'],
            'max_quote_age_seconds' => $validated['max_quote_age_seconds'],
            'circuit_breaker_enabled' => $validated['circuit_breaker_enabled'],
            'circuit_breaker_stops_threshold' => $validated['circuit_breaker_stops_threshold'],
            'circuit_breaker_window_minutes' => $validated['circuit_breaker_window_minutes'],
            'circuit_breaker_pause_minutes' => $validated['circuit_breaker_pause_minutes'],
            'max_position_pct_of_liquidity' => $validated['max_position_pct_of_liquidity'],
            'min_position_size' => $validated['min_position_size'],
            'max_position_size' => $validated['max_position_size'],
            'min_dollar_volume_per_minute' => $validated['min_dollar_volume_per_minute'],
            'position_slippage_rule_enabled' => $validated['position_slippage_rule']['enabled'],
            'position_slippage_rule_window_days' => $validated['position_slippage_rule']['window_days'],
            'position_slippage_rule_min_samples' => $validated['position_slippage_rule']['min_samples'],
            'position_slippage_rule_cache_seconds' => $validated['position_slippage_rule']['cache_seconds'],
            'position_slippage_rule_include_paper_orders' => $validated['position_slippage_rule']['include_paper_orders'],
            'position_slippage_rule_low_liquidity_pct' => $validated['position_slippage_rule']['low_liquidity_pct'],
            'position_slippage_rule_medium_liquidity_pct' => $validated['position_slippage_rule']['medium_liquidity_pct'],
            'position_slippage_rule_high_liquidity_pct' => $validated['position_slippage_rule']['high_liquidity_pct'],
            'position_slippage_rule_medium_risk_avg_slippage_pct' => $validated['position_slippage_rule']['medium_risk_avg_slippage_pct'],
            'position_slippage_rule_medium_risk_worst_slippage_pct' => $validated['position_slippage_rule']['medium_risk_worst_slippage_pct'],
            'position_slippage_rule_high_risk_avg_slippage_pct' => $validated['position_slippage_rule']['high_risk_avg_slippage_pct'],
            'position_slippage_rule_high_risk_worst_slippage_pct' => $validated['position_slippage_rule']['high_risk_worst_slippage_pct'],
            'position_slippage_rule_min_liquidity_pct' => $validated['position_slippage_rule']['min_liquidity_pct'],
            'position_slippage_rule_max_liquidity_pct' => $validated['position_slippage_rule']['max_liquidity_pct'],
        ];

        $changes = array_filter(
            $after,
            fn ($value, string $key) => (string) $value !== (string) ($before[$key] ?? ''),
            ARRAY_FILTER_USE_BOTH
        );

        if ($changes !== []) {
            Log::info('[TradingSettings] General settings updated by '.auth()->user()?->email, [
                'changes' => collect($changes)->mapWithKeys(
                    fn ($value, string $key) => [$key => ['from' => $before[$key], 'to' => $value]]
                )->all(),
            ]);
        }

        return back()->with('status', 'settings-updated');
    }

    /**
     * @return array<string, mixed>
     */
    private function buildPositionSizingStatus(): array
    {
        $mode = (string) config('trading.position_size_mode', 'fixed');

        if ($mode !== 'dynamic') {
            return [
                'mode' => $mode,
                'is_dynamic' => false,
                'slippage_rule_enabled' => false,
                'active_position_size' => (float) config('trading.auto_alpaca_orders.position_size', 5000),
                'active_liquidity_pct' => null,
                'active_tier' => 'fixed',
                'has_metrics' => false,
                'metrics' => null,
            ];
        }

        $basePct = TradingSettingService::getMaxPositionPctOfLiquidity();
        $ruleConfig = TradingSettingService::getPositionSizeSlippageRuleConfig();
        $isRuleEnabled = (bool) ($ruleConfig['enabled'] ?? false);

        $activePct = $basePct;
        $metrics = null;
        $hasMetrics = false;

        if ($isRuleEnabled) {
            $cachedMetrics = Cache::get('trading:position-size-slippage-rule-metrics');

            if (is_array($cachedMetrics)) {
                $metrics = [
                    'sample_count' => (int) ($cachedMetrics['sample_count'] ?? 0),
                    'avg_adverse_slippage_pct' => (float) ($cachedMetrics['avg_adverse_slippage_pct'] ?? 0.0),
                    'worst_adverse_slippage_pct' => (float) ($cachedMetrics['worst_adverse_slippage_pct'] ?? 0.0),
                    'enough_data' => (bool) ($cachedMetrics['enough_data'] ?? false),
                ];

                if ($metrics['enough_data']) {
                    $activePct = TradeAlertWriterV1::determineLiquidityPctFromSlippageRule(
                        $ruleConfig,
                        $basePct,
                        $metrics['sample_count'],
                        $metrics['avg_adverse_slippage_pct'],
                        $metrics['worst_adverse_slippage_pct']
                    );
                    $hasMetrics = true;
                }
            }
        }

        $lowLiquidityPct = (float) ($ruleConfig['low_liquidity_pct'] ?? $basePct);
        $mediumLiquidityPct = (float) ($ruleConfig['medium_liquidity_pct'] ?? $basePct);
        $highLiquidityPct = (float) ($ruleConfig['high_liquidity_pct'] ?? $basePct);

        $activeTier = 'base';
        if ($isRuleEnabled) {
            if (abs($activePct - $lowLiquidityPct) < 0.0001) {
                $activeTier = 'low';
            } elseif (abs($activePct - $mediumLiquidityPct) < 0.0001) {
                $activeTier = 'medium';
            } elseif (abs($activePct - $highLiquidityPct) < 0.0001) {
                $activeTier = 'high';
            } else {
                $activeTier = 'clamped';
            }
        }

        return [
            'mode' => $mode,
            'is_dynamic' => true,
            'slippage_rule_enabled' => $isRuleEnabled,
            'active_position_size' => null,
            'active_liquidity_pct' => $activePct,
            'active_tier' => $activeTier,
            'has_metrics' => $hasMetrics,
            'metrics' => $metrics,
        ];
    }

    /**
     * Update per-pipeline run_cron and live_rescore_enabled flags.
     */
    public function updatePipelines(Request $request): RedirectResponse
    {
        abort_unless(auth()->user()?->isAdmin(), 403);

        $validated = $request->validate([
            'pipelines' => ['required', 'array'],
            'pipelines.*.run_cron' => ['required', 'boolean'],
            'pipelines.*.live_rescore_enabled' => ['nullable', 'boolean'],
        ]);

        $pipelineChanges = [];

        foreach ($validated['pipelines'] as $pipeline => $pipelineSettings) {
            if (! in_array($pipeline, self::PIPELINES, strict: true)) {
                continue;
            }

            $oldRunCron = TradingSettingService::isPipelineRunCronEnabled($pipeline);
            $oldLiveRescore = TradingSettingService::getPipelineLiveRescoreOverride($pipeline);

            TradingSettingService::set(
                "trading.pipeline_{$pipeline}.run_cron",
                $pipelineSettings['run_cron'] ? '1' : '0'
            );

            if ($oldRunCron !== $pipelineSettings['run_cron']) {
                $pipelineChanges[$pipeline]['run_cron'] = ['from' => $oldRunCron, 'to' => $pipelineSettings['run_cron']];
            }

            $rescoreKey = "trading.pipeline_{$pipeline}.live_rescore_enabled";

            if (array_key_exists('live_rescore_enabled', $pipelineSettings) && $pipelineSettings['live_rescore_enabled'] === null) {
                Setting::where('name', $rescoreKey)->delete();
                Cache::forget("trading_setting:{$rescoreKey}");

                if ($oldLiveRescore !== null) {
                    $pipelineChanges[$pipeline]['live_rescore_enabled'] = ['from' => $oldLiveRescore, 'to' => null];
                }
            } else {
                TradingSettingService::set(
                    $rescoreKey,
                    $pipelineSettings['live_rescore_enabled'] ? '1' : '0'
                );

                $newLiveRescore = (bool) $pipelineSettings['live_rescore_enabled'];

                if ($oldLiveRescore !== $newLiveRescore) {
                    $pipelineChanges[$pipeline]['live_rescore_enabled'] = ['from' => $oldLiveRescore, 'to' => $newLiveRescore];
                }
            }
        }

        if ($pipelineChanges !== []) {
            Log::info('[TradingSettings] Pipeline settings updated by '.auth()->user()?->email, [
                'changes' => $pipelineChanges,
            ]);
        }

        return back()->with('status', 'pipelines-updated');
    }

    /**
     * Update per-15-minute time slot enabled/disabled flags.
     */
    public function updateTimeSlots(Request $request): RedirectResponse
    {
        abort_unless(auth()->user()?->isAdmin(), 403);

        $validated = $request->validate([
            'slots' => ['required', 'array'],
            'slots.*' => ['required', 'boolean'],
        ]);

        $availableSlots = TradingSettingService::availableTimeSlots();

        foreach ($validated['slots'] as $slot => $enabled) {
            if (! in_array($slot, $availableSlots, strict: true)) {
                continue;
            }

            TradingSettingService::set("trading.time_slot.{$slot}", $enabled ? '1' : '0');
        }

        Log::info('[TradingSettings] Time slots updated by '.auth()->user()?->email, [
            'enabled' => collect($validated['slots'])->filter()->keys()->all(),
            'disabled' => collect($validated['slots'])->reject(fn ($v) => $v)->keys()->all(),
        ]);

        return back()->with('status', 'time-slots-updated');
    }

    /**
     * Update per-15-minute realtime time slot enabled/disabled flags (Pipeline R only).
     */
    public function updateRealtimeSlots(Request $request): RedirectResponse
    {
        abort_unless(auth()->user()?->isAdmin(), 403);

        $validated = $request->validate([
            'slots' => ['required', 'array'],
            'slots.*' => ['required', 'boolean'],
        ]);

        $availableSlots = TradingSettingService::availableRealtimeSlots();

        foreach ($validated['slots'] as $slot => $enabled) {
            if (! in_array($slot, $availableSlots, strict: true)) {
                continue;
            }

            TradingSettingService::set("trading.realtime_slot.{$slot}", $enabled ? '1' : '0');
        }

        Log::info('[TradingSettings] Realtime time slots updated by '.auth()->user()?->email, [
            'enabled' => collect($validated['slots'])->filter()->keys()->all(),
            'disabled' => collect($validated['slots'])->reject(fn ($v) => $v)->keys()->all(),
        ]);

        return back()->with('status', 'realtime-slots-updated');
    }

    /**
     * Update per-pipeline ML thresholds used by auto order placement.
     */
    public function updateMlThresholds(Request $request): RedirectResponse
    {
        abort_unless(auth()->user()?->isAdmin(), 403);

        $validated = $request->validate([
            'ml_thresholds' => ['required', 'array'],
            'ml_thresholds.*' => ['required', 'numeric', 'min:0', 'max:1.1'],
        ]);

        $changes = [];

        foreach ($validated['ml_thresholds'] as $pipeline => $threshold) {
            if (! in_array($pipeline, self::PIPELINES, strict: true)) {
                continue;
            }

            $oldThreshold = TradingSettingService::getPipelineMlThreshold($pipeline);
            $newThreshold = round((float) $threshold, 3);

            TradingSettingService::set("trading.pipeline_{$pipeline}.ml_threshold", (string) $newThreshold);

            if ((string) $oldThreshold !== (string) $newThreshold) {
                $changes[strtoupper($pipeline)] = ['from' => $oldThreshold, 'to' => $newThreshold];
            }
        }

        if ($changes !== []) {
            Log::info('[TradingSettings] ML thresholds updated by '.auth()->user()?->email, [
                'changes' => $changes,
            ]);
        }

        return back()->with('status', 'ml-thresholds-updated');
    }

    /**
     * Update per-pipeline max age settings used by order gating.
     */
    public function updateMaxAgeSettings(Request $request): RedirectResponse
    {
        abort_unless(auth()->user()?->isAdmin(), 403);

        $validated = $request->validate([
            'max_age_minutes' => ['required', 'array'],
            'max_age_minutes.*' => ['required', 'integer', 'min:1', 'max:120'],
        ]);

        $changes = [];

        foreach ($validated['max_age_minutes'] as $pipeline => $maxAgeMinutes) {
            if (! in_array($pipeline, self::MAX_AGE_PIPELINES, strict: true)) {
                continue;
            }

            $oldValue = TradingSettingService::getPipelineMaxAgeMinutes($pipeline);
            $newValue = (int) $maxAgeMinutes;

            TradingSettingService::set("trading.pipeline_{$pipeline}.max_age_minutes", (string) $newValue);

            if ($oldValue !== $newValue) {
                $changes[strtoupper($pipeline)] = ['from' => $oldValue, 'to' => $newValue];
            }
        }

        if ($changes !== []) {
            Log::info('[TradingSettings] Max age settings updated by '.auth()->user()?->email, [
                'changes' => $changes,
            ]);
        }

        return back()->with('status', 'max-age-settings-updated');
    }

    /**
     * Update stop loss settings.
     */
    public function updateStopLoss(Request $request): RedirectResponse
    {
        abort_unless(auth()->user()?->isAdmin(), 403);

        $validated = $request->validate([
            'mode' => ['required', 'string', 'in:fixed,atr'],
            'profit_protection_enabled' => ['required', 'boolean'],
            'fixed_pct' => ['required', 'numeric', 'min:0.1', 'max:5'],
            'atr_multiplier' => ['required', 'numeric', 'min:0.5', 'max:10'],
            'atr_min_pct' => ['required', 'numeric', 'min:0.1', 'max:5'],
            'atr_max_pct' => ['required', 'numeric', 'min:0.1', 'max:10'],
        ]);

        $before = [
            'mode' => TradingSettingService::getStopLossMode(),
            'profit_protection_enabled' => TradingSettingService::isProfitProtectionEnabled(),
            'fixed_pct' => TradingSettingService::getStopLossFixedPct(),
            'atr_multiplier' => TradingSettingService::getStopLossAtrMultiplier(),
            'atr_min_pct' => TradingSettingService::getStopLossAtrMinPct(),
            'atr_max_pct' => TradingSettingService::getStopLossAtrMaxPct(),
        ];

        TradingSettingService::set('trading.stop_loss.mode', $validated['mode']);
        TradingSettingService::set('trading.stop_loss.profit_protection_enabled', $validated['profit_protection_enabled'] ? '1' : '0');
        TradingSettingService::set('trading.stop_loss.fixed_pct', (string) $validated['fixed_pct']);
        TradingSettingService::set('trading.stop_loss.atr_multiplier', (string) $validated['atr_multiplier']);
        TradingSettingService::set('trading.stop_loss.atr_min_pct', (string) $validated['atr_min_pct']);
        TradingSettingService::set('trading.stop_loss.atr_max_pct', (string) $validated['atr_max_pct']);

        $after = $validated;

        $changes = array_filter(
            $after,
            fn ($value, string $key) => (string) $value !== (string) ($before[$key] ?? ''),
            ARRAY_FILTER_USE_BOTH
        );

        if ($changes !== []) {
            Log::info('[TradingSettings] Stop loss settings updated by '.auth()->user()?->email, [
                'changes' => collect($changes)->mapWithKeys(
                    fn ($value, string $key) => [$key => ['from' => $before[$key], 'to' => $value]]
                )->all(),
            ]);
        }

        return back()->with('status', 'stop-loss-updated');
    }

    /**
     * Update limit order and slippage settings.
     */
    public function updateLimitOrders(Request $request): RedirectResponse
    {
        abort_unless(auth()->user()?->isAdmin(), 403);

        $validated = $request->validate([
            'use_limit_orders' => ['required', 'boolean'],
            'slippage_pct' => ['required', 'numeric', 'min:0', 'max:5'],
            'slippage_pct_stale_price' => ['required', 'numeric', 'min:0', 'max:10'],
            'partial_fill_stop_timeout_minutes' => ['required', 'numeric', 'min:0.1', 'max:30'],
            'pipeline_overrides' => ['required', 'array'],
            'pipeline_overrides.*' => ['nullable', 'numeric', 'min:0', 'max:5'],
        ]);

        $before = [
            'use_limit_orders' => TradingSettingService::isUseLimitOrdersEnabled(),
            'slippage_pct' => TradingSettingService::getLimitSlippagePct(),
            'slippage_pct_stale_price' => TradingSettingService::getLimitSlippagePctStalePrice(),
            'partial_fill_stop_timeout_minutes' => TradingSettingService::getPartialFillStopTimeoutMinutes(),
        ];

        TradingSettingService::set('trading.limit_orders.use_limit_orders', $validated['use_limit_orders'] ? '1' : '0');
        TradingSettingService::set('trading.limit_orders.slippage_pct', (string) $validated['slippage_pct']);
        TradingSettingService::set('trading.limit_orders.slippage_pct_stale_price', (string) $validated['slippage_pct_stale_price']);
        TradingSettingService::set('trading.limit_orders.partial_fill_stop_timeout_minutes', (string) $validated['partial_fill_stop_timeout_minutes']);

        foreach ($validated['pipeline_overrides'] as $pipeline => $slippagePct) {
            $dbKey = "trading.limit_orders.slippage_pct_pipeline_{$pipeline}";

            if ($slippagePct === null) {
                Setting::where('name', $dbKey)->delete();
                Cache::forget("trading_setting:{$dbKey}");
            } else {
                TradingSettingService::set($dbKey, (string) $slippagePct);
            }
        }

        $after = $validated;

        $changes = array_filter(
            $after,
            fn ($value, string $key) => isset($before[$key]) && (string) $value !== (string) $before[$key],
            ARRAY_FILTER_USE_BOTH
        );

        if ($changes !== []) {
            Log::info('[TradingSettings] Limit order settings updated by '.auth()->user()?->email, [
                'changes' => collect($changes)->mapWithKeys(
                    fn ($value, string $key) => [$key => ['from' => $before[$key], 'to' => $value]]
                )->all(),
            ]);
        }

        return back()->with('status', 'limit-orders-updated');
    }

    /**
     * Update trading hours.
     */
    public function updateTradingHours(Request $request): RedirectResponse
    {
        abort_unless(auth()->user()?->isAdmin(), 403);

        $validated = $request->validate([
            'start_time' => ['required', 'string', 'regex:/^\d{2}:\d{2}$/'],
            'end_time' => ['required', 'string', 'regex:/^\d{2}:\d{2}$/'],
        ]);

        $before = [
            'start_time' => TradingSettingService::getTradingStartTime(),
            'end_time' => TradingSettingService::getTradingEndTime(),
        ];

        TradingSettingService::set('trading.hours.start_time', $validated['start_time']);
        TradingSettingService::set('trading.hours.end_time', $validated['end_time']);

        $changes = array_filter(
            $validated,
            fn ($value, string $key) => (string) $value !== (string) ($before[$key] ?? ''),
            ARRAY_FILTER_USE_BOTH
        );

        if ($changes !== []) {
            Log::info('[TradingSettings] Trading hours updated by '.auth()->user()?->email, [
                'changes' => collect($changes)->mapWithKeys(
                    fn ($value, string $key) => [$key => ['from' => $before[$key], 'to' => $value]]
                )->all(),
            ]);
        }

        return back()->with('status', 'trading-hours-updated');
    }

    /**
     * Update stale alert rescore settings.
     */
    public function updateStaleRescore(Request $request): RedirectResponse
    {
        abort_unless(auth()->user()?->isAdmin(), 403);

        $validated = $request->validate([
            'enabled' => ['required', 'boolean'],
            'paper_only' => ['required', 'boolean'],
            'max_age_minutes' => ['required', 'integer', 'min:1', 'max:480'],
        ]);

        TradingSettingService::set('trading.stale_rescore.enabled', $validated['enabled'] ? '1' : '0');
        TradingSettingService::set('trading.stale_rescore.paper_only', $validated['paper_only'] ? '1' : '0');
        TradingSettingService::set('trading.stale_rescore.max_age_minutes', (string) $validated['max_age_minutes']);

        Log::info('[TradingSettings] Stale rescore settings updated by '.auth()->user()?->email, [
            'enabled' => $validated['enabled'],
            'paper_only' => $validated['paper_only'],
            'max_age_minutes' => $validated['max_age_minutes'],
        ]);

        return back()->with('status', 'stale-rescore-updated');
    }

    /**
     * Update benchmark VWAP gate settings.
     */
    public function updateBenchmarkVwapGate(Request $request): RedirectResponse
    {
        abort_unless(auth()->user()?->isAdmin(), 403);

        $validated = $request->validate([
            'enabled' => ['required', 'boolean'],
            'symbol' => ['required', 'string', 'max:10'],
            'max_pct_below_high' => ['nullable', 'numeric', 'min:0', 'max:10'],
            'pipeline_overrides' => ['required', 'array'],
            'pipeline_overrides.*' => ['nullable', 'boolean'],
        ]);

        TradingSettingService::set('trading.benchmark_vwap_gate.enabled', $validated['enabled'] ? '1' : '0');
        TradingSettingService::set('trading.benchmark_vwap_gate.symbol', $validated['symbol']);

        if ($validated['max_pct_below_high'] === null) {
            Setting::where('name', 'trading.benchmark_vwap_gate.max_pct_below_high')->delete();
            Cache::forget('trading_setting:trading.benchmark_vwap_gate.max_pct_below_high');
        } else {
            TradingSettingService::set('trading.benchmark_vwap_gate.max_pct_below_high', (string) $validated['max_pct_below_high']);
        }

        foreach ($validated['pipeline_overrides'] as $pipeline => $override) {
            $dbKey = "trading.benchmark_vwap_gate.pipeline_{$pipeline}";

            if ($override === null) {
                Setting::where('name', $dbKey)->delete();
                Cache::forget("trading_setting:{$dbKey}");
            } else {
                TradingSettingService::set($dbKey, $override ? '1' : '0');
            }
        }

        Log::info('[TradingSettings] Benchmark VWAP gate settings updated by '.auth()->user()?->email);

        return back()->with('status', 'benchmark-vwap-gate-updated');
    }

    /**
     * Update global minimum AUC and minimum Precision@10 gates.
     */
    public function updatePipelineMlGates(Request $request): RedirectResponse
    {
        abort_unless(auth()->user()?->isAdmin(), 403);

        $validated = $request->validate([
            'min_auc' => ['required', 'numeric', 'min:0', 'max:1'],
            'min_precision_at_10' => ['required', 'numeric', 'min:0', 'max:1'],
        ]);

        $oldMinAuc = TradingSettingService::getMinAuc();
        $oldMinPrecisionAt10 = TradingSettingService::getMinPrecisionAt10();
        $newMinAuc = (float) $validated['min_auc'];
        $newMinPrecisionAt10 = (float) $validated['min_precision_at_10'];

        TradingSettingService::setMinAuc($newMinAuc);
        TradingSettingService::setMinPrecisionAt10($newMinPrecisionAt10);

        if ((string) $oldMinAuc !== (string) $newMinAuc || (string) $oldMinPrecisionAt10 !== (string) $newMinPrecisionAt10) {
            Log::info('[TradingSettings] ML quality gates updated by '.auth()->user()?->email, [
                'min_auc' => ['from' => $oldMinAuc, 'to' => $newMinAuc],
                'min_precision_at_10' => ['from' => $oldMinPrecisionAt10, 'to' => $newMinPrecisionAt10],
            ]);
        }

        return back()->with('status', 'pipeline-ml-gates-updated');
    }

    /**
     * Update realtime entry gate settings.
     */
    public function updateRealtime(Request $request): RedirectResponse
    {
        abort_unless(auth()->user()?->isAdmin(), 403);
        $validated = $request->validate([
            'max_quote_age_seconds' => ['required', 'integer', 'min:1', 'max:300'],
            'max_spread_pct' => ['required', 'numeric', 'min:0', 'max:10'],
            'candidate_ttl_seconds' => ['required', 'integer', 'min:30', 'max:3600'],
            'early_score_min' => ['required', 'numeric', 'min:0', 'max:100'],
            'min_dollar_volume_1m' => ['required', 'numeric', 'min:0'],
            'min_rvol' => ['required', 'numeric', 'min:0', 'max:50'],
            'min_atr_pct' => ['required', 'numeric', 'min:0', 'max:10'],
            'min_move_30m_pct' => ['required', 'numeric', 'min:0', 'max:10'],
            'max_vwap_extension_pct' => ['required', 'numeric', 'min:0', 'max:10'],
            'max_entry_age_seconds' => ['required', 'integer', 'min:1', 'max:600'],
            // Entry trigger gates (DB-backed)
            'entry_candidate_max_age_seconds' => ['required', 'integer', 'min:10', 'max:600'],
            'entry_final_score_min' => ['required', 'numeric', 'min:0', 'max:100'],
            'entry_min_price' => ['required', 'numeric', 'min:0', 'max:10000'],
            'entry_max_price' => ['required', 'numeric', 'min:0', 'max:100000'],
            'entry_require_vwap' => ['required', 'boolean'],
            'entry_return_1m_min_pct' => ['required', 'numeric', 'min:-5', 'max:15'],
            'entry_return_3m_min_pct' => ['required', 'numeric', 'min:-5', 'max:15'],
            'entry_volume_ratio_min' => ['required', 'numeric', 'min:0', 'max:20'],
            'entry_min_dollar_volume_1m' => ['required', 'numeric', 'min:0'],
            'max_move_since_candidate_pct' => ['required', 'numeric', 'min:0', 'max:10'],
            'entry_above_candidate_min_pct' => ['required', 'numeric', 'min:-5', 'max:10'],
            'entry_close_position_min' => ['required', 'numeric', 'min:0', 'max:1'],
            'entry_upper_wick_max' => ['required', 'numeric', 'min:0', 'max:1'],
            'entry_bid_ask_imbalance_min' => ['required', 'numeric', 'min:-2', 'max:2'],
            'entry_require_ema9_above_ema21' => ['required', 'boolean'],
            'skip_first_minutes' => ['sometimes', 'integer', 'min:0', 'max:30'],
            // Momentum Continuation Finder
            'consolidation_max_range_pct' => ['required', 'numeric', 'min:0.1', 'max:5'],
            'breakout_min_vol_ratio' => ['required', 'numeric', 'min:0.5', 'max:10'],
            'max_vwap_extension_pct_finder' => ['required', 'numeric', 'min:0', 'max:10'],
            'structure_lookback_bars' => ['required', 'integer', 'min:3', 'max:20'],
            'consolidation_bar_count' => ['required', 'integer', 'min:1', 'max:10'],
        ]);
        $keys = [
            'max_quote_age_seconds' => 'trading.auto_alpaca_orders.max_quote_age_seconds',
            'max_spread_pct' => 'trading.auto_alpaca_orders.max_spread_pct',
            'candidate_ttl_seconds' => 'trading.realtime.candidate_ttl_seconds',
            'early_score_min' => 'trading.realtime.early_score_min',
            'min_dollar_volume_1m' => 'trading.realtime.min_dollar_volume_1m',
            'min_rvol' => 'trading.realtime.min_rvol',
            'min_atr_pct' => 'trading.realtime.min_atr_pct',
            'min_move_30m_pct' => 'trading.realtime.min_move_30m_pct',
            'max_vwap_extension_pct' => 'trading.realtime.max_vwap_extension_pct',
            'max_entry_age_seconds' => 'trading.realtime.max_entry_age_seconds',
            // Entry trigger gates
            'entry_candidate_max_age_seconds' => 'trading.realtime.entry_candidate_max_age_seconds',
            'entry_final_score_min' => 'trading.realtime.entry_final_score_min',
            'entry_min_price' => 'trading.realtime.entry_min_price',
            'entry_max_price' => 'trading.realtime.entry_max_price',
            'entry_require_vwap' => 'trading.realtime.entry_require_vwap',
            'entry_return_1m_min_pct' => 'trading.realtime.entry_return_1m_min_pct',
            'entry_return_3m_min_pct' => 'trading.realtime.entry_return_3m_min_pct',
            'entry_volume_ratio_min' => 'trading.realtime.entry_volume_ratio_min',
            'entry_min_dollar_volume_1m' => 'trading.realtime.entry_min_dollar_volume_1m',
            'max_move_since_candidate_pct' => 'trading.realtime.max_move_since_candidate_pct',
            'entry_above_candidate_min_pct' => 'trading.realtime.entry_above_candidate_min_pct',
            'entry_close_position_min' => 'trading.realtime.entry_close_position_min',
            'entry_upper_wick_max' => 'trading.realtime.entry_upper_wick_max',
            'entry_bid_ask_imbalance_min' => 'trading.realtime.entry_bid_ask_imbalance_min',
            'entry_require_ema9_above_ema21' => 'trading.realtime.entry_require_ema9_above_ema21',
            'skip_first_minutes' => 'trading.realtime.skip_first_minutes',
            // Momentum Continuation Finder
            'consolidation_max_range_pct' => 'trading.realtime.consolidation_max_range_pct',
            'breakout_min_vol_ratio' => 'trading.realtime.breakout_min_vol_ratio',
            'max_vwap_extension_pct_finder' => 'trading.realtime.max_vwap_extension_pct_finder',
            'structure_lookback_bars' => 'trading.realtime.structure_lookback_bars',
            'consolidation_bar_count' => 'trading.realtime.consolidation_bar_count',
        ];
        foreach ($keys as $field => $dbKey) {
            if (! array_key_exists($field, $validated)) {
                continue;
            }
            TradingSettingService::set($dbKey, (string) $validated[$field]);
        }
        Log::info('[TradingSettings] Realtime momentum continuation settings updated by '.auth()->user()?->email);

        return back()->with('status', 'realtime-updated');
    }
}
