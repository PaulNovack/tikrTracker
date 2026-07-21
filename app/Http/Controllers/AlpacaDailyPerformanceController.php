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

        // Get all orders with filled quantity (includes filled, partially_filled, and
        // canceled orders that were partially filled before cancellation)
        $query = AlpacaOrder::with('tradeAlert:id,ml_win_prob,version,pipeline_run')
            ->where(function ($query) {
                $query->where('status', 'filled')
                    ->orWhere(function ($q) {
                        $q->whereIn('status', ['partially_filled', 'canceled'])
                            ->where('filled_qty', '>', 0);
                    });
            })
            ->whereNotNull('submitted_at')
            ->when($mode === 'live', fn ($q) => $q->where('is_paper', false))
            ->when($mode === 'paper', fn ($q) => $q->where('is_paper', true));

        // Apply date filters using created_at to match alpaca-orders page methodology
        if ($startDate) {
            $query->whereDate('created_at', '>=', $startDate);
        }
        if ($endDate) {
            $query->whereDate('created_at', '<=', $endDate);
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
        })->map(function ($dayOrders, $date) use ($orders, $mlThreshold, $currentPrices) {
            // Per-symbol P&L: mirror the alpaca-orders page by evaluating each
            // buy order individually — realized P&L via parent_alpaca_order_id,
            // unrealized via one_minute_prices.
            $symbols = $dayOrders->groupBy('symbol')->map(function ($symbolOrders, $symbol) use ($orders, $date, $currentPrices, $mlThreshold) {
                $buys = $symbolOrders->where('side', 'buy');
                $sells = $symbolOrders->where('side', 'sell');

                $buyQty = $buys->sum('filled_qty');
                $sellQty = $sells->sum('filled_qty');
                $buyCost = $buys->sum(fn ($o) => $o->filled_qty * $o->filled_avg_price);
                $sellProceeds = $sells->sum(fn ($o) => $o->filled_qty * $o->filled_avg_price);

                // Collect all orders for this symbol up to this date (for buy/sell matching).
                $allSymbolOrders = $orders->where('symbol', $symbol)
                    ->filter(fn ($o) => $o->created_at->format('Y-m-d') <= $date);

                // Build lookup: buy alpaca_order_id → sell price using FIFO matching.
                // Parent_alpaca_order_id can be wrong (e.g. from UpdateTrailingStopLosses),
                // so after direct matching we FIFO-match remaining sells to buys.
                $allSells = $allSymbolOrders->where('side', 'sell')->where('status', 'filled')->whereNotNull('filled_avg_price');
                $buySellMap = [];

                // Pass 1: direct parent_alpaca_order_id matching (same-day preferred)
                foreach ($allSells as $sell) {
                    if ($sell->parent_alpaca_order_id) {
                        $buyRow = $allSymbolOrders->where('side', 'buy')->first(fn ($b) => $b->alpaca_order_id === $sell->parent_alpaca_order_id);
                        $sellDay = $sell->submitted_at?->format('Y-m-d');
                        $buyDay = $buyRow?->submitted_at?->format('Y-m-d');
                        if ($buyDay && $sellDay === $buyDay) {
                            $buySellMap[$sell->parent_alpaca_order_id] = (float) $sell->filled_avg_price;
                        } elseif (! isset($buySellMap[$sell->parent_alpaca_order_id])) {
                            $buySellMap[$sell->parent_alpaca_order_id] = (float) $sell->filled_avg_price;
                        }
                    }
                }

                // Pass 2: FIFO chronological matching for unmatched buys.
                // Only match sells from the same date to avoid cross-day mixing.
                $unmatchedBuys = $buys->filter(fn ($b) => $b->alpaca_order_id && ! isset($buySellMap[$b->alpaca_order_id]))
                    ->sortBy('submitted_at')->values();

                if ($unmatchedBuys->isNotEmpty()) {
                    // Use sells from this day only — same as $symbolOrders scope
                    $daySells = $symbolOrders->where('side', 'sell')
                        ->where('status', 'filled')
                        ->where('filled_qty', '>', 0)
                        ->whereNotNull('filled_avg_price')
                        ->sortBy('submitted_at');

                    // Track qty consumed by pass-1 matches
                    $consumedByBuyId = [];
                    foreach ($daySells as $sell) {
                        if ($sell->parent_alpaca_order_id && isset($buySellMap[$sell->parent_alpaca_order_id])) {
                            $matchedBuy = $buys->first(fn ($b) => $b->alpaca_order_id === $sell->parent_alpaca_order_id);
                            if ($matchedBuy) {
                                $consumedByBuyId[$sell->parent_alpaca_order_id] = ($consumedByBuyId[$sell->parent_alpaca_order_id] ?? 0) + (float) $sell->filled_qty;
                            }
                        }
                    }

                    // Remaining sell inventory (FIFO order)
                    $remainingSells = [];
                    foreach ($daySells as $sell) {
                        $consumed = $sell->parent_alpaca_order_id && isset($consumedByBuyId[$sell->parent_alpaca_order_id])
                            ? $consumedByBuyId[$sell->parent_alpaca_order_id] : 0;
                        $remaining = (float) $sell->filled_qty - $consumed;
                        if ($remaining > 0) {
                            $remainingSells[] = ['price' => (float) $sell->filled_avg_price, 'qty' => $remaining];
                        }
                    }

                    // FIFO: match buys against remaining sells.
                    // Each buy consumes from sells until its qty is satisfied or
                    // sell inventory runs out. Unmatched buy qty stays unrealized.
                    $sellIdx = 0;
                    foreach ($unmatchedBuys as $buy) {
                        $remainingBuyQty = (float) $buy->filled_qty;
                        $weightedSum = 0.0;
                        $matchedQtyTotal = 0.0;

                        while ($remainingBuyQty > 0 && $sellIdx < count($remainingSells)) {
                            $matchedQty = min($remainingBuyQty, $remainingSells[$sellIdx]['qty']);
                            $sellPrice = $remainingSells[$sellIdx]['price'];
                            $weightedSum += $sellPrice * $matchedQty;
                            $matchedQtyTotal += $matchedQty;

                            $remainingSells[$sellIdx]['qty'] -= $matchedQty;
                            $remainingBuyQty -= $matchedQty;

                            if ($remainingSells[$sellIdx]['qty'] <= 0) {
                                $sellIdx++;
                            }
                        }

                        if ($matchedQtyTotal > 0) {
                            $buySellMap[$buy->alpaca_order_id] = $weightedSum / $matchedQtyTotal;
                        }
                    }
                }

                // Determine open/closed status: a symbol is closed when every
                // buy with an alpaca_order_id has been matched to a sell in the
                // buySellMap. This mirrors the alpaca-orders page logic and is
                // robust against fractional-share data anomalies that break
                // simple net-position (buy qty === sell qty) checks.
                $matchableBuys = $allSymbolOrders->where('side', 'buy')
                    ->filter(fn ($b) => $b->alpaca_order_id);
                $matchedCount = $matchableBuys->filter(fn ($b) => isset($buySellMap[$b->alpaca_order_id]))->count();
                $status = $matchableBuys->isEmpty() ? 'open' : ($matchedCount === $matchableBuys->count() ? 'closed' : 'open');

                $totalPL = 0;
                $realizedPL = 0;
                $unrealizedPL = 0;

                foreach ($buys as $buy) {
                    $qty = (float) $buy->filled_qty;
                    $avgPrice = (float) $buy->filled_avg_price;
                    $sellPrice = $buy->alpaca_order_id && isset($buySellMap[$buy->alpaca_order_id])
                        ? $buySellMap[$buy->alpaca_order_id]
                        : null;

                    if ($sellPrice !== null) {
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
