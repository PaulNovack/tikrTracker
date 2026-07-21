<?php

namespace App\Services;

use App\Models\Setting;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * Runtime-mutable trading settings backed by the `settings` DB table.
 * DB values override config/env. All reads are cached for 60 seconds
 * so queue workers don't hammer the DB on every order event.
 *
 * Setting name conventions:
 *   trading.orders_enabled
 *   trading.nightly_analyze_thresholds
 *   trading.paper_bypass_ml_threshold
 *   trading.circuit_breaker.enabled
 *   trading.circuit_breaker.stops_threshold
 *   trading.circuit_breaker.window_minutes
 *   trading.circuit_breaker.pause_minutes
 *   trading.daily_loss_limit
 *   trading.consecutive_loss_days
 *   trading.pipeline_{letter}.run_cron
 *   trading.pipeline_{letter}.ml_threshold
 *   trading.pipeline_{letter}.ml_threshold_override
 *   trading.pipeline_{letter}.live_rescore_enabled
 *   trading.pipeline_{letter}.max_age_minutes
 *   trading.stop_loss.mode
 *   trading.stop_loss.profit_protection_enabled
 *   trading.stop_loss.fixed_pct
 *   trading.stop_loss.atr_multiplier
 *   trading.stop_loss.atr_min_pct
 *   trading.stop_loss.atr_max_pct
 *   trading.limit_orders.use_limit_orders
 *   trading.limit_orders.slippage_pct
 *   trading.limit_orders.slippage_pct_stale_price
 *   trading.limit_orders.partial_fill_stop_timeout_minutes
 *   trading.limit_orders.slippage_pct_pipeline_{letter}
 *   trading.hours.start_time
 *   trading.hours.end_time
 *   trading.stale_rescore.enabled
 *   trading.stale_rescore.paper_only
 *   trading.stale_rescore.max_age_minutes
 *   trading.benchmark_vwap_gate.enabled
 *   trading.benchmark_vwap_gate.symbol
 *   trading.benchmark_vwap_gate.max_pct_below_high
 *   trading.benchmark_vwap_gate.pipeline_{letter}
 */
class TradingSettingService
{
    private const CACHE_TTL = 60;

    /** @var list<string> */
    private const PIPELINES = ['a', 'b', 'c', 'd', 'e', 'f', 'g', 'h', 'i', 'j', 'k', 'l', 'm', 'n', 'o', 'p', 'q', 'r', 's', 'x', 'manual', 'external'];

    /** @var array<string, array{db_key: string, config_key: string, default: int}> */
    private const PIPELINE_MAX_AGE_SETTINGS = [
        'a' => ['db_key' => 'trading.pipeline_a.max_age_minutes', 'config_key' => 'trading.auto_alpaca_orders.max_age_minutes_pipeline_a', 'default' => 20],
        'b' => ['db_key' => 'trading.pipeline_b.max_age_minutes', 'config_key' => 'trading.auto_alpaca_orders.max_age_minutes_pipeline_b', 'default' => 20],
        'c' => ['db_key' => 'trading.pipeline_c.max_age_minutes', 'config_key' => 'trading.auto_alpaca_orders.max_age_minutes_pipeline_c', 'default' => 20],
        'd' => ['db_key' => 'trading.pipeline_d.max_age_minutes', 'config_key' => 'trading.auto_alpaca_orders.max_age_minutes_pipeline_d', 'default' => 20],
        'e' => ['db_key' => 'trading.pipeline_e.max_age_minutes', 'config_key' => 'trading.auto_alpaca_orders.max_age_minutes_pipeline_e', 'default' => 20],
        'f' => ['db_key' => 'trading.pipeline_f.max_age_minutes', 'config_key' => 'trading.auto_alpaca_orders.max_age_minutes_pipeline_f', 'default' => 20],
        'g' => ['db_key' => 'trading.pipeline_g.max_age_minutes', 'config_key' => 'trading.auto_alpaca_orders.max_age_minutes_pipeline_g', 'default' => 20],
        'h' => ['db_key' => 'trading.pipeline_h.max_age_minutes', 'config_key' => 'trading.auto_alpaca_orders.max_age_minutes_pipeline_h', 'default' => 20],
        'i' => ['db_key' => 'trading.pipeline_i.max_age_minutes', 'config_key' => 'trading.auto_alpaca_orders.max_age_minutes_pipeline_i', 'default' => 20],
        'j' => ['db_key' => 'trading.pipeline_j.max_age_minutes', 'config_key' => 'trading.auto_alpaca_orders.max_age_minutes_pipeline_j', 'default' => 15],
        'k' => ['db_key' => 'trading.pipeline_k.max_age_minutes', 'config_key' => 'trading.auto_alpaca_orders.max_age_minutes_pipeline_k', 'default' => 20],
        'l' => ['db_key' => 'trading.pipeline_l.max_age_minutes', 'config_key' => 'trading.auto_alpaca_orders.max_age_minutes_pipeline_l', 'default' => 10],
        'm' => ['db_key' => 'trading.pipeline_m.max_age_minutes', 'config_key' => 'trading.auto_alpaca_orders.max_age_minutes_pipeline_m', 'default' => 20],
        'n' => ['db_key' => 'trading.pipeline_n.max_age_minutes', 'config_key' => 'trading.auto_alpaca_orders.max_age_minutes_pipeline_n', 'default' => 20],
        'o' => ['db_key' => 'trading.pipeline_o.max_age_minutes', 'config_key' => 'trading.auto_alpaca_orders.max_age_minutes_pipeline_o', 'default' => 20],
        'p' => ['db_key' => 'trading.pipeline_p.max_age_minutes', 'config_key' => 'trading.auto_alpaca_orders.max_age_minutes_pipeline_p', 'default' => 15],
        'q' => ['db_key' => 'trading.pipeline_q.max_age_minutes', 'config_key' => 'trading.auto_alpaca_orders.max_age_minutes_pipeline_q', 'default' => 12],
        'r' => ['db_key' => 'trading.pipeline_r.max_age_minutes', 'config_key' => 'trading.auto_alpaca_orders.max_age_minutes_pipeline_r', 'default' => 10],
        's' => ['db_key' => 'trading.pipeline_s.max_age_minutes', 'config_key' => 'trading.auto_alpaca_orders.max_age_minutes_pipeline_s', 'default' => 5],
    ];

