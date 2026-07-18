<?php

namespace App\Http\Controllers;

use App\Models\AlpacaOrder;
use App\Services\AlpacaPythonService;
use App\Services\TradingSettingService;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class AlpacaOrderController extends Controller
{
    public function index(AlpacaPythonService $alpacaPythonService): Response
    {
        $today = now('America/New_York')->toDateString();
        $startDate = request('start_date', $today);
        $endDate = request('end_date', $today);
        $pipeline = request('pipeline');
        $mlThreshold = request('ml_threshold') !== null ? (float) request('ml_threshold') : null;
        $hideVwapBlocked = request()->boolean('hide_vwap_blocked');

        $query = AlpacaOrder::query()
            ->orderBy('created_at', 'desc');

        // Apply date filters using datetime range to allow index use
        if ($startDate) {
            $query->where('created_at', '>=', $startDate.' 00:00:00');
        }
        if ($endDate) {
            $query->where('created_at', '<=', $endDate.' 23:59:59');
        }

        // Filter by pipeline if specified (via tradeAlert relationship)
        if ($pipeline) {
            $query->whereHas('tradeAlert', fn ($q) => $q->where('pipeline_run', $pipeline));
        }

        // Filter by ML threshold if specified
        if ($mlThreshold === -1.0) {
            // .env mode: use per-pipeline thresholds from settings
            $mlThresholds = TradingSettingService::getAllPipelineMlThresholds();
            $query->whereHas('tradeAlert', function ($q) use ($mlThresholds) {
                $caseSql = 'CASE';
                $bindings = [];
                foreach ($mlThresholds as $pipeline => $threshold) {
                    $upper = strtoupper($pipeline);
                    $caseSql .= ' WHEN pipeline_run = ? THEN ml_win_prob >= ?';
                    $bindings[] = $upper;
                    $bindings[] = $threshold;
                }
                $caseSql .= ' ELSE 1 END';
                $q->whereRaw($caseSql, $bindings);
            });
        } elseif ($mlThreshold !== null) {
            $query->whereHas('tradeAlert', fn ($q) => $q->where('ml_win_prob', '>=', $mlThreshold));
        }

        // Hide trades where the benchmark was below VWAP (or too far below intraday high).
        // Pre-computes blocked time ranges in one fast query, then filters orders by time.
        if ($hideVwapBlocked) {
            $benchmarkSymbol = TradingSettingService::getBenchmarkSymbol();
            $maxPctBelowHigh = TradingSettingService::getBenchmarkMaxPctBelowHigh();

            // Fetch all benchmark bars and compute blocked windows (5-min wide each)
            $bars = DB::connection('mysql')
                ->table('five_minute_prices')
                ->where('symbol', $benchmarkSymbol)
                ->where('asset_type', 'stock')
                ->whereNotNull('vwap')
                ->whereBetween('trading_date_est', [$startDate, $endDate])
                ->orderBy('ts_est')
                ->get(['ts_est', 'price', 'vwap_dist_pct']);

            if ($bars->isNotEmpty()) {
                $highs = $bars->groupBy(fn ($b) => substr($b->ts_est, 0, 10))
                    ->map(fn ($day) => $day->max('price'));

                // Each blocked bar creates a 5-min exclusion window
                $blockedWindows = [];
                foreach ($bars as $bar) {
                    $dist = (float) $bar->vwap_dist_pct;
                    $blocked = $dist < 0;

                    if (! $blocked && $maxPctBelowHigh !== null) {
                        $date = substr($bar->ts_est, 0, 10);
                        $high = $highs->get($date, 0);
                        if ($high > 0) {
                            $blocked = ((($high - (float) $bar->price) / $high) * 100) >= $maxPctBelowHigh;
                        }
                    }

                    if ($blocked) {
                        $from = $bar->ts_est;
                        $to = Carbon::parse($bar->ts_est, 'America/New_York')->addMinutes(5)->format('Y-m-d H:i:s');
                        $blockedWindows[] = ['from' => $from, 'to' => $to];
                    }
                }

                if (! empty($blockedWindows)) {
                    $query->where(function ($q) use ($blockedWindows) {
                        $q->whereDoesntHave('tradeAlert')
                            ->orWhereHas('tradeAlert', function ($taq) use ($blockedWindows) {
                                foreach ($blockedWindows as $win) {
                                    $taq->whereNotBetween('entry_ts_est', [$win['from'], $win['to']]);
                                }
                            });
                    });
                }
            }
        }

        $orders = $query->with('tradeAlert:id,ml_win_prob,version,pipeline_run')->paginate(500)->withQueryString();

        // Remove duplicate filled sells for the same parent_alpaca_order_id.
        // Prefer real orders over reconciled ones; otherwise keep the latest.
        $deduped = $orders->getCollection()->reduce(function ($carry, $order) {
            if ($order->side === 'sell' && $order->status === 'filled' && $order->parent_alpaca_order_id) {
                $key = $order->parent_alpaca_order_id;
                $isReconciled = $order->alpaca_order_id && str_starts_with($order->alpaca_order_id, 'reconciled-');

                if (! isset($carry[$key])) {
                    $carry[$key] = $order;
                } else {
                    $existingIsReconciled = $carry[$key]->alpaca_order_id
                        && str_starts_with($carry[$key]->alpaca_order_id, 'reconciled-');

                    // Prefer non-reconciled over reconciled, then higher id
                    if ($existingIsReconciled && ! $isReconciled) {
                        $carry[$key] = $order;
                    } elseif (! $existingIsReconciled && $isReconciled) {
                        // keep existing (real) order
                    } elseif ($order->id > $carry[$key]->id) {
                        $carry[$key] = $order;
                    }
                }
            } else {
                $carry['_'.$order->id] = $order;
            }

            return $carry;
        }, []);

        // Rebuild the paginator with deduped items sorted by created_at desc.
        // Hide synthetic reconciliation sells so the real stop-loss sell remains the visible row.
        $deduped = collect($deduped)
            ->reject(function ($order) {
                return $order->side === 'sell'
                    && $order->alpaca_order_id
                    && str_starts_with($order->alpaca_order_id, 'reconciled-');
            })
            ->sortByDesc(fn ($o) => $o->created_at->timestamp)
            ->values();
        $orders->setCollection($deduped);

        // Get unique symbols from current page
        $symbols = $orders->pluck('symbol')->unique()->values()->toArray();

        // Get asset IDs for symbols
        $assetIds = [];
        if (! empty($symbols)) {
            $assets = DB::table('asset_info')
                ->select('symbol', 'id')
                ->whereIn('symbol', $symbols)
                ->where('asset_type', 'stock')
                ->get();

            foreach ($assets as $asset) {
                $assetIds[$asset->symbol] = $asset->id;
            }
        }

        // Get latest prices for these symbols from one_minute_prices
        // Use a JOIN against a grouped subquery instead of a correlated IN() for performance
        $currentPrices = [];
        if (! empty($symbols)) {
            $placeholders = implode(',', array_fill(0, count($symbols), '?'));
            $oneDayAgo = now()->subHours(24)->format('Y-m-d H:i:s');
            $prices = DB::select("
                SELECT p.symbol, p.price, p.ts_est
                FROM one_minute_prices p
                INNER JOIN (
                    SELECT symbol, MAX(ts_est) AS max_ts
                    FROM one_minute_prices
                    WHERE symbol IN ({$placeholders})
                      AND ts_est >= ?
                    GROUP BY symbol
                ) latest ON p.symbol = latest.symbol AND p.ts_est = latest.max_ts
                WHERE p.symbol IN ({$placeholders})
            ", array_merge($symbols, [$oneDayAgo], $symbols));

            foreach ($prices as $price) {
                $currentPrices[$price->symbol] = [
                    'price' => $price->price,
                    'timestamp' => $price->ts_est,
                ];
            }
        }

        // Get current positions from Alpaca (cached for 30s to avoid slow Python subprocess on every load)
        $positions = Cache::remember('alpaca_positions', 30, function () use ($alpacaPythonService) {
            $result = [];
            try {
                $positionsResult = $alpacaPythonService->runScript('get_positions.py');
                if ($positionsResult['success']) {
                    $positionsData = json_decode($positionsResult['output'], true);
                    foreach ($positionsData as $position) {
                        $result[$position['symbol']] = [
                            'qty' => floor((float) $position['qty']),
                            'qty_available' => floor((float) $position['qty_available']),
                        ];
                    }
                }
            } catch (\Throwable $e) {
                \Log::warning("Failed to fetch positions for orders page: {$e->getMessage()}");
            }

            return $result;
        });

        // Build map of buy order alpaca_order_id -> realized sell fill price.
        // Uses parent_alpaca_order_id for precise buy→sell matching instead of
        // FIFO, which can produce misleading P/L when manual and pipeline orders
        // on the same symbol are intermixed.
        $realizedSellPrices = [];
        $pageSymbols = $orders->getCollection()->pluck('symbol')->unique()->values()->toArray();

        if (! empty($pageSymbols)) {
            // Phase 1: Exact parent-linked matching (within date range).
            // Only keep matches where the parent_alpaca_order_id actually exists
            // as a buy's alpaca_order_id. Alpaca sometimes assigns the stop-loss
            // order ID as parent instead of the entry order ID.
            $matchedSells = AlpacaOrder::whereIn('symbol', $pageSymbols)
                ->where('side', 'sell')
                ->where('status', 'filled')
                ->where('filled_qty', '>', 0)
                ->whereNotNull('filled_avg_price')
                ->whereNotNull('parent_alpaca_order_id')
                ->when($startDate, fn ($q) => $q->where('created_at', '>=', $startDate.' 00:00:00'))
                ->when($endDate, fn ($q) => $q->where('created_at', '<=', $endDate.' 23:59:59'))
                ->get(['filled_avg_price', 'filled_qty', 'parent_alpaca_order_id', 'alpaca_order_id', 'symbol', 'created_at']);

            $validBuyIds = AlpacaOrder::whereIn('symbol', $pageSymbols)
                ->where('side', 'buy')
                ->where('status', 'filled')
                ->whereNotNull('alpaca_order_id')
                ->when($startDate, fn ($q) => $q->where('created_at', '>=', $startDate.' 00:00:00'))
                ->when($endDate, fn ($q) => $q->where('created_at', '<=', $endDate.' 23:59:59'))
                ->pluck('alpaca_order_id')
                ->toArray();

            $phase1UnmatchedSells = [];

            foreach ($matchedSells as $sell) {
                $parentId = $sell->parent_alpaca_order_id;

                if (! in_array($parentId, $validBuyIds, true)) {
                    // Parent doesn't resolve to a real buy — treat as orphan
                    $phase1UnmatchedSells[] = $sell;

                    continue;
                }

                if (isset($realizedSellPrices[$parentId])) {
                    $existing = $realizedSellPrices[$parentId];
                    $totalQty = (float) $existing['qty'] + (float) $sell->filled_qty;
                    $weightedPrice = (
                        (float) $existing['price'] * (float) $existing['qty']
                        + (float) $sell->filled_avg_price * (float) $sell->filled_qty
                    ) / $totalQty;
                    $realizedSellPrices[$parentId] = [
                        'price' => (string) round($weightedPrice, 2),
                        'qty' => (string) $totalQty,
                    ];
                } else {
                    $realizedSellPrices[$parentId] = [
                        'price' => (string) $sell->filled_avg_price,
                        'qty' => (string) $sell->filled_qty,
                    ];
                }
            }

            // Phase 2: FIFO-match all unmatched sells (NULL parents + Phase 1 rejects)
            $orphanSells = AlpacaOrder::whereIn('symbol', $pageSymbols)
                ->where('side', 'sell')
                ->where('status', 'filled')
                ->where('filled_qty', '>', 0)
                ->whereNotNull('filled_avg_price')
                ->whereNull('parent_alpaca_order_id')
                ->when($startDate, fn ($q) => $q->where('created_at', '>=', $startDate.' 00:00:00'))
                ->when($endDate, fn ($q) => $q->where('created_at', '<=', $endDate.' 23:59:59'))
                ->orderBy('created_at')
                ->get(['symbol', 'filled_avg_price', 'filled_qty', 'created_at']);

            // Merge Phase 1 rejects into the FIFO pool
            if ($phase1UnmatchedSells !== []) {
                $orphanSells = $orphanSells->concat($phase1UnmatchedSells)->sortBy('created_at');
            }

            if ($orphanSells->isNotEmpty()) {
                // Get all buys ordered by creation time for FIFO matching (within date range)
                $allBuys = AlpacaOrder::whereIn('symbol', $pageSymbols)
                    ->where('side', 'buy')
                    ->where('status', 'filled')
                    ->where('filled_qty', '>', 0)
                    ->whereNotNull('filled_avg_price')
                    ->when($startDate, fn ($q) => $q->where('created_at', '>=', $startDate.' 00:00:00'))
                    ->when($endDate, fn ($q) => $q->where('created_at', '<=', $endDate.' 23:59:59'))
                    ->orderBy('created_at')
                    ->get(['symbol', 'alpaca_order_id', 'filled_qty', 'filled_avg_price', 'created_at']);

                foreach ($orphanSells as $sell) {
                    $remainingQty = (float) $sell->filled_qty;

                    foreach ($allBuys as $buy) {
                        if ($buy->symbol !== $sell->symbol) {
                            continue;
                        }

                        $alreadySold = $realizedSellPrices[$buy->alpaca_order_id]['qty'] ?? 0;
                        $availableQty = max(0, (float) $buy->filled_qty - (float) $alreadySold);

                        if ($availableQty <= 0) {
                            continue;
                        }

                        $matchedQty = min($remainingQty, $availableQty);

                        if (isset($realizedSellPrices[$buy->alpaca_order_id])) {
                            $existing = $realizedSellPrices[$buy->alpaca_order_id];
                            $totalQty = (float) $existing['qty'] + $matchedQty;
                            $weightedPrice = (
                                (float) $existing['price'] * (float) $existing['qty']
                                + (float) $sell->filled_avg_price * $matchedQty
                            ) / $totalQty;
                            $realizedSellPrices[$buy->alpaca_order_id] = [
                                'price' => (string) round($weightedPrice, 2),
                                'qty' => (string) $totalQty,
                            ];
                        } else {
                            $realizedSellPrices[$buy->alpaca_order_id] = [
                                'price' => (string) $sell->filled_avg_price,
                                'qty' => (string) $matchedQty,
                            ];
                        }

                        $remainingQty -= $matchedQty;
                        if ($remainingQty <= 0) {
                            break;
                        }
                    }
                }
            }
        }

        $pipelineVersions = [];
        foreach (['a', 'b', 'c', 'd', 'e', 'f', 'g', 'h', 'i', 'j', 'k', 'l', 'm', 'n', 'o', 'p', 'q', 'r', 's'] as $p) {
            $key = 'trade_alert_'.$p.'_version';
            $upper = strtoupper($p);
            $pipelineVersions[strtoupper($p)] = config("app.{$key}", env("TRADE_ALERT_{$upper}_VERSION", 'v0.0'));
        }
        $pipelineVersions['X'] = 'v0.0';
        $pipelineVersions['BIASED1'] = 'v1.0-biased';
        $pipelineVersions['EXTERNAL'] = 'v0.0';
        $pipelineVersions['MANUAL'] = 'v0.0';

        return Inertia::render('alpaca-orders/index', [
            'orders' => $orders,
            'currentPrices' => $currentPrices,
            'positions' => $positions,
            'assetIds' => $assetIds,
            'realizedSellPrices' => $realizedSellPrices,
            'pipelineVersions' => $pipelineVersions,
            'pipelineMlThresholds' => TradingSettingService::getAllPipelineMlThresholds(),
            'filters' => [
                'start_date' => $startDate,
                'end_date' => $endDate,
                'pipeline' => $pipeline,
                'ml_threshold' => $mlThreshold,
                'hide_vwap_blocked' => request()->boolean('hide_vwap_blocked'),
            ],
        ]);
    }

    public function cancelBuyOrder(AlpacaOrder $order, AlpacaPythonService $alpacaPythonService): RedirectResponse
    {
        if ($order->side !== 'buy' || $order->status !== 'new' || floatval($order->filled_qty) !== 0.0) {
            return redirect()->back()->with('error', 'Can only cancel unfilled new buy orders');
        }

        try {
            $cancelResult = $alpacaPythonService->cancelOrderById($order->alpaca_order_id);

            if (! $cancelResult['success']) {
                throw new \Exception($cancelResult['error'] ?? 'Unknown error canceling order');
            }

            $order->update(['status' => 'canceled']);

            \Log::info("Cancelled unfilled buy order {$order->alpaca_order_id} for {$order->symbol}");

            return redirect()->back()->with('success', "Buy order for {$order->symbol} cancelled successfully");
        } catch (\Exception $e) {
            \Log::error("Failed to cancel buy order {$order->id}: {$e->getMessage()}");

            return redirect()->back()->with('error', 'Failed to cancel order: '.$e->getMessage());
        }
    }

    public function sell(AlpacaOrder $order, AlpacaPythonService $alpacaPythonService): RedirectResponse
    {
        // Validate that this is a filled or partially-filled buy order
        if ($order->side !== 'buy' || ! in_array($order->status, ['filled', 'partially_filled'], true)) {
            return redirect()->back()->with('error', 'Can only sell filled or partially-filled buy orders');
        }

        try {
            // Cancel any existing stop loss orders for this symbol to free up shares
            $cancelResult = $alpacaPythonService->cancelOrdersBySymbol($order->symbol);

            if ($cancelResult['success']) {
                \Log::info("Cancelled stop orders for {$order->symbol} before selling");

                // Update local database for canceled orders
                AlpacaOrder::where('symbol', $order->symbol)
                    ->where('side', 'sell')
                    ->whereIn('order_type', ['stop', 'stop_limit'])
                    ->whereIn('status', ['pending_new', 'accepted', 'new', 'held'])
                    ->update([
                        'status' => 'canceled',
                        'paper' => (bool) config('alpaca.paper_trading', true),
                    ]);
            } else {
                \Log::warning("Failed to cancel orders for {$order->symbol}: {$cancelResult['error']}");
            }

            // Get ACTUAL available quantity from Alpaca positions
            $positionsResult = $alpacaPythonService->runScript('get_positions.py');
            if (! $positionsResult['success']) {
                throw new \Exception('Failed to fetch positions from Alpaca');
            }

            $positions = json_decode($positionsResult['output'], true);
            $qtyToSell = null;

            foreach ($positions as $position) {
                if ($position['symbol'] === $order->symbol) {
                    $qtyToSell = floor((float) $position['qty_available']);
                    break;
                }
            }

            if ($qtyToSell === null || $qtyToSell <= 0) {
                throw new \Exception("No shares available to sell for {$order->symbol}");
            }

            // Place market sell order
            $result = $alpacaPythonService->placeOrder(
                symbol: $order->symbol,
                side: 'sell',
                qty: $qtyToSell,
                stopOnly: false
            );

            if (! $result['success']) {
                throw new \Exception($result['error'] ?? 'Unknown error placing sell order');
            }

            // Parse the JSON output
            $outputData = json_decode($result['output'], true);

            if (! $outputData || ! isset($outputData['order'])) {
                throw new \Exception('Invalid order response format');
            }

            // Store the sell order in database
            $sellOrderData = $outputData['order'];
            AlpacaOrder::create([
                'alpaca_order_id' => $sellOrderData['id'],
                'client_order_id' => $sellOrderData['client_order_id'] ?? null,
                'is_paper' => (bool) config('alpaca.paper_trading', true),
                'symbol' => $sellOrderData['symbol'],
                'side' => $sellOrderData['side'],
                'qty' => $sellOrderData['qty'],
                'filled_qty' => $sellOrderData['filled_qty'] ?? null,
                'filled_avg_price' => $sellOrderData['filled_avg_price'] ?? null,
                'order_type' => $sellOrderData['type'] ?? $sellOrderData['order_type'] ?? 'market',
                'status' => $sellOrderData['status'],
                'stop_price' => $sellOrderData['stop_price'] ?? null,
                'limit_price' => $sellOrderData['limit_price'] ?? null,
                'time_in_force' => $sellOrderData['time_in_force'],
                'submitted_at' => $sellOrderData['submitted_at'] ?? now(),
                'filled_at' => $sellOrderData['filled_at'] ?? null,
                'parent_alpaca_order_id' => $order->alpaca_order_id,
                'notes' => "Manual sell of order {$order->alpaca_order_id}",
                'atr' => $order->atr,
                'atr_pct' => $order->atr_pct,
            ]);

            return redirect()->back()->with('success', "Sell order placed for {$order->symbol}: {$qtyToSell} shares");
        } catch (\Throwable $e) {
            \Log::error("Failed to place sell order for {$order->symbol}: {$e->getMessage()}");

            return redirect()->back()->with('error', "Failed to place sell order: {$e->getMessage()}");
        }
    }
}
