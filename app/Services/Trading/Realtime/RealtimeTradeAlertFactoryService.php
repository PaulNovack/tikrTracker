<?php

namespace App\Services\Trading\Realtime;

use App\Models\RealtimeTradeCandidate;
use App\Models\TradeAlert;
use App\Services\TradingSettingService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class RealtimeTradeAlertFactoryService
{
    /**
     * Static caches to avoid N+1 queries during backtest loops.
     */
    private static array $avgDollarVolCache = [];

    private static array $tradingSettingsCache = [];

    private array $pendingInserts = [];

    /**
     * Whether created alerts should be marked is_realtime=1 (live) or 0 (backtest).
     */
    public bool $isRealtime = true;

    /**
     * Reset caches at the start of a new time point (loop).
     */
    public static function resetCaches(): void
    {
        self::$avgDollarVolCache = [];
        self::$tradingSettingsCache = [];
    }

    /**
     * Get all pending inserts and clear the batch.
     */
    public function getPendingInserts(): array
    {
        $pending = $this->pendingInserts;
        $this->pendingInserts = [];

        return $pending;
    }

    /**
     * Batch insert all pending trade alerts at once.
     * Deduplicates within the batch first to avoid duplicate key violations.
     */
    public function flushPendingInserts(): int
    {
        if (empty($this->pendingInserts)) {
            Log::debug('[RealtimeFactory] flushPendingInserts: No pending records to insert');

            return 0;
        }

        $count = count($this->pendingInserts);

        // Deduplicate within the batch by dedupe_key — insertOrIgnore only prevents
        // cross-batch duplicates, not in-batch duplicates where neither row exists yet.
        $unique = [];
        $dupCount = 0;
        foreach ($this->pendingInserts as $row) {
            $key = $row['dedupe_key'] ?? null;
            if ($key !== null && isset($unique[$key])) {
                $dupCount++;

                continue;
            }
            if ($key !== null) {
                $unique[$key] = $row;
            } else {
                $unique[] = $row;
            }
        }
        $this->pendingInserts = array_values($unique);

        if ($dupCount > 0) {
            Log::info("[RealtimeFactory] Deduplicated {$dupCount} in-batch duplicates, {$count} → ".count($this->pendingInserts).' records');
        }
        Log::info("[RealtimeFactory] Flushing $count pending trade_alert records to database");

        try {
            // Check a sample record to debug structure
            $sample = $this->pendingInserts[0] ?? null;
            if ($sample) {
                Log::debug('[RealtimeFactory] Sample record keys: '.implode(', ', array_keys($sample)));
            }

            $result = DB::table('trade_alerts')->insertOrIgnore($this->pendingInserts);
            $this->pendingInserts = [];

            Log::info("[RealtimeFactory] insertOrIgnore returned: $result (attempted to insert $count records)");

            return $count;
        } catch (\Exception $e) {
            Log::error('[RealtimeFactory] Error during insertOrIgnore: '.$e->getMessage(), [
                'exception' => $e,
                'pending_count' => $count,
                'sample_keys' => array_keys($this->pendingInserts[0] ?? []),
            ]);
            $this->pendingInserts = [];

            return 0;
        }
    }

    /**
     * Create a trade_alert that your existing ML/order pipeline can score.
     *
     * If your trade_alerts table has required columns not included here,
     * add them in this one method. This is intentionally centralized.
     *
     * When batching is enabled, records are queued and NOT immediately inserted.
     * Call flushPendingInserts() to write them all at once.
     */
    public function createFromCandidate(
        RealtimeTradeCandidate $candidate,
        array $entry,
        array $quote,
        float $moveSinceCandidatePct,
        float $moveSinceEntryPct,
        ?string $asOfTsEst = null
    ): TradeAlert {
        $nowEst = $asOfTsEst ? CarbonImmutable::parse($asOfTsEst, 'America/New_York') : now('America/New_York');
        $entryTsEst = $entry['entry_ts_est'] ?? $nowEst->format('Y-m-d H:i:s');
        $entryPrice = (float) ($entry['entry_price'] ?? $quote['ask']);

        $meta = [
            'source' => 'realtime_candidate',
            'candidate_id' => $candidate->id,
            'early_score' => $candidate->early_score,
            'candidate_detected_price' => $candidate->detected_price,
            'candidate_return_1m_pct' => $candidate->return_1m_pct,
            'candidate_return_3m_pct' => $candidate->return_3m_pct,
            'candidate_volume_ratio' => $candidate->volume_ratio,
            'candidate_vwap_dist_pct' => $candidate->vwap_dist_pct,
            'entry_finder' => $entry['entry_finder'] ?? null,
            'entry_reason' => $entry['reason'] ?? null,
            'pipeline_run' => $entry['pipeline_run'] ?? 'R',
            'raw_entry' => $entry,
        ];

        // Use dedupe_key for batch duplicate handling (minute granularity — one alert per symbol per minute)
        $dedupeKey = 'rt:'.$candidate->symbol.':'.$nowEst->format('YmdHi');

        $avgDollarVol = $this->calculateAvgDollarVolumePerMinute($candidate->symbol, $nowEst->toDateString())
            ?: $candidate->dollar_volume_1m;

        // Build the record for batch insert
        $stopPrice = round($this->computeStopPrice($candidate, $entryPrice), 2);
        $record = [
            'realtime_candidate_id' => $candidate->id,
            'symbol' => $candidate->symbol,
            'asset_type' => $candidate->asset_type,
            'trading_date_est' => $nowEst->toDateString(),
            'as_of_ts_est' => $nowEst->format('Y-m-d H:i:s'),
            'signal_type' => $entry['signal_type'] ?? 'REALTIME_MOMENTUM',
            'signal_ts_est' => $candidate->getRawOriginal('detected_ts_est'),
            'time_of_day' => $nowEst->format('H:i:s'),
            'entry_type' => 'RealTime',
            'entry_ts_est' => $entryTsEst,
            'entry' => $entryPrice,
            'stop' => $stopPrice,
            'risk_pct' => $this->computeRiskPct($candidate, $entryPrice),
            'risk_per_share' => round($this->computeRiskPerShare($candidate, $entryPrice), 6),
            'score' => $entry['score'] ?? $candidate->early_score,
            'vol_ratio' => $candidate->volume_ratio,
            'avg_dollar_volume_per_minute' => $avgDollarVol,
            'calculated_position_size' => $this->computePositionSize($avgDollarVol),
            'five_min_directional_changes' => 0,
            'five_min_green_bar_pct' => 0,
            'five_min_net_progress' => 0,
            'consolidation_bars' => 0,
            'breakout_volume_ratio' => 0,
            'atr' => $this->computeAtrDollars($candidate, $entryPrice),
            'atr_pct' => $this->computeAtrPct($candidate, $entryPrice),
            'daily_trend_5d_pct' => 0,
            'range_position_60m' => 0,
            'pct_below_intraday_high' => 0,
            'minutes_since_high' => 0,
            'price_velocity_5min' => 0,
            'price_velocity_10min' => 0,
            'failed_rally_count' => 0,
            'move_30m_pct' => 0,
            'rvol_5m' => 0,
            'atr_pct_5m' => 0,
            'notional_last5m' => 0,
            'pct_nd' => 0,
            'spy_move_30m_pct' => 0,
            'universe_size' => 3828,
            'hod' => $entryPrice,
            'room_to_hod_pct' => 0,
            'room_to_hod_atr' => 0,
            'above_vwap_entry_pct' => $candidate->vwap_dist_pct,
            'entry_body_pct' => 0,
            'entry_close_position' => 0,
            'entry_volume_ratio' => $candidate->volume_ratio,
            'entry_notional_1m' => $candidate->dollar_volume_1m,
            'entry_spread_strength' => $candidate->spread_pct ? round(1 - ($candidate->spread_pct / 0.5), 6) : null,
            'entry_vwap_dist_score' => 0,
            'entry_atr_score' => 0,
            'entry_vol_score' => 0,
            'entry_candle_score' => 0,
            'entry_time_bonus' => 0,
            'vwap_reclaim_strength_pct' => 0,
            'vwap_reclaim_wick_below_pct' => 0,
            'or_high_v252' => 0,
            'or_break_distance_pct' => 0,
            'or_retest_depth_pct' => 0,
            'or_hold_close_pct' => 0,
            'bars_since_or_break' => 0,
            'ema9_pullback_depth_pct' => 0,
            'ema9_reclaim_pct' => 0,
            'rsi_14_1m' => 0,
            'suggested_trailing_stop' => round($this->computeTrailingStop($candidate, $entryPrice), 6),
            'suggested_trailing_stop_pct' => $this->computeTrailingStopPct($candidate, $entryPrice),
            'targets' => json_encode([]),
            'exit_price' => null,
            'exit_ts_est' => null,
            'exit_reason' => null,
            'pnl_percent' => null,
            'pnl_dollar' => null,
            'max_adverse_excursion' => null,
            'hold_time_minutes' => null,
            'r_multiple' => null,
            'target_hit' => 0,
            'analyzed' => 0,
            'analyzed_at' => null,
            'meta' => json_encode($meta),
            'dedupe_key' => $dedupeKey,
            'version' => $entry['version'] ?? config('app.trade_alert_r_version', 'rt-v1.0'),
            'pipeline_run' => $entry['pipeline_run'] ?? 'R',
            'is_paper' => 1,
            'current_bid' => $quote['bid'] ?? null,
            'current_ask' => $quote['ask'] ?? null,
            'current_bid_qty' => $quote['bid_qty'] ?? null,
            'current_ask_qty' => $quote['ask_qty'] ?? null,
            'current_spread_pct' => $entry['spread_pct'] ?? $candidate->spread_pct,
            'move_since_candidate_pct' => $moveSinceCandidatePct,
            'move_since_entry_pct' => $moveSinceEntryPct,
            'ml_win_prob' => null,
            'passed_ml' => 0,
            'ml_scored_at' => null,
            'ml_model_version' => null,
            'ml_live_win_prob' => null,
            'ml_live_scored_at' => null,
            'is_realtime' => $this->isRealtime ? 1 : 0,
            'blacklisted' => 0,
            'skipped_reason' => null,
            'skipped_at' => null,
            'skip_price' => null,
            'signal_detected_at_est' => $candidate->getRawOriginal('detected_ts_est'),
            'entry_detected_at_est' => $nowEst->format('Y-m-d H:i:s'),
            'ml_scored_at_est' => null,
            'order_submitted_at_est' => null,
            'alert_age_seconds' => 0,
            'quote_age_seconds' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ];

        // Queue for batch insert
        Log::debug('[RealtimeFactory] Queueing trade_alert for '.$candidate->symbol.' at '.$nowEst->format('Y-m-d H:i:s').' (pending count: '.count($this->pendingInserts).')');
        $this->pendingInserts[] = $record;

        // Return a hydrated model for compatibility with existing code
        // (but the actual insert happens during flush)
        return new TradeAlert($record);
    }

    /**
     * Create a trade alert and insert it immediately (no batching).
     * Skips if a duplicate dedupe_key already exists (minute-level dedup).
     * Returns the alert with a real DB ID so the integration dispatcher
     * can dispatch scoring jobs without a null alert_id.
     */
    public function createFromCandidateAndFlush(
        RealtimeTradeCandidate $candidate,
        array $entry,
        array $quote,
        float $moveSinceCandidatePct,
        float $moveSinceEntryPct,
        ?string $asOfTsEst = null
    ): TradeAlert {
        $alert = $this->createFromCandidate(
            $candidate, $entry, $quote,
            $moveSinceCandidatePct, $moveSinceEntryPct, $asOfTsEst
        );

        // Remove the last item from pending (it was added by createFromCandidate)
        array_pop($this->pendingInserts);

        // Check for existing alert with the same dedupe_key (minute-level dedup)
        $existingId = DB::table('trade_alerts')
            ->where('dedupe_key', $alert->dedupe_key)
            ->value('id');

        if ($existingId) {
            Log::debug('[RealtimeFactory] Skipping duplicate alert', [
                'symbol' => $candidate->symbol,
                'dedupe_key' => $alert->dedupe_key,
                'existing_id' => $existingId,
            ]);
            $alert->id = $existingId;
            $alert->exists = true;

            return $alert;
        }

        // Insert immediately so we get a real DB ID
        $id = DB::table('trade_alerts')->insertGetId($alert->getAttributes());

        // Return alert with the real ID set
        $alert->id = $id;
        $alert->exists = true;

        return $alert;
    }

    /**
     * Calculate average dollar volume per minute across today's bars.
     * Results are cached to avoid repeated queries for the same symbol/date.
     */
    private function calculateAvgDollarVolumePerMinute(string $symbol, string $tradingDate): float
    {
        $cacheKey = "{$symbol}:{$tradingDate}";

        // Return cached result if available
        if (isset(self::$avgDollarVolCache[$cacheKey])) {
            return self::$avgDollarVolCache[$cacheKey];
        }

        $avg = DB::table('one_minute_prices')
            ->where('symbol', $symbol)
            ->where('trading_date_est', $tradingDate)
            ->whereBetween('trading_time_est', ['09:30:00', '16:00:00'])
            ->avg(DB::raw('volume * price'));

        // Fall back to one_minute_prices_full if no intraday data found
        if (! $avg) {
            $avg = DB::table('one_minute_prices_full')
                ->where('symbol', $symbol)
                ->where('trading_date_est', $tradingDate)
                ->whereBetween('trading_time_est', ['09:30:00', '16:00:00'])
                ->avg(DB::raw('volume * price'));
        }

        $result = (float) ($avg ?? 0);

        // Cache the result for this symbol/date
        self::$avgDollarVolCache[$cacheKey] = $result;

        return $result;
    }

    /**
     * Get real ATR% from the candidate (computed by bar_buffer.py).
     * Falls back to 1.0% if not available.
     */
    private function getRealAtrPct(RealtimeTradeCandidate $candidate, float $entryPrice): float
    {
        $atrPct = (float) ($candidate->atr_pct ?? 0);

        if ($atrPct <= 0) {
            return 1.0;
        }

        return $atrPct;
    }

    /**
     * Compute ATR in dollars from the real ATR% stored on the candidate.
     * Same approach as pipelines: atr_dollars = entry_price * (atr_pct / 100).
     */
    private function computeAtrDollars(RealtimeTradeCandidate $candidate, float $entryPrice): float
    {
        $atrPct = $this->getRealAtrPct($candidate, $entryPrice);

        return round($entryPrice * ($atrPct / 100), 6);
    }

    /**
     * ATR% sourced from the candidate (bar_buffer.py computed indicator).
     */
    private function computeAtrPct(RealtimeTradeCandidate $candidate, float $entryPrice): float
    {
        return $this->getRealAtrPct($candidate, $entryPrice);
    }

    /**
     * Get cached trading setting value to avoid repeated lookups.
     */
    private function getTradingSetting(string $key, mixed $default = null): mixed
    {
        if (! isset(self::$tradingSettingsCache[$key])) {
            self::$tradingSettingsCache[$key] = config($key, $default);
        }

        return self::$tradingSettingsCache[$key];
    }

    /**
     * Stop distance = ATR_pct × atr_multiplier, clamped to [atr_min_pct, atr_max_pct].
     * This matches the pipeline formula: stopPct = clamp(ATR% * multiplier, minPct, maxPct).
     */
    private function computeStopDistancePct(RealtimeTradeCandidate $candidate, float $entryPrice): float
    {
        $atrPct = $this->getRealAtrPct($candidate, $entryPrice);
        $multiplier = $this->getTradingSetting('trading.auto_alpaca_orders.stop_loss_atr_multiplier', 4.0);
        $minPct = $this->getTradingSetting('trading.auto_alpaca_orders.stop_loss_atr_min_pct', 1.0);
        $maxPct = $this->getTradingSetting('trading.auto_alpaca_orders.stop_loss_atr_max_pct', 2.0);

        $rawPct = $atrPct * $multiplier;

        return max($minPct, min($maxPct, $rawPct));
    }

    /**
     * Trailing stop distance = ATR × multiplier (matches pipeline suggested_trailing_stop).
     */
    private function computeTrailingStop(RealtimeTradeCandidate $candidate, float $entryPrice): float
    {
        $stopPct = $this->computeStopDistancePct($candidate, $entryPrice);

        return $entryPrice * ($stopPct / 100);
    }

    /**
     * Trailing stop as percentage of entry (matches pipeline suggested_trailing_stop_pct).
     */
    private function computeTrailingStopPct(RealtimeTradeCandidate $candidate, float $entryPrice): float
    {
        return round($this->computeStopDistancePct($candidate, $entryPrice), 6);
    }

    /**
     * Stop price = entry - trailing_stop (matches pipeline stop logic).
     */
    private function computeStopPrice(RealtimeTradeCandidate $candidate, float $entryPrice): float
    {
        return $entryPrice - $this->computeTrailingStop($candidate, $entryPrice);
    }

    /**
     * Risk per share = trailing stop distance in dollars (matches pipeline risk_per_share).
     */
    private function computeRiskPerShare(RealtimeTradeCandidate $candidate, float $entryPrice): float
    {
        return $this->computeTrailingStop($candidate, $entryPrice);
    }

    /**
     * Risk as percentage of entry (matches pipeline risk_pct).
     */
    private function computeRiskPct(RealtimeTradeCandidate $candidate, float $entryPrice): float
    {
        return round($this->computeStopDistancePct($candidate, $entryPrice), 3);
    }

    /**
     * Position size using the same dynamic sizing rules as TradeAlertWriterV1.
     * Matches Pipeline H v25.2 behavior: 10% of minute volume, capped by min/max bounds.
     */
    private function computePositionSize(float $avgDollarVolPerMin): float
    {
        $mode = config('trading.position_size_mode', 'dynamic');

        if ($mode === 'dynamic') {
            $maxPct = TradingSettingService::getMaxPositionPctOfLiquidity();
            $minSize = TradingSettingService::getMinPositionSize();
            $maxSize = TradingSettingService::getMaxPositionSize();

            // Ensure we have a valid dollar volume — fall back to fixed position size if 0
            if ($avgDollarVolPerMin <= 0) {
                return (float) config('trading.auto_alpaca_orders.position_size', 5000);
            }

            $calculatedSize = $avgDollarVolPerMin * ($maxPct / 100);

            return round(max($minSize, min($maxSize, $calculatedSize)), 2);
        }

        return (float) config('trading.auto_alpaca_orders.position_size', 5000);
    }
}