    /** @var array<string, array{db_key: string, config_key: string, default: int}> */
    private const PIPELINE_L_BACKTEST_MAX_AGE_SETTINGS = [
        'l' => ['db_key' => 'trading.pipeline_l.max_age_minutes_backtest', 'config_key' => 'trading.auto_alpaca_orders.max_age_minutes_pipeline_l_backtest', 'default' => 10],
    ];

    // -------------------------------------------------------------------------
    // Orders
    // -------------------------------------------------------------------------

    public static function isOrdersEnabled(): bool
    {
        return (bool) self::get(
            'trading.orders_enabled',
            config('trading.auto_alpaca_orders.enabled', false)
        );
    }

    public static function isPaperBypassMlThreshold(): bool
    {
        return (bool) self::get(
            'trading.paper_bypass_ml_threshold',
            config('trading.auto_alpaca_orders.paper_bypass_ml_threshold', false)
        );
    }

    public static function isNightlyAnalyzeThresholdsEnabled(): bool
    {
        return (bool) self::get(
            'trading.nightly_analyze_thresholds',
            config('trading.auto_alpaca_orders.nightly_analyze_thresholds', true)
        );
    }

    public static function getGlobalMlThreshold(): float
    {
        return (float) self::get(
            'trading.ml_threshold.global',
            config('trading.auto_alpaca_orders.ml_threshold', 0.45)
        );
    }

    /**
     * Return the lowest ML threshold configured across all active pipelines.
     * Used as a fast pre-filter so pipeline-specific overrides (e.g. H=0.60)
     * are never blocked before the pipeline-specific threshold is applied.
     */
    public static function getMinimumPipelineMlThreshold(): float
    {
        $min = self::getGlobalMlThreshold();

        foreach (self::PIPELINES as $pipeline) {
            $baseline = self::getPipelineMlThresholdBaseline($pipeline);
            if ($baseline !== null && $baseline < $min) {
                $min = $baseline;
            }
        }

        return $min;
    }

    public static function getPipelineMlThresholdBaseline(string $pipeline): ?float
    {
        $pipeline = strtolower($pipeline);
        $dbKey = "trading.pipeline_{$pipeline}.ml_threshold";

        $dbValue = self::getRaw($dbKey);
        if ($dbValue !== null) {
            return (float) $dbValue;
        }

        $configKey = "trading.auto_alpaca_orders.ml_threshold_pipeline_{$pipeline}";
        $configValue = config($configKey);

        return $configValue !== null ? (float) $configValue : null;
    }

    public static function getPipelineMlThresholdOverride(string $pipeline): ?float
    {
        $pipeline = strtolower($pipeline);
        $dbKey = "trading.pipeline_{$pipeline}.ml_threshold_override";

        $dbValue = self::getRaw($dbKey);
        if ($dbValue !== null) {
            return (float) $dbValue;
        }

        return null;
    }

    public static function getPipelineMlThreshold(string $pipeline): float
    {
        if ((bool) config('trading.auto_alpaca_orders.ml_threshold_regime_override.enabled', false)) {
            $temporaryOverride = self::getPipelineMlThresholdOverride($pipeline);

            if ($temporaryOverride !== null) {
                return $temporaryOverride;
            }
        }

        return self::getPipelineMlThresholdBaseline($pipeline) ?? self::getGlobalMlThreshold();
    }

    /**
     * @return array<string, float>
     */
    public static function getAllPipelineMlThresholds(): array
    {
        return collect(self::PIPELINES)
            ->mapWithKeys(fn (string $pipeline) => [strtoupper($pipeline) => self::getPipelineMlThreshold($pipeline)])
            ->all();
    }

    public static function getPipelineAuc(string $pipeline): ?float
    {
        $val = self::getRaw('trading.pipeline_auc.'.strtolower($pipeline));

        return $val !== null && $val !== 'null' ? (float) $val : null;
    }

    /**
     * @return array<string, float|null>
     */
    public static function getAllPipelineAuc(): array
    {
        return collect(self::PIPELINES)
            ->mapWithKeys(fn (string $p) => [$p => self::getPipelineAuc($p)])
            ->all();
    }

