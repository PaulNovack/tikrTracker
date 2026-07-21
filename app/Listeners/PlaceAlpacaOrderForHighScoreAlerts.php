<?php

namespace App\Listeners;

use App\Events\TradeAlertMLScored;
use App\Jobs\MonitorAlpacaOrderFillAndPlaceStopLoss;
use App\Models\AlpacaOrder;
use App\Models\CircuitBreakerEvent;
use App\Services\AlpacaPythonService;
use App\Services\TradingSettingService;
use App\Trading\SymbolBlacklist;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Process;

/**
 * NOT queued intentionally — runs synchronously inside the ML scoring job
 * to place orders with minimum latency after a high-score signal is detected.
 * Removing ShouldQueue eliminates one full queue round-trip (~5-10 s of added delay).
 */
class PlaceAlpacaOrderForHighScoreAlerts
{
    public function __construct(
        protected AlpacaPythonService $alpacaService
    ) {}

    /**
     * Handle the TradeAlertMLScored event.
     * Automatically place an Alpaca order if ML score >= 65%
     */
    public function handle(TradeAlertMLScored $event): void
    {
        $listenerStart = microtime(true);
        $listenerWall = now('America/New_York')->format('Y-m-d H:i:s');

        // Phase 1: fast pre-DB threshold check.
        // Use the lowest configured threshold across all pipelines so that pipeline-specific
        // overrides (e.g. Pipeline H = 0.60) are never blocked here before Phase 2 can apply them.
        $globalThreshold = TradingSettingService::getGlobalMlThreshold();
        $minimumThreshold = TradingSettingService::getMinimumPipelineMlThreshold();

        if ($event->mlWinProb < $minimumThreshold) {
            Log::info("Alert {$event->alertId} ML score {$event->mlWinProb} below minimum threshold {$minimumThreshold}, skipping order");

            return;
        }

        // Check if auto-trading is enabled
        if (! TradingSettingService::isOrdersEnabled()) {
            Log::info("Auto Alpaca orders disabled, skipping alert {$event->alertId}");

            return;
        }

        // === EARLY AGE CHECK: Skip ancient alerts immediately ===
        $alert = DB::connection('mysql')->table($event->tableName)->where('id', $event->alertId)->first();
        if (! $alert) {
            Log::warning("Alert {$event->alertId} not found in {$event->tableName} for order placement");

            return;
        }

        // Phase 2: per-pipeline threshold override (e.g. Pipeline F needs a higher bar due to low base win rate).
        $pipelineRun = $alert->pipeline_run ?? '';
        $mlThreshold = TradingSettingService::getPipelineMlThreshold((string) $pipelineRun);

        // In paper trading mode, optionally bypass ML threshold to collect outcome data across all pipelines.
        // DB setting 'trading.paper_bypass_ml_threshold' takes precedence; falls back to .env AUTO_ALPACA_PAPER_BYPASS_ML_THRESHOLD.
        $isPaperBypass = (bool) TradingSettingService::get(
            'trading.paper_bypass_ml_threshold',
            config('trading.auto_alpaca_orders.paper_bypass_ml_threshold', false)
        ) && TradingSettingService::isPaperTrading();

        if (! $isPaperBypass && $event->mlWinProb < $mlThreshold) {
            Log::info("Alert {$event->alertId} ({$pipelineRun}) ML score {$event->mlWinProb} below pipeline threshold {$mlThreshold}, skipping order");

            return;
        }

        // Pipeline K risk filter: risk_pct >= 2.0% is blocked at TWO stages:
        //   1. ScoreTradeAlertWithMl job — scoring is skipped entirely, ml_win_prob stays null,
        //      TradeAlertMLScored event never fires, so this listener is never even reached.
        //   2. Here — as a safety net in case the alert somehow arrives with a score.
        // Result: alerts with risk_pct >= 2.0% show NO ML score badge in the UI. This is
        // intentional. Historical WR: < 1.0% risk → ~95%, >= 2.0% risk → ~63%.
        if ($pipelineRun === 'K') {
            $riskPct = (float) ($alert->risk_pct ?? 0);
            if ($riskPct >= 2.0) {
                Log::info("Alert {$event->alertId} ({$alert->symbol}) Pipeline K risk_pct {$riskPct}% >= 2.0% cap, skipping order", [
                    'symbol' => $alert->symbol,
                    'risk_pct' => $riskPct,
                    'ml_win_prob' => $event->mlWinProb,
                ]);
                $this->recordSkip($event->alertId, $event->tableName, 'pipeline_k_risk_too_high');

                return;
            }
        }

        // Use one consistent event timestamp for staleness checks and reporting.
        // Prefer signal_ts_est (what the observability page shows as Signal -> Skip), then
        // fall back to entry_ts_est, then created_at.
        $signalTimestamp = $this->resolveSignalTimestampDetails($alert);
        $signalAtUtc = $signalTimestamp['timestamp'];
        $signalTimestampSource = $signalTimestamp['source'];

        // ── TIMING TRACE ──────────────────────────────────────────────────────────
        // Log the full chain from bar signal → alert written → listener reached.
        // This is the ground-truth measurement — do not remove.
        $wallNow = now('America/New_York');
        $signalEpoch = $alert->signal_ts_est ? \Carbon\Carbon::parse($alert->signal_ts_est, 'America/New_York')->getTimestamp() : null;
        $entryEpoch = $alert->entry_ts_est ? \Carbon\Carbon::parse($alert->entry_ts_est, 'America/New_York')->getTimestamp() : null;
        $writtenEpoch = $signalAtUtc->getTimestamp();
        $listenerEpoch = (int) $listenerStart;
        $nowEpoch = $wallNow->getTimestamp();

        Log::channel('pipeline-timing')->info('[LISTENER] REACHED', [
            'alert_id' => $event->alertId,
            'pipeline' => $pipelineRun,
            'symbol' => $alert->symbol,
            'ml_win_prob' => $event->mlWinProb,
            // When did the 5m bar fire the signal?
            'signal_ts_est' => $alert->signal_ts_est,
            // When did the 1m entry bar occur?
            'entry_ts_est' => $alert->entry_ts_est,
            // When did the pipeline write the alert to DB?
            'alert_written_at' => $signalAtUtc->copy()->setTimezone('America/New_York')->format('Y-m-d H:i:s'),
            // When did this listener fire?
            'listener_wall_clock' => $listenerWall,
            // KEY GAPS:
            'signal_to_write_sec' => $signalEpoch ? round($writtenEpoch - $signalEpoch, 1) : null,
            'entry_to_write_sec' => $entryEpoch ? round($writtenEpoch - $entryEpoch, 1) : null,
            'write_to_listener_sec' => round($listenerEpoch - $writtenEpoch, 1),
            'signal_to_now_sec' => $signalEpoch ? round($nowEpoch - $signalEpoch, 1) : null,
            'entry_to_now_sec' => $entryEpoch ? round($nowEpoch - $entryEpoch, 1) : null,
        ]);
        // ── END TIMING TRACE ──────────────────────────────────────────────────────
        $currentTime = now('UTC');
        $ageMinutes = $currentTime->diffInMinutes($signalAtUtc, true);
        $staleRescoreRequired = false;
        $staleRescoreAgeMinutes = null;

        // Pipeline-specific max age (Pipeline H uses 20 min, Pipeline J uses 15 min, others use default 10 min)
        $maxAgeMinutes = TradingSettingService::getPipelineMaxAgeMinutes((string) $pipelineRun);

        // Pipeline L: allow backtest-origin alerts only within a strict dedicated age window.
        $isPipelineLBacktestAlert = strtoupper((string) $pipelineRun) === 'L' && ! (bool) ($alert->is_realtime ?? false);
        if ($isPipelineLBacktestAlert) {
            $maxAgeMinutes = TradingSettingService::getPipelineMaxAgeMinutes((string) $pipelineRun, true);
        }

        // Resolve pipeline-specific max age key for logging
        $pipelineMaxAgeKey = 'trading.pipeline_'.strtolower((string) $pipelineRun).'.max_age_minutes';

        Log::info("Alert {$event->alertId} age check", [
            'symbol' => $alert->symbol,
            'pipeline' => $pipelineRun,
            'signal_ts_est' => $alert->signal_ts_est,
            'signal_at_utc' => $signalAtUtc->format('Y-m-d H:i:s T'),
            'current_time' => $currentTime->format('Y-m-d H:i:s T'),
            'age_minutes' => $ageMinutes,
            'max_age_minutes' => $maxAgeMinutes,
        ]);

        Log::info("Alert {$event->alertId} staleness diagnostics", [
            'symbol' => $alert->symbol,
            'pipeline' => $pipelineRun,
            'pipeline_max_age_key' => $pipelineMaxAgeKey,
            'resolved_timestamp_source' => $signalTimestampSource,
            'resolved_timestamp_utc' => $signalAtUtc->format('Y-m-d H:i:s T'),
            'computed_age_minutes' => $ageMinutes,
            'max_age_minutes' => $maxAgeMinutes,
        ]);

        if ($ageMinutes > $maxAgeMinutes) {
            if ($isPipelineLBacktestAlert) {
                Log::info("Alert {$event->alertId} (L/backtest) too old ({$ageMinutes} minutes > {$maxAgeMinutes} max), skipping early", [
                    'symbol' => $alert->symbol,
                    'pipeline' => $pipelineRun,
                    'is_realtime' => (bool) ($alert->is_realtime ?? false),
                    'signal_at_utc' => $signalAtUtc->format('Y-m-d H:i:s'),
                    'age_minutes' => $ageMinutes,
                    'max_age_minutes' => $maxAgeMinutes,
                ]);
                $this->recordSkip($event->alertId, $event->tableName, 'age_too_old_backtest_l');

                return;
            }

            $staleRescoreCheck = $this->evaluateStaleRescoreEligibility((string) $pipelineRun, (float) $ageMinutes, $alert->symbol);

            if (! $staleRescoreCheck['allowed']) {
                Log::info("Alert {$event->alertId} ({$pipelineRun}) signal too old ({$ageMinutes} minutes > {$maxAgeMinutes} max), skipping early", [
                    'symbol' => $alert->symbol,
                    'pipeline' => $pipelineRun,
                    'signal_at_utc' => $signalAtUtc->format('Y-m-d H:i:s'),
                    'age_minutes' => $ageMinutes,
                    'max_age_minutes' => $maxAgeMinutes,
                    'stale_rescore_decision' => $staleRescoreCheck['reason'],
                ]);
                $this->recordSkip($event->alertId, $event->tableName, 'age_too_old');

                return;
            }

            $staleRescoreRequired = true;
            $staleRescoreAgeMinutes = (float) $ageMinutes;

            Log::warning("Alert {$event->alertId} ({$pipelineRun}) exceeded stale window ({$ageMinutes}m > {$maxAgeMinutes}m) but is eligible for stale rescore recovery", [
                'symbol' => $alert->symbol,
                'pipeline' => $pipelineRun,
                'age_minutes' => $ageMinutes,
                'max_age_minutes' => $maxAgeMinutes,
                'stale_rescore_max_age_minutes' => $staleRescoreCheck['max_age_minutes'],
                'paper_only' => $staleRescoreCheck['paper_only'],
            ]);
        }
        // === END EARLY AGE CHECK ===

        // === TRADING HOURS CHECK (EST timezone) ===
        $startTime = TradingSettingService::getTradingStartTime();
        $endTime = TradingSettingService::getTradingEndTime();
        $nowEst = now()->setTimezone('America/New_York');
        $currentTimeEst = $nowEst->format('H:i');

        if ($currentTimeEst < $startTime || $currentTimeEst >= $endTime) {
            Log::info("Alert {$event->alertId} outside trading hours (current: {$currentTimeEst} EST, allowed: {$startTime}-{$endTime}), skipping order", [
                'current_time_est' => $currentTimeEst,
                'start_time' => $startTime,
                'end_time' => $endTime,
            ]);

            return;
        }
        // === END TRADING HOURS CHECK ===

        // === TIME SLOT CHECK (per-15-minute window) ===
        $slotMinute = (int) floor((int) $nowEst->format('i') / 15) * 15;
        $slotKey = $nowEst->format('H').':'.str_pad((string) $slotMinute, 2, '0', STR_PAD_LEFT);

        // Pipeline R uses its own realtime time slots
        if ($pipelineRun === 'R') {
            if (! $isPaperBypass && ! TradingSettingService::isRealtimeSlotEnabled($slotKey)) {
                Log::info("Alert {$event->alertId} (R) in disabled realtime slot {$slotKey} EST, skipping order");
                $this->recordSkip($event->alertId, $event->tableName, 'realtime_slot_disabled');

                return;
            }

            // Skip-first-minutes gate: block within X minutes of 9:30 AM
            $skipFirstMinutes = TradingSettingService::getRealtimeSkipFirstMinutes();
            if ($skipFirstMinutes > 0) {
                $marketOpen = $nowEst->copy()->setTime(9, 30, 0);
                $secondsSinceOpen = $marketOpen->diffInSeconds($nowEst, true);
                if ($secondsSinceOpen < ($skipFirstMinutes * 60)) {
                    Log::info("Alert {$event->alertId} (R) within skip-first-{$skipFirstMinutes}min window, skipping order");
                    $this->recordSkip($event->alertId, $event->tableName, 'realtime_skip_first_minutes');

                    return;
                }
            }
        } else {
            if (! $isPaperBypass && ! TradingSettingService::isTimeSlotEnabled($slotKey)) {
                Log::info("Alert {$event->alertId} ({$pipelineRun}) in disabled time slot {$slotKey} EST, skipping order");
                $this->recordSkip($event->alertId, $event->tableName, 'time_slot_disabled');

                return;
            }
        }
        // === END TIME SLOT CHECK ===

        // === CIRCUIT BREAKER: Pause new entries when losing stops are firing rapidly ===
        // In paper mode: still evaluate and record trips but do NOT block order placement.
        if (TradingSettingService::isCircuitBreakerEnabled()) {
            $stopsThreshold = TradingSettingService::getCircuitBreakerStopsThreshold();
            $windowMinutes = TradingSettingService::getCircuitBreakerWindowMinutes();
            $pauseMinutes = TradingSettingService::getCircuitBreakerPauseMinutes();
            $isPaper = TradingSettingService::isPaperTrading();
            $windowStart = now()->subMinutes($windowMinutes);

            // Only count stops where the sell price < buy price (actual losses, not profitable exits)
            $recentLosingStops = AlpacaOrder::query()
                ->selectRaw('alpaca_orders.id, alpaca_orders.filled_at')
                ->join('alpaca_orders as buy_orders', 'buy_orders.alpaca_order_id', '=', 'alpaca_orders.parent_alpaca_order_id')
                ->where('alpaca_orders.side', 'sell')
                ->where('alpaca_orders.order_type', 'stop')
                ->where('alpaca_orders.status', 'filled')
                ->where('alpaca_orders.filled_at', '>=', $windowStart)
                ->whereNotNull('alpaca_orders.filled_avg_price')
                ->whereNotNull('buy_orders.filled_avg_price')
                ->whereRaw('alpaca_orders.filled_avg_price < buy_orders.filled_avg_price')
                ->get();

            if ($recentLosingStops->count() >= $stopsThreshold) {
                // Pause from the MOST RECENT losing stop, not the first.
                // Using the first stop causes early expiry: once it slides out of the
                // rolling window the count drops below threshold and the breaker un-trips.
                $lastStopAt = $recentLosingStops->max('filled_at');
                $pauseExpiry = \Carbon\Carbon::parse($lastStopAt)->addMinutes($pauseMinutes);

                if (now()->lessThan($pauseExpiry)) {
                    $remainingMinutes = (int) now()->diffInMinutes($pauseExpiry, false);
                    $paperPrefix = $isPaper ? '[PAPER] Would have tripped' : 'TRIPPED';

                    Log::warning("Circuit breaker {$paperPrefix} for alert {$event->alertId} ({$alert->symbol}): {$recentLosingStops->count()} losing stops in last {$windowMinutes}m. Pausing for ~{$remainingMinutes} more minutes.", [
                        'symbol' => $alert->symbol,
                        'losing_stops' => $recentLosingStops->count(),
                        'window_minutes' => $windowMinutes,
                        'pause_expires_at' => $pauseExpiry->format('H:i:s T'),
                        'remaining_minutes' => $remainingMinutes,
                        'is_paper' => $isPaper,
                    ]);

                    // Record the trip — only insert if no active event already exists to avoid
                    // flooding the table with one row per alert processed during a pause window.
                    $alreadyRecorded = CircuitBreakerEvent::query()
                        ->where('is_paper', $isPaper)
                        ->where('pause_expires_at', '>', now())
                        ->exists();

                    if (! $alreadyRecorded) {
                        CircuitBreakerEvent::create([
                            'symbol' => $alert->symbol,
                            'losing_stops_count' => $recentLosingStops->count(),
                            'window_minutes' => $windowMinutes,
                            'pause_minutes' => $pauseMinutes,
                            'tripped_at' => now(),
                            'pause_expires_at' => $pauseExpiry,
                            'is_paper' => $isPaper,
                        ]);
                    }

                    // Only block order placement in live mode.
                    if (! $isPaper) {
                        return;
                    }
                }
            }
        }
        // === END CIRCUIT BREAKER ===

        try {
            // === BLACKLIST CHECK: Skip blacklisted symbols ===
            if (SymbolBlacklist::isBlacklisted($alert->symbol)) {
                Log::info("Alert {$event->alertId} symbol {$alert->symbol} is blacklisted, skipping order", [
                    'symbol' => $alert->symbol,
                    'alert_id' => $event->alertId,
                    'reason' => '3+ losses with 70%+ ML confidence',
                ]);

                return;
            }
            // === END BLACKLIST CHECK ===

            // === LIQUIDITY FILTER: Check if stock has sufficient dollar volume per minute ===
            $minDollarVolPerMin = TradingSettingService::getMinDollarVolumePerMinute();
            if ($minDollarVolPerMin > 0) {
                $avgDollarVolPerMin = $alert->avg_dollar_volume_per_minute ?? 0;

                if ($avgDollarVolPerMin < $minDollarVolPerMin) {
                    Log::warning("Alert {$event->alertId} ({$alert->symbol}) has insufficient liquidity: \$".number_format($avgDollarVolPerMin, 0).'/min (min: $'.number_format($minDollarVolPerMin, 0).'/min), skipping order', [
                        'symbol' => $alert->symbol,
                        'alert_id' => $event->alertId,
                        'avg_dollar_vol_per_min' => $avgDollarVolPerMin,
                        'min_required' => $minDollarVolPerMin,
                        'reason' => 'Prevents slippage on illiquid stocks',
                    ]);
                    $this->recordSkip($event->alertId, $event->tableName, 'low_liquidity');

                    return;
                }

                Log::info("Alert {$event->alertId} ({$alert->symbol}) passed liquidity check: \$".number_format($avgDollarVolPerMin, 0).'/min', [
                    'avg_dollar_vol_per_min' => $avgDollarVolPerMin,
                    'min_required' => $minDollarVolPerMin,
                ]);
            }
            // === END LIQUIDITY FILTER ===

            // === BUYING POWER CHECK: Ensure sufficient funds before placing order ===
            $firstCheckTime = time();
            $accountInfo = $this->alpacaService->getAccountInfo();
            if (! $accountInfo['success']) {
                Log::error("Alert {$event->alertId} ({$alert->symbol}): Failed to get account info, skipping order", [
                    'symbol' => $alert->symbol,
                    'alert_id' => $event->alertId,
                    'error' => $accountInfo['error'],
                ]);

                return;
            }

            $buyingPower = $accountInfo['buying_power'] ?? 0;
            $positionSize = $alert->calculated_position_size ?? TradingSettingService::getMaxPositionSize();

            $minPositionSize = (float) config('trading.auto_alpaca_orders.min_position_size', 500);
            if ($buyingPower < $positionSize) {
                $reducedPositionSize = floor($buyingPower * 0.90);

                if ($reducedPositionSize < $minPositionSize) {
                    Log::warning("Alert {$event->alertId} ({$alert->symbol}): Insufficient buying power and reduced size below minimum, skipping order", [
                        'symbol' => $alert->symbol,
                        'alert_id' => $event->alertId,
                        'buying_power' => number_format($buyingPower, 2),
                        'position_size_needed' => number_format($positionSize, 2),
                        'reduced_size' => number_format($reducedPositionSize, 2),
                        'min_position_size' => number_format($minPositionSize, 2),
                    ]);

                    return;
                }

                Log::warning("Alert {$event->alertId} ({$alert->symbol}): Insufficient buying power, reducing position size to 90% of available", [
                    'symbol' => $alert->symbol,
                    'alert_id' => $event->alertId,
                    'buying_power' => number_format($buyingPower, 2),
                    'original_position_size' => number_format($positionSize, 2),
                    'reduced_position_size' => number_format($reducedPositionSize, 2),
                ]);

                $positionSize = $reducedPositionSize;
            }

            Log::info("Alert {$event->alertId} ({$alert->symbol}) passed buying power check", [
                'buying_power' => number_format($buyingPower, 2),
                'position_size_needed' => number_format($positionSize, 2),
                'cash_available' => number_format($accountInfo['cash'] ?? 0, 2),
            ]);
            // === END BUYING POWER CHECK ===

            // === PER-ALERT MUTEX: Serialize concurrent listeners for the same alert ===
            // Two ML scoring jobs can race and both reach this point before either has placed an order,
            // causing duplicate buys (as seen with FN on 2026-05-14: two fills at the same second).
            // The lock is released automatically after 30 s (safety) or when the block exits.
            $alertLock = \Illuminate\Support\Facades\Cache::lock("place_order:alert:{$event->alertId}", 30);
            if (! $alertLock->get()) {
                Log::info("Alert {$event->alertId} ({$alert->symbol}) already being processed by another listener, skipping (lock contention)");

                return;
            }

            // === PER-SYMBOL MUTEX: Serialize concurrent listeners for the same symbol ===
            // Two *different* alerts for the same symbol can fire simultaneously (as seen with APP on
            // 2026-05-14: alert 6277272 and 6277279 both passed the "recent buy" DB check before
            // either had committed its row). Hold the symbol lock for 30 s max.
            $symbolLock = \Illuminate\Support\Facades\Cache::lock('place_order:symbol:'.strtolower($alert->symbol), 30);
            if (! $symbolLock->get()) {
                Log::info("Symbol {$alert->symbol} already being ordered by another alert, skipping alert {$event->alertId} (symbol lock contention)");
                $alertLock->release();

                return;
            }

            try {
                // Check if order already exists for this alert
                $existingOrder = AlpacaOrder::where('notes', 'like', "%alert_id:{$event->alertId}%")->exists();
                if ($existingOrder) {
                    Log::info("Order already exists for alert {$event->alertId}, skipping");

                    return;
                }

                // Check if we already bought this symbol recently.
                // 0 means once per day; positive values mean wait that many minutes between same-symbol buys.
                // First check: Recent orders (within last 5 minutes) - catches race conditions
                $recentBuyOrder = AlpacaOrder::query()
                    ->where('symbol', $alert->symbol)
                    ->where('side', 'buy')
                    ->whereIn('status', ['filled', 'new', 'accepted', 'pending_new', 'partially_filled'])
                    ->where('created_at', '>=', now()->subMinutes(5))
                    ->exists();

                if ($recentBuyOrder) {
                    Log::info("Recent buy order for {$alert->symbol} exists (last 5 min), skipping alert {$event->alertId}", [
                        'symbol' => $alert->symbol,
                        'alert_id' => $event->alertId,
                        'reason' => 'Prevents duplicate buys within short time window',
                    ]);

                    return;
                }

                $symbolRetradeWaitMinutes = $this->getSymbolRetradeWaitMinutes();
                $symbolRetradeCooldown = $this->getSymbolRetradeCooldownData($alert->symbol, $currentTime, $symbolRetradeWaitMinutes);

                if ($symbolRetradeCooldown !== null) {
                    Log::info("Recent buy order for {$alert->symbol} exists ({$symbolRetradeCooldown['minutes_since_last_buy']} min ago, wait {$symbolRetradeCooldown['wait_minutes']} min), skipping alert {$event->alertId}", [
                        'symbol' => $alert->symbol,
                        'alert_id' => $event->alertId,
                        'last_buy_order_id' => $symbolRetradeCooldown['last_buy_order_id'],
                        'last_buy_order_at' => $symbolRetradeCooldown['last_buy_order_at'],
                        'minutes_since_last_buy' => $symbolRetradeCooldown['minutes_since_last_buy'],
                        'wait_minutes' => $symbolRetradeCooldown['wait_minutes'],
                        'reason' => 'Enforces configurable per-symbol retrade cooldown',
                    ]);
                    $this->recordSkip($event->alertId, $event->tableName, 'symbol_retrade_cooldown');

                    return;
                }

                // === DAILY TRADE LIMIT CHECK ===
                $maxTradesPerDay = config('trading.auto_alpaca_orders.max_trades_per_day', 999);
                if ($maxTradesPerDay > 0) {
                    $todayTradeCount = AlpacaOrder::query()
                        ->where('side', 'buy')
                        ->whereIn('status', ['filled', 'new', 'accepted', 'pending_new', 'partially_filled'])
                        ->whereDate('created_at', now()->timezone('America/New_York')->format('Y-m-d'))
                        ->count();

                    if ($todayTradeCount >= $maxTradesPerDay) {
                        Log::warning("Daily trade limit reached ({$todayTradeCount}/{$maxTradesPerDay}), skipping alert {$event->alertId}", [
                            'symbol' => $alert->symbol,
                            'alert_id' => $event->alertId,
                            'trades_today' => $todayTradeCount,
                            'max_per_day' => $maxTradesPerDay,
                            'date' => now()->timezone('America/New_York')->format('Y-m-d'),
                        ]);
                        $this->recordSkip($event->alertId, $event->tableName, 'daily_limit');

                        return;
                    }

                    Log::info("Daily trade limit check passed for alert {$event->alertId}", [
                        'symbol' => $alert->symbol,
                        'trades_today' => $todayTradeCount,
                        'max_per_day' => $maxTradesPerDay,
                        'remaining' => $maxTradesPerDay - $todayTradeCount,
                    ]);
                }

                // Use calculated position size from alert (respects liquidity-based sizing)
                // Falls back to consistent max position size, same as the upstream path.
                $positionSize = $alert->calculated_position_size ?? TradingSettingService::getMaxPositionSize();

                Log::info("Position size for alert {$event->alertId} ({$alert->symbol}): \${$positionSize}", [
                    'calculated_position_size' => $alert->calculated_position_size,
                    'avg_dollar_vol_per_min' => $alert->avg_dollar_volume_per_minute ?? 0,
                    'sizing_mode' => config('trading.position_size_mode', 'fixed'),
                ]);

                // === TIME CHECK: Ensure signal is not older than max_age_minutes (pipeline-specific) ===
                // Use the same timestamp basis as the early check and observability page.
                $signalTimestamp2 = $this->resolveSignalTimestampDetails($alert);
                $signalAtUtc2 = $signalTimestamp2['timestamp'];
                $signalTimestampSource2 = $signalTimestamp2['source'];
                $currentTime2 = now('UTC');
                $ageMinutes = $currentTime2->diffInMinutes($signalAtUtc2, true);

                $pipelineRun2 = $alert->pipeline_run ?? '';
                $maxAgeMinutes = TradingSettingService::getPipelineMaxAgeMinutes((string) $pipelineRun2);
                $isPipelineLBacktestAlert2 = strtoupper((string) $pipelineRun2) === 'L' && ! (bool) ($alert->is_realtime ?? false);
                if ($isPipelineLBacktestAlert2) {
                    $maxAgeMinutes = TradingSettingService::getPipelineMaxAgeMinutes((string) $pipelineRun2, true);
                }
                $pipelineMaxAgeKey2 = 'trading.pipeline_'.strtolower((string) $pipelineRun2).'.max_age_minutes';

                Log::info("Order timing check for alert {$event->alertId}:", [
                    'symbol' => $alert->symbol,
                    'signal_ts_est' => $alert->signal_ts_est,
                    'signal_at_utc' => $signalAtUtc2->format('Y-m-d H:i:s T'),
                    'current_time_utc' => $currentTime2->format('Y-m-d H:i:s T'),
                    'age_minutes' => $ageMinutes,
                    'threshold_minutes' => $maxAgeMinutes,
                ]);

                Log::info("Order staleness diagnostics for alert {$event->alertId}", [
                    'symbol' => $alert->symbol,
                    'pipeline' => $pipelineRun2,
                    'pipeline_max_age_key' => $pipelineMaxAgeKey2,
                    'resolved_timestamp_source' => $signalTimestampSource2,
                    'resolved_timestamp_utc' => $signalAtUtc2->format('Y-m-d H:i:s T'),
                    'computed_age_minutes' => $ageMinutes,
                    'max_age_minutes' => $maxAgeMinutes,
                ]);

                if ($ageMinutes > $maxAgeMinutes) {
                    if ($isPipelineLBacktestAlert2) {
                        Log::warning("Alert {$event->alertId} (L/backtest) too old ({$ageMinutes} minutes), skipping order placement", [
                            'symbol' => $alert->symbol,
                            'signal_at_utc' => $signalAtUtc2->format('Y-m-d H:i:s T'),
                            'current_time_utc' => $currentTime2->format('Y-m-d H:i:s T'),
                            'age_minutes' => $ageMinutes,
                            'max_age_minutes' => $maxAgeMinutes,
                            'is_realtime' => (bool) ($alert->is_realtime ?? false),
                        ]);
                        $this->recordSkip($event->alertId, $event->tableName, 'age_too_old_backtest_l');

                        return;
                    }

                    $staleRescoreCheck = $this->evaluateStaleRescoreEligibility((string) $pipelineRun2, (float) $ageMinutes, $alert->symbol);

                    if (! $staleRescoreCheck['allowed']) {
                        Log::warning("Alert {$event->alertId} signal too old ({$ageMinutes} minutes), skipping order placement", [
                            'symbol' => $alert->symbol,
                            'signal_at_utc' => $signalAtUtc2->format('Y-m-d H:i:s T'),
                            'current_time_utc' => $currentTime2->format('Y-m-d H:i:s T'),
                            'age_minutes' => $ageMinutes,
                            'stale_rescore_decision' => $staleRescoreCheck['reason'],
                        ]);
                        $this->recordSkip($event->alertId, $event->tableName, 'age_too_old');

                        return;
                    }

                    $staleRescoreRequired = true;
                    $staleRescoreAgeMinutes = (float) $ageMinutes;

                    Log::warning("Alert {$event->alertId} ({$pipelineRun2}) remained stale at execution ({$ageMinutes}m > {$maxAgeMinutes}m), requiring stale rescore pass", [
                        'symbol' => $alert->symbol,
                        'pipeline' => $pipelineRun2,
                        'age_minutes' => $ageMinutes,
                        'max_age_minutes' => $maxAgeMinutes,
                        'stale_rescore_max_age_minutes' => $staleRescoreCheck['max_age_minutes'],
                    ]);
                }

                Log::info("Alert {$event->alertId} passed timing check ({$ageMinutes} minutes old), proceeding with order");
                // === END TIME CHECK ===

                // === SIP QUOTE CHECK: Use real-time quote for live entry pricing ===
                // For Algo Trader Plus/SIP, the buy decision should be based on the latest ask,
                // not the last completed 1-minute bar. 1-minute bars are still useful for
                // indicators/rescoring, but the quote is the freshest executable reference.
                $signalPrice = (float) $alert->entry;
                $entry = $signalPrice;
                $priceIsStale = false;

                $latestQuote = $this->getLatestStockQuote($alert->symbol);
                $quoteCheck = $this->validateLatestQuoteForBuy($alert->symbol, $latestQuote, $staleRescoreRequired);

                if (! $quoteCheck['ok']) {
                    $extra = [];
                    if ($quoteCheck['reason_code'] === 'quote_spread_too_wide') {
                        $extra['spread_pct'] = $quoteCheck['spread_pct'] ?? null;
                        $extra['max_spread_pct'] = $quoteCheck['max_spread_pct'] ?? null;
                    }
                    Log::warning("Alert {$event->alertId} ({$alert->symbol}): skipping order because live SIP quote failed validation: {$quoteCheck['message']}", array_merge([
                        'symbol' => $alert->symbol,
                        'alert_id' => $event->alertId,
                        'reason_code' => $quoteCheck['reason_code'],
                        'quote' => $latestQuote,
                    ], $extra));
                    $this->recordSkip($event->alertId, $event->tableName, $quoteCheck['reason_code'], null, $extra);

                    return;
                }

                $bidPrice = (float) $latestQuote->bid_price;
                $askPrice = (float) $latestQuote->ask_price;
                $entry = $askPrice;

                Log::info("Using live SIP ask as entry reference for {$alert->symbol}: ask=\${$askPrice}, bid=\${$bidPrice}, alert entry was \${$alert->entry}", [
                    'symbol' => $alert->symbol,
                    'bid_price' => $bidPrice,
                    'ask_price' => $askPrice,
                    'bid_size' => $latestQuote->bid_size ?? null,
                    'ask_size' => $latestQuote->ask_size ?? null,
                    'quote_ts_utc' => $latestQuote->quote_ts_utc ?? null,
                    'received_at_utc' => $latestQuote->received_at_utc ?? null,
                    'quote_age_seconds' => $latestQuote->quote_age_seconds ?? null,
                ]);

                if ($staleRescoreRequired && $entry <= 0) {
                    Log::warning("Alert {$event->alertId} ({$alert->symbol}) is stale-rescore eligible but no valid live quote entry price is available, skipping", [
                        'pipeline' => $pipelineRun2,
                        'stale_age_minutes' => $staleRescoreAgeMinutes,
                    ]);
                    $this->recordSkip($event->alertId, $event->tableName, 'stale_rescore_no_fresh_quote');

                    return;
                }

                // === PRICE EXTENSION CHECK: Skip if live ask has already moved too far from signal ===
                $maxExtensionPct = (float) config('trading.auto_alpaca_orders.max_extension_pct', 0);
                if ($maxExtensionPct > 0 && $signalPrice > 0) {
                    $extensionPct = (($entry - $signalPrice) / $signalPrice) * 100;
                    if ($extensionPct > $maxExtensionPct) {
                        Log::warning("Alert {$event->alertId} ({$alert->symbol}): Live ask \${$entry} has already extended {$extensionPct}% from signal price \${$signalPrice} (max: {$maxExtensionPct}%), skipping", [
                            'symbol' => $alert->symbol,
                            'signal_price' => $signalPrice,
                            'ask_price' => $entry,
                            'bid_price' => $bidPrice,
                            'extension_pct' => round($extensionPct, 2),
                            'max_extension' => $maxExtensionPct,
                        ]);
                        $this->recordSkip($event->alertId, $event->tableName, 'price_extension', $entry);

                        return;
                    }
                    Log::info("Alert {$event->alertId} ({$alert->symbol}): Live ask extension {$extensionPct}% within limit ({$maxExtensionPct}%), proceeding");
                }
                // === END SIP QUOTE / PRICE EXTENSION CHECK ===

                // === BENCHMARK VWAP GATE: Skip if benchmark is below its intraday VWAP ===
                // Check per-pipeline override first, fall back to global toggle.
                $benchmarkGatePipelineOverride = TradingSettingService::getPipelineBenchmarkVwapGateOverride($pipelineRun2);
                $benchmarkGateEnabled = $benchmarkGatePipelineOverride !== null
                    ? $benchmarkGatePipelineOverride
                    : TradingSettingService::isBenchmarkVwapGateEnabled();

                if ($benchmarkGateEnabled) {
                    $benchmarkSymbol = TradingSettingService::getBenchmarkSymbol();
                    $nowEstForGate = now('America/New_York')->format('Y-m-d H:i:s');

                    $benchmarkBar = DB::connection('mysql')
                        ->table('five_minute_prices')
                        ->where('symbol', $benchmarkSymbol)
                        ->where('asset_type', 'stock')
                        ->where('ts_est', '<=', $nowEstForGate)
                        ->orderByDesc('ts_est')
                        ->first(['ts_est', 'price', 'vwap', 'above_vwap', 'vwap_dist_pct']);

                    if ($benchmarkBar) {
                        $vwapDistPct = (float) $benchmarkBar->vwap_dist_pct;
                        $belowVwap = $vwapDistPct < 0;

                        $maxPctBelowHigh = TradingSettingService::getBenchmarkMaxPctBelowHigh();
                        $belowHighPct = null;
                        $failsBelowHighGate = false;
                        if ($maxPctBelowHigh !== null) {
                            $todayBenchmarkHigh = DB::connection('mysql')
                                ->table('five_minute_prices')
                                ->where('symbol', $benchmarkSymbol)
                                ->where('asset_type', 'stock')
                                ->whereDate('trading_date_est', now('America/New_York')->format('Y-m-d'))
                                ->max('price');
                            if ($todayBenchmarkHigh > 0) {
                                $belowHighPct = (($todayBenchmarkHigh - (float) $benchmarkBar->price) / $todayBenchmarkHigh) * 100;
                                $failsBelowHighGate = $belowHighPct >= $maxPctBelowHigh;
                            }
                        }

                        Log::info("Benchmark gate check for alert {$event->alertId} ({$alert->symbol})", [
                            'benchmark' => $benchmarkSymbol,
                            'bar_ts' => $benchmarkBar->ts_est,
                            'price' => $benchmarkBar->price,
                            'vwap' => $benchmarkBar->vwap,
                            'above_vwap' => $benchmarkBar->above_vwap,
                            'vwap_dist_pct' => $benchmarkBar->vwap_dist_pct,
                            'below_high_pct' => $belowHighPct !== null ? round($belowHighPct, 2) : null,
                            'max_pct_below_high' => $maxPctBelowHigh,
                            'pipeline' => $pipelineRun2,
                        ]);

                        if ($belowVwap) {
                            Log::warning("Benchmark gate blocked alert {$event->alertId} ({$alert->symbol}): {$benchmarkSymbol} below VWAP ({$vwapDistPct}%)", [
                                'symbol' => $alert->symbol,
                                'benchmark' => $benchmarkSymbol,
                                'benchmark_price' => $benchmarkBar->price,
                                'benchmark_vwap' => $benchmarkBar->vwap,
                                'vwap_dist_pct' => $vwapDistPct,
                                'pipeline' => $pipelineRun2,
                            ]);
                            $this->recordSkip($event->alertId, $event->tableName, 'benchmark_below_vwap');

                            return;
                        }

                        if ($failsBelowHighGate) {
                            Log::warning("Benchmark gate blocked alert {$event->alertId} ({$alert->symbol}): {$benchmarkSymbol} ".round($belowHighPct, 2).'% below intraday high', [
                                'symbol' => $alert->symbol,
                                'benchmark' => $benchmarkSymbol,
                                'below_high_pct' => round($belowHighPct, 2),
                                'max_allowed' => $maxPctBelowHigh,
                                'pipeline' => $pipelineRun2,
                            ]);
                            $this->recordSkip($event->alertId, $event->tableName, 'benchmark_below_intraday_high');

                            return;
                        }
                    } else {
                        Log::warning("Benchmark gate: no recent bar found for {$benchmarkSymbol}, proceeding without gate", [
                            'symbol' => $alert->symbol,
                            'pipeline' => $pipelineRun2,
                        ]);
                    }
                }
                // === END BENCHMARK VWAP GATE ===

                // === LIVE ML RESCORE: Re-score using current 1-min bar, not the historical entry bar ===
                // The initial ML score was computed at alert creation time using entry_ts_est (the historical bar).
                // We now rescore using the current market bar so the model sees what the setup looks like RIGHT NOW.
                $liveRescoringEnabled = TradingSettingService::isLiveRescoreEnabled($pipelineRun2);
                $mustPassLiveRescore = $staleRescoreRequired;
                if (($liveRescoringEnabled && ! $priceIsStale) || $mustPassLiveRescore) {
                    $currentTs = now('America/New_York')->format('Y-m-d H:i:s');
                    $modelPath = config('trading.ml_scoring.model_path', 'python_ml/models/winner_model_xgb.joblib');
                    $pipelineModelKey = 'trading.ml_scoring.model_path_pipeline_'.strtolower($pipelineRun2);
                    $pipelineModelPath = config($pipelineModelKey);
                    if ($pipelineModelPath) {
                        $modelPath = $pipelineModelPath;
                    }

                    $pythonBin = config('trading.ml_scoring.python_bin', 'python');
                    $scriptPath = base_path('python_ml/v2/score_single_alert_live.py');

                    $liveScoreProcess = new Process([
                        $pythonBin, $scriptPath,
                        '--alert-id', (string) $event->alertId,
                        '--table', $event->tableName,
                        '--model-in', base_path($modelPath),
                        '--current-ts', $currentTs,
                    ], base_path());
                    $liveScoreProcess->setTimeout(30);
                    $liveScoreProcess->run();

                    if ($liveScoreProcess->isSuccessful()) {
                        $liveScoreOutput = $liveScoreProcess->getOutput();
                        // Parse "Live-scored alert 123 at ...: ml_win_prob = 0.712345"
                        if (preg_match('/ml_win_prob\s*=\s*([\d.]+)/', $liveScoreOutput, $matches)) {
                            $liveProb = (float) $matches[1];
                            $liveThreshold = TradingSettingService::getPipelineMlThreshold((string) $pipelineRun2);

                            // Persist the live rescore so it's visible in the UI
                            DB::table($event->tableName)
                                ->where('id', $event->alertId)
                                ->update([
                                    'ml_live_win_prob' => $liveProb,
                                    'ml_live_scored_at' => now(),
                                ]);

                            Log::info("Live ML rescore for alert {$event->alertId} ({$alert->symbol}): {$liveProb} (initial: {$event->mlWinProb}, threshold: {$liveThreshold})", [
                                'current_ts' => $currentTs,
                                'stale_rescore_required' => $mustPassLiveRescore,
                                'stale_signal_age_minutes' => $staleRescoreAgeMinutes,
                            ]);

                            if ($liveProb < $liveThreshold) {
                                $skipReason = $mustPassLiveRescore ? 'stale_rescore_low_score' : 'ml_rescore_low_score';
                                Log::info("Alert {$event->alertId} ({$alert->symbol}): Live ML rescore {$liveProb} below threshold {$liveThreshold}, skipping order", [
                                    'skip_reason' => $skipReason,
                                    'stale_rescore_required' => $mustPassLiveRescore,
                                ]);
                                $this->recordSkip($event->alertId, $event->tableName, $skipReason);

                                return;
                            }
                        } else {
                            Log::warning("Alert {$event->alertId}: Could not parse live ML rescore output", [
                                'output' => $liveScoreOutput,
                                'stale_rescore_required' => $mustPassLiveRescore,
                            ]);

                            if ($mustPassLiveRescore) {
                                $this->recordSkip($event->alertId, $event->tableName, 'stale_rescore_failed');

                                return;
                            }
                        }
                    } else {
                        Log::warning("Alert {$event->alertId}: Live ML rescore process failed", [
                            'error' => $liveScoreProcess->getErrorOutput(),
                            'stale_rescore_required' => $mustPassLiveRescore,
                        ]);

                        if ($mustPassLiveRescore) {
                            $this->recordSkip($event->alertId, $event->tableName, 'stale_rescore_failed');

                            return;
                        }
                    }
                }
                // === END LIVE ML RESCORE ===

                // Calculate stop loss price based on configured mode.
                // This is based on the live ask reference. The monitor job should still
                // prefer actual filled_avg_price when available before placing the final stop.
                $stopPrice = $this->calculateStopLoss($entry, $alert);
                Log::info("Calculated stop loss for {$alert->symbol}: \${$stopPrice}");

                // Calculate entry limit price if limit orders are enabled.
                // New SIP behavior: the limit is a marketable limit based on live ask.
                // Example .env/config: ALPACA_MARKETABLE_LIMIT_ORDER=1.0005 means limit = ask * 1.0005.
                $useLimit = TradingSettingService::isUseLimitOrdersEnabled();
                $limitPrice = null;
                $marketableLimitMultiplier = null;
                if ($useLimit) {
                    $marketableLimitMultiplier = $this->getMarketableLimitMultiplier();
                    $limitPrice = $this->roundBuyLimitPriceUp($entry * $marketableLimitMultiplier);

                    Log::info("Marketable limit order mode: {$alert->symbol} limit_price=\${$limitPrice} (ask=\${$entry} * multiplier={$marketableLimitMultiplier}, bid=\${$bidPrice}, signal=\${$signalPrice}, pipeline={$pipelineRun2})", [
                        'symbol' => $alert->symbol,
                        'signal_price' => $signalPrice,
                        'bid_price' => $bidPrice,
                        'ask_price' => $entry,
                        'limit_price' => $limitPrice,
                        'marketable_limit_multiplier' => $marketableLimitMultiplier,
                        'quote_age_seconds' => $latestQuote->quote_age_seconds ?? null,
                    ]);
                }

                // Use the position size already calculated above (respects liquidity constraints).
                // Qty should be based on the maximum possible entry cost. For limit orders that is
                // limit_price, not the ask, otherwise the fill could exceed position size slightly.
                $qtyReferencePrice = $limitPrice ?? $entry;
                $qty = floor($positionSize / $qtyReferencePrice);

                // === ASK SIZE CHECK: Don't buy more shares than offered on the ask ===
                // The latest SIP quote includes ask_size (number of shares offered at the ask).
                // Buying more than available could cause slippage or partial fills.
                $availableAskSize = (int) ($latestQuote->ask_size ?? 0);
                $maxAskQty = (int) floor($availableAskSize * 0.75);
                if ($maxAskQty > 0 && $qty > $maxAskQty) {
                    $originalQty = $qty;
                    $qty = $maxAskQty;
                    Log::info("Alert {$event->alertId} ({$alert->symbol}): Capped qty to 75% of ask size", [
                        'symbol' => $alert->symbol,
                        'original_qty' => $originalQty,
                        'capped_qty' => $qty,
                        'ask_size' => $availableAskSize,
                        'max_ask_qty' => $maxAskQty,
                        'ask_price' => $entry,
                        'reason' => 'ask_size_75pct_cap',
                    ]);
                }
                // === END ASK SIZE CHECK ===

                if ($qty < 1) {
                    Log::warning("Alert {$event->alertId} ({$alert->symbol}): computed qty < 1, skipping order", [
                        'symbol' => $alert->symbol,
                        'position_size' => $positionSize,
                        'entry_reference_price' => $entry,
                        'qty_reference_price' => $qtyReferencePrice,
                        'limit_price' => $limitPrice,
                    ]);
                    $this->recordSkip($event->alertId, $event->tableName, 'qty_less_than_one', $entry);

                    return;
                }

                Log::info("Placing Alpaca orders for alert {$event->alertId}: {$alert->symbol} qty={$qty} entry={$entry} stop={$stopPrice} position_size=\${$positionSize} order_type=".($useLimit ? 'limit' : 'market'));

                // === PRE-PLACEMENT BUYING POWER RE-CHECK ===
                // The initial check (lines 358-401) can be 30+ seconds stale because
                // concurrent orders may have consumed buying power between the check
                // and actual placement. Re-fetch account info right before placing.
                $recheckAccount = $this->alpacaService->getAccountInfo();
                $recheckBuyingPower = $recheckAccount['buying_power'] ?? 0;
                $estimatedCost = $qty * ($limitPrice ?? $entry);
                if ($recheckAccount['success'] && $recheckBuyingPower < $estimatedCost) {
                    Log::warning("Alert {$event->alertId} ({$alert->symbol}): Pre-placement buying power re-check failed", [
                        'symbol' => $alert->symbol,
                        'alert_id' => $event->alertId,
                        'buying_power_now' => number_format($recheckBuyingPower, 2),
                        'buying_power_at_check' => number_format($buyingPower, 2),
                        'estimated_cost' => number_format($estimatedCost, 2),
                        'qty' => $qty,
                        'ref_price' => $limitPrice ?? $entry,
                        'seconds_since_first_check' => time() - ($firstCheckTime ?? time()),
                    ]);
                    $this->recordSkip($event->alertId, $event->tableName, 'buying_power_stale', $entry);

                    return;
                }
                // === END PRE-PLACEMENT BUYING POWER RE-CHECK ===

                // Step 1: Place the entry order (market or limit buy)
                $entryResult = $this->alpacaService->placeOrder(
                    symbol: $alert->symbol,
                    qty: $qty,
                    side: 'buy',
                    stopPrice: null, // Stop placed separately after fill by monitor job
                    limitPrice: $limitPrice
                );

                if (! $entryResult['success']) {
                    Log::error("Alpaca entry order failed for alert {$event->alertId}: {$entryResult['error']}");

                    return;
                }

                // Parse the entry order response and save to database
                $entryOrderData = $this->parseOrderResponse($entryResult['output']);

                if (! $entryOrderData || ! isset($entryOrderData['order'])) {
                    Log::warning("Could not parse entry order response for alert {$event->alertId}");

                    return;
                }

                $entryOrder = $entryOrderData['order'];
                $entryAlpacaOrderId = $entryOrder['id'] ?? null;

                // Save entry order to database (with stop_price for trailing stop activation logic)
                $entryOrderRecord = AlpacaOrder::create([
                    'trade_alert_id' => $event->alertId,
                    'alpaca_order_id' => $entryAlpacaOrderId,
                    'client_order_id' => $entryOrder['client_order_id'] ?? null,
                    'paper' => (bool) config('alpaca.paper_trading', true),
                    'is_paper' => (bool) config('alpaca.paper_trading', true),
                    'symbol' => $alert->symbol,
                    'side' => 'buy',
                    'qty' => $qty,
                    'status' => $entryOrder['status'] ?? 'pending',
                    'order_type' => $entryOrder['order_type'] ?? 'market',
                    'time_in_force' => $entryOrder['time_in_force'] ?? 'day',
                    'submitted_at' => isset($entryOrder['submitted_at']) ? now()->parse($entryOrder['submitted_at']) : null,
                    'raw_json' => $entryOrderData,
                    'notes' => "Entry order for alert_id:{$event->alertId}, ML:{$event->mlWinProb}, stale_rescore:".($staleRescoreRequired ? '1' : '0').', stale_age_min:'.($staleRescoreAgeMinutes !== null ? round($staleRescoreAgeMinutes, 2) : '0').', quote_ask:'.($askPrice ?? '0').', quote_age_sec:'.($latestQuote->quote_age_seconds ?? 'null').', marketable_limit_multiplier:'.($marketableLimitMultiplier ?? 'null'),
                    'atr' => $alert->atr ?? null,
                    'atr_pct' => $alert->atr_pct ?? null,
                    'stop_price' => $stopPrice, // Store initial stop for trailing stop logic
                ]);

                // Update the alert's entry price and stop price with the current market price used for the order
                DB::connection('mysql')->table($event->tableName)
                    ->where('id', $event->alertId)
                    ->update([
                        'entry' => $entry,
                        'stop' => $stopPrice,
                        'updated_at' => now(),
                    ]);

                Log::info("Alpaca entry order placed successfully for alert {$event->alertId}: {$alert->symbol} qty={$qty} alpaca_id={$entryAlpacaOrderId}, updated alert entry=\${$entry} stop=\${$stopPrice}");

                Log::channel('pipeline-timing')->info('[LISTENER] ORDER_PLACED', [
                    'alert_id' => $event->alertId,
                    'pipeline' => $pipelineRun,
                    'symbol' => $alert->symbol,
                    'alpaca_order_id' => $entryAlpacaOrderId,
                    'entry_price' => $entry,
                    'stop_price' => $stopPrice,
                    'wall_clock' => now('America/New_York')->format('Y-m-d H:i:s'),
                    'listener_total_ms' => round((microtime(true) - $listenerStart) * 1000),
                    'signal_to_order_sec' => $alert->signal_ts_est
                        ? round(now('America/New_York')->getTimestamp() - strtotime($alert->signal_ts_est), 1)
                        : null,
                    'entry_to_order_sec' => $alert->entry_ts_est
                        ? round(now('America/New_York')->getTimestamp() - strtotime($alert->entry_ts_est), 1)
                        : null,
                ]);

                // Dispatch monitoring job to check when order fills and then place stop loss
                MonitorAlpacaOrderFillAndPlaceStopLoss::dispatch(
                    entryAlpacaOrderDbId: $entryOrderRecord->id,
                    alertId: $event->alertId,
                    symbol: $alert->symbol,
                    qty: $qty,
                    stopPrice: $stopPrice,
                );

                Log::info("Dispatched stop loss monitoring job for alert {$event->alertId}, will check every 30s until entry order fills");
            } finally {
                $symbolLock->release();
                $alertLock->release();
            }
        } catch (\Throwable $e) {
            Log::error("Exception placing Alpaca order for alert {$event->alertId}: {$e->getMessage()}");
        }
    }

