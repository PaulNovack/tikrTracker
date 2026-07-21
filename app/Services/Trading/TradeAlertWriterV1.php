<?php

namespace App\Services\Trading;

use App\Events\TradeAlertCreated;
use App\Jobs\ScoreTradeAlertWithMl;
use App\Services\TradingSettingService;
use App\Trading\SymbolBlacklist;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class TradeAlertWriterV1
{
    use HasPriceTables;

    protected FadeDetectionService $fadeDetection;

    public function __construct(FadeDetectionService $fadeDetection)
    {
        $this->fadeDetection = $fadeDetection;
    }

    private string $version = 'v1';

    private bool $backtestMode = false;

    private function resolveTimestampToUtc(?string $timestampEst): ?Carbon
    {
        if (! $timestampEst) {
            return null;
        }

        try {
            return Carbon::createFromFormat('Y-m-d H:i:s', $timestampEst, 'America/New_York')->setTimezone('UTC');
        } catch (\Throwable) {
            return null;
        }
    }

    private function resolveMinuteBucket(?string $timestampEst): ?string
    {
        if (! $timestampEst) {
            return null;
        }

        try {
            return Carbon::parse($timestampEst, 'America/New_York')->startOfMinute()->format('Y-m-d H:i:00');
        } catch (\Throwable) {
            return null;
        }
    }

    private function buildDedupeKey(array $signal, array $entry, string $tradingDate, ?string $version, string $pipelineRun): string
    {
        if ($pipelineRun === 'M') {
            return implode('|', [
                $signal['asset_type'],
                $signal['symbol'],
                $tradingDate,
                $version ?? $this->version,
                $pipelineRun,
            ]);
        }

        // Use signal_ts_est as the dedupe bucket so the same 5-minute bar
        // always produces the same dedupe key, even if the entry finder
        // generates different entry times for the same signal.
        // (Previously used entry_ts_est, which varied per entry candidate
        // and allowed duplicate alerts for the same signal bar.)
        // For Pipeline M (per-day dedupe), the signal time doesn't matter.
        $dedupeMinuteBucket = $this->resolveMinuteBucket(
            (string) ($signal['signal_ts_est'] ?? $entry['entry_ts_est'] ?? $tradingDate.' 00:00:00')
        );

        return implode('|', [
            $signal['asset_type'],
            $signal['symbol'],
            $dedupeMinuteBucket ?? 'UNKNOWN',
        ]);
    }

    private function resolveWriteFreshnessMinutes(string $pipelineRun, bool $isRealtime): int
    {
        $maxAgeMinutes = TradingSettingService::getPipelineMaxAgeMinutes((string) $pipelineRun);

        if (strtoupper($pipelineRun) === 'L' && ! $isRealtime) {
            $maxAgeMinutes = TradingSettingService::getPipelineMaxAgeMinutes((string) $pipelineRun, true);
        }

        return max(1, $maxAgeMinutes);
    }

    /**
     * Set backtest mode — suppresses ML scoring job dispatch so backtest runs
     * don't flood the queue and delay live alert processing.
     */
    public function setBacktestMode(bool $backtestMode): void
    {
        $this->backtestMode = $backtestMode;
    }

    private function shouldRunMlScoringSync(bool $isRealtime, string $pipelineRun): bool
    {
        if (! $isRealtime || $this->backtestMode) {
            return false;
        }

        // Pipeline H is latency-sensitive; score inline to avoid queue wait.
        return strtoupper($pipelineRun) === 'H';
    }

    /**
     * Calculate average dollar volume per minute for a symbol on a trading day.
     * Used to filter illiquid stocks that could cause slippage.
     */
    private function calculateAvgDollarVolumePerMinute(string $symbol, string $tradingDate): float
    {
        $avgDollarVol = DB::table($this->oneMinuteTable)
            ->where('symbol', $symbol)
            ->where('trading_date_est', $tradingDate)
            ->whereBetween('trading_time_est', ['09:30:00', '16:00:00'])
            ->selectRaw('AVG(volume * price) as avg_dollar_vol')
            ->value('avg_dollar_vol');

        return (float) ($avgDollarVol ?? 0);
    }

    /**
     * Calculate position size based on liquidity and configured mode.
     * - fixed mode: use AUTO_ALPACA_POSITION_SIZE
     * - dynamic mode: scale based on liquidity (position = min(MAX, max(MIN, liquidity × PCT)))
     * IMPORTANT: Returns the CAPPED amount (ready for Alpaca orders)
     * Use avg_dollar_volume_per_minute * config('trading.max_position_pct_of_liquidity') / 100 to get uncapped
     */
    private function calculatePositionSize(float $avgDollarVolPerMin): float
    {
        $mode = config('trading.position_size_mode', 'fixed');

        if ($mode === 'dynamic') {
            $maxPct = $this->resolveDynamicLiquidityPct();
            $minSize = TradingSettingService::getMinPositionSize();
            $maxSize = TradingSettingService::getMaxPositionSize();

            // Calculate: position = liquidity × (percentage / 100)
            // Example: $50K/min × 10% = $5,000
            $calculatedSize = $avgDollarVolPerMin * ($maxPct / 100);

            // Apply min/max bounds (returns CAPPED amount for safe order placement)
            $positionSize = max($minSize, min($maxSize, $calculatedSize));

            return round($positionSize, 2);
        }

        // Fixed mode: use configured position size
        return (float) config('trading.auto_alpaca_orders.position_size', 5000);
    }

    private function resolveDynamicLiquidityPct(): float
    {
        $basePct = TradingSettingService::getMaxPositionPctOfLiquidity();
        $ruleConfig = TradingSettingService::getPositionSizeSlippageRuleConfig();

        if (! ((bool) ($ruleConfig['enabled'] ?? false))) {
            return $basePct;
        }

        $cacheSeconds = max(30, (int) ($ruleConfig['cache_seconds'] ?? 300));
        $cacheKey = 'trading:position-size-slippage-rule-metrics';
        $cacheHit = Cache::has($cacheKey);
        $startedAt = microtime(true);
        $metrics = Cache::remember(
            $cacheKey,
            now()->addSeconds($cacheSeconds),
            fn () => $this->calculateRecentAdverseSlippageMetrics($ruleConfig)
        );

        if (! $cacheHit) {
            Log::info('Temporary slippage metrics timing', [
                'cache_hit' => false,
                'elapsed_ms' => round((microtime(true) - $startedAt) * 1000, 2),
                'cache_seconds' => $cacheSeconds,
            ]);
        }

        if (! ((bool) ($metrics['enough_data'] ?? false))) {
            return $basePct;
        }

        return self::determineLiquidityPctFromSlippageRule(
            $ruleConfig,
            $basePct,
            (int) $metrics['sample_count'],
            (float) $metrics['avg_adverse_slippage_pct'],
            (float) $metrics['worst_adverse_slippage_pct']
        );
    }

    /**
     * Determine the dynamic liquidity percentage from slippage metrics.
     */
    public static function determineLiquidityPctFromSlippageRule(
        array $ruleConfig,
        float $basePct,
        int $sampleCount,
        float $avgAdverseSlippagePct,
        float $worstAdverseSlippagePct
    ): float {
        $lowLiquidityPct = (float) ($ruleConfig['low_liquidity_pct'] ?? $basePct);
        $mediumLiquidityPct = (float) ($ruleConfig['medium_liquidity_pct'] ?? $basePct);
        $highLiquidityPct = (float) ($ruleConfig['high_liquidity_pct'] ?? $basePct);

        $highAvgThreshold = (float) ($ruleConfig['high_risk_avg_slippage_pct'] ?? 0.12);
        $highWorstThreshold = (float) ($ruleConfig['high_risk_worst_slippage_pct'] ?? 1.50);
        $mediumAvgThreshold = (float) ($ruleConfig['medium_risk_avg_slippage_pct'] ?? 0.06);
        $mediumWorstThreshold = (float) ($ruleConfig['medium_risk_worst_slippage_pct'] ?? 0.80);

        $targetPct = $highLiquidityPct;

        if ($sampleCount > 0 && ($avgAdverseSlippagePct >= $highAvgThreshold || $worstAdverseSlippagePct >= $highWorstThreshold)) {
            $targetPct = $lowLiquidityPct;
        } elseif ($sampleCount > 0 && ($avgAdverseSlippagePct >= $mediumAvgThreshold || $worstAdverseSlippagePct >= $mediumWorstThreshold)) {
            $targetPct = $mediumLiquidityPct;
        }

        $minLiquidityPct = (float) ($ruleConfig['min_liquidity_pct'] ?? min($lowLiquidityPct, $mediumLiquidityPct, $highLiquidityPct));
        $maxLiquidityPct = (float) ($ruleConfig['max_liquidity_pct'] ?? max($lowLiquidityPct, $mediumLiquidityPct, $highLiquidityPct));

        return max($minLiquidityPct, min($maxLiquidityPct, $targetPct));
    }

    /**
     * Calculate recent adverse slippage from filled Alpaca orders.
     *
     * Buy adverse slippage uses trade alert entry vs filled buy price.
     * Sell adverse slippage uses stop price vs filled stop sell price.
     *
     * @return array{sample_count:int,avg_adverse_slippage_pct:float,worst_adverse_slippage_pct:float,enough_data:bool}
     */
    private function calculateRecentAdverseSlippageMetrics(array $ruleConfig): array
    {
        $windowDays = max(1, (int) ($ruleConfig['window_days'] ?? 30));
        $minSamples = max(1, (int) ($ruleConfig['min_samples'] ?? 80));
        $includePaperOrders = (bool) ($ruleConfig['include_paper_orders'] ?? true);
        $since = now()->subDays($windowDays);

        $buyQuery = DB::table('alpaca_orders as ao')
            ->join('trade_alerts as ta', 'ta.id', '=', 'ao.trade_alert_id')
            ->where('ao.side', 'buy')
            ->where('ao.status', 'filled')
            ->whereNotNull('ao.filled_avg_price')
            ->whereNotNull('ta.entry')
            ->where('ta.entry', '>', 0)
            ->where('ao.created_at', '>=', $since);

        if (! $includePaperOrders) {
            $buyQuery->where(function ($query) {
                $query->whereNull('ao.is_paper')->orWhere('ao.is_paper', false);
            });
        }

        $buyStats = $buyQuery
            ->selectRaw('COUNT(*) as sample_count')
            ->selectRaw('AVG(GREATEST(((ao.filled_avg_price - ta.entry) / ta.entry) * 100, 0)) as avg_adverse_slippage_pct')
            ->selectRaw('MAX(GREATEST(((ao.filled_avg_price - ta.entry) / ta.entry) * 100, 0)) as worst_adverse_slippage_pct')
            ->first();

        $sellQuery = DB::table('alpaca_orders as ao')
            ->where('ao.side', 'sell')
            ->whereIn('ao.order_type', ['stop', 'stop_limit'])
            ->where('ao.status', 'filled')
            ->whereNotNull('ao.filled_avg_price')
            ->whereNotNull('ao.stop_price')
            ->where('ao.stop_price', '>', 0)
            ->where('ao.created_at', '>=', $since);

        if (! $includePaperOrders) {
            $sellQuery->where(function ($query) {
                $query->whereNull('ao.is_paper')->orWhere('ao.is_paper', false);
            });
        }

        $sellStats = $sellQuery
            ->selectRaw('COUNT(*) as sample_count')
            ->selectRaw('AVG(GREATEST(((ao.stop_price - ao.filled_avg_price) / ao.stop_price) * 100, 0)) as avg_adverse_slippage_pct')
            ->selectRaw('MAX(GREATEST(((ao.stop_price - ao.filled_avg_price) / ao.stop_price) * 100, 0)) as worst_adverse_slippage_pct')
            ->first();

        $buySampleCount = (int) ($buyStats->sample_count ?? 0);
        $sellSampleCount = (int) ($sellStats->sample_count ?? 0);
        $sampleCount = $buySampleCount + $sellSampleCount;

        $buyAvg = (float) ($buyStats->avg_adverse_slippage_pct ?? 0);
        $sellAvg = (float) ($sellStats->avg_adverse_slippage_pct ?? 0);

        $weightedAvg = $sampleCount > 0
            ? (($buyAvg * $buySampleCount) + ($sellAvg * $sellSampleCount)) / $sampleCount
            : 0.0;

        $worstAdverse = max(
            (float) ($buyStats->worst_adverse_slippage_pct ?? 0),
            (float) ($sellStats->worst_adverse_slippage_pct ?? 0)
        );

        return [
            'sample_count' => $sampleCount,
            'avg_adverse_slippage_pct' => round($weightedAvg, 4),
            'worst_adverse_slippage_pct' => round($worstAdverse, 4),
            'enough_data' => $sampleCount >= $minSamples,
        ];
    }

    /**
     * Apply config-based min/max bounds to suggested_trailing_stop_pct.
     * Uses AUTO_ALPACA_STOP_LOSS_ATR_MIN_PCT and AUTO_ALPACA_STOP_LOSS_ATR_MAX_PCT.
     * This ensures consistency between alert creation and actual stop loss placement.
     */
    private function applySuggestedTrailingStopBounds(?float $suggestedPct): ?float
    {
        if ($suggestedPct === null || $suggestedPct <= 0) {
            return null;
        }

        $minPct = TradingSettingService::getStopLossAtrMinPct();
        $maxPct = TradingSettingService::getStopLossAtrMaxPct();

        return max($minPct, min($maxPct, $suggestedPct));
    }

    /**
     * Calculate 5-day daily trend percentage.
     * Returns (today_close - 5_days_ago_close) / 5_days_ago_close * 100
     */
    private function calculateDailyTrend(string $symbol, string $assetType, string $tradingDate): ?float
    {
        $result = DB::table('daily_prices as dp')
            ->select([
                'dp.price as today_price',
                'dp5.price as price_5d_ago',
            ])
            ->leftJoin('daily_prices as dp5', function ($join) use ($symbol, $assetType) {
                $join->on('dp5.symbol', '=', DB::raw("'".$symbol."'"))
                    ->on('dp5.asset_type', '=', DB::raw("'".$assetType."'"))
                    ->on('dp5.date', '=', DB::raw('DATE_SUB(dp.date, INTERVAL 5 DAY)'));
            })
            ->where('dp.symbol', $symbol)
            ->where('dp.asset_type', $assetType)
            ->where('dp.date', $tradingDate)
            ->first();

        if (! $result || ! $result->price_5d_ago) {
            return null;
        }

        return round((($result->today_price - $result->price_5d_ago) / $result->price_5d_ago) * 100, 2);
    }

    /**
     * Calculate position in 60-minute range (0.0 to 1.0).
     * Uses 12 five-minute bars before entry to establish range.
     */
    private function calculateRangePosition(string $symbol, string $assetType, string $entryTs, float $entryPrice): ?float
    {
        // Get 60 minutes of 5-minute bars before entry (12 bars * 5 minutes = 60 minutes)
        $bars = DB::table($this->fiveMinuteTable)
            ->select(['price', 'high', 'low'])
            ->where('symbol', $symbol)
            ->where('asset_type', $assetType)
            ->where('ts_est', '<=', $entryTs)
            ->orderBy('ts_est', 'desc')
            ->limit(12)
            ->get();

        if ($bars->isEmpty()) {
            return null;
        }

        $rangeLow = $bars->min('low');
        $rangeHigh = $bars->max('high');

        if ($rangeHigh <= $rangeLow) {
            return 0.5; // No range, default to middle
        }

        $position = ($entryPrice - $rangeLow) / ($rangeHigh - $rangeLow);

        return round(max(0, min(1, $position)), 6);
    }

    public function upsertAlert(array $signal, array $entry, string $asOfTsEst, ?string $algorithmVersion = null, string $pipelineRun = 'A', bool $isRealtime = true): int|false
    {
        // Centralized run_cron gate: if pipeline is disabled, block alert creation
        // regardless of caller (live, backtest, manual, stream watcher, etc.).
        if (! TradingSettingService::isPipelineRunCronEnabled($pipelineRun)) {
            \Log::channel('scheduled')->info("[TradeAlertWriter] Pipeline {$pipelineRun} disabled (run_cron=0) — alert suppressed.", [
                'symbol' => $signal['symbol'] ?? null,
                'pipeline' => $pipelineRun,
            ]);

            return false;
        }

        // Use provided algorithm version, or fall back to writer version
        $version = $algorithmVersion ?? $this->version;

        // Hard write-time freshness gate: prevent stale alerts from being created.
        // This is the centralized safety net across all pipelines.
        // Skip for backtest mode — historical entries will always be "stale" vs now().
        if (! $this->backtestMode) {
            $maxWriteAgeMinutes = $this->resolveWriteFreshnessMinutes($pipelineRun, $isRealtime);
            $nowUtc = now('UTC');
            $entryAtUtc = $this->resolveTimestampToUtc((string) ($entry['entry_ts_est'] ?? ''));
            $signalAtUtc = $this->resolveTimestampToUtc((string) ($signal['signal_ts_est'] ?? ''));

            $entryAgeMinutes = $entryAtUtc ? $nowUtc->diffInMinutes($entryAtUtc, true) : null;
            $signalAgeMinutes = $signalAtUtc ? $nowUtc->diffInMinutes($signalAtUtc, true) : null;

            $entryTooOld = $entryAgeMinutes !== null && $entryAgeMinutes > $maxWriteAgeMinutes;
            $signalTooOld = $signalAgeMinutes !== null && $signalAgeMinutes > $maxWriteAgeMinutes;

            if ($entryTooOld || $signalTooOld) {
                \Log::channel('stale-alerts')->warning('[TradeAlertWriter] Rejecting stale alert at write time', [
                    'symbol' => $signal['symbol'] ?? null,
                    'pipeline_run' => $pipelineRun,
                    'is_realtime' => $isRealtime,
                    'entry_ts_est' => $entry['entry_ts_est'] ?? null,
                    'signal_ts_est' => $signal['signal_ts_est'] ?? null,
                    'entry_age_minutes' => $entryAgeMinutes !== null ? round((float) $entryAgeMinutes, 2) : null,
                    'signal_age_minutes' => $signalAgeMinutes !== null ? round((float) $signalAgeMinutes, 2) : null,
                    'max_age_minutes' => $maxWriteAgeMinutes,
                    'as_of_ts_est' => $asOfTsEst,
                ]);

                return false;
            }
        }

        // SKIP_NEXT_ALERT_AFTER_ML_PASSED_MINUTES: suppress duplicate alerts for the same
        // symbol when a recent alert already passed ML scoring (passed_ml = 1).
        $skipMinutes = TradingSettingService::getSkipNextAlertAfterMlPassedMinutes();
        if ($skipMinutes > 0) {
            $skipCutoff = now()->subMinutes($skipMinutes);
            $recentPassedAlert = DB::table('trade_alerts')
                ->where('symbol', $signal['symbol'])
                ->where('passed_ml', 1)
                ->where('created_at', '>=', $skipCutoff)
                ->orderByDesc('created_at')
                ->first(['id', 'created_at', 'pipeline_run', 'ml_win_prob']);

            if ($recentPassedAlert) {
                \Log::channel('stale-alerts')->info('[TradeAlertWriter] Skipping duplicate symbol due to recent passed-ML alert', [
                    'symbol' => $signal['symbol'] ?? null,
                    'pipeline_run' => $pipelineRun,
                    'is_realtime' => $isRealtime,
                    'recent_alert_id' => $recentPassedAlert->id,
                    'recent_alert_time' => $recentPassedAlert->created_at,
                    'recent_pipeline' => $recentPassedAlert->pipeline_run,
                    'recent_ml_win_prob' => $recentPassedAlert->ml_win_prob,
                    'skip_minutes' => $skipMinutes,
                ]);

                return false;
            }
        }

        // Determine which table to use based on pipeline and config
        $pipelineLower = strtolower($pipelineRun);

        // Check NO_FILTER_FINDER config to determine table
        $noFilterConfig = config("trading.pipelines.{$pipelineLower}.no_filter_finder", false);
        $tableName = $noFilterConfig ? 'trade_alerts_unfiltered' : 'trade_alerts';

        $tradingDate = substr($asOfTsEst, 0, 10);

        // Calculate liquidity (always store it for future analysis and auto-sizing)
        $avgDollarVolPerMin = $this->calculateAvgDollarVolumePerMinute(
            $signal['symbol'],
            $tradingDate
        );

        // Calculate position size based on liquidity and mode
        // This returns the CAPPED amount (safe for Alpaca orders)
        $calculatedPositionSize = $this->calculatePositionSize($avgDollarVolPerMin);

        // Pipeline M: Only one alert per symbol per day (dedupe on symbol + trading_date)
        // Other pipelines: Dedupe by symbol + minute bucket so same-symbol alerts in the same
        // minute collapse into a single row, regardless of entry type or pipeline.
        $dedupeKey = $this->buildDedupeKey($signal, $entry, $tradingDate, $version, $pipelineRun);

        // Removed excessive debug logging - dedupe is working as expected

        // Debug logging for Pipeline G to catch corruption
        if ($pipelineRun === 'G' && $signal['symbol'] === 'APLD') {
            \Log::info('[TradeAlertWriter DEBUG] APLD entry data received', [
                'entry_field' => $entry['entry'] ?? 'MISSING',
                'entry_price_field' => $entry['entry_price'] ?? 'MISSING',
                'full_entry_array' => $entry,
            ]);
        }

        $alertData = [
            'symbol' => $signal['symbol'],
            'asset_type' => $signal['asset_type'],

            'trading_date_est' => $tradingDate,
            'as_of_ts_est' => $asOfTsEst,

            'signal_type' => $signal['signal_type'],
            'signal_ts_est' => $signal['signal_ts_est'],
            'time_of_day' => isset($signal['signal_ts_est']) ? substr($signal['signal_ts_est'], 11, 5) : null,

            'entry_type' => $entry['type'] ?? null,
            'entry_ts_est' => $entry['entry_ts_est'] ?? null,
            'entry' => $entry['entry'] ?? $entry['entry_price'] ?? null, // Support v100 entry_price
            'stop' => $entry['stop'] ?? $entry['stop_price'] ?? null, // Support v100 stop_price

            'risk_pct' => $entry['risk_pct'] ?? null,
            'risk_per_share' => $entry['risk_per_share'] ?? $entry['risk_amount'] ?? null, // Support v100 risk_amount
            'score' => $entry['score'] ?? null,
            'vol_ratio' => $entry['vol_ratio'] ?? null,
            'avg_dollar_volume_per_minute' => $avgDollarVolPerMin,
            'calculated_position_size' => $calculatedPositionSize,

            'five_min_directional_changes' => $entry['five_min_directional_changes'] ?? null,
            'five_min_green_bar_pct' => $entry['five_min_green_bar_pct'] ?? null,
            'five_min_net_progress' => $entry['five_min_net_progress'] ?? null,
            'consolidation_bars' => $entry['consolidation_bars'] ?? null,
            'breakout_volume_ratio' => $entry['breakout_volume_ratio'] ?? null,

            'atr' => $entry['atr'] ?? null,
            'atr_pct' => $entry['atr_pct'] ?? null,
            'daily_trend_5d_pct' => $this->calculateDailyTrend($signal['symbol'], $signal['asset_type'], $tradingDate),
            'range_position_60m' => $this->calculateRangePosition($signal['symbol'], $signal['asset_type'], $entry['entry_ts_est'], $entry['entry'] ?? $entry['entry_price'] ?? 0),
            'rsi_14_1m' => $entry['rsi'] ?? null,
            'suggested_trailing_stop' => $entry['suggested_trailing_stop'] ?? null,
            'suggested_trailing_stop_pct' => $this->applySuggestedTrailingStopBounds($entry['suggested_trailing_stop_pct'] ?? null),

            'targets' => isset($entry['targets']) ? json_encode($entry['targets']) : null,
            'meta' => json_encode([
                'signal_meta' => $signal['meta'] ?? null,
                'entry_meta' => $entry,
            ]),

            // --- v25.2 Pipeline H ML features ---
            // Scanner features (from FiveMinuteSignalScannerV25_2 meta; NULL for other pipelines)
            'move_30m_pct' => $signal['meta']['move_30m_pct'] ?? null,
            'rvol_5m' => $signal['meta']['rvol_5m'] ?? null,
            'atr_pct_5m' => $signal['meta']['atr_pct_5m'] ?? null,
            'notional_last5m' => $signal['meta']['notional_last5m'] ?? null,
            'pct_nd' => $signal['meta']['pct_nd'] ?? null,
            'spy_move_30m_pct' => $signal['meta']['spy_move_30m_pct'] ?? null,
            'universe_size' => $signal['meta']['universe_size'] ?? null,
            // Room-to-run features
            'hod' => $entry['hod'] ?? null,
            'room_to_hod_pct' => $entry['room_to_hod_pct'] ?? null,
            'room_to_hod_atr' => $entry['room_to_hod_atr'] ?? null,
            // VWAP entry distance
            'above_vwap_entry_pct' => $entry['above_vwap_entry_pct'] ?? null,
            // Entry quality
            'entry_body_pct' => $entry['entry_body_pct'] ?? null,
            'entry_close_position' => $entry['entry_close_position'] ?? null,
            'entry_volume_ratio' => $entry['entry_volume_ratio'] ?? null,
            'entry_notional_1m' => $entry['entry_notional_1m'] ?? null,
            // Entry score sub-components
            'entry_spread_strength' => $entry['entry_spread_strength'] ?? null,
            'entry_vwap_dist_score' => $entry['entry_vwap_dist_score'] ?? null,
            'entry_atr_score' => $entry['entry_atr_score'] ?? null,
            'entry_vol_score' => $entry['entry_vol_score'] ?? null,
            'entry_candle_score' => $entry['entry_candle_score'] ?? null,
            'entry_time_bonus' => $entry['entry_time_bonus'] ?? null,
            // VWAP reclaim specific
            'vwap_reclaim_strength_pct' => $entry['vwap_reclaim_strength_pct'] ?? null,
            'vwap_reclaim_wick_below_pct' => $entry['vwap_reclaim_wick_below_pct'] ?? null,
            // ORB retest specific
            'or_high_v252' => $entry['or_high_v252'] ?? null,
            'or_break_distance_pct' => $entry['or_break_distance_pct'] ?? null,
            'or_retest_depth_pct' => $entry['or_retest_depth_pct'] ?? null,
            'or_hold_close_pct' => $entry['or_hold_close_pct'] ?? null,
            'bars_since_or_break' => $entry['bars_since_or_break'] ?? null,
            // EMA9 pullback specific
            'ema9_pullback_depth_pct' => $entry['ema9_pullback_depth_pct'] ?? null,
            'ema9_reclaim_pct' => $entry['ema9_reclaim_pct'] ?? null,

            'dedupe_key' => $dedupeKey,
            'version' => $version,
            'pipeline_run' => $pipelineRun,
            'is_paper' => (bool) config('alpaca.paper_trading', true),
            'is_realtime' => $isRealtime,
            'blacklisted' => SymbolBlacklist::isBlacklisted($signal['symbol']),
            'created_at' => now(),
            'updated_at' => now(),
        ];

        // Calculate fade detection features
        try {
            $fadeFeatures = $this->fadeDetection->calculateFadeFeatures(
                $signal['symbol'],
                $signal['asset_type'],
                $entry['entry_ts_est']
            );

            // Preserve entry-finder values when fade calculation returns nulls.
            // Fade features should enrich data, not erase existing populated fields.
            foreach ($fadeFeatures as $key => $value) {
                if ($value !== null || ! array_key_exists($key, $alertData)) {
                    $alertData[$key] = $value;
                }
            }
        } catch (\Throwable $e) {
            \Log::warning("[TradeAlertWriter] Failed to calculate fade features for {$signal['symbol']}: {$e->getMessage()}");
            // Continue without fade features rather than failing alert creation
        }

        try {
            // Use insertOrIgnore to prevent duplicate key errors during parallel execution
            // The unique index on dedupe_key ensures atomicity at the MySQL level
            $inserted = DB::table($tableName)->insertOrIgnore($alertData);

            // If nothing was inserted, alert already exists (dedupe working correctly).
            // However, if the cron (is_realtime=true) lost the race to the continuous backtest
            // loop (is_realtime=false), upgrade the flag so the alert is correctly attributed.
            if (! $inserted) {
                if ($isRealtime) {
                    $existingAlert = DB::table($tableName)
                        ->where('dedupe_key', $dedupeKey)
                        ->first(['id', 'signal_ts_est', 'entry_ts_est', 'as_of_ts_est', 'is_realtime', 'ml_scored_at']);

                    $existingId = $existingAlert?->id;
                    $incomingSignalTs = (string) $signal['signal_ts_est'];
                    $incomingEntryTs = (string) ($entry['entry_ts_est'] ?? '');
                    $incomingAsOfTs = (string) $asOfTsEst;
                    $existingSignalTs = (string) ($existingAlert?->signal_ts_est ?? '');
                    $existingEntryTs = (string) ($existingAlert?->entry_ts_est ?? '');
                    $existingAsOfTs = (string) ($existingAlert?->as_of_ts_est ?? '');

                    $isNewerSignal = $incomingSignalTs > $existingSignalTs;
                    $isNewerEntry = $incomingEntryTs > $existingEntryTs;
                    $isNewerAsOf = $incomingAsOfTs > $existingAsOfTs;
                    $upgradingFromBacktest = (bool) $existingAlert && ! (bool) $existingAlert->is_realtime;

                    $shouldRefreshExisting = (bool) $existingAlert && ($isNewerSignal || $isNewerEntry || $isNewerAsOf || $upgradingFromBacktest);

                    if ($shouldRefreshExisting) {
                        $refreshData = $alertData;
                        $refreshData['is_realtime'] = true;
                        $refreshData['ml_win_prob'] = null;
                        $refreshData['ml_scored_at'] = null;
                        $refreshData['ml_model_version'] = null;
                        $refreshData['created_at'] = now();
                        $refreshData['updated_at'] = now();

                        DB::table($tableName)
                            ->where('id', $existingId)
                            ->update($refreshData);
                    } else {
                        DB::table($tableName)
                            ->where('dedupe_key', $dedupeKey)
                            ->where('is_realtime', false)
                            ->update(['is_realtime' => true, 'updated_at' => now()]);
                    }

                    // If we refreshed with newer timing or the row is still unscored,
                    // dispatch ML scoring for the live path.
                    $needsMlDispatch = false;
                    if ($existingId) {
                        $needsMlDispatch = $shouldRefreshExisting || DB::table($tableName)
                            ->where('id', $existingId)
                            ->whereNull('ml_scored_at')
                            ->exists();
                    }

                    if ($existingId && $needsMlDispatch && config('trading.ml_scoring.enabled', true) && ! $this->backtestMode) {
                        try {
                            \Log::info("[TradeAlertWriter] Dispatching ML job (backtest-race) for existing alert {$existingId} ({$signal['symbol']}) pipeline={$pipelineRun}");
                            if ($this->shouldRunMlScoringSync($isRealtime, (string) $pipelineRun)) {
                                ScoreTradeAlertWithMl::dispatchSync($existingId, $tableName, $pipelineRun);
                            } else {
                                ScoreTradeAlertWithMl::dispatch($existingId, $tableName, $pipelineRun)->onQueue('ml-scoring');
                            }
                        } catch (\Throwable $mlError) {
                            \Log::warning("Failed to dispatch ML scoring job for existing alert {$existingId}: ".$mlError->getMessage());
                        }
                    } else {
                        \Log::debug("[TradeAlertWriter] Skipping ML dispatch (backtest-race) for {$signal['symbol']}: existingId={$existingId}, needsMlDispatch=".var_export($needsMlDispatch, true).', enabled='.var_export(config('trading.ml_scoring.enabled', true), true).', backtestMode='.var_export($this->backtestMode, true));
                    }

                    if ($shouldRefreshExisting && $existingId) {
                        return (int) $existingId;
                    }
                }

                return false;
            }

            // Get the inserted alert ID
            $alertId = DB::table($tableName)
                ->where('dedupe_key', $dedupeKey)
                ->value('id');

            // Removed excessive insert success logging - only log for important pipelines
            // Log successful insert for F pipeline only (others are too noisy)
            if ($pipelineRun === 'F') {
                \Log::info("[TradeAlertWriter] Inserted: {$signal['symbol']} | {$entry['type']} | {$entry['entry_ts_est']} | Pipeline {$pipelineRun} | Table={$tableName}");
            }

            // Dispatch ML scoring job (async, won't block alert creation)
            // Skip in backtest mode — backtest jobs flood the queue and delay live alert processing.
            if ($alertId && config('trading.ml_scoring.enabled', true) && ! $this->backtestMode) {
                try {
                    \Log::info("[TradeAlertWriter] Dispatching ML job for alert {$alertId} ({$signal['symbol']}) pipeline={$pipelineRun}");
                    if ($this->shouldRunMlScoringSync($isRealtime, (string) $pipelineRun)) {
                        ScoreTradeAlertWithMl::dispatchSync($alertId, $tableName, $pipelineRun);
                    } else {
                        ScoreTradeAlertWithMl::dispatch($alertId, $tableName, $pipelineRun)->onQueue('ml-scoring');
                    }
                } catch (\Throwable $mlError) {
                    \Log::warning("Failed to dispatch ML scoring job for alert {$alertId}: ".$mlError->getMessage());
                }
            } else {
                \Log::debug("[TradeAlertWriter] Skipping ML dispatch for alert {$alertId} ({$signal['symbol']}): enabled=".var_export(config('trading.ml_scoring.enabled', true), true).', backtestMode='.var_export($this->backtestMode, true));
            }

            // Try to broadcast, but don't fail if Reverb is down
            // Only broadcast alerts from today to avoid overwhelming frontend with historical data
            $todayEst = now('America/New_York')->format('Y-m-d');
            if ($tradingDate === $todayEst) {
                try {
                    broadcast(new TradeAlertCreated($alertData));
                } catch (\Throwable $broadcastError) {
                    // Silently ignore broadcast errors - the alert was still inserted
                }
            }

            return (int) $alertId;
        } catch (\Throwable $e) {
            // Log any unexpected errors
            \Log::error('TradeAlertWriterV1 failed: '.$e->getMessage());

            return false;
        }
    }
}