    public static function getPrecisionAtK(string $pipeline): ?float
    {
        $val = self::getRaw('trading.pipeline_p10.'.strtolower($pipeline));

        return $val !== null && $val !== 'null' ? (float) $val : null;
    }

    /**
     * @return array<string, float|null>
     */
    public static function getAllPrecisionAtK(): array
    {
        return collect(self::PIPELINES)
            ->mapWithKeys(fn (string $p) => [$p => self::getPrecisionAtK($p)])
            ->all();
    }

    /**
     * Get the updated_at timestamp of a pipeline's ML model metrics (AUC/P@10),
     * converted to America/New_York timezone.
     *
     * First checks the dedicated trading.pipeline_ml_updated.{letter} setting row.
     * Falls back to trading.pipeline_auc.{letter}'s updated_at for backward
     * compatibility with rows created before the dedicated setting was introduced.
     *
     * Returns null if no ML metrics have been persisted yet.
     */
    public static function getPipelineMlUpdatedAt(string $pipeline): ?string
    {
        $key = strtolower($pipeline);

        // Prefer the dedicated ML updated timestamp
        $dedicated = Setting::where('name', 'trading.pipeline_ml_updated.'.$key)->first(['updated_at']);
        if ($dedicated?->updated_at !== null) {
            return Carbon::parse($dedicated->updated_at)
                ->setTimezone('America/New_York')
                ->toISOString();
        }

        // Fall back to the AUC row's updated_at for legacy rows
        $aucRow = Setting::where('name', 'trading.pipeline_auc.'.$key)->first(['updated_at']);
        if ($aucRow?->updated_at === null) {
            return null;
        }

        return Carbon::parse($aucRow->updated_at)
            ->setTimezone('America/New_York')
            ->toISOString();
    }

    /**
     * @return array<string, string|null>
     */
    public static function getAllPipelineMlUpdatedAt(): array
    {
        return collect(self::PIPELINES)
            ->mapWithKeys(fn (string $p) => [$p => self::getPipelineMlUpdatedAt($p)])
            ->all();
    }

    public static function getMinAuc(): float
    {
        return (float) self::get(
            'trading.ml_gates.min_auc',
            config('trading.ml_scoring.min_auc', 0.68)
        );
    }

    public static function setMinAuc(float $value): void
    {
        self::set('trading.ml_gates.min_auc', (string) $value);
    }

    public static function getMinPrecisionAt10(): float
    {
        return (float) self::get(
            'trading.ml_gates.min_precision_at_10',
            config('trading.ml_scoring.min_precision_at_10', 0.40)
        );
    }

    public static function setMinPrecisionAt10(float $value): void
    {
        self::set('trading.ml_gates.min_precision_at_10', (string) $value);
    }

    public static function getPipelineMaxAgeMinutes(string $pipeline, bool $backtest = false): int
    {
        $pipeline = strtolower($pipeline);
        $settings = $backtest && $pipeline === 'l'
            ? self::PIPELINE_L_BACKTEST_MAX_AGE_SETTINGS
            : self::PIPELINE_MAX_AGE_SETTINGS;

        if (! isset($settings[$pipeline])) {
            return (int) config('trading.auto_alpaca_orders.max_age_minutes', 10);
        }

        $setting = $settings[$pipeline];
        $dbValue = self::getRaw($setting['db_key']);

        if ($dbValue !== null) {
            return (int) $dbValue;
        }

        return (int) config($setting['config_key'], $setting['default']);
    }

    /**
     * @return array<string, int>
     */
    public static function getAllPipelineMaxAgeMinutes(): array
    {
        return collect(self::PIPELINE_MAX_AGE_SETTINGS)
            ->mapWithKeys(fn (array $setting, string $pipeline) => [$pipeline => self::getPipelineMaxAgeMinutes($pipeline)])
            ->all();
    }

    public static function forget(string $key): void
    {
        Setting::where('name', $key)->delete();
        Cache::forget("trading_setting:{$key}");
    }

    public static function disableOrders(string $reason = ''): void
    {
        Setting::set('trading.orders_enabled', '0');
        Cache::forget('trading_setting:trading.orders_enabled');

        if ($reason !== '') {
            Setting::set('trading.orders_disabled_reason', $reason);
            Cache::forget('trading_setting:trading.orders_disabled_reason');
        }
    }

    // -------------------------------------------------------------------------
    // Circuit breaker
    // -------------------------------------------------------------------------

    public static function isCircuitBreakerEnabled(): bool
    {
        return (bool) self::get(
            'trading.circuit_breaker.enabled',
            config('trading.auto_alpaca_orders.circuit_breaker.enabled', false)
        );
    }

    public static function getCircuitBreakerStopsThreshold(): int
    {
        return (int) self::get(
            'trading.circuit_breaker.stops_threshold',
            config('trading.auto_alpaca_orders.circuit_breaker.stops_threshold', 3)
        );
    }

    public static function getCircuitBreakerWindowMinutes(): int
    {
        return (int) self::get(
            'trading.circuit_breaker.window_minutes',
            config('trading.auto_alpaca_orders.circuit_breaker.window_minutes', 20)
        );
    }

