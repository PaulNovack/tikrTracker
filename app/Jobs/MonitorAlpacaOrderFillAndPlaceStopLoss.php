<?php

namespace App\Jobs;

use App\Models\AlpacaOrder;
use App\Services\AlpacaPythonService;
use App\Services\TradingSettingService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class MonitorAlpacaOrderFillAndPlaceStopLoss implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Maximum number of retry attempts (30 seconds * 30 = 15 minutes max)
     */
    public int $tries = 30;

    /**
     * Number of seconds to wait before retrying
     */
    public int $backoff = 30;

    /**
     * Guard prevents infinite retry loop when stop price adjustment keeps failing
     * (e.g. penny stocks where floor-adjusted stop price still rounds to same value).
     */
    private int $stopPlacementAttempts = 0;

    private const MAX_STOP_PLACEMENT_ATTEMPTS = 5;

    public function __construct(
        public int $entryAlpacaOrderDbId,
        public int $alertId,
        public string $symbol,
        public float $qty,
        public float $stopPrice,
    ) {}

    /**
     * Unique key to prevent duplicate job dispatches for the same entry order.
     */
    public function uniqueId(): string
    {
        return (string) $this->entryAlpacaOrderDbId;
    }

    public function handle(AlpacaPythonService $alpacaService): void
    {
        // Get the entry order from database
        $entryOrder = AlpacaOrder::find($this->entryAlpacaOrderDbId);

        if (! $entryOrder) {
            Log::error("Entry order not found in database: ID={$this->entryAlpacaOrderDbId}");

            return;
        }

        // Guard: verify this is the most recent buy for this symbol.
        // Prevents the job from linking a stop loss to an old buy when a
        // newer position exists (e.g. a manual order was placed after a
        // prior automated position was partially or fully closed).
        // NOTE: Only consider buys with a higher ID (created AFTER this entry order)
        // so that an unfilled entry order does not incorrectly match a historical
        // filled buy from a previous trading session.
        $latestBuy = AlpacaOrder::where('symbol', $this->symbol)
            ->where('side', 'buy')
            ->where('status', 'filled')
            ->where('filled_qty', '>', 0)
            ->where('id', '>', $entryOrder->id)
            ->whereDate('created_at', $entryOrder->created_at->format('Y-m-d'))
            ->orderByDesc('id')
            ->first();

        if ($latestBuy && $latestBuy->id !== $entryOrder->id) {
            Log::warning(
                'Stop-loss entry order is stale — a newer buy exists for '.$this->symbol,
                [
                    'job_entry_order_id' => $entryOrder->id,
                    'job_entry_order_alpaca_id' => $entryOrder->alpaca_order_id,
                    'latest_buy_id' => $latestBuy->id,
                    'latest_buy_alpaca_id' => $latestBuy->alpaca_order_id,
                    'latest_buy_filled_qty' => $latestBuy->filled_qty,
                ]
            );

            // Re-dispatch this job against the latest buy
            if ($this->stopPlacementAttempts < self::MAX_STOP_PLACEMENT_ATTEMPTS) {
                MonitorAlpacaOrderFillAndPlaceStopLoss::dispatch(
                    entryAlpacaOrderDbId: $latestBuy->id,
                    alertId: $this->alertId,
                    symbol: $this->symbol,
                    qty: $latestBuy->filled_qty,
                    stopPrice: $this->stopPrice,
                );

                Log::info("Re-dispatched stop-loss monitor for {$this->symbol} using latest buy #{$latestBuy->id}");
            }

            return;
        }

        // Check if stop loss already placed for this entry order
        $existingStop = AlpacaOrder::where('parent_alpaca_order_id', $entryOrder->alpaca_order_id)
            ->where('side', 'sell')
            ->where('order_type', 'stop')
            ->exists();

        if ($existingStop) {
            Log::info("Stop loss already exists for entry order {$entryOrder->alpaca_order_id}, skipping");

            return;
        }

        // Fail-fast if the entry order has been in 'new' status for more than 10 minutes.
        // This prevents wasting 30 retries (15 minutes) on buy limit orders that may
        // never fill (e.g., stock gapped up above the limit price).
        if ($entryOrder->status === 'new' && $entryOrder->submitted_at && $entryOrder->submitted_at->diffInMinutes(now()) > 10) {
            Log::warning("Entry order {$entryOrder->alpaca_order_id} has been 'new' for >10 min — giving up", [
                'symbol' => $this->symbol,
                'submitted_at' => $entryOrder->submitted_at,
                'minutes_elapsed' => $entryOrder->submitted_at->diffInMinutes(now()),
            ]);

            return;
        }

        // Check order status with Alpaca API
        $result = $alpacaService->checkOrderStatus($entryOrder->alpaca_order_id);

        if (! $result['success']) {
            Log::warning("Failed to check order status for {$entryOrder->alpaca_order_id}: {$result['error']}");
            throw new \Exception('Failed to check order status, will retry');
        }

        // Parse response
        $statusData = $this->parseStatusResponse($result['output']);

        if (! $statusData || ! isset($statusData['order'])) {
            Log::warning("Could not parse order status response for {$entryOrder->alpaca_order_id}");
            throw new \Exception('Could not parse status response, will retry');
        }

        $order = $statusData['order'];
        $status = $order['status'] ?? 'unknown';

        Log::info("Order {$entryOrder->alpaca_order_id} status: {$status}");

        // Update entry order status in database
        $updateData = [
            'status' => $status,
            'filled_qty' => $order['filled_qty'] ?? $entryOrder->filled_qty,
            'filled_avg_price' => $order['filled_avg_price'] ?? $entryOrder->filled_avg_price,
            'updated_at' => now(),
            'paper' => (bool) config('alpaca.paper_trading', true),
        ];

        // Set filled_at timestamp when order is filled
        if ($status === 'filled' && isset($order['filled_at'])) {
            $updateData['filled_at'] = now()->parse($order['filled_at']);
        } elseif ($status === 'filled' && ! $entryOrder->filled_at) {
            $updateData['filled_at'] = now();
        }

        $entryOrder->update($updateData);

        // If order is filled, place stop loss
        if ($status === 'filled') {
            // Get the original alert to retrieve ATR-based stop loss percentage
            $alert = \App\Models\TradeAlert::find($this->alertId);
            $filledPrice = (float) ($order['filled_avg_price'] ?? $this->stopPrice / 0.99);

            // Pipeline J uses a hard max-loss stop rather than ATR-based recalculation.
            $stopPct = null;
            $pipelineRun = strtoupper((string) ($alert->pipeline_run ?? ''));
            if ($pipelineRun === 'J') {
                $maxLossPct = (float) config('trading.auto_alpaca_orders.max_loss_pipeline_j', 0.50);

                if ($maxLossPct > 0) {
                    $stopPct = $maxLossPct;

                    Log::info("Using Pipeline J max-loss stop for {$this->symbol}", [
                        'alert_id' => $this->alertId,
                        'stop_pct' => $stopPct,
                    ]);
                }
            } elseif ($alert && isset($alert->atr) && $alert->atr > 0 && $filledPrice > 0) {
                // RECALCULATE stop using current config multiplier and raw ATR (not old suggested_trailing_stop_pct)
                // This ensures we use the latest config (4x multiplier, 1.0-2.5% bounds) even for old alerts
                $atr = (float) $alert->atr;
                $configMultiplier = TradingSettingService::getStopLossAtrMultiplier();
                $minPct = TradingSettingService::getStopLossAtrMinPct();
                $maxPct = TradingSettingService::getStopLossAtrMaxPct();

                // Calculate: ATR * multiplier / entry price * 100 = stop %
                $calculatedPct = (($atr * $configMultiplier) / $filledPrice) * 100.0;
                $stopPct = max($minPct, min($maxPct, $calculatedPct));

                Log::info("Using RECALCULATED ATR-based stop for {$this->symbol}", [
                    'alert_id' => $this->alertId,
                    'atr' => $atr,
                    'multiplier' => $configMultiplier,
                    'calculated_pct' => round($calculatedPct, 2),
                    'bounded_pct' => round($stopPct, 2),
                    'old_suggested_pct' => $alert->suggested_trailing_stop_pct ?? null,
                ]);
            } else {
                // Fallback to 2% if no ATR data available
                $stopPct = 2.00;
                Log::warning("No ATR data available for alert {$this->alertId}, using fallback 2% stop");
            }

            $actualStopPrice = round($filledPrice * (1 - $stopPct / 100), 2);

            Log::info("Entry order {$entryOrder->alpaca_order_id} FILLED at \${$filledPrice}! Stop loss: {$stopPct}% = \${$actualStopPrice}");

            // CRITICAL: Check if position still exists before placing stop loss
            // If previous stop loss already filled, position will be 0 or not exist
            $positionResult = $alpacaService->checkPosition($this->symbol);
            $actualPositionQty = 0;

            if ($positionResult['success']) {
                $positionData = $this->parseStatusResponse($positionResult['output']);
                $actualPositionQty = (float) ($positionData['position']['qty'] ?? 0);

                // Cap stop price below current market price — Alpaca rejects stop orders at or above market.
                // This can happen on low-priced stocks where ATR min bound pushes the stop too high.
                $currentMarketPrice = (float) ($positionData['position']['current_price'] ?? 0);
                if ($currentMarketPrice > 0 && $actualStopPrice >= $currentMarketPrice) {
                    $adjustedStopPrice = floor($currentMarketPrice * 0.99 * 100) / 100;
                    Log::warning("Stop price \${$actualStopPrice} >= current price \${$currentMarketPrice} for {$this->symbol} — adjusting stop to \${$adjustedStopPrice} (1% below market)");
                    $actualStopPrice = $adjustedStopPrice;
                }

                if ($actualPositionQty <= 0) {
                    Log::warning("Position for {$this->symbol} is 0 or closed (qty={$actualPositionQty}), not placing stop loss for alert {$this->alertId}");

                    return;
                }

                Log::info("Position exists for {$this->symbol}: qty={$actualPositionQty}, proceeding with stop loss at \${$actualStopPrice}");
            } else {
                // If position doesn't exist, skip stop loss
                Log::warning("Could not find position for {$this->symbol}, not placing stop loss for alert {$this->alertId}");

                return;
            }

            // CRITICAL: Check if there are any pending stop orders for this symbol at Alpaca
            // This prevents race conditions where multiple jobs try to place stop losses simultaneously

            // First, check our database for existing stop orders
            $existingStopInDb = AlpacaOrder::query()
                ->where('symbol', $this->symbol)
                ->where('trade_alert_id', $this->alertId)
                ->where('side', 'sell')
                ->where('order_type', 'stop')
                ->whereIn('status', ['new', 'accepted', 'pending_new', 'held'])
                ->first();

            if ($existingStopInDb) {
                $existingQty = (int) floor((float) $existingStopInDb->qty);
                if ($existingQty == $actualPositionQty) {
                    Log::info("Stop order already exists with correct qty ({$existingQty}) for {$this->symbol} - alert {$this->alertId}. No action needed.");

                    return;
                }

                // Quantity mismatch - cancel old stop and place new one
                Log::warning("Stop order qty mismatch for {$this->symbol} - alert {$this->alertId}: existing={$existingQty}, actual_position={$actualPositionQty}. Canceling old stop and placing new one.");
                $cancelResult = $alpacaService->cancelOrderById($existingStopInDb->alpaca_order_id);
                if ($cancelResult['success']) {
                    $existingStopInDb->status = 'canceled';
                    $existingStopInDb->save();
                    Log::info("Canceled old stop order {$existingStopInDb->alpaca_order_id} for {$this->symbol}");
                }
                // Continue to place new stop with correct qty
            }

            // Then check Alpaca API
            $openOrdersResult = $alpacaService->getOrders('open', 100);
            if ($openOrdersResult['success']) {
                $openOrdersData = $this->parseStatusResponse($openOrdersResult['output']);
                if ($openOrdersData && isset($openOrdersData['orders'])) {
                    foreach ($openOrdersData['orders'] as $openOrder) {
                        if (isset($openOrder['symbol']) && $openOrder['symbol'] === $this->symbol &&
                            isset($openOrder['side']) && $openOrder['side'] === 'sell' &&
                            isset($openOrder['order_type']) && in_array($openOrder['order_type'], ['stop', 'stop_limit'])) {

                            $existingOrderQty = isset($openOrder['qty']) ? (int) floor((float) $openOrder['qty']) : 0;
                            if ($existingOrderQty == $actualPositionQty) {
                                Log::info("Stop order already exists at Alpaca with correct qty ({$existingOrderQty}) for {$this->symbol} - order {$openOrder['id']}");

                                return;
                            }

                            // Quantity mismatch - cancel old stop and place new one
                            Log::warning("Stop order qty mismatch at Alpaca for {$this->symbol}: existing={$existingOrderQty}, actual_position={$actualPositionQty}. Canceling old stop {$openOrder['id']} and placing new one.");
                            $cancelResult = $alpacaService->cancelOrderById($openOrder['id']);
                            if ($cancelResult['success']) {
                                Log::info("Canceled old stop order {$openOrder['id']} at Alpaca for {$this->symbol}");
                                // Update in DB if it exists
                                $dbOrder = AlpacaOrder::where('alpaca_order_id', $openOrder['id'])->first();
                                if ($dbOrder) {
                                    $dbOrder->status = 'canceled';
                                    $dbOrder->save();
                                }
                            }
                            // Continue to place new stop with correct qty
                        }
                    }
                }
            }

            // SAFETY: Use actual position quantity to prevent shorting
            $this->placeStopLoss($alpacaService, $entryOrder, $actualStopPrice, $actualPositionQty);

            return; // Don't throw exception, job is complete
        }

        // If order is cancelled or expired, check for partial fill before giving up
        if (in_array($status, ['canceled', 'cancelled', 'expired', 'rejected'])) {
            $filledQty = (float) ($order['filled_qty'] ?? 0);
            $filledPrice = (float) ($order['filled_avg_price'] ?? 0);

            if ($filledQty > 0 && $filledPrice > 0) {
                // Partial fill occurred before expiry/cancellation — place stop for filled qty
                Log::warning("Entry order {$entryOrder->alpaca_order_id} has terminal status: {$status} but was partially filled (qty={$filledQty} @ \${$filledPrice}). Placing stop loss for filled portion.");

                $alert = \App\Models\TradeAlert::find($this->alertId);
                $stopPct = null;
                $pipelineRun = strtoupper((string) ($alert->pipeline_run ?? ''));

                if ($pipelineRun === 'J') {
                    $maxLossPct = (float) config('trading.auto_alpaca_orders.max_loss_pipeline_j', 0.50);

                    if ($maxLossPct > 0) {
                        $stopPct = $maxLossPct;
                    }
                } elseif ($alert && isset($alert->atr) && $alert->atr > 0 && $filledPrice > 0) {
                    $atr = (float) $alert->atr;
                    $configMultiplier = (float) config('trading.auto_alpaca_orders.stop_loss_atr_multiplier', 2.0);
                    $minPct = (float) config('trading.auto_alpaca_orders.stop_loss_atr_min_pct', 0.50);
                    $maxPct = (float) config('trading.auto_alpaca_orders.stop_loss_atr_max_pct', 2.00);
                    $calculatedPct = (($atr * $configMultiplier) / $filledPrice) * 100.0;
                    $stopPct = max($minPct, min($maxPct, $calculatedPct));
                } else {
                    $stopPct = 2.00;
                    Log::warning("No ATR data for partial fill stop on alert {$this->alertId}, using fallback 2% stop");
                }

                $actualStopPrice = round($filledPrice * (1 - $stopPct / 100), 2);

                $positionResult = $alpacaService->checkPosition($this->symbol);
                if ($positionResult['success']) {
                    $positionData = $this->parseStatusResponse($positionResult['output']);
                    $actualPositionQty = (float) ($positionData['position']['qty'] ?? 0);

                    if ($actualPositionQty > 0) {
                        $this->placeStopLoss($alpacaService, $entryOrder, $actualStopPrice, $actualPositionQty);
                    } else {
                        Log::warning("Partial fill detected for {$this->symbol} but position no longer exists, skipping stop loss");
                    }
                } else {
                    Log::warning("Could not verify position for partial fill on {$this->symbol}, skipping stop loss");
                }

                return;
            }

            Log::warning("Entry order {$entryOrder->alpaca_order_id} has terminal status: {$status}, not placing stop loss");

            return; // Don't throw exception, just stop trying
        }

        // For pending/new/partially_filled status, release back to queue (avoids noisy ERROR logs)
        if (in_array($status, ['new', 'pending_new', 'accepted', 'partially_filled', 'pending_replace'])) {
            // If partially_filled and sitting for longer than threshold, protect the filled shares now.
            // The remaining unfilled shares will either fill later (triggering qty mismatch → stop update)
            // or cancel/expire (handled by the terminal status block above).
            if ($status === 'partially_filled') {
                $partialFillTimeoutMinutes = (float) config('trading.auto_alpaca_orders.partial_fill_stop_timeout_minutes', 2.0);
                $orderAge = $entryOrder->created_at
                    ? now('UTC')->diffInMinutes(\Carbon\Carbon::parse($entryOrder->created_at)->utc(), true)
                    : 0;

                $filledQty = (float) ($order['filled_qty'] ?? 0);
                $filledPrice = (float) ($order['filled_avg_price'] ?? 0);

                if ($orderAge >= $partialFillTimeoutMinutes && $filledQty > 0 && $filledPrice > 0) {
                    Log::warning("Entry order {$entryOrder->alpaca_order_id} partially_filled for {$orderAge} min (threshold={$partialFillTimeoutMinutes}). Protecting {$filledQty} filled shares immediately.", [
                        'symbol' => $this->symbol,
                        'filled_qty' => $filledQty,
                        'filled_price' => $filledPrice,
                    ]);

                    $alert = \App\Models\TradeAlert::find($this->alertId);
                    $stopPct = null;
                    $pipelineRun = strtoupper((string) ($alert->pipeline_run ?? ''));

                    if ($pipelineRun === 'J') {
                        $maxLossPct = (float) config('trading.auto_alpaca_orders.max_loss_pipeline_j', 0.50);

                        if ($maxLossPct > 0) {
                            $stopPct = $maxLossPct;
                        }
                    } elseif ($alert && isset($alert->atr) && $alert->atr > 0) {
                        $atr = (float) $alert->atr;
                        $configMultiplier = TradingSettingService::getStopLossAtrMultiplier();
                        $minPct = TradingSettingService::getStopLossAtrMinPct();
                        $maxPct = TradingSettingService::getStopLossAtrMaxPct();
                        $calculatedPct = (($atr * $configMultiplier) / $filledPrice) * 100.0;
                        $stopPct = max($minPct, min($maxPct, $calculatedPct));
                    } else {
                        $stopPct = 2.00;
                        Log::warning("No ATR data for partial fill timeout stop on alert {$this->alertId}, using fallback 2%");
                    }

                    $actualStopPrice = round($filledPrice * (1 - $stopPct / 100), 2);

                    // Cancel the remaining unfilled portion to avoid wash trade rejection
                    // when placing the sell stop against an open buy limit order.
                    $cancelResult = $alpacaService->cancelOrderById($entryOrder->alpaca_order_id);
                    $positionResult = $alpacaService->checkPosition($this->symbol);
                    if (! ($cancelResult['success'] ?? false)) {
                        Log::warning("Partial fill timeout: could not cancel remaining open buy for {$this->symbol}, stop placement may fail", [
                            'order_id' => $entryOrder->alpaca_order_id,
                        ]);
                    } else {
                        Log::info("Partial fill timeout: cancelled remaining open buy for {$this->symbol} before placing stop", [
                            'order_id' => $entryOrder->alpaca_order_id,
                        ]);
                    }

                    if ($positionResult['success']) {
                        $positionData = $this->parseStatusResponse($positionResult['output']);
                        $actualPositionQty = (float) ($positionData['position']['qty'] ?? 0);

                        if ($actualPositionQty > 0) {
                            $this->placeStopLoss($alpacaService, $entryOrder, $actualStopPrice, $actualPositionQty);
                        } else {
                            Log::warning("Partial fill timeout: {$this->symbol} position is 0, skipping stop loss");
                        }
                    } else {
                        Log::warning("Partial fill timeout: could not verify position for {$this->symbol}, skipping stop loss");
                    }

                    return;
                }
            }

            Log::info("Entry order {$entryOrder->alpaca_order_id} still pending (status={$status}), will check again in 30 seconds");
            $this->release($this->backoff);

            return;
        }

        // Unknown status
        Log::warning("Unknown order status '{$status}' for {$entryOrder->alpaca_order_id}");
        $this->release($this->backoff);
    }

    protected function placeStopLoss(AlpacaPythonService $alpacaService, AlpacaOrder $entryOrder, float $actualStopPrice, float $actualQty): void
    {
        $this->stopPlacementAttempts++;

        // Use the ATR-based stop price calculated in handle() method
        // Already has min/max bounds applied and is guaranteed to be below filled price
        Log::info("Placing ATR-based stop loss: filled_price=\${$entryOrder->filled_avg_price}, stop_price=\${$actualStopPrice}, actual_position_qty={$actualQty}");

        // SAFETY: Use actual position quantity to prevent selling more shares than we own
        $stopResult = $alpacaService->placeOrder(
            symbol: $this->symbol,
            qty: $actualQty,  // Use actual position quantity, not stored qty
            side: 'sell',
            stopPrice: $actualStopPrice,  // Use ATR-based stop with config bounds
            stopOnly: true
        );

        if (! $stopResult['success']) {
            $errorMsg = $stopResult['error'] ?? 'Unknown error';

            // Check if this is a duplicate order error (shares already held by another stop order)
            if (str_contains($errorMsg, 'insufficient qty available') ||
                str_contains($errorMsg, 'held_for_orders')) {
                Log::warning("Stop order already exists for {$this->symbol} (shares held by existing order) - alert {$this->alertId}. Error: {$errorMsg}");
            } elseif (str_contains($errorMsg, 'stop price must be less than current price')) {
                // Extract market price from Alpaca error JSON and retry with 1% below market.
                // This can happen when the stock gapped down between fill and stop placement,
                // making the ATR-based stop price higher than the current market price.
                $marketPrice = 0.0;
                if (preg_match('/"market_price":"([\d.]+)"/', $errorMsg, $m)) {
                    $marketPrice = (float) $m[1];
                }
                if ($marketPrice > 0 && $this->stopPlacementAttempts < self::MAX_STOP_PLACEMENT_ATTEMPTS) {
                    $adjustedStop = floor($marketPrice * 0.99 * 100) / 100;
                    if ($adjustedStop <= 0) {
                        Log::error("Stop price for {$this->symbol} would be \$0.00 after adjustment (market=\${$marketPrice}) — aborting stop placement");

                        return;
                    }

                    Log::warning("Stop price \${$actualStopPrice} >= market \${$marketPrice} for {$this->symbol} — retrying with \${$adjustedStop} (1% below market, attempt {$this->stopPlacementAttempts}/".self::MAX_STOP_PLACEMENT_ATTEMPTS.')');
                    $this->placeStopLoss($alpacaService, $entryOrder, $adjustedStop, $actualQty);

                    return;
                }

                if ($this->stopPlacementAttempts >= self::MAX_STOP_PLACEMENT_ATTEMPTS) {
                    Log::error("Failed to place stop loss for {$this->symbol} after max retries ({$this->stopPlacementAttempts} attempts, market=\${$marketPrice}, last stop=\${$actualStopPrice})");
                } else {
                    Log::error("Failed to place stop loss for {$this->symbol} (stop >= market, could not extract market price): {$errorMsg}");
                }
            } else {
                Log::error("Failed to place stop loss for alert {$this->alertId}: {$errorMsg}");
            }

            return; // Don't try to save non-existent order to database
        }

        // Parse the stop order response and save to database
        $stopOrderData = $this->parseStatusResponse($stopResult['output']);

        if ($stopOrderData && isset($stopOrderData['order'])) {
            $stopOrder = $stopOrderData['order'];

            AlpacaOrder::create([
                'alpaca_order_id' => $stopOrder['id'] ?? null,
                'client_order_id' => $stopOrder['client_order_id'] ?? null,
                'is_paper' => (bool) config('alpaca.paper_trading', true),
                'symbol' => $this->symbol,
                'side' => 'sell',
                'qty' => $actualQty,  // Use actual position quantity
                'stop_price' => $actualStopPrice,  // Use ATR-based stop
                'status' => $stopOrder['status'] ?? 'pending',
                'order_type' => $stopOrder['order_type'] ?? 'stop',
                'time_in_force' => $stopOrder['time_in_force'] ?? 'gtc',
                'submitted_at' => isset($stopOrder['submitted_at']) ? now()->parse($stopOrder['submitted_at']) : null,
                'raw_json' => $stopOrderData,
                'parent_alpaca_order_id' => $entryOrder->alpaca_order_id,
                'notes' => "ATR-based stop loss for alert_id:{$this->alertId}, entry_order:{$entryOrder->alpaca_order_id}, filled_at:\${$entryOrder->filled_avg_price}, actual_qty:{$actualQty}",
                'atr' => $entryOrder->atr,  // Inherit ATR from entry order
                'atr_pct' => $entryOrder->atr_pct,
            ]);

            Log::info("Stop loss order placed successfully for alert {$this->alertId}: {$this->symbol} qty={$actualQty} stop={$actualStopPrice} alpaca_id={$stopOrder['id']}");
        } else {
            Log::warning("Could not parse stop order response for alert {$this->alertId}");
        }
    }

    /**
     * Called by the queue worker when the job exhausts all retry attempts.
     * Logs the symbol and alert ID so the failed order can be investigated.
     */
    public function failed(\Throwable $e): void
    {
        Log::error("MonitorAlpacaOrderFillAndPlaceStopLoss permanently failed after {$this->tries} attempts", [
            'symbol' => $this->symbol,
            'alert_id' => $this->alertId,
            'entry_alpaca_order_db_id' => $this->entryAlpacaOrderDbId,
            'stop_price' => $this->stopPrice,
            'qty' => $this->qty,
            'error' => $e->getMessage(),
        ]);
    }

    protected function parseStatusResponse(string $output): ?array
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
}
