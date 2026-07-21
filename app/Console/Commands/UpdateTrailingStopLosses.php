<?php

namespace App\Console\Commands;

use App\Models\AlpacaOrder;
use App\Services\AlpacaPythonService;
use App\Services\Trading\ProfitProtectionStopCalculator;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class UpdateTrailingStopLosses extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'alpaca:update-trailing-stops';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Update trailing stop losses to 1% below current price for all positions';

    /**
     * Execute the console command.
     */
    public function handle(AlpacaPythonService $alpacaPythonService)
    {
        $this->info('Updating trailing stop losses...');

        // Get current positions from Alpaca
        $positionsResult = $alpacaPythonService->runScript('get_positions.py');

        if (! $positionsResult['success']) {
            $this->error('Failed to get positions from Alpaca');

            return 1;
        }

        $positions = json_decode($positionsResult['output'], true);

        if (empty($positions)) {
            $this->info('No positions found');

            return 0;
        }

        $this->info('Found '.count($positions).' position(s)');

        // Get current prices from one_minute_prices
        $symbols = array_column($positions, 'symbol');
        $prices = DB::table('one_minute_prices')
            ->select('symbol', 'price')
            ->whereIn('symbol', $symbols)
            ->whereRaw('(symbol, ts_est) IN (
                SELECT symbol, MAX(ts_est)
                FROM one_minute_prices
                WHERE symbol IN ('.implode(',', array_fill(0, count($symbols), '?')).')
                GROUP BY symbol
            )', $symbols)
            ->get()
            ->keyBy('symbol');

        // Get current open orders from Alpaca
        $ordersResult = $alpacaPythonService->getOrders('open', 500);

        if (! $ordersResult['success']) {
            $this->error('Failed to get orders from Alpaca');

            return 1;
        }

        $ordersData = json_decode($ordersResult['output'], true);
        $openOrders = $ordersData['orders'] ?? [];

        // Index stop orders by symbol — keep highest stop price per symbol,
        // and track total count so we can detect and cancel duplicates
        $stopOrders = [];
        $stopOrderCounts = [];
        foreach ($openOrders as $order) {
            if ($order['order_type'] === 'stop' && $order['side'] === 'sell') {
                $sym = $order['symbol'];
                $stopOrderCounts[$sym] = ($stopOrderCounts[$sym] ?? 0) + 1;
                // Keep the highest stop price (most protective) as the canonical entry
                if (! isset($stopOrders[$sym]) || (float) $order['stop_price'] > (float) $stopOrders[$sym]['stop_price']) {
                    $stopOrders[$sym] = $order;
                }
            }
        }

        foreach ($positions as $position) {
            $symbol = $position['symbol'];
            $qty = (float) $position['qty'];

            if (! isset($prices[$symbol])) {
                $this->warn("No current price found for {$symbol}, skipping");

                continue;
            }

            // If Alpaca has multiple stop orders for this symbol, cancel all of them
            // and let this run place a single clean one
            if (($stopOrderCounts[$symbol] ?? 0) > 1) {
                $this->warn("{$symbol}: Found {$stopOrderCounts[$symbol]} duplicate stop orders — cancelling all");
                $alpacaPythonService->cancelOrdersBySymbol($symbol);
                AlpacaOrder::where('symbol', $symbol)
                    ->where('side', 'sell')
                    ->where('order_type', 'stop')
                    ->whereIn('status', ['new', 'pending_new', 'accepted', 'partially_filled'])
                    ->whereNull('canceled_at')
                    ->update(['status' => 'canceled', 'canceled_at' => now()]);
                unset($stopOrders[$symbol]); // force fresh placement below
            }

            $currentPrice = (float) $prices[$symbol]->price;

            // Use Alpaca's avg_entry_price for the CURRENT position — this is always accurate
            // regardless of whether our DB has been updated yet (avoids stale order lookups).
            $entryPrice = (float) ($position['avg_entry_price'] ?? 0);

            if ($entryPrice <= 0) {
                $this->warn("{$symbol}: No avg_entry_price on position, skipping");

                continue;
            }

            // Find the most recent filled buy order whose fill price is within 2% of the
            // current position's avg_entry_price — this guards against finding a stale order
            // from a previous trade on the same symbol.
            $buyOrder = AlpacaOrder::where('symbol', $symbol)
                ->where('side', 'buy')
                ->where('status', 'filled')
                ->whereBetween('filled_avg_price', [$entryPrice * 0.98, $entryPrice * 1.02])
                ->orderBy('filled_at', 'desc')
                ->first();

            // Derive initial stop: prefer the stored stop from our matching DB order,
            // otherwise fall back to 1% below the current position's entry price.
            // Guard against bad data: stop must not be more than 5% below entry price
            // (e.g. a scanner-derived stop of $0.22 on a $2.52 stock).
            $rawStopPrice = (float) ($buyOrder?->stop_price ?? 0);
            $initialStopPrice = ($rawStopPrice >= $entryPrice * 0.95)
                ? $rawStopPrice
                : round($entryPrice * 0.99, 2);
            $profitProtectionEnabled = ProfitProtectionStopCalculator::isEnabled();
            $activationPct = $profitProtectionEnabled
                ? ProfitProtectionStopCalculator::activationThresholdPct()  // 0.75%
                : 1.0;                                                       // legacy 1.0%
            $activationThreshold = $entryPrice * (1.0 + $activationPct / 100.0);

            // Check if we've gained enough to activate stop management
            if ($currentPrice < $activationThreshold) {
                $profitPct = (($currentPrice - $entryPrice) / $entryPrice) * 100;
                $this->line("{$symbol}: Not yet at +{$activationPct}% gain (current: \${$currentPrice}, entry: \${$entryPrice}, profit: ".number_format($profitPct, 2)."%, need: +{$activationPct}%), keeping initial stop at \${$initialStopPrice}");

                // Ensure initial stop order exists — check BOTH Alpaca open orders AND our DB
                // to avoid placing duplicates when a stop is in pending_new/new state
                $hasStopInDb = AlpacaOrder::where('symbol', $symbol)
                    ->where('side', 'sell')
                    ->where('order_type', 'stop')
                    ->whereIn('status', ['new', 'pending_new', 'accepted', 'partially_filled'])
                    ->whereNull('canceled_at')
                    ->exists();

                if (! isset($stopOrders[$symbol]) && ! $hasStopInDb) {
                    $this->info("{$symbol}: Creating initial stop order at \${$initialStopPrice}");

                    // Get current position qty
                    $positionCheckResult = $alpacaPythonService->checkPosition($symbol);
                    if (! $positionCheckResult['success']) {
                        $this->warn("{$symbol}: Could not verify position for initial stop");

                        continue;
                    }

                    $positionData = json_decode($positionCheckResult['output'], true);
                    $currentQty = (float) ($positionData['position']['qty'] ?? 0);

                    if ($currentQty <= 0) {
                        $this->warn("{$symbol}: Position no longer exists (qty={$currentQty})");

                        continue;
                    }

                    $result = $alpacaPythonService->placeOrder(
                        symbol: $symbol,
                        qty: floor($currentQty),
                        side: 'sell',
                        stopPrice: $initialStopPrice,
                        stopOnly: true
                    );

                    if ($result['success']) {
                        $this->saveOrderToDatabase($result['output']);
                        $this->info("{$symbol}: Successfully placed initial stop order");
                    } elseif (AlpacaPythonService::isWashTradeError($result['error'] ?? '')) {
                        $this->warn("{$symbol}: Wash trade detected — cancelling conflicting orders");
                        $alpacaPythonService->cancelOrdersBySymbol($symbol);
                    } else {
                        // Retry with progressively lower stop prices (same logic as trailing stop)
                        $errorMsg = $result['error'] ?? '';
                        $realMarket = AlpacaPythonService::extractMarketPriceFromError($errorMsg);

                        if ($realMarket !== null) {
                            $retryCount = 0;
                            $maxRetries = 5;

                            while ($retryCount < $maxRetries) {
                                $retryStop = round($realMarket * (1.0 - 0.005 * ($retryCount + 1)), 2);
                                $this->warn("{$symbol}: Initial stop \${$initialStopPrice} rejected (market=\${$realMarket}), retry #".($retryCount + 1)." at \${$retryStop}");

                                $retryResult = $alpacaPythonService->placeOrder(
                                    symbol: $symbol, qty: floor($currentQty),
                                    side: 'sell', stopPrice: $retryStop, stopOnly: true
                                );

                                if ($retryResult['success']) {
                                    $this->saveOrderToDatabase($retryResult['output']);
                                    $this->info("{$symbol}: Retry #".($retryCount + 1)." succeeded — stop at \${$retryStop}");

                                    break;
                                }

                                $retryCount++;
                            }

                            if ($retryCount >= $maxRetries) {
                                $this->error("{$symbol}: All {$maxRetries} initial stop retries failed — ".($retryResult['error'] ?? ''));
                            }
                        } else {
                            $this->error("{$symbol}: Failed to place initial stop order — {$errorMsg}");
                        }
                    }
                }

                continue;
            }

            $this->line("{$symbol}: Stop management ACTIVATED (current: \${$currentPrice} >= activation: \${$activationThreshold})");

            if ($profitProtectionEnabled) {
                // Tiered profit-protection: tightens at +0.75%, locks at +1.25% / +2.00%, trails above.
                $buyOrder = $buyOrder ?? AlpacaOrder::where('symbol', $symbol)
                    ->where('side', 'buy')->where('status', 'filled')
                    ->orderBy('filled_at', 'desc')->first();
                $atrPct = (float) ($buyOrder?->atr_pct ?? 0);
                $existingStopPrice = isset($stopOrders[$symbol])
                    ? (float) $stopOrders[$symbol]['stop_price']
                    : $initialStopPrice;
                $newStopPrice = ProfitProtectionStopCalculator::calculateStop(
                    entryPrice: $entryPrice,
                    sessionHighPrice: $currentPrice,
                    atrPct: $atrPct,
                    currentStop: $existingStopPrice,
                );
            } else {
                // Legacy: ATR-based or fixed-percentage trailing stop
                $newStopPrice = $this->calculateTrailingStop($symbol, $currentPrice);
            }

            // Check if there's an existing stop order
            if (isset($stopOrders[$symbol])) {
                $existingStopPrice = (float) $stopOrders[$symbol]['stop_price'];

                // Only update if new stop is higher than existing (trailing up)
                if ($newStopPrice > $existingStopPrice) {
                    $this->info("{$symbol}: Updating stop from \${$existingStopPrice} to \${$newStopPrice}");

                    // Cancel old stop order
                    $cancelResult = $alpacaPythonService->cancelOrdersBySymbol($symbol);

                    if (! $cancelResult['success']) {
                        $this->error("{$symbol}: Failed to cancel existing stop order");

                        continue;
                    }

                    // Sync cancellation to DB so the duplicate guard stays accurate
                    AlpacaOrder::where('symbol', $symbol)
                        ->where('side', 'sell')
                        ->where('order_type', 'stop')
                        ->whereIn('status', ['new', 'pending_new', 'accepted', 'partially_filled'])
                        ->whereNull('canceled_at')
                        ->update(['status' => 'canceled', 'canceled_at' => now()]);

                    // CRITICAL: Verify position still exists before placing new stop
                    $positionCheckResult = $alpacaPythonService->checkPosition($symbol);
                    if (! $positionCheckResult['success']) {
                        $this->warn("{$symbol}: Could not verify position, skipping stop placement");

                        continue;
                    }

                    $positionData = json_decode($positionCheckResult['output'], true);
                    $currentQty = (float) ($positionData['position']['qty'] ?? 0);

                    if ($currentQty <= 0) {
                        $this->warn("{$symbol}: Position no longer exists (qty={$currentQty}), skipping stop placement");

                        continue;
                    }

                    // Place new stop order with verified qty
                    $result = $alpacaPythonService->placeOrder(
                        symbol: $symbol,
                        qty: floor($currentQty),
                        side: 'sell',
                        stopPrice: $newStopPrice,
                        stopOnly: true
                    );

                    if ($result['success']) {
                        $this->saveOrderToDatabase($result['output']);
                        $this->info("{$symbol}: Successfully placed new stop order at \${$newStopPrice}");
                    } else {
                        $this->error("{$symbol}: Failed to place new stop order");
                    }
                } else {
                    $this->line("{$symbol}: Current stop \${$existingStopPrice} is still valid (new would be \${$newStopPrice})");
                }
            } else {
                // No stop order exists in Alpaca — also check DB before placing
                // to avoid duplicates when orders are in pending_new/new state
                $hasStopInDb = AlpacaOrder::where('symbol', $symbol)
                    ->where('side', 'sell')
                    ->where('order_type', 'stop')
                    ->whereIn('status', ['new', 'pending_new', 'accepted', 'partially_filled'])
                    ->whereNull('canceled_at')
                    ->exists();

                if ($hasStopInDb) {
                    $this->line("{$symbol}: Stop order exists in DB with pending status, skipping placement");

                    continue;
                }

                // No stop order exists, create one
                $this->info("{$symbol}: Creating initial stop order at \${$newStopPrice}");

                // CRITICAL: Verify position still exists before placing stop
                $positionCheckResult = $alpacaPythonService->checkPosition($symbol);
                if (! $positionCheckResult['success']) {
                    $this->warn("{$symbol}: Could not verify position, skipping stop placement");

                    continue;
                }

                $positionData = json_decode($positionCheckResult['output'], true);
                $currentQty = (float) ($positionData['position']['qty'] ?? 0);

                if ($currentQty <= 0) {
                    $this->warn("{$symbol}: Position no longer exists (qty={$currentQty}), skipping stop placement");

                    continue;
                }

                $result = $alpacaPythonService->placeOrder(
                    symbol: $symbol,
                    qty: floor($currentQty),
                    side: 'sell',
                    stopPrice: $newStopPrice,
                    stopOnly: true
                );

                if ($result['success']) {
                    $this->saveOrderToDatabase($result['output']);
                    $this->info("{$symbol}: Successfully placed stop order at \${$newStopPrice}");
                } else {
                    $errorMsg = $result['error'] ?? '';

                    // Wash trade: an opposite-side order is blocking. Cancel it.
                    if (AlpacaPythonService::isWashTradeError($errorMsg)) {
                        $this->warn("{$symbol}: Wash trade detected — cancelling conflicting orders");
                        $alpacaPythonService->cancelOrdersBySymbol($symbol);

                        continue;
                    }

                    $realMarket = AlpacaPythonService::extractMarketPriceFromError($errorMsg);

                    if ($realMarket !== null) {
                        // Retry up to 5 times, stepping stop price down by 0.5% each attempt
                        $retryCount = 0;
                        $maxRetries = 5;
                        $retryStop = round($realMarket * 0.995, 2);

                        while ($retryCount < $maxRetries) {
                            $retryStop = round($realMarket * (1.0 - 0.005 * ($retryCount + 1)), 2);
                            $this->warn("{$symbol}: Stop rejected (market=\${$realMarket}), retry #".($retryCount + 1)." at \${$retryStop}");

                            $retryResult = $alpacaPythonService->placeOrder(
                                symbol: $symbol, qty: floor($currentQty),
                                side: 'sell', stopPrice: $retryStop, stopOnly: true
                            );

                            if ($retryResult['success']) {
                                $this->saveOrderToDatabase($retryResult['output']);
                                $this->info("{$symbol}: Retry #".($retryCount + 1)." succeeded — stop at \${$retryStop}");

                                break;
                            }

                            $retryCount++;
                        }

                        if ($retryCount >= $maxRetries) {
                            $this->error("{$symbol}: All {$maxRetries} retries failed — ".($retryResult['error'] ?? ''));
                        }
                    } else {
                        $this->error("{$symbol}: Failed to place stop order — {$errorMsg}");
                    }
                }
            }
        }

        $this->info('Trailing stop loss update complete');

        return 0;
    }

    /**
     * Calculate trailing stop price using ATR or fixed percentage
     * Only called after 1% activation threshold is reached
     */
    private function calculateTrailingStop(string $symbol, float $currentPrice): float
    {
        $mode = config('trading.auto_alpaca_orders.stop_loss_mode', 'fixed');

        if ($mode === 'atr') {
            // Find the original buy order to get ATR data
            $buyOrder = AlpacaOrder::where('symbol', $symbol)
                ->where('side', 'buy')
                ->where('status', 'filled')
                ->orderBy('filled_at', 'desc')
                ->first();

            if ($buyOrder && $buyOrder->atr_pct > 0) {
                // Get raw ATR percentage from buy order
                $rawAtrPct = (float) $buyOrder->atr_pct;

                // Apply ATR multiplier from config (default 2.0x)
                $multiplier = config('trading.auto_alpaca_orders.stop_loss_atr_multiplier', 2.0);
                $trailingStopPct = $rawAtrPct * $multiplier;

                // Apply min/max bounds from config
                $minPct = config('trading.auto_alpaca_orders.stop_loss_atr_min_pct', 0.50);
                $maxPct = config('trading.auto_alpaca_orders.stop_loss_atr_max_pct', 2.00);
                $boundedPct = max($minPct, min($maxPct, $trailingStopPct));

                $stopPrice = round($currentPrice * (1 - $boundedPct / 100), 2);

                $this->line("{$symbol}: ATR {$rawAtrPct}% × {$multiplier} = {$trailingStopPct}% → bounded to {$boundedPct}% trailing stop");

                return $stopPrice;
            }

            // No ATR data available, fall back to fixed
            $this->warn("{$symbol}: No ATR data found, using fixed stop");
        }

        // Fixed percentage mode (default from config)
        $fixedPct = config('trading.auto_alpaca_orders.stop_loss_pct', 1.00);

        return round($currentPrice * (1 - $fixedPct / 100), 2);
    }

    /**
     * Save order details to alpaca_orders table
     */
    private function saveOrderToDatabase(string $jsonOutput): void
    {
        try {
            $data = json_decode($jsonOutput, true);
            $order = $data['order'] ?? null;

            if (! $order) {
                $this->warn('No order data found in response');

                return;
            }

            // Find original buy order for this symbol to get ATR data and parent ID.
            // Prefer the canceled stop's parent_alpaca_order_id so the replacement
            // keeps the correct buy link for P&L tracking.
            $buyOrder = null;
            $canceledStop = AlpacaOrder::where('symbol', $order['symbol'])
                ->where('side', 'sell')
                ->where('order_type', 'stop')
                ->where('status', 'canceled')
                ->whereNotNull('canceled_at')
                ->whereNotNull('parent_alpaca_order_id')
                ->orderByDesc('canceled_at')
                ->first();
            if ($canceledStop?->parent_alpaca_order_id) {
                $buyOrder = AlpacaOrder::where('alpaca_order_id', $canceledStop->parent_alpaca_order_id)
                    ->where('side', 'buy')
                    ->first();
            }
            $buyOrder ??= AlpacaOrder::where('symbol', $order['symbol'])
                ->where('side', 'buy')
                ->where('status', 'filled')
                ->orderBy('filled_at', 'desc')
                ->first();

            if (! $buyOrder) {
                Log::warning('[UpdateTrailingStop] Could not determine parent buy for trailing stop', [
                    'symbol' => $order['symbol'],
                    'new_stop_alpaca_id' => $order['id'],
                ]);
            }

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
                'parent_alpaca_order_id' => $buyOrder?->alpaca_order_id ?? $order['legs'][0]['id'] ?? null,
                'notes' => 'Trailing stop loss - automated',
                'atr' => $buyOrder?->atr,
                'atr_pct' => $buyOrder?->atr_pct,
            ]);
        } catch (\Exception $e) {
            $this->warn('Failed to save order to database: '.$e->getMessage());
        }
    }
}