    public static function getCircuitBreakerPauseMinutes(): int
    {
        return (int) self::get(
            'trading.circuit_breaker.pause_minutes',
            config('trading.auto_alpaca_orders.circuit_breaker.pause_minutes', 30)
        );
    }

    // -------------------------------------------------------------------------
    // Daily loss / auto-risk
    // -------------------------------------------------------------------------

    public static function getDailyLossLimit(): float
    {
        return (float) self::get(
            'trading.daily_loss_limit',
            config('trading.auto_alpaca_orders.auto_risk.daily_loss_limit', -500)
        );
    }

    public static function getConsecutiveLossDays(): int
    {
        return (int) self::get(
            'trading.consecutive_loss_days',
            config('trading.auto_alpaca_orders.auto_risk.consecutive_loss_days', 3)
        );
    }

    /**
     * Time-weighted intraday loss halt limits. The active threshold tightens
     * as the day progresses — looser early (normal volatile opens) and
     * tighter late (recovery window has closed).
     */
    public static function getIntradayHaltLimitPre11am(): float
    {
        return (float) self::get('trading.intraday_loss_halt_limit_pre_11am', -800);
    }

    public static function getIntradayHaltLimit11amTo1pm(): float
    {
        return (float) self::get('trading.intraday_loss_halt_limit_11am_1pm', -600);
    }

    public static function getIntradayHaltLimitPost1pm(): float
    {
        return (float) self::get('trading.intraday_loss_halt_limit_post_1pm', -400);
    }

    /**
     * Minimum paper P&L required for the pre-open resume check to switch back to live trading.
     */
    public static function getPaperResumeMinProfit(): float
    {
        return (float) self::get('trading.paper_resume_min_profit', 100);
    }

    // -------------------------------------------------------------------------
    // Position sizing / slippage rule
    // -------------------------------------------------------------------------

    public static function getMaxPositionPctOfLiquidity(): float
    {
        return (float) self::get(
            'trading.position.max_position_pct_of_liquidity',
            config('trading.max_position_pct_of_liquidity', 10.0)
        );
    }

    public static function getMinPositionSize(): float
    {
        return (float) self::get(
            'trading.position.min_position_size',
            config('trading.min_position_size', 500)
        );
    }

    public static function getMaxPositionSize(): float
    {
        return (float) self::get(
            'trading.position.max_position_size',
            config('trading.max_position_size', 5000)
        );
    }

    public static function getMinDollarVolumePerMinute(): float
    {
        return (float) self::get(
            'trading.position.min_dollar_volume_per_minute',
            config('trading.min_dollar_volume_per_minute', 0)
        );
    }

    /**
     * @return array<string, float|int|bool>
     */
    public static function getPositionSizeSlippageRuleConfig(): array
    {
        return [
            'enabled' => (bool) self::get(
                'trading.position.slippage_rule.enabled',
                config('trading.position_size_slippage_rule.enabled', false)
            ),
            'window_days' => (int) self::get(
                'trading.position.slippage_rule.window_days',
                config('trading.position_size_slippage_rule.window_days', 30)
            ),
            'min_samples' => (int) self::get(
                'trading.position.slippage_rule.min_samples',
                config('trading.position_size_slippage_rule.min_samples', 80)
            ),
            'cache_seconds' => (int) self::get(
                'trading.position.slippage_rule.cache_seconds',
                config('trading.position_size_slippage_rule.cache_seconds', 300)
            ),
            'include_paper_orders' => (bool) self::get(
                'trading.position.slippage_rule.include_paper_orders',
                config('trading.position_size_slippage_rule.include_paper_orders', true)
            ),
            'low_liquidity_pct' => (float) self::get(
                'trading.position.slippage_rule.low_liquidity_pct',
                config('trading.position_size_slippage_rule.low_liquidity_pct', 10.0)
            ),
            'medium_liquidity_pct' => (float) self::get(
                'trading.position.slippage_rule.medium_liquidity_pct',
                config('trading.position_size_slippage_rule.medium_liquidity_pct', 12.5)
            ),
            'high_liquidity_pct' => (float) self::get(
                'trading.position.slippage_rule.high_liquidity_pct',
                config('trading.position_size_slippage_rule.high_liquidity_pct', 15.0)
            ),
            'medium_risk_avg_slippage_pct' => (float) self::get(
                'trading.position.slippage_rule.medium_risk_avg_slippage_pct',
                config('trading.position_size_slippage_rule.medium_risk_avg_slippage_pct', 0.06)
            ),
            'medium_risk_worst_slippage_pct' => (float) self::get(
                'trading.position.slippage_rule.medium_risk_worst_slippage_pct',
                config('trading.position_size_slippage_rule.medium_risk_worst_slippage_pct', 0.80)
            ),
            'high_risk_avg_slippage_pct' => (float) self::get(
                'trading.position.slippage_rule.high_risk_avg_slippage_pct',
                config('trading.position_size_slippage_rule.high_risk_avg_slippage_pct', 0.12)
            ),
            'high_risk_worst_slippage_pct' => (float) self::get(
                'trading.position.slippage_rule.high_risk_worst_slippage_pct',
                config('trading.position_size_slippage_rule.high_risk_worst_slippage_pct', 1.50)
            ),
            'min_liquidity_pct' => (float) self::get(
                'trading.position.slippage_rule.min_liquidity_pct',
                config('trading.position_size_slippage_rule.min_liquidity_pct', 10.0)
            ),
            'max_liquidity_pct' => (float) self::get(
                'trading.position.slippage_rule.max_liquidity_pct',
                config('trading.position_size_slippage_rule.max_liquidity_pct', 20.0)
            ),
        ];
    }