    /**
     * Parse order response from Python script output
     */
    protected function parseOrderResponse(string $output): ?array
    {
        // Try to extract JSON from output
        if (preg_match('/(\{.*\})/s', $output, $matches)) {
            $json = json_decode($matches[1], true);
            if ($json) {
                return $json;
            }
        }

        return null;
    }

    /**
     * Get the latest SIP quote written by alpaca_python_api/stream_bars.py.
     *
     * The buy logic uses ask_price as the entry reference for long limit orders.
     */
    protected function getLatestStockQuote(string $symbol): ?object
    {
        return DB::connection('mysql')
            ->table('latest_stock_quotes')
            ->selectRaw('
                symbol,
                bid_price,
                ask_price,
                bid_size,
                ask_size,
                bid_exchange,
                ask_exchange,
                quote_ts_utc,
                received_at_utc,
                feed,
                TIMESTAMPDIFF(SECOND, received_at_utc, UTC_TIMESTAMP(6)) AS received_age_seconds,
                TIMESTAMPDIFF(SECOND, quote_ts_utc, UTC_TIMESTAMP(6)) AS quote_age_seconds
            ')
            ->where('symbol', strtoupper($symbol))
            ->where('received_at_utc', '>=', now('UTC')->subMinutes(30))
            ->first();
    }

    /**
     * Validate the live quote before using it for a buy.
     *
     * Defaults are intentionally conservative for fast 1-minute/5-minute momentum:
     *   ALPACA_MAX_QUOTE_AGE_SECONDS=5
     *   ALPACA_MAX_SPREAD_PCT=0.35
     */
    protected function validateLatestQuoteForBuy(string $symbol, ?object $quote, bool $staleRescoreOverride = false): array
    {
        if (! $quote) {
            return [
                'ok' => false,
                'reason_code' => 'no_latest_quote',
                'message' => "No latest SIP quote found for {$symbol}",
            ];
        }

        $bid = (float) ($quote->bid_price ?? 0);
        $ask = (float) ($quote->ask_price ?? 0);
        $receivedAgeSeconds = (int) ($quote->received_age_seconds ?? 999999);
        $quoteAgeSeconds = (int) ($quote->quote_age_seconds ?? 999999);
        $ageSeconds = max($receivedAgeSeconds, $quoteAgeSeconds);

        $maxAgeSeconds = TradingSettingService::getMaxQuoteAgeSeconds();

        // Stale rescore bypass: allow older quotes when rescoring a stale alert
        if ($staleRescoreOverride) {
            $maxAgeSeconds = max($maxAgeSeconds, 300); // up to 5 minutes for stale rescore
        }

        if ($ageSeconds > $maxAgeSeconds) {
            return [
                'ok' => false,
                'reason_code' => 'quote_stale',
                'message' => "Quote is stale: received {$receivedAgeSeconds}s ago, quote_ts {$quoteAgeSeconds}s ago; max allowed is {$maxAgeSeconds}s",
            ];
        }

        if ($bid <= 0 || $ask <= 0 || $ask < $bid) {
            return [
                'ok' => false,
                'reason_code' => 'invalid_quote',
                'message' => "Invalid quote bid={$bid}, ask={$ask}",
            ];
        }

        $mid = ($bid + $ask) / 2.0;
        if ($mid <= 0) {
            return [
                'ok' => false,
                'reason_code' => 'invalid_quote_midpoint',
                'message' => "Invalid quote midpoint for bid={$bid}, ask={$ask}",
            ];
        }

        $spreadPct = (($ask - $bid) / $mid) * 100.0;
        $maxSpreadPct = TradingSettingService::getMaxSpreadPct();
        if ($spreadPct > $maxSpreadPct) {
            return [
                'ok' => false,
                'reason_code' => 'quote_spread_too_wide',
                'message' => 'Spread '.round($spreadPct, 4)."% exceeds max {$maxSpreadPct}%",
                'spread_pct' => round($spreadPct, 4),
                'max_spread_pct' => $maxSpreadPct,
            ];
        }

        return [
            'ok' => true,
            'reason_code' => 'ok',
            'message' => 'OK',
        ];
    }

    /**
     * Multiplier used for marketable limit orders.
     *
     * Example:
     *   ALPACA_MARKETABLE_LIMIT_ORDER=1.0005
     *   limit_price = latest_ask * 1.0005
     */
    protected function getMarketableLimitMultiplier(): float
    {
        $multiplier = (float) config('trading.auto_alpaca_orders.marketable_limit_multiplier', env('ALPACA_MARKETABLE_LIMIT_ORDER', 1.0005));

        // Safety guard: values <= 1.0 are not marketable for buys, and huge values
        // can create unnecessary chasing/slippage if .env is accidentally mistyped.
        if ($multiplier <= 1.0 || $multiplier > 1.02) {
            Log::warning('Invalid marketable limit multiplier; using safe default 1.0005', [
                'configured_value' => config('trading.auto_alpaca_orders.marketable_limit_multiplier', env('ALPACA_MARKETABLE_LIMIT_ORDER', null)),
                'safe_default' => 1.0005,
            ]);

            return 1.0005;
        }

        return $multiplier;
    }

    /**
     * Round BUY limit prices up to normal stock precision.
     *
     * For a marketable buy limit, rounding to nearest can occasionally round
     * below the calculated limit. Ceiling keeps the order marketable while still
     * respecting Alpaca's common equity price precision rules.
     */
    protected function roundBuyLimitPriceUp(float $price): float
    {
        if ($price >= 1.0) {
            return ceil($price * 100) / 100;
        }

        return ceil($price * 10000) / 10000;
    }

    /**
     * Get current market price from 1-minute data
     */
    protected function getCurrentMarketPrice(string $symbol): ?float
    {
        $latestPrice = DB::connection('mysql')
            ->table('one_minute_prices')
            ->where('symbol', $symbol)
            ->latest('ts')
            ->first();

        if (! $latestPrice || ! $latestPrice->price) {
            return null;
        }

        $maxAgeMinutes = (int) TradingSettingService::get(
            'trading.current_price_max_age_minutes',
            config('trading.auto_alpaca_orders.current_price_max_age_minutes', 5)
        );
        $ageMinutes = now()->diffInMinutes($latestPrice->ts);

        if ($ageMinutes > $maxAgeMinutes) {
            Log::warning("Current price for {$symbol} is {$ageMinutes} minutes old (max {$maxAgeMinutes}), treating as stale");

            return null;
        }

        return (float) $latestPrice->price;
    }

    /**
     * Calculate stop loss price based on configured mode (fixed % or ATR-based)
     */
    protected function calculateStopLoss(float $entryPrice, object $alert): float
    {
        $pipelineRun = strtoupper((string) ($alert->pipeline_run ?? ''));
        if ($pipelineRun === 'J') {
            $maxLossPct = (float) config('trading.auto_alpaca_orders.max_loss_pipeline_j', 0.50);

            if ($maxLossPct > 0) {
                Log::info('Using Pipeline J max-loss stop loss', [
                    'symbol' => $alert->symbol ?? 'unknown',
                    'entry_price' => $entryPrice,
                    'max_loss_pct' => $maxLossPct,
                ]);

                return round($entryPrice * (1 - $maxLossPct / 100), 2);
            }
        }

        $mode = TradingSettingService::getStopLossMode();

        if ($mode === 'atr') {
            // ALWAYS recalculate using current config multiplier and raw ATR (not old suggested_trailing_stop_pct)
            // This ensures we use the latest config (4x multiplier, 1.0-2.5% bounds) even for old alerts
            if (isset($alert->atr) && $alert->atr > 0) {
                $atr = (float) $alert->atr;
                $configMultiplier = TradingSettingService::getStopLossAtrMultiplier();
                $minPct = TradingSettingService::getStopLossAtrMinPct();
                $maxPct = TradingSettingService::getStopLossAtrMaxPct();

                // Calculate: ATR * multiplier / entry price * 100 = stop %
                $calculatedPct = (($atr * $configMultiplier) / $entryPrice) * 100.0;
                $stopPct = max($minPct, min($maxPct, $calculatedPct));
                $stopPrice = round($entryPrice * (1 - $stopPct / 100), 2);

                Log::info('Using RECALCULATED ATR stop loss with current config', [
                    'symbol' => $alert->symbol ?? 'unknown',
                    'entry_price' => $entryPrice,
                    'atr' => $atr,
                    'multiplier' => $configMultiplier,
                    'calculated_pct' => round($calculatedPct, 2),
                    'bounded_pct' => round($stopPct, 2),
                    'stop_price' => $stopPrice,
                    'old_suggested_pct' => $alert->suggested_trailing_stop_pct ?? null,
                ]);

                return $stopPrice;
            }

            // No ATR data available, fall back to fixed
            Log::warning('Alert in ATR mode but no ATR data available, using fixed stop loss', [
                'alert_id' => $alert->id ?? null,
                'symbol' => $alert->symbol ?? 'unknown',
            ]);
        }

        // Default to fixed percentage
        return $this->calculateFixedStopLoss($entryPrice);
    }

    private function getSymbolRetradeWaitMinutes(): int
    {
        return TradingSettingService::getRetradeSymbolWaitMinutes();
    }

    /**
     * @return array{last_buy_order_id:int,last_buy_order_at:string,minutes_since_last_buy:int,wait_minutes:int}|null
     */
    private function getSymbolRetradeCooldownData(string $symbol, Carbon $currentTime, int $waitMinutes): ?array
    {
        if ($waitMinutes === 0) {
            $recentBuyOrder = AlpacaOrder::query()
                ->where('symbol', $symbol)
                ->where('side', 'buy')
                ->whereIn('status', ['filled', 'new', 'accepted', 'pending_new', 'partially_filled'])
                ->whereDate('created_at', $currentTime->copy()->setTimezone('America/New_York')->format('Y-m-d'))
                ->orderByDesc('created_at')
                ->orderByDesc('id')
                ->first();

            if ($recentBuyOrder === null) {
                return null;
            }

            $recentBuyAt = Carbon::parse((string) $recentBuyOrder->created_at, 'UTC');

            return [
                'last_buy_order_id' => (int) $recentBuyOrder->id,
                'last_buy_order_at' => $recentBuyAt->format('Y-m-d H:i:s'),
                'minutes_since_last_buy' => (int) floor($currentTime->diffInSeconds($recentBuyAt, true) / 60),
                'wait_minutes' => 0,
            ];
        }

        $recentBuyOrder = AlpacaOrder::query()
            ->where('symbol', $symbol)
            ->where('side', 'buy')
            ->whereIn('status', ['filled', 'new', 'accepted', 'pending_new', 'partially_filled'])
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->first();

        if ($recentBuyOrder === null) {
            return null;
        }

        $recentBuyAt = Carbon::parse((string) $recentBuyOrder->created_at, 'UTC');
        $minutesSinceLastBuy = (int) floor($currentTime->diffInSeconds($recentBuyAt, true) / 60);

        if ($minutesSinceLastBuy >= $waitMinutes) {
            return null;
        }

        return [
            'last_buy_order_id' => (int) $recentBuyOrder->id,
            'last_buy_order_at' => $recentBuyAt->format('Y-m-d H:i:s'),
            'minutes_since_last_buy' => $minutesSinceLastBuy,
            'wait_minutes' => $waitMinutes,
        ];
    }

    /**
     * Record that an alert was skipped by an order guard, for shadow P&L analysis.
     *
     * @param  string  $reason  Short reason code, e.g. 'extension', 'age', 'duplicate_symbol'
     * @param  float|null  $skipPrice  Live price at the moment of skip (null when unavailable)
     * @param  array<string, mixed>  $context  Extra context for logging (e.g. spread_pct, max_spread_pct)
     */
    protected function recordSkip(int|string $alertId, string $tableName, string $reason, ?float $skipPrice = null, array $context = []): void
    {
        try {
            DB::connection('mysql')->table($tableName)->where('id', $alertId)->update([
                'skipped_reason' => $reason,
                'skipped_at' => now(),
                'skip_price' => $skipPrice,
                'updated_at' => now(),
            ]);

            Log::channel('pipeline-timing')->info('[LISTENER] SKIPPED', array_merge([
                'alert_id' => $alertId,
                'reason' => $reason,
                'skip_price' => $skipPrice,
                'wall_clock' => now('America/New_York')->format('Y-m-d H:i:s'),
            ], $context));
        } catch (\Throwable $e) {
            Log::warning("Failed to record skip for alert {$alertId}: {$e->getMessage()}");
        }
    }

    /**
     * Decide whether a stale alert can continue to a mandatory live rescore gate.
     *
     * @return array{allowed:bool,reason:string,paper_only:bool,max_age_minutes:int}
     */
    protected function evaluateStaleRescoreEligibility(string $pipelineRun, float $ageMinutes, ?string $symbol = null): array
    {
        $staleRescoreEnabled = TradingSettingService::isStaleRescoreEnabled();
        $paperOnly = TradingSettingService::isStaleRescorePaperOnly();
        $maxAgeMinutes = TradingSettingService::getStaleRescoreMaxAgeMinutes();

        if (! $staleRescoreEnabled) {
            Log::info('[StaleRescore] Feature disabled — skipping', [
                'symbol' => $symbol,
                'pipeline' => $pipelineRun,
                'age_minutes' => $ageMinutes,
                'stale_rescore_max_age_minutes' => $maxAgeMinutes,
            ]);

            return [
                'allowed' => false,
                'reason' => 'disabled',
                'paper_only' => $paperOnly,
                'max_age_minutes' => $maxAgeMinutes,
            ];
        }

        if ($paperOnly && ! TradingSettingService::isPaperTrading()) {
            Log::info('[StaleRescore] Live trading mode — skipping (paper_only=true)', [
                'symbol' => $symbol,
                'pipeline' => $pipelineRun,
                'age_minutes' => $ageMinutes,
                'is_paper_trading' => false,
            ]);

            return [
                'allowed' => false,
                'reason' => 'paper_only_mode',
                'paper_only' => $paperOnly,
                'max_age_minutes' => $maxAgeMinutes,
            ];
        }

        if ($ageMinutes > $maxAgeMinutes) {
            Log::info("[StaleRescore] Age {$ageMinutes}m exceeds max {$maxAgeMinutes}m — skipping", [
                'symbol' => $symbol,
                'pipeline' => $pipelineRun,
                'age_minutes' => $ageMinutes,
                'stale_rescore_max_age_minutes' => $maxAgeMinutes,
            ]);

            return [
                'allowed' => false,
                'reason' => 'beyond_stale_rescore_window',
                'paper_only' => $paperOnly,
                'max_age_minutes' => $maxAgeMinutes,
            ];
        }

        if (! TradingSettingService::isLiveRescoreEnabled($pipelineRun)) {
            Log::info('[StaleRescore] Live rescore disabled for pipeline — skipping', [
                'symbol' => $symbol,
                'pipeline' => $pipelineRun,
                'age_minutes' => $ageMinutes,
            ]);

            return [
                'allowed' => false,
                'reason' => 'live_rescore_disabled',
                'paper_only' => $paperOnly,
                'max_age_minutes' => $maxAgeMinutes,
            ];
        }

        Log::info('[StaleRescore] Eligible — proceeding with live rescore', [
            'symbol' => $symbol,
            'pipeline' => $pipelineRun,
            'age_minutes' => $ageMinutes,
            'max_age_minutes' => $maxAgeMinutes,
        ]);

        return [
            'allowed' => true,
            'reason' => 'eligible',
            'paper_only' => $paperOnly,
            'max_age_minutes' => $maxAgeMinutes,
        ];
    }

    /**
     * Calculate fixed percentage stop loss
     */
    protected function calculateFixedStopLoss(float $entryPrice): float
    {
        $stopLossPct = config('trading.auto_alpaca_orders.stop_loss_pct', 0.75);

        return round($entryPrice * (1 - $stopLossPct / 100), 2);
    }

    /**
     * Calculate ATR-based stop loss with min/max bounds (fallback when suggested_trailing_stop_pct not available)
     */
    protected function calculateATRStopLoss(float $entryPrice, float $atr): float
    {
        $multiplier = TradingSettingService::getStopLossAtrMultiplier();
        $minPct = TradingSettingService::getStopLossAtrMinPct();
        $maxPct = TradingSettingService::getStopLossAtrMaxPct();

        // Calculate ATR-based stop distance
        $atrStopDistance = $atr * $multiplier;
        $stopPrice = $entryPrice - $atrStopDistance;

        // Convert to percentage
        $stopPct = (($entryPrice - $stopPrice) / $entryPrice) * 100;

        // Clamp to min/max bounds
        $stopPct = max($minPct, min($maxPct, $stopPct));

        // Recalculate stop price with bounded percentage
        $boundedStopPrice = round($entryPrice * (1 - $stopPct / 100), 2);

        Log::info("ATR stop loss calculation (fallback): entry=\${$entryPrice}, atr={$atr}, multiplier={$multiplier}, raw_stop_pct=".round($stopPct, 2)."%, bounded_stop=\${$boundedStopPrice}");

        return $boundedStopPrice;
    }

    protected function resolveSignalTimestampUtc(object $alert): \Carbon\Carbon
    {
        return $this->resolveSignalTimestampDetails($alert)['timestamp'];
    }

    /**
     * Temporary staleness diagnostics helper.
     *
     * @return array{timestamp:\Carbon\Carbon,source:string}
     */
    protected function resolveSignalTimestampDetails(object $alert): array
    {
        if (! empty($alert->signal_ts_est)) {
            return [
                'timestamp' => \Carbon\Carbon::parse($alert->signal_ts_est, 'America/New_York')->utc(),
                'source' => 'signal_ts_est',
            ];
        }

        if (! empty($alert->entry_ts_est)) {
            return [
                'timestamp' => \Carbon\Carbon::parse($alert->entry_ts_est, 'America/New_York')->utc(),
                'source' => 'entry_ts_est',
            ];
        }

        return [
            'timestamp' => \Carbon\Carbon::parse($alert->created_at ?? now('UTC'))->utc(),
            'source' => 'created_at',
        ];
    }
}
