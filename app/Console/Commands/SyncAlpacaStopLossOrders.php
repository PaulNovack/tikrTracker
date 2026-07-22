<?php

namespace App\Console\Commands;

use App\Models\AlpacaOrder;
use App\Services\AlpacaPythonService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class SyncAlpacaStopLossOrders extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'alpaca:sync-stop-loss-orders {--dry-run : Run without updating database} {--all-orders : Sync all pending orders, not just stop losses}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Check and update status of open orders with Alpaca API (stop losses by default, all orders with --all-orders)';

    public function __construct(
        private AlpacaPythonService $alpacaService
    ) {
        parent::__construct();
    }

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $isDryRun = $this->option('dry-run');
        $allOrders = $this->option('all-orders');

        if ($isDryRun) {
            $this->info('Running in DRY RUN mode - no changes will be made');
        }

        if ($allOrders) {
            $this->info('Checking all open orders...');
        } else {
            $this->info('Checking open stop loss orders...');
        }

        // Get pending orders - either all or just stop losses
        $query = AlpacaOrder::query()
            ->whereIn('status', ['pending_new', 'new', 'accepted', 'pending_replace', 'partially_filled', 'pending_cancel'])
            ->whereNotNull('alpaca_order_id');

        if (! $allOrders) {
            // Only sync stop loss orders by default
            $query->where('order_type', 'stop')
                ->where('side', 'sell');
        }

        $stopLossOrders = $query->get();

        // Auto-cancel stale unfilled buy limit orders if threshold is configured
        $cancelMinutes = (int) config('alpaca.unfilled_order_cancel_minutes', 0);
        if ($cancelMinutes > 0) {
            $this->cancelStaleUnfilledOrders($cancelMinutes, $isDryRun);
        }

        // Reconcile DB orders with Alpaca positions — catches manual sells done outside the system
        // Runs once per cycle regardless of whether there are open orders to sync.
        try {
            $this->reconcileOrphanedPositions($isDryRun);
        } catch (\Throwable $e) {
            $this->error("  Position reconciliation error: {$e->getMessage()}");
            Log::channel('stale-alerts')->error('[Sync] Exception during position reconciliation', [
                'error' => $e->getMessage(),
            ]);
        }

        // Fix any filled/partially-filled sells that still have null parent_alpaca_order_id.
        // These can accumulate from UpdateTrailingStopLosses placing stops before the sync
        // auto-link runs, or from sell orders reconciled by reconcileOrphanedPositions.
        $this->fixNullParentSells($isDryRun);

        if ($stopLossOrders->isEmpty()) {
            $this->info($allOrders ? 'No open orders found.' : 'No open stop loss orders found.');

            // Even with no open orders, still fix any filled sells with null parent.
            $this->fixNullParentSells($isDryRun);

            return Command::SUCCESS;
        }

        $this->info("Found {$stopLossOrders->count()} open orders to check.");

        $updated = 0;
        $filled = 0;
        $canceled = 0;
        $errors = 0;

        foreach ($stopLossOrders as $order) {
            try {
                $this->line("Checking order {$order->symbol} (ID: {$order->alpaca_order_id})...");

                // Check order status with Alpaca API
                $result = $this->alpacaService->checkOrderStatus($order->alpaca_order_id);

                if (! $result['success']) {
                    if ($this->isOrderNotFoundError($result['error'] ?? '')) {
                        $this->warn('  Order not found in Alpaca; marking as canceled locally');

                        Log::warning("Stop loss order not found in Alpaca, marking canceled locally: {$order->alpaca_order_id}", [
                            'order_id' => $order->id,
                            'alpaca_order_id' => $order->alpaca_order_id,
                            'status' => $order->status,
                            'error' => $result['error'],
                        ]);

                        if (! $isDryRun) {
                            $order->update([
                                'status' => 'canceled',
                                'canceled_at' => $order->canceled_at ?: now(),
                                'updated_at' => now(),
                                'paper' => (bool) config('alpaca.paper_trading', true),
                            ]);
                            $updated++;
                            $canceled++;
                        } else {
                            $this->comment('  [DRY RUN] Would mark order as canceled');
                        }

                        continue;
                    }

                    $this->warn("  Failed to check status: {$result['error']}");
                    Log::warning("Failed to check stop loss order status: {$order->alpaca_order_id}", [
                        'error' => $result['error'],
                    ]);
                    $errors++;

                    continue;
                }

                // Parse response
                $statusData = $this->parseStatusResponse($result['output']);

                if (! $statusData || ! isset($statusData['order'])) {
                    $this->warn('  Could not parse API response');
                    Log::warning("Could not parse stop loss order response: {$order->alpaca_order_id}");
                    $errors++;

                    continue;
                }

                $apiOrder = $statusData['order'];
                $newStatus = $apiOrder['status'] ?? 'unknown';

                // Auto-link: fix null parent_alpaca_order_id on ANY sell order
                // regardless of status change. Runs on every sync cycle so
                // newly-created stop-loss orders get their parent buy linked ASAP.
                if ($order->side === 'sell' && $order->parent_alpaca_order_id === null) {
                    $matchingBuy = AlpacaOrder::where('symbol', $order->symbol)
                        ->where('side', 'buy')
                        ->where('status', 'filled')
                        ->whereNotNull('alpaca_order_id')
                        ->orderBy('filled_at', 'desc')
                        ->first();

                    if ($matchingBuy) {
                        $order->update(['parent_alpaca_order_id' => $matchingBuy->alpaca_order_id]);
                        $order->refresh();
                        Log::info("[Sync] auto-linked sell {$order->alpaca_order_id} ({$order->symbol}) to buy {$matchingBuy->alpaca_order_id}");
                        $this->line("  Auto-linked to buy {$matchingBuy->alpaca_order_id}");
                    }
                }

                // Check if status has changed
                if ($newStatus === $order->status) {
                    $this->line("  Status unchanged: {$newStatus}");

                    continue;
                }

                // Prepare update data
                $updateData = [
                    'status' => $newStatus,
                    'filled_qty' => $apiOrder['filled_qty'] ?? $order->filled_qty,
                    'filled_avg_price' => $apiOrder['filled_avg_price'] ?? $order->filled_avg_price,
                    'updated_at' => now(),
                    'paper' => (bool) config('alpaca.paper_trading', true),
                ];

                // Set filled_at timestamp if order is filled
                if ($newStatus === 'filled') {
                    if (isset($apiOrder['filled_at'])) {
                        $updateData['filled_at'] = now()->parse($apiOrder['filled_at']);
                    } else {
                        $updateData['filled_at'] = now();
                    }
                }

                // Set canceled_at timestamp if order is canceled
                if (in_array($newStatus, ['canceled', 'cancelled'])) {
                    if (isset($apiOrder['canceled_at'])) {
                        $updateData['canceled_at'] = now()->parse($apiOrder['canceled_at']);
                    } elseif (! $order->canceled_at) {
                        $updateData['canceled_at'] = now();
                    }
                }

                // Log the change
                $orderType = ($order->order_type === 'stop' && $order->side === 'sell') ? 'Stop loss' : ucfirst($order->order_type);
                $logMessage = "{$orderType} order {$order->symbol} status changed: {$order->status} → {$newStatus}";

                if ($newStatus === 'filled') {
                    $filledPrice = $updateData['filled_avg_price'] ?? 'N/A';
                    $filledQty = $updateData['filled_qty'] ?? 'N/A';
                    $logMessage .= " | Filled: {$filledQty} shares @ \${$filledPrice}";
                    $this->info("  ✓ FILLED: {$filledQty} shares @ \${$filledPrice}");
                    $filled++;
                } elseif (in_array($newStatus, ['canceled', 'cancelled'])) {
                    $this->warn('  ✗ CANCELED');
                    $canceled++;
                } else {
                    $this->line("  → Status: {$order->status} → {$newStatus}");
                }

                Log::info($logMessage, [
                    'order_id' => $order->id,
                    'alpaca_order_id' => $order->alpaca_order_id,
                    'old_status' => $order->status,
                    'new_status' => $newStatus,
                    'stop_price' => $order->stop_price,
                    'current_price' => $order->filled_avg_price,
                    'symbol' => $order->symbol,
                ]);

                // Update database
                if (! $isDryRun) {
                    $order->update($updateData);
                    $updated++;
                } else {
                    $this->comment('  [DRY RUN] Would update order');
                }

                // Small delay to avoid rate limiting
                usleep(100000); // 0.1 second
            } catch (\Exception $e) {
                $this->error("  Error: {$e->getMessage()}");
                Log::error("Exception checking stop loss order: {$order->alpaca_order_id}", [
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]);
                $errors++;
            }
        }

        $this->newLine();
        $this->info('=== Sync Complete ===');
        $this->info("Checked: {$stopLossOrders->count()} orders");
        $this->info("Updated: {$updated}");
        $this->info("Filled: {$filled}");

        if ($canceled > 0) {
            $this->warn("Canceled: {$canceled}");
        }

        if ($errors > 0) {
            $this->error("Errors: {$errors}");
        }

        // Final pass: backfill parent_alpaca_order_id on already-filled sells
        // that slipped through before the auto-link was added.
        $this->fixNullParentSells($isDryRun);

        return Command::SUCCESS;
    }

    /**
     * Cancel unfilled buy limit orders that have been open longer than the configured threshold.
     */
    protected function cancelStaleUnfilledOrders(int $cancelMinutes, bool $isDryRun): void
    {
        $cutoff = now()->subMinutes($cancelMinutes);

        $staleOrders = AlpacaOrder::query()
            ->where('side', 'buy')
            ->where('order_type', 'limit')
            ->whereIn('status', ['pending_new', 'new', 'accepted', 'pending_replace'])
            ->whereNotNull('alpaca_order_id')
            ->where('submitted_at', '<=', $cutoff)
            ->get();

        if ($staleOrders->isEmpty()) {
            return;
        }

        $this->warn("Found {$staleOrders->count()} stale unfilled buy limit order(s) older than {$cancelMinutes} minutes — canceling...");

        foreach ($staleOrders as $order) {
            try {
                $age = now()->diffInMinutes($order->submitted_at);
                $this->warn("  Canceling {$order->symbol} BUY limit (submitted {$age}min ago, limit: \${$order->stop_price})");

                if (! $isDryRun) {
                    $result = $this->alpacaService->cancelOrderById($order->alpaca_order_id);

                    if ($result['success']) {
                        $order->update([
                            'status' => 'canceled',
                            'canceled_at' => now(),
                            'updated_at' => now(),
                        ]);
                        $this->warn('  ✗ Canceled (stale unfilled)');
                        Log::info("Auto-canceled stale unfilled buy limit order: {$order->symbol}", [
                            'order_id' => $order->id,
                            'alpaca_order_id' => $order->alpaca_order_id,
                            'submitted_at' => $order->submitted_at,
                            'age_minutes' => $age,
                            'cancel_threshold_minutes' => $cancelMinutes,
                        ]);
                    } else {
                        $this->error("  Failed to cancel: {$result['error']}");
                        Log::error("Failed to auto-cancel stale unfilled order: {$order->symbol}", [
                            'order_id' => $order->id,
                            'error' => $result['error'],
                        ]);
                    }
                } else {
                    $this->comment('  [DRY RUN] Would cancel order');
                }

                usleep(100000); // 0.1 second
            } catch (\Exception $e) {
                $this->error("  Error canceling {$order->symbol}: {$e->getMessage()}");
                Log::error("Exception auto-canceling stale order: {$order->alpaca_order_id}", [
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * Reconcile DB state with Alpaca positions.
     *
     * Finds buy orders that are "filled" in the DB but no longer held on Alpaca
     * (e.g. manually sold on the Alpaca dashboard). Queries Alpaca's closed orders
     * to capture the actual sell price, records a reconciling sell order so P&L
     * stays accurate, and logs to the stale-alerts channel.
     */
    protected function reconcileOrphanedPositions(bool $isDryRun): void
    {
        // 1. Fetch all current Alpaca positions (cached 15s to avoid hammering the API)
        $positionsResult = $this->alpacaService->runScript('get_positions.py');
        if (! $positionsResult['success']) {
            Log::channel('stale-alerts')->warning('[Sync] Could not fetch Alpaca positions for reconciliation', [
                'error' => $positionsResult['error'],
            ]);

            return;
        }

        $alpacaPositions = [];
        try {
            $parsed = json_decode($positionsResult['output'], true, 512, JSON_THROW_ON_ERROR);
            foreach ($parsed as $pos) {
                $sym = strtoupper($pos['symbol'] ?? '');
                $alpacaPositions[$sym] = (float) ($pos['qty'] ?? 0);
            }
        } catch (\JsonException $e) {
            Log::channel('stale-alerts')->warning('[Sync] Failed to parse Alpaca positions JSON', [
                'error' => $e->getMessage(),
            ]);

            return;
        }

        // 2. Get today's filled buy orders — all trades are same-day, so only
        // today's buys need reconciliation. Historical buys are already closed
        // on Alpaca and would produce false mismatches.
        $today = now('America/New_York')->toDateString();
        $openBuys = AlpacaOrder::query()
            ->where('side', 'buy')
            ->where('status', 'filled')
            ->whereNull('parent_alpaca_order_id')       // not a sell
            ->whereNotNull('alpaca_order_id')
            ->whereNotNull('filled_qty')
            ->where('filled_qty', '>', 0)
            ->where('created_at', '>=', $today.' 00:00:00')
            ->where('created_at', '<=', $today.' 23:59:59')
            ->get();

        $reconciled = 0;
        $skippedNoSell = 0;

        foreach ($openBuys as $buyOrder) {
            $symbol = $buyOrder->symbol;
            $buyQty = (float) $buyOrder->filled_qty;

            // Check if Alpaca still holds this position (allow small rounding)
            $alpacaQty = $alpacaPositions[$symbol] ?? 0;
            if ($alpacaQty >= $buyQty * 0.95) {
                continue; // Position still fully held — nothing to reconcile
            }

            // Check how much has already been sold in the DB for THIS specific buy.
            // Using parent_alpaca_order_id ensures historical sells on the same symbol
            // don't falsely inflate the count and cause a legitimate open position to be skipped.
            $dbSellQty = (float) AlpacaOrder::query()
                ->where('parent_alpaca_order_id', $buyOrder->alpaca_order_id)
                ->where('side', 'sell')
                ->where('status', 'filled')
                ->sum('filled_qty');

            $remaining = $buyQty - $dbSellQty - $alpacaQty;
            if ($remaining <= 0.5) {
                continue; // Already matched up
            }

            $this->warn("  Position mismatch for {$symbol}: DB says {$buyQty} shares, Alpaca says {$alpacaQty}, DB sells total {$dbSellQty} — {$remaining} shares orphaned");

            // 3. Query Alpaca closed orders to find the sell that actually happened
            $closedResult = $this->alpacaService->getOrders(
                status: 'closed',
                limit: 50,
                startDate: now()->subDays(7)->format('Y-m-d'),
            );

            if (! $closedResult['success']) {
                $this->warn("  Could not fetch closed orders from Alpaca for {$symbol}");

                continue;
            }

            $foundSell = null;
            try {
                $closedData = json_decode($closedResult['output'], true, 512, JSON_THROW_ON_ERROR);
                $closedOrders = $closedData['orders'] ?? [];
                foreach ($closedOrders as $co) {
                    if (
                        strtoupper($co['symbol'] ?? '') === $symbol
                        && strtolower($co['side'] ?? '') === 'sell'
                        && ($co['status'] ?? '') === 'filled'
                        && (float) ($co['filled_qty'] ?? 0) > 0
                    ) {
                        $foundSell = $co;
                        break;
                    }
                }
            } catch (\JsonException $e) {
                $this->warn("  Could not parse closed orders JSON for {$symbol}");

                continue;
            }

            if ($foundSell === null) {
                $this->warn("  No matching closed sell found on Alpaca for {$symbol} — skipped");
                $skippedNoSell++;

                continue;
            }

            // 4. Record the reconciliation
            $sellQty = (float) ($foundSell['filled_qty'] ?? $remaining);
            $sellPrice = (float) ($foundSell['filled_avg_price'] ?? 0);
            $sellFilledAt = $foundSell['filled_at'] ?? now()->toISOString();

            $context = [
                'symbol' => $symbol,
                'buy_order_id' => $buyOrder->id,
                'buy_alpaca_id' => $buyOrder->alpaca_order_id,
                'alpaca_sell_order_id' => $foundSell['id'] ?? null,
                'alpaca_held_qty' => $alpacaQty,
                'buy_filled_qty' => $buyQty,
                'db_sell_qty_so_far' => $dbSellQty,
                'reconciled_qty' => $sellQty,
                'reconciled_price' => $sellPrice,
                'reconciled_at' => $sellFilledAt,
            ];

            Log::channel('stale-alerts')->info('[Sync] Reconciling orphaned position — recording sell from Alpaca closed orders', $context);

            if (! $isDryRun) {
                $alpacaSellOrderId = $foundSell['id'] ?? ('reconciled-'.$buyOrder->alpaca_order_id);
                $alpacaClientOrderId = $foundSell['client_order_id'] ?? null;
                $matchedQty = min($sellQty, $buyQty);

                // Use firstOrCreate to avoid duplicate entry errors on retries
                $existingOrder = AlpacaOrder::where('alpaca_order_id', $alpacaSellOrderId)->first();
                if ($existingOrder) {
                    $this->warn("  ⚠ Sell order {$alpacaSellOrderId} already recorded — skipping");

                    continue;
                }

                AlpacaOrder::create([
                    'alpaca_order_id' => $alpacaSellOrderId,
                    'client_order_id' => $alpacaClientOrderId,
                    'is_paper' => (bool) config('alpaca.paper_trading', true),
                    'symbol' => $symbol,
                    'side' => 'sell',
                    'qty' => $matchedQty,
                    'filled_qty' => $matchedQty,
                    'filled_avg_price' => $sellPrice,
                    'filled_at' => $sellFilledAt,
                    'order_type' => $foundSell['type'] ?? $foundSell['order_type'] ?? 'market',
                    'status' => 'filled',
                    'time_in_force' => $foundSell['time_in_force'] ?? 'day',
                    'submitted_at' => $foundSell['submitted_at'] ?? $sellFilledAt,
                    'parent_alpaca_order_id' => $buyOrder->alpaca_order_id,
                    'notes' => 'Reconciled: sell found in Alpaca closed orders',
                ]);
                $this->info("  ✓ Reconciled {$symbol}: {$matchedQty} shares @ \${$sellPrice}");

                $reconciled++;

                // Also update the buy order's notes
                $buyOrder->update(['notes' => ($buyOrder->notes ? $buyOrder->notes.'; ' : '').'Partially reconciled - manual external sell']);
            } else {
                $this->comment("  [DRY RUN] Would record reconciling sell for {$symbol}: {$sellQty} @ \${$sellPrice}");
            }
        }

        if ($reconciled > 0 || $skippedNoSell > 0) {
            $this->newLine();
            $this->info('=== Position Reconciliation Complete ===');
            $this->info("Reconciled: {$reconciled}");
            if ($skippedNoSell > 0) {
                $this->warn("Skipped (no closed sell found on Alpaca): {$skippedNoSell}");
            }
        }
    }

    /**
     * Fix any filled/partially-filled sells that still have null parent_alpaca_order_id.
     * Runs after the main sync loop and orphan reconciliation to catch sells that
     * already transitioned to filled before the sync could auto-link them.
     */
    protected function fixNullParentSells(bool $isDryRun): void
    {
        $orphanSells = AlpacaOrder::where('side', 'sell')
            ->whereIn('status', ['filled', 'partially_filled'])
            ->where('filled_qty', '>', 0)
            ->whereNull('parent_alpaca_order_id')
            ->get();

        if ($orphanSells->isEmpty()) {
            return;
        }

        $this->newLine();
        $this->info("Backfilling {$orphanSells->count()} filled sell(s) with null parent_alpaca_order_id...");

        foreach ($orphanSells as $sell) {
            $buy = AlpacaOrder::where('symbol', $sell->symbol)
                ->where('side', 'buy')
                ->where('status', 'filled')
                ->whereNotNull('alpaca_order_id')
                ->orderBy('filled_at', 'desc')
                ->first();

            if (! $buy) {
                $this->warn("  {$sell->symbol} (sell {$sell->alpaca_order_id}): no matching buy found");

                continue;
            }

            if ($isDryRun) {
                $this->line("  [DRY RUN] {$sell->symbol}: sell {$sell->alpaca_order_id} → buy {$buy->alpaca_order_id}");
            } else {
                $sell->update(['parent_alpaca_order_id' => $buy->alpaca_order_id]);
                Log::info("[Sync] backfilled parent_alpaca_order_id on sell {$sell->alpaca_order_id} ({$sell->symbol}) → buy {$buy->alpaca_order_id}");
                $this->line("  ✓ {$sell->symbol}: sell {$sell->alpaca_order_id} → buy {$buy->alpaca_order_id}");
            }
        }
    }

    protected function parseStatusResponse(string $output): ?array
    {
        // Try to extract JSON from output
        if (preg_match('/(\{.*\})/s', $output, $matches)) {
            $json = json_decode($matches[1], true);
            if (is_array($json)) {
                return $json;
            }
        }

        return null;
    }

    protected function isOrderNotFoundError(string $error): bool
    {
        return str_contains($error, '40410000')
            || str_contains(strtolower($error), 'order not found');
    }
}