    /**
     * Whether the system is currently running in paper trading mode.
     */
    public static function isPaperTrading(): bool
    {
        return (bool) config('alpaca.paper_trading', true);
    }

    // -------------------------------------------------------------------------
    // Realtime Pipeline R / S candidate detection gates
    // -------------------------------------------------------------------------

    public static function getRealtimeEarlyScoreMin(): float
    {
        return (float) self::get(
            'trading.realtime.early_score_min',
            config('trading_realtime.early_score_min', 65)
        );
    }

    public static function getRealtimeMinRvol(): float
    {
        return (float) self::get(
            'trading.realtime.min_rvol',
            config('trading_realtime.min_rvol', 1.5)
        );
    }

    public static function getRealtimeMinAtrPct(): float
    {
        return (float) self::get(
            'trading.realtime.min_atr_pct',
            config('trading_realtime.min_atr_pct', 0.25)
        );
    }

    public static function getRealtimeMinMove30mPct(): float
    {
        return (float) self::get(
            'trading.realtime.min_move_30m_pct',
            config('trading_realtime.min_move_30m_pct', 0.5)
        );
    }

    public static function getRealtimeMinDollarVolume1m(): float
    {
        return (float) self::get(
            'trading.realtime.min_dollar_volume_1m',
            config('trading_realtime.min_dollar_volume_1m', 50000)
        );
    }

    public static function getRealtimeMaxVwapExtensionPct(): float
    {
        return (float) self::get(
            'trading.realtime.max_vwap_extension_pct',
            config('trading_realtime.max_vwap_extension_pct', 2.0)
        );
    }

    public static function getRealtimeEntryCandidateMaxAgeSeconds(): int
    {
        return (int) self::get(
            'trading.realtime.entry_candidate_max_age_seconds',
            config('trading_realtime.entry_candidate_max_age_seconds', 180)
        );
    }

    public static function getRealtimeEntryFinalScoreMin(): float
    {
        return (float) self::get(
            'trading.realtime.entry_final_score_min',
            config('trading_realtime.entry_final_score_min', 70)
        );
    }

    public static function getRealtimeEntryMinPrice(): float
    {
        return (float) self::get(
            'trading.realtime.entry_min_price',
            config('trading_realtime.entry_min_price', 1.00)
        );
    }

    public static function getRealtimeEntryMaxPrice(): float
    {
        return (float) self::get(
            'trading.realtime.entry_max_price',
            config('trading_realtime.entry_max_price', 200.00)
        );
    }

    public static function isRealtimeEntryRequireVwap(): bool
    {
        return (bool) self::get(
            'trading.realtime.entry_require_vwap',
            config('trading_realtime.entry_require_vwap', true)
        );
    }

    public static function getRealtimeEntryReturn1mMinPct(): float
    {
        return (float) self::get(
            'trading.realtime.entry_return_1m_min_pct',
            config('trading_realtime.entry_return_1m_min_pct', 0.10)
        );
    }

    public static function getRealtimeEntryReturn3mMinPct(): float
    {
        return (float) self::get(
            'trading.realtime.entry_return_3m_min_pct',
            config('trading_realtime.entry_return_3m_min_pct', 0.20)
        );
    }

    public static function getRealtimeEntryVolumeRatioMin(): float
    {
        return (float) self::get(
            'trading.realtime.entry_volume_ratio_min',
            config('trading_realtime.entry_volume_ratio_min', 2.00)
        );
    }

    public static function getRealtimeEntryMinDollarVolume1m(): float
    {
        return (float) self::get(
            'trading.realtime.entry_min_dollar_volume_1m',
            config('trading_realtime.entry_min_dollar_volume_1m', 10000)
        );
    }

    public static function getRealtimeMaxMoveSinceCandidatePct(): float
    {
        return (float) self::get(
            'trading.realtime.max_move_since_candidate_pct',
            config('trading_realtime.max_move_since_candidate_pct', 0.75)
        );
    }

    public static function getRealtimeEntryAboveCandidateMinPct(): float
    {
        return (float) self::get(
            'trading.realtime.entry_above_candidate_min_pct',
            config('trading_realtime.entry_above_candidate_min_pct', -0.10)
        );
    }

    public static function getRealtimeEntryClosePositionMin(): float
    {
        return (float) self::get(
            'trading.realtime.entry_close_position_min',
            config('trading_realtime.entry_close_position_min', 0.60)
        );
    }

    public static function getRealtimeEntryUpperWickMax(): float
    {
        return (float) self::get(
            'trading.realtime.entry_upper_wick_max',
            config('trading_realtime.entry_upper_wick_max', 0.45)
        );
    }

