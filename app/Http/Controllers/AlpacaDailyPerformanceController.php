<?php

namespace App\Http\Controllers;

use App\Models\AlpacaOrder;
use App\Services\TradingSettingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class AlpacaDailyPerformanceController extends Controller
{
    public function index(Request $request)
    {
        // Default to last 7 days if no dates provided
        $startDate = $request->input('start_date') ?? now('America/New_York')->subDays(7)->format('Y-m-d');
        $endDate = $request->input('end_date') ?? now('America/New_York')->format('Y-m-d');
        $mlThreshold = $request->input('ml_threshold') !== null ? (float) $request->input('ml_threshold') : null;
        $mode = $request->input('mode', config('alpaca.paper_trading') ? 'paper' : 'live'); // 'live', 'paper', or 'all'
        $pipeline = $request->input('pipeline');

        // Get all orders — no status filter, matching AlpacaOrderController approach.
        // The per-symbol P&L section below filters to filled/partially_filled/canceled
        // with filled_qty > 0 for actual P&L computation.
        $query = AlpacaOrder::with('tradeAlert:id,ml_win_prob,version,pipeline_run')
            ->when($mode === 'live', fn ($q) => $q->where('is_paper', false))
            ->when($mode === 'paper', fn ($q) => $q->where('is_paper', true));

        // Apply date filters using datetime range (matching AlpacaOrderController)
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

        $orders = $query->orderBy('created_at', 'desc')->get();

        // When filtering by pipeline, sell orders (stops, limits) don't have a
        // tradeAlert linked to the pipeline, so they're missing from $orders.
        // Fetch them via parent_alpaca_order_id AND by symbol/date for sells
        // without a parent link (e.g. manual closes, trailing stop replacements).
        if ($pipeline && $orders->isNotEmpty()) {
            $buyIds = $orders->where('side', 'buy')->pluck('alpaca_order_id')->filter()->values()->toArray();
            $buySymbols = $orders->where('side', 'buy')->pluck('symbol')->unique()->values()->toArray();
            $buyDates = $orders->where('side', 'buy')->map(fn ($o) => $o->created_at->format('Y-m-d'))->unique()->values()->toArray();

            $sellOrders = collect();

            // Pass 1: match by parent_alpaca_order_id
            if ($buyIds !== []) {
                $sellOrders = AlpacaOrder::whereIn('parent_alpaca_order_id', $buyIds)
                    ->whereIn('status', ['filled', 'partially_filled', 'canceled'])
                    ->where('filled_qty', '>', 0)
                    ->get();
            }

            // Pass 2: match by symbol + date for sells without a parent link.
            // Must explicitly include null-parent sells because MySQL treats
            // NULL NOT IN (...) as NULL/false, silently dropping those rows.
            if ($buySymbols !== [] && $buyDates !== []) {
                $matchedParentIds = $sellOrders->pluck('parent_alpaca_order_id')->filter()->values()->toArray();
                $extraSells = AlpacaOrder::whereIn('symbol', $buySymbols)
                    ->whereIn('status', ['filled', 'partially_filled', 'canceled'])
                    ->where('filled_qty', '>', 0)
                    ->where('side', 'sell')
                    ->where(function ($q) use ($buyDates) {
                        foreach ($buyDates as $i => $date) {
                            $i === 0 ? $q->whereDate('created_at', $date) : $q->orWhereDate('created_at', $date);
                        }
                    })
                    ->when($matchedParentIds !== [], fn ($q) => $q->where(function ($sub) use ($matchedParentIds) {
                        $sub->whereNotIn('parent_alpaca_order_id', $matchedParentIds)
                            ->orWhereNull('parent_alpaca_order_id');
                    }))
                    ->get();
                $sellOrders = $sellOrders->concat($extraSells);
            }

            $orders = $orders->concat($sellOrders);
        }

        // Collect all symbols across the date range for a single batch price query.
        // Use the same MAX(ts_est) subquery pattern as the alpaca-orders page.
        $allSymbols = $orders->pluck('symbol')->unique()->values()->toArray();
        $currentPrices = [];
        if ($allSymbols !== []) {
            $placeholders = implode(',', array_fill(0, count($allSymbols), '?'));
            $oneDayAgo = now()->subHours(24)->format('Y-m-d H:i:s');
            $rows = DB::select("
                SELECT p.symbol, p.price
                FROM one_minute_prices p
                INNER JOIN (
                    SELECT symbol, MAX(ts_est) AS max_ts
                    FROM one_minute_prices
                    WHERE symbol IN ({$placeholders})
                      AND ts_est >= ?
                    GROUP BY symbol
                ) latest ON p.symbol = latest.symbol AND p.ts_est = latest.max_ts
                WHERE p.symbol IN ({$placeholders})
            ", array_merge($allSymbols, [$oneDayAgo], $allSymbols));

            foreach ($rows as $row) {
                $currentPrices[$row->symbol] = $row->price;
            }
        }

        // Group by date using created_at to match alpaca-orders page methodology
        $dailyPerformance = $orders->groupBy(function ($order) {
            return $order->created_at->format('Y-m-d');
        })->map(function ($dayOrders, $date) use ($mlThreshold, $currentPrices) {
            $daySymbols = $dayOrders->pluck('symbol')->unique()->values()->toArray();

            // Build realizedSellPrices using canonical AlpacaOrderController algorithm
            // Phase 1: exact parent_alpaca_order_id matching
            $realizedSellPrices = [];

            $matchedSells = AlpacaOrder::whereIn('symbol', $daySymbols)
                ->where('side', 'sell')
                ->where('status', 'filled')
                ->where('filled_qty', '>', 0)
                ->whereNotNull('filled_avg_price')
                ->whereNotNull('parent_alpaca_order_id')
                ->where('created_at', '>=', $date.' 00:00:00')
                ->where('created_at', '<=', $date.' 23:59:59')
                ->get(['filled_avg_price', 'filled_qty', 'parent_alpaca_order_id', 'symbol', 'created_at']);

            $validBuyIds = AlpacaOrder::whereIn('symbol', $daySymbols)
                ->where('side', 'buy')
                ->where('status', 'filled')
                ->whereNotNull('alpaca_order_id')
                ->where('created_at', '>=', $date.' 00:00:00')
                ->where('created_at', '<=', $date.' 23:59:59')
                ->pluck('alpaca_order_id')
                ->toArray();

            $phase1UnmatchedSells = [];

            foreach ($matchedSells as $sell) {
                $parentId = $sell->parent_alpaca_order_id;

                if (! in_array($parentId, $validBuyIds, true)) {
                    $phase1UnmatchedSells[] = $sell;

                    continue;
                }

                if (isset($realizedSellPrices[$parentId])) {
                    $existing = $realizedSellPrices[$parentId];
                    $totalQty = (float) $existing['qty'] + (float) $sell->filled_qty;
                    $weightedPrice = ((float) $existing['price'] * (float) $existing['qty'] + (float) $sell->filled_avg_price * (float) $sell->filled_qty) / $totalQty;
                    $realizedSellPrices[$parentId] = ['price' => (string) round($weightedPrice, 2), 'qty' => (string) $totalQty];
                } else {
                    $realizedSellPrices[$parentId] = ['price' => (string) $sell->filled_avg_price, 'qty' => (string) $sell->filled_qty];
                }
            }

            // Phase 2: FIFO for orphan sells (NULL parents + Phase 1 rejects)
            $orphanSells = AlpacaOrder::whereIn('symbol', $daySymbols)
                ->where('side', 'sell')
                ->where('status', 'filled')
                ->where('filled_qty', '>', 0)
                ->whereNotNull('filled_avg_price')
                ->whereNull('parent_alpaca_order_id')
                ->where('created_at', '>=', $date.' 00:00:00')
                ->where('created_at', '<=', $date.' 23:59:59')
                ->orderBy('created_at')
                ->get(['symbol', 'filled_avg_price', 'filled_qty', 'created_at']);

            if ($phase1UnmatchedSells !== []) {
                $orphanSells = $orphanSells->concat($phase1UnmatchedSells)->sortBy('created_at');
            }

            if ($orphanSells->isNotEmpty()) {
                $allBuys = AlpacaOrder::whereIn('symbol', $daySymbols)
                    ->where('side', 'buy')
                    ->where('status', 'filled')
                    ->where('filled_qty', '>', 0)
                    ->whereNotNull('filled_avg_price')
                    ->where('created_at', '>=', $date.' 00:00:00')
                    ->where('created_at', '<=', $date.' 23:59:59')
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
                            $weightedPrice = ((float) $existing['price'] * (float) $existing['qty'] + (float) $sell->filled_avg_price * $matchedQty) / $totalQty;
                            $realizedSellPrices[$buy->alpaca_order_id] = ['price' => (string) round($weightedPrice, 2), 'qty' => (string) $totalQty];
                        } else {
                            $realizedSellPrices[$buy->alpaca_order_id] = ['price' => (string) $sell->filled_avg_price, 'qty' => (string) $matchedQty];
                        }
                        $remainingQty -= $matchedQty;
                        if ($remainingQty <= 0) {
                            break;
                        }
                    }
                }
            }

            // Per-symbol aggregation using the canonical $realizedSellPrices
            $symbols = $dayOrders->groupBy('symbol')->map(function ($symbolOrders, $symbol) use ($mlThreshold, $currentPrices, $realizedSellPrices) {
                $buys = $symbolOrders->where('side', 'buy')->filter(fn ($o) => $o->status === 'filled'
                    || (in_array($o->status, ['partially_filled', 'canceled']) && $o->filled_qty > 0)
                );
                $sells = $symbolOrders->where('side', 'sell')->filter(fn ($o) => $o->status === 'filled'
                    || (in_array($o->status, ['partially_filled', 'canceled']) && $o->filled_qty > 0)
                );

                $buyQty = $buys->sum('filled_qty');
                $sellQty = $sells->sum('filled_qty');
                $buyCost = $buys->sum(fn ($o) => $o->filled_qty * $o->filled_avg_price);
                $sellProceeds = $sells->sum(fn ($o) => $o->filled_qty * $o->filled_avg_price);

                $totalPL = 0;
                $realizedPL = 0;
                $unrealizedPL = 0;

                foreach ($buys as $buy) {
                    $qty = (float) $buy->filled_qty;
                    $avgPrice = (float) $buy->filled_avg_price;
                    $buyId = $buy->alpaca_order_id;

                    if ($buyId && isset($realizedSellPrices[$buyId])) {
                        $sellPrice = (float) $realizedSellPrices[$buyId]['price'];
                        $pl = ($sellPrice - $avgPrice) * $qty;
                        $realizedPL += $pl;
                        $totalPL += $pl;
                    } elseif (isset($currentPrices[$symbol])) {
                        $currentPrice = (float) $currentPrices[$symbol];
                        $pl = ($currentPrice - $avgPrice) * $qty;
                        $unrealizedPL += $pl;
                        $totalPL += $pl;
                    }
                }

                $plPct = $buyCost > 0 ? ($totalPL / $buyCost) * 100 : 0;

                // Get ML score from the first buy order (entry signal)
                $mlWinProb = null;
                $firstBuy = $buys->first();
                if ($firstBuy && $firstBuy->tradeAlert) {
                    $mlWinProb = (float) $firstBuy->tradeAlert->ml_win_prob;
                }

                if ($mlThreshold !== null) {
                    $thresholdToUse = $mlThreshold;

                    if ($thresholdToUse === -1.0) {
                        $pipelineRun = $firstBuy?->tradeAlert?->pipeline_run;
                        $thresholdToUse = $pipelineRun ? TradingSettingService::getPipelineMlThreshold($pipelineRun) : null;
                    }

                    if ($thresholdToUse === null || $mlWinProb === null || $mlWinProb < $thresholdToUse) {
                        return null;
                    }
                }

                // Determine open/closed: a symbol is closed when no buy has open shares
                $allBuysForSymbol = $symbolOrders->where('side', 'buy')->filter(fn ($b) => $b->alpaca_order_id);
                $matchedBuys = $allBuysForSymbol->filter(fn ($b) => isset($realizedSellPrices[$b->alpaca_order_id]));
                $status = $allBuysForSymbol->isEmpty() ? 'open' : ($matchedBuys->count() === $allBuysForSymbol->count() ? 'closed' : 'open');

                return [
                    'symbol' => $symbol,
                    'ml_win_prob' => $mlWinProb,
                    'buy_qty' => round($buyQty, 2),
                    'sell_qty' => round($sellQty, 2),
                    'buy_cost' => round($buyCost, 2),
                    'sell_proceeds' => round($sellProceeds, 2),
                    'realized_pl' => round($realizedPL, 2),
                    'unrealized_pl' => round($unrealizedPL, 2),
                    'total_pl' => round($totalPL, 2),
                    'pl_pct' => round($plPct, 2),
                    'status' => $status,
                    'trades' => $symbolOrders->map(function ($order) {
                        $qty = (float) ($order->filled_qty ?? 0);
                        $price = (float) ($order->filled_avg_price ?? 0);
                        $mlWinProb = $order->tradeAlert ? (float) $order->tradeAlert->ml_win_prob : null;
                        $version = $order->tradeAlert?->version;

                        return [
                            'id' => $order->id,
                            'side' => $order->side,
                            'ml_win_prob' => $mlWinProb,
                            'version' => $version,
                            'qty' => round($qty, 2),
                            'price' => round($price, 2),
                            'amount' => round($qty * $price, 2),
                            'submitted_at' => $order->submitted_at?->setTimezone('America/New_York')->format('g:i:s A T'),
                            'filled_at' => $order->filled_at?->setTimezone('America/New_York')->format('g:i:s A T'),
                        ];
                    })->values(),
                ];
            })->filter()->values();

            if ($symbols->isEmpty()) {
                return null;
            }

            // Recalculate daily totals after filtering
            $totalPL = $symbols->sum('total_pl');
            $totalBuyCost = $symbols->sum('buy_cost');
            $totalRealizedPL = $symbols->sum('realized_pl');
            $totalUnrealizedPL = $symbols->sum('unrealized_pl');
            $plPct = $totalBuyCost > 0 ? ($totalPL / $totalBuyCost) * 100 : 0;
            $tradeCount = $symbols->sum(fn ($s) => count($s['trades']));
            $winCount = $symbols->filter(fn ($s) => $s['total_pl'] > 0)->count();
            $lossCount = $symbols->filter(fn ($s) => $s['total_pl'] < 0)->count();
            $winRate = $symbols->count() > 0 ? ($winCount / $symbols->count()) * 100 : 0;

            return [
                'date' => $date,
                'date_formatted' => now()->parse($date)->format('D, M j, Y'),
                'total_pl' => round($totalPL, 2),
                'realized_pl' => round($totalRealizedPL, 2),
                'unrealized_pl' => round($totalUnrealizedPL, 2),
                'pl_pct' => round($plPct, 2),
                'total_buy_cost' => round($totalBuyCost, 2),
                'trade_count' => $tradeCount,
                'symbol_count' => $symbols->count(),
                'win_count' => $winCount,
                'loss_count' => $lossCount,
                'win_rate' => round($winRate, 1),
                'symbols' => $symbols,
            ];
        })->filter()->values();

        // Calculate cumulative statistics
        $totalPL = $dailyPerformance->sum('total_pl');
        $totalRealizedPL = $dailyPerformance->sum('realized_pl');
        $totalUnrealizedPL = $dailyPerformance->sum('unrealized_pl');
        $totalTrades = $dailyPerformance->sum('trade_count');
        $winningDays = $dailyPerformance->filter(fn ($d) => $d['total_pl'] > 0)->count();
        $losingDays = $dailyPerformance->filter(fn ($d) => $d['total_pl'] < 0)->count();

        // Calculate win rate based on symbols, not days
        $totalWinningSymbols = $dailyPerformance->sum('win_count');
        $totalLosingSymbols = $dailyPerformance->sum('loss_count');
        $totalSymbols = $totalWinningSymbols + $totalLosingSymbols;
        $winRate = $totalSymbols > 0 ? ($totalWinningSymbols / $totalSymbols) * 100 : 0;

        return Inertia::render('alpaca-daily-performance/index', [
            'dailyPerformance' => $dailyPerformance,
            'summary' => [
                'total_pl' => round($totalPL, 2),
                'realized_pl' => round($totalRealizedPL, 2),
                'unrealized_pl' => round($totalUnrealizedPL, 2),
                'total_trades' => $totalTrades,
                'total_days' => $dailyPerformance->count(),
                'winning_symbols' => $totalWinningSymbols,
                'losing_symbols' => $totalLosingSymbols,
                'win_rate' => round($winRate, 1),
                'avg_daily_pl' => $dailyPerformance->count() > 0 ? round($totalPL / $dailyPerformance->count(), 2) : 0,
            ],
            'filters' => [
                'start_date' => $startDate,
                'end_date' => $endDate,
                'ml_threshold' => $mlThreshold,
                'mode' => $mode,
                'pipeline' => $pipeline,
            ],
            'pipelineVersions' => [
                'A' => config('app.trade_alert_a_version', 'v17.0'),
                'B' => config('app.trade_alert_b_version', 'v19.0'),
                'C' => config('app.trade_alert_c_version', 'v25.0'),
                'D' => config('app.trade_alert_d_version', 'v26.0'),
                'E' => config('app.trade_alert_e_version', 'v80.1'),
                'F' => config('app.trade_alert_f_version', 'v70.0'),
                'G' => config('app.trade_alert_g_version', 'v80.1'),
                'H' => config('app.trade_alert_h_version', 'v26.1'),
                'I' => config('app.trade_alert_i_version', 'v60.1'),
                'J' => config('app.trade_alert_j_version', 'v2000.0'),
                'K' => config('app.trade_alert_k_version', 'v1100.0'),
                'L' => config('app.trade_alert_l_version', 'v1600.0'),
                'M' => config('app.trade_alert_m_version', 'v1.0'),
                'N' => config('app.trade_alert_n_version', 'v1200.0'),
                'O' => config('app.trade_alert_o_version', 'v1500.0'),
                'P' => config('app.trade_alert_p_version', 'v3000.0'),
                'Q' => config('app.trade_alert_q_version', 'v27.0'),
                'R' => config('app.trade_alert_r_version', 'rt-v1.0'),
                'S' => config('app.trade_alert_s_version', 'rt-vwap-reversal-v1.0'),
                'X' => config('app.trade_alert_x_version', 'v1.0'),
                'BIASED1' => 'v1.0-biased',
                'EXTERNAL' => config('app.trade_alert_external_version', 'external'),
            ],
        ]);
    }
}
