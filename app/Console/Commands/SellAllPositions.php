<?php

namespace App\Console\Commands;

use App\Models\AlpacaOrder;
use App\Services\AlpacaPythonService;
use Illuminate\Console\Command;

class SellAllPositions extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'alpaca:sell-all-positions';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Sell all Alpaca positions at market close (3:45 PM EST)';

    /**
     * Execute the console command.
     */
    public function handle(AlpacaPythonService $alpacaPythonService)
    {
        $this->info('Selling all Alpaca positions...');

        // Get current positions from Alpaca with retry logic
        $maxRetries = 3;
        $retryDelay = 5; // seconds
        $positionsResult = null;

        for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {
            $positionsResult = $alpacaPythonService->runScript('get_positions.py');

            if ($positionsResult['success']) {
                break;
            }

            if ($attempt < $maxRetries) {
                $this->warn("Failed to get positions (attempt {$attempt}/{$maxRetries}). Retrying in {$retryDelay}s...");
                sleep($retryDelay);
                $retryDelay *= 2; // Exponential backoff
            } else {
                $this->error("Failed to get positions from Alpaca after {$maxRetries} attempts");

                return 1;
            }
        }

        $positions = json_decode($positionsResult['output'], true);

        if (empty($positions)) {
            $this->info('No positions to sell');

            return 0;
        }

        $this->info('Found '.count($positions).' position(s) to sell');

        $successCount = 0;
        $failCount = 0;

        foreach ($positions as $position) {
            $symbol = $position['symbol'];
            // Use total qty including fractional shares for complete liquidation
            $qty = (float) $position['qty'];

            if ($qty <= 0) {
                $this->warn("{$symbol}: No shares to sell (qty: {$qty})");

                continue;
            }

            $this->info("{$symbol}: Selling {$qty} shares (available: {$position['qty_available']})...");

            // Cancel any existing stop orders for this symbol to free up shares.
            // Alpaca can report the cancel before the shares are fully released, so retry
            // until no open orders remain for the symbol or we exhaust the retry budget.
            if (! $this->cancelStopOrdersForSymbol($alpacaPythonService, $symbol)) {
                $this->error("{$symbol}: Unable to clear open stop orders after retries, skipping market sell");
                $failCount++;

                continue;
            }

            // Place market sell order with fractional flag to sell ALL shares
            $result = $alpacaPythonService->placeOrder(
                symbol: $symbol,
                qty: $qty,
                side: 'sell',
                fractional: true  // Allow fractional shares for complete liquidation
            );

            if ($result['success']) {
                $this->saveOrderToDatabase($result['output'], $symbol);
                $this->info("{$symbol}: Successfully placed sell order for {$qty} shares");
                $successCount++;
            } else {
                $this->error("{$symbol}: Failed to place sell order");
                $failCount++;
            }
        }

        $this->info("Sell all positions complete: {$successCount} successful, {$failCount} failed");

        return $failCount > 0 ? 1 : 0;
    }

    private function cancelStopOrdersForSymbol(AlpacaPythonService $alpacaPythonService, string $symbol): bool
    {
        $maxAttempts = 3;
        $waitSeconds = 1;

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            $cancelResult = $alpacaPythonService->cancelOrdersBySymbol($symbol);

            if (! $cancelResult['success']) {
                $this->warn("{$symbol}: Failed to request stop-order cancel (attempt {$attempt}/{$maxAttempts})");
            }

            sleep($waitSeconds);

            if ($this->hasOpenOrdersForSymbol($alpacaPythonService, $symbol)) {
                $this->warn("{$symbol}: Stop orders still open after cancel attempt {$attempt}/{$maxAttempts}");
                $waitSeconds = min($waitSeconds * 2, 5);

                continue;
            }

            $this->line("{$symbol}: Confirmed stop orders cancelled");

            return true;
        }

        return false;
    }

    private function hasOpenOrdersForSymbol(AlpacaPythonService $alpacaPythonService, string $symbol): bool
    {
        $ordersResult = $alpacaPythonService->getOrders('open', 500);

        if (! $ordersResult['success']) {
            $this->warn("{$symbol}: Could not verify open orders after cancel, will retry");

            return true;
        }

        $decoded = json_decode($ordersResult['output'], true);
        $orders = $this->extractOrdersFromResponse($decoded);

        return collect($orders)
            ->contains(fn (array $order): bool => strtoupper((string) ($order['symbol'] ?? '')) === strtoupper($symbol));
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function extractOrdersFromResponse(mixed $decoded): array
    {
        if (! is_array($decoded)) {
            return [];
        }

        if (isset($decoded['orders']) && is_array($decoded['orders'])) {
            return $decoded['orders'];
        }

        return array_is_list($decoded) ? $decoded : [];
    }

    /**
     * Save order details to alpaca_orders table.
     *
     * A single Alpaca close_position call may close multiple buy orders for the
     * same symbol, so we distribute the total filled sell quantity across all
     * unfilled buys in FIFO order, creating a separate DB sell record for each
     * linked to its parent buy via parent_alpaca_order_id.
     */
    private function saveOrderToDatabase(string $jsonOutput, string $symbol): void
    {
        try {
            $data = json_decode($jsonOutput, true);
            $order = $data['order'] ?? null;

            if (! $order) {
                $this->warn("{$symbol}: No order data found in response");

                return;
            }

            $totalSellQty = (float) ($order['filled_qty'] ?? $order['qty'] ?? 0);
            $sellPrice = (float) ($order['filled_avg_price'] ?? 0);

            // Find ALL unfilled buy orders for this symbol, ordered FIFO
            $unfilledBuys = AlpacaOrder::where('symbol', $symbol)
                ->where('side', 'buy')
                ->where('status', 'filled')
                ->whereDoesntHave('sellOrder', fn ($q) => $q->where('status', 'filled'))
                ->orderBy('filled_at', 'asc')
                ->get();

            if ($unfilledBuys->isEmpty()) {
                // Fallback: keep the single-record behavior for safety
                AlpacaOrder::create([
                    'alpaca_order_id' => $order['id'],
                    'client_order_id' => $order['client_order_id'] ?? null,
                    'is_paper' => (bool) config('alpaca.paper_trading', true),
                    'symbol' => $order['symbol'],
                    'side' => $order['side'],
                    'qty' => $order['qty'],
                    'filled_qty' => $order['filled_qty'] ?? null,
                    'filled_avg_price' => $order['filled_avg_price'] ?? null,
                    'order_type' => $order['order_type'] ?? $order['type'],
                    'status' => $order['status'],
                    'stop_price' => $order['stop_price'] ?? null,
                    'limit_price' => $order['limit_price'] ?? null,
                    'time_in_force' => $order['time_in_force'],
                    'submitted_at' => $order['submitted_at'] ?? null,
                    'filled_at' => $order['filled_at'] ?? null,
                    'parent_alpaca_order_id' => null,
                    'notes' => 'End of day sell - automated (no matching buy found)',
                ]);

                return;
            }

            $remaining = $totalSellQty;

            foreach ($unfilledBuys as $buyOrder) {
                if ($remaining <= 0) {
                    break;
                }

                $buyQty = (float) $buyOrder->filled_qty;
                $matchedQty = min($remaining, $buyQty);

                AlpacaOrder::create([
                    'alpaca_order_id' => $order['id'],
                    'client_order_id' => $order['client_order_id'] ?? null,
                    'is_paper' => (bool) config('alpaca.paper_trading', true),
                    'symbol' => $order['symbol'],
                    'side' => $order['side'],
                    'qty' => $matchedQty,
                    'filled_qty' => $matchedQty,
                    'filled_avg_price' => $sellPrice,
                    'order_type' => $order['order_type'] ?? $order['type'],
                    'status' => $order['status'],
                    'stop_price' => null,
                    'limit_price' => null,
                    'time_in_force' => $order['time_in_force'],
                    'submitted_at' => $order['submitted_at'] ?? null,
                    'filled_at' => $order['filled_at'] ?? null,
                    'parent_alpaca_order_id' => $buyOrder->alpaca_order_id,
                    'notes' => 'End of day sell - automated',
                    'atr' => $buyOrder->atr,
                    'atr_pct' => $buyOrder->atr_pct,
                ]);

                $this->line("{$symbol}: Linked {$matchedQty}/{$buyQty} shares to buy {$buyOrder->alpaca_order_id}");

                $remaining -= $matchedQty;
            }

            if ($remaining > 0.01) {
                $this->warn("{$symbol}: {$remaining} shares of EOD sell could not be matched to a buy order");
            }
        } catch (\Exception $e) {
            $this->warn("{$symbol}: Failed to save order to database: ".$e->getMessage());
        }
    }
}