    public static function getRealtimeEntryBidAskImbalanceMin(): float
    {
        return (float) self::get(
            'trading.realtime.entry_bid_ask_imbalance_min',
            config('trading_realtime.entry_bid_ask_imbalance_min', -0.20)
        );
    }

    public static function isRealtimeEntryRequireEma9AboveEma21(): bool
    {
        return (bool) self::get(
            'trading.realtime.entry_require_ema9_above_ema21',
            config('trading_realtime.entry_require_ema9_above_ema21', true)
        );
    }

    // -------------------------------------------------------------------------
    // Per-pipeline: run_cron
    // -------------------------------------------------------------------------

    public static function isPipelineRunCronEnabled(string $pipeline): bool
    {
        $pipeline = strtolower($pipeline);
        $dbKey = "trading.pipeline_{$pipeline}.run_cron";

        return (bool) self::get($dbKey, false);
    }

    /**
     * Resolve the human-readable display name for a pipeline by reading
     * the scanner class's $name property via reflection. Cached in Redis.
     */
    public static function getPipelineDisplayName(string $pipeline): string
    {
        $pipeline = strtolower($pipeline);
        $cacheKey = "trading:pipeline_name:{$pipeline}";
        $upper = strtoupper($pipeline);

        return (string) Cache::remember($cacheKey, 3600, function () use ($pipeline, $upper): string {
            $version = config("app.trade_alert_{$pipeline}_version");
            if (! $version) {
                return $upper;
            }

            $classSuffix = str_replace('.', '_', ltrim((string) $version, 'v'));
            $className = "App\\Services\\Trading\\FiveMinuteSignalScannerV{$classSuffix}";

            if (! class_exists($className)) {
                return $upper;
            }

            try {
                $ref = new \ReflectionClass($className);
                $defaults = $ref->getDefaultProperties();
                $name = $defaults['name'] ?? null;

                return $name ? "{$upper} — {$version} — {$name}" : $upper;
            } catch (\ReflectionException) {
                return "Pipeline — {$upper}";
            }
        });
    }

    /**
     * @return array<string, string>
     */
    public static function getAllPipelineDisplayNames(): array
    {
        return collect(self::PIPELINES)
            ->mapWithKeys(fn (string $p) => [$p => self::getPipelineDisplayName($p)])
            ->all();
    }

    // -------------------------------------------------------------------------
    // Per-pipeline: live rescore
    // -------------------------------------------------------------------------

    /**
     * Returns null when neither DB nor config has an override,
     * allowing the caller to fall back to the global default.
     */
    public static function getPipelineLiveRescoreOverride(string $pipeline): ?bool
    {
        $pipeline = strtolower($pipeline);
        $dbKey = "trading.pipeline_{$pipeline}.live_rescore_enabled";

        $dbValue = self::getRaw($dbKey);
        if ($dbValue !== null) {
            return (bool) $dbValue;
        }

        $configKey = "trading.ml_scoring.live_rescore_enabled_pipeline_{$pipeline}";
        $configValue = config($configKey);
        if ($configValue !== null) {
            return (bool) $configValue;
        }

        return null;
    }

    public static function isLiveRescoreEnabled(string $pipeline): bool
    {
        $override = self::getPipelineLiveRescoreOverride($pipeline);

        if ($override !== null) {
            return $override;
        }

        $dbGlobal = self::getRaw('trading.live_rescore_enabled_global');
        if ($dbGlobal !== null) {
            return (bool) $dbGlobal;
        }

        return self::isStaleRescoreEnabled();
    }

    // -------------------------------------------------------------------------
    // Time slots
    // -------------------------------------------------------------------------

    /**
     * Returns all 15-minute slot keys from 09:30 to 15:45.
     *
     * @return list<string>
     */
    public static function availableTimeSlots(): array
    {
        $slots = [];

        for ($h = 9; $h <= 15; $h++) {
            for ($m = 0; $m < 60; $m += 15) {
                if ($h === 9 && $m < 30) {
                    continue;
                }

                $slots[] = str_pad((string) $h, 2, '0', STR_PAD_LEFT).':'.str_pad((string) $m, 2, '0', STR_PAD_LEFT);
            }
        }

        return $slots; // 09:30 through 15:45
    }

    public static function isTimeSlotEnabled(string $slot): bool
    {
        return (bool) self::get("trading.time_slot.{$slot}", true);
    }

    /**
     * @return array<string, bool>
     */
    public static function getTimeSlots(): array
    {
        return collect(self::availableTimeSlots())
            ->mapWithKeys(fn (string $slot) => [$slot => self::isTimeSlotEnabled($slot)])
            ->all();
    }

    // -------------------------------------------------------------------------
    // Realtime Time Slots (Pipeline R only)
    // -------------------------------------------------------------------------

    /**
     * Pipeline R needs different active windows than the global time slots.
     * Uses separate DB keys: trading.realtime_slot.{HH:MM}
     *
     * @return list<string>
     */
    public static function availableRealtimeSlots(): array
    {
        $slots = [];

        for ($h = 9; $h <= 15; $h++) {
            for ($m = 0; $m < 60; $m += 15) {
                if ($h === 9 && $m < 30) {
                    continue;
                }

                $slots[] = str_pad((string) $h, 2, '0', STR_PAD_LEFT).':'.str_pad((string) $m, 2, '0', STR_PAD_LEFT);
            }
        }

        return $slots;
    }

    public static function isRealtimeSlotEnabled(string $slot): bool
    {
        return (bool) self::get("trading.realtime_slot.{$slot}", true);
    }

    /**
     * @return array<string, bool>
     */
    public static function getRealtimeSlots(): array
    {
        return collect(self::availableRealtimeSlots())
            ->mapWithKeys(fn (string $slot) => [$slot => self::isRealtimeSlotEnabled($slot)])
            ->all();
    }

    public static function getRealtimeSkipFirstMinutes(): int
    {
        return (int) self::get(
            'trading.realtime.skip_first_minutes',
            config('trading_realtime.skip_first_minutes', 0)
        );
    }

    public static function setRealtimeSkipFirstMinutes(int $minutes): void
    {
        self::set('trading.realtime.skip_first_minutes', (string) $minutes);
    }

    // -------------------------------------------------------------------------
    // Stop Loss
    // -------------------------------------------------------------------------

    public static function getStopLossMode(): string
    {
        return (string) self::get(
            'trading.stop_loss.mode',
            config('trading.auto_alpaca_orders.stop_loss_mode', 'atr')
        );
    }

    public static function isProfitProtectionEnabled(): bool
    {
        return (bool) self::get(
            'trading.stop_loss.profit_protection_enabled',
            config('trading.auto_alpaca_orders.profit_protection_enabled', false)
        );
    }

    public static function getStopLossFixedPct(): float
    {
        return (float) self::get(
            'trading.stop_loss.fixed_pct',
            config('trading.auto_alpaca_orders.stop_loss_pct', 0.80)
        );
    }

    public static function getStopLossAtrMultiplier(): float
    {
        return (float) self::get(
            'trading.stop_loss.atr_multiplier',
            config('trading.auto_alpaca_orders.stop_loss_atr_multiplier', 3.0)
        );
    }

    public static function getStopLossAtrMinPct(): float
    {
        return (float) self::get(
            'trading.stop_loss.atr_min_pct',
            config('trading.auto_alpaca_orders.stop_loss_atr_min_pct', 0.75)
        );
    }

    public static function getStopLossAtrMaxPct(): float
    {
        return (float) self::get(
            'trading.stop_loss.atr_max_pct',
            config('trading.auto_alpaca_orders.stop_loss_atr_max_pct', 2.0)
        );
    }

    // -------------------------------------------------------------------------
    // Limit Orders & Slippage
    // -------------------------------------------------------------------------

    public static function isUseLimitOrdersEnabled(): bool
    {
        return (bool) self::get(
            'trading.limit_orders.use_limit_orders',
            config('trading.auto_alpaca_orders.use_limit_orders', true)
        );
    }

    public static function getLimitSlippagePct(): float
    {
        return (float) self::get(
            'trading.limit_orders.slippage_pct',
            config('trading.auto_alpaca_orders.limit_slippage_pct', 0.3)
        );
    }

    public static function getLimitSlippagePctStalePrice(): float
    {
        return (float) self::get(
            'trading.limit_orders.slippage_pct_stale_price',
            config('trading.auto_alpaca_orders.limit_slippage_pct_stale_price', 0.8)
        );
    }

    public static function getPartialFillStopTimeoutMinutes(): float
    {
        return (float) self::get(
            'trading.limit_orders.partial_fill_stop_timeout_minutes',
            config('trading.auto_alpaca_orders.partial_fill_stop_timeout_minutes', 2.0)
        );
    }

    public static function getMaxSpreadPct(): float
    {
        return (float) self::get(
            'trading.auto_alpaca_orders.max_spread_pct',
            config('trading.auto_alpaca_orders.max_spread_pct', 0.35)
        );
    }

    public static function getMaxQuoteAgeSeconds(): int
    {
        return (int) self::get(
            'trading.auto_alpaca_orders.max_quote_age_seconds',
            config('trading.auto_alpaca_orders.max_quote_age_seconds', 5)
        );
    }

    /** @var list<string> */
    private const SLIPPAGE_PIPELINES = ['a', 'b', 'c', 'd', 'f', 'h', 'k', 'n', 'o'];

    public static function getPipelineSlippageOverride(string $pipeline): ?float
    {
        $pipeline = strtolower($pipeline);
        $dbKey = "trading.limit_orders.slippage_pct_pipeline_{$pipeline}";

        $dbValue = self::getRaw($dbKey);
        if ($dbValue !== null) {
            return (float) $dbValue;
        }

        $configKey = "trading.auto_alpaca_orders.limit_slippage_pct_pipeline_{$pipeline}";
        $configValue = config($configKey);

        return $configValue !== null ? (float) $configValue : null;
    }

    /**
     * @return array<string, float|null>
     */
    public static function getAllPipelineSlippageOverrides(): array
    {
        return collect(self::SLIPPAGE_PIPELINES)
            ->mapWithKeys(fn (string $p) => [$p => self::getPipelineSlippageOverride($p)])
            ->all();
    }

    // -------------------------------------------------------------------------
    // Trading Hours
    // -------------------------------------------------------------------------

    public static function getTradingStartTime(): string
    {
        return (string) self::get(
            'trading.hours.start_time',
            config('trading.auto_alpaca_orders.trading_start_time', '09:40')
        );
    }

    public static function getTradingEndTime(): string
    {
        return (string) self::get(
            'trading.hours.end_time',
            config('trading.auto_alpaca_orders.trading_end_time', '14:45')
        );
    }

    // -------------------------------------------------------------------------
    // Stale Alert Rescore
    // -------------------------------------------------------------------------

    public static function isStaleRescoreEnabled(): bool
    {
        return (bool) self::get(
            'trading.stale_rescore.enabled',
            config('trading.auto_alpaca_orders.stale_rescore_enabled', false)
        );
    }

    public static function isStaleRescorePaperOnly(): bool
    {
        return (bool) self::get(
            'trading.stale_rescore.paper_only',
            config('trading.auto_alpaca_orders.stale_rescore_paper_only', true)
        );
    }

    public static function getStaleRescoreMaxAgeMinutes(): int
    {
        return (int) self::get(
            'trading.stale_rescore.max_age_minutes',
            config('trading.auto_alpaca_orders.stale_rescore_max_age_minutes', 60)
        );
    }

    // -------------------------------------------------------------------------
    // Benchmark VWAP Gate
    // -------------------------------------------------------------------------

    public static function isBenchmarkVwapGateEnabled(): bool
    {
        return (bool) self::get(
            'trading.benchmark_vwap_gate.enabled',
            config('trading.auto_alpaca_orders.benchmark_vwap_gate_enabled', false)
        );
    }

    public static function getBenchmarkSymbol(): string
    {
        return (string) self::get(
            'trading.benchmark_vwap_gate.symbol',
            config('trading.auto_alpaca_orders.benchmark_symbol', 'QQQM')
        );
    }

    public static function getBenchmarkMaxPctBelowHigh(): ?float
    {
        $dbValue = self::getRaw('trading.benchmark_vwap_gate.max_pct_below_high');
        if ($dbValue !== null) {
            return (float) $dbValue;
        }

        return config('trading.auto_alpaca_orders.benchmark_max_pct_below_high');
    }

    /** @var list<string> */
    private const BENCHMARK_VWAP_PIPELINES = ['a', 'b', 'c', 'd', 'e', 'f', 'g', 'h', 'i', 'j', 'k', 'l', 'm', 'n', 'o', 'p', 'q', 'r', 's', 'manual', 'external'];  // order must match self::PIPELINES

    public static function getPipelineBenchmarkVwapGateOverride(string $pipeline): ?bool
    {
        $pipeline = strtolower($pipeline);
        $dbKey = "trading.benchmark_vwap_gate.pipeline_{$pipeline}";

        $dbValue = self::getRaw($dbKey);
        if ($dbValue !== null) {
            return (bool) $dbValue;
        }

        $configKey = "trading.auto_alpaca_orders.benchmark_vwap_gate_pipeline_{$pipeline}";

        return config($configKey);
    }

    /**
     * @return array<string, bool|null>
     */
    public static function getAllPipelineBenchmarkVwapGateOverrides(): array
    {
        return collect(self::BENCHMARK_VWAP_PIPELINES)
            ->mapWithKeys(fn (string $p) => [$p => self::getPipelineBenchmarkVwapGateOverride($p)])
            ->all();
    }

    // -------------------------------------------------------------------------
    // Low-level helpers
    // -------------------------------------------------------------------------

    /**
     * Get a setting with DB-first, fallback second, and a 60-second cache.
     */
    public static function get(string $key, mixed $default = null): mixed
    {
        return Cache::remember("trading_setting:{$key}", self::CACHE_TTL, function () use ($key, $default) {
            $value = Setting::where('name', $key)->value('value');

            return $value ?? $default;
        });
    }

    /**
     * Get the raw DB value only (null when not in DB).
     */
    public static function getRaw(string $key): mixed
    {
        return Cache::remember("trading_setting:{$key}", self::CACHE_TTL, function () use ($key) {
            return Setting::where('name', $key)->value('value');
        });
    }

    /**
     * Write a setting and bust its cache immediately.
     */
    public static function set(string $key, mixed $value): void
    {
        Setting::set($key, $value);
        Cache::forget("trading_setting:{$key}");
    }

    public static function getRetradeSymbolWaitMinutes(): int
    {
        return max(0, (int) self::get(
            'trading.retrade_symbol_wait_minutes',
            config('trading.auto_alpaca_orders.retrade_symbol_wait_minutes', 60)
        ));
    }

    public static function getGlobalMaxAgeMinutes(): int
    {
        return (int) self::get(
            'trading.max_age_minutes',
            config('trading.auto_alpaca_orders.max_age_minutes', 10)
        );
    }

    public static function getSkipNextAlertAfterMlPassedMinutes(): int
    {
        return max(0, (int) self::get(
            'trading.skip_next_alert_after_ml_passed_minutes',
            config('trading.auto_alpaca_orders.skip_next_alert_after_ml_passed_minutes', 0)
        ));
    }
}
