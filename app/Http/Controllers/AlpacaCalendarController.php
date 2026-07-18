<?php

namespace App\Http\Controllers;

use App\Models\AlpacaOrder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class AlpacaCalendarController extends Controller
{
    public function index(Request $request): Response
    {
        $year = (int) $request->input('year', now()->year);
        $month = (int) $request->input('month', now()->month);
        $mode = $request->input('mode', 'live'); // 'live', 'paper', or 'all'

        $startDate = sprintf('%04d-%02d-01', $year, $month);
        $endDate = date('Y-m-t', strtotime($startDate));

        $query = AlpacaOrder::with('tradeAlert:id,ml_win_prob,version')
            ->where(function ($q) {
                $q->where('status', 'filled')
                    ->orWhere(function ($innerQuery) {
                        $innerQuery->whereIn('status', ['partially_filled', 'canceled'])
                            ->where('filled_qty', '>', 0);
                    });
            })
            ->whereNotNull('submitted_at')
            ->when($mode === 'live', fn ($query) => $query->where('is_paper', false))
            ->when($mode === 'paper', fn ($query) => $query->where('is_paper', true));

        if ($startDate) {
            $query->whereDate('created_at', '>=', $startDate);
        }

        if ($endDate) {
            $query->whereDate('created_at', '<=', $endDate);
        }

        $orders = $query->orderBy('created_at', 'desc')->get();

        // Collect all symbols for a single batch price query.
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

        // Group by date using created_at to match alpaca-daily-performance methodology
        $dailyPerformance = $orders->groupBy(function ($order) {
            return $order->created_at->format('Y-m-d');
        })->map(function ($dayOrders, $date) use ($orders, $currentPrices) {
            // Per-symbol P&L: mirror the alpaca-daily-performance page by evaluating each
            // buy order individually — realized P&L via parent_alpaca_order_id / FIFO,
            // unrealized via one_minute_prices.
            $symbols = $dayOrders->groupBy('symbol')->map(function ($symbolOrders, $symbol) use ($orders, $date, $currentPrices) {
                $buys = $symbolOrders->where('side', 'buy');
                $sells = $symbolOrders->where('side', 'sell');

                $buyQty = $buys->sum('filled_qty');
                $sellQty = $sells->sum('filled_qty');
                $buyCost = $buys->sum(fn ($o) => $o->filled_qty * $o->filled_avg_price);
                $sellProceeds = $sells->sum(fn ($o) => $o->filled_qty * $o->filled_avg_price);

                // Determine net position across all history up to this date.
                $allSymbolOrders = $orders->where('symbol', $symbol)
                    ->filter(fn ($o) => $o->created_at->format('Y-m-d') <= $date);
                $totalBuyQty = $allSymbolOrders->where('side', 'buy')->sum('filled_qty');
                $totalSellQty = $allSymbolOrders->where('side', 'sell')->sum('filled_qty');
                $netPosition = $totalBuyQty - $totalSellQty;
                $status = abs($netPosition) < 0.01 ? 'closed' : 'open';

                // Build lookup: buy alpaca_order_id → sell price using FIFO matching.
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
                $unmatchedBuys = $buys->filter(fn ($b) => $b->alpaca_order_id && ! isset($buySellMap[$b->alpaca_order_id]))
                    ->sortBy('submitted_at')->values();

                if ($unmatchedBuys->isNotEmpty()) {
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

                    // FIFO consume sells against unmatched buys
                    $sellIdx = 0;
                    foreach ($unmatchedBuys as $buy) {
                        if ($sellIdx >= count($remainingSells)) {
                            break;
                        }
                        $buyQty = (float) $buy->filled_qty;
                        $sellPrice = $remainingSells[$sellIdx]['price'];
                        $remainingSells[$sellIdx]['qty'] -= $buyQty;
                        if ($remainingSells[$sellIdx]['qty'] <= 0) {
                            $sellIdx++;
                        }
                        $buySellMap[$buy->alpaca_order_id] = $sellPrice;
                    }
                }

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

                return [
                    'symbol' => $symbol,
                    'buy_qty' => round($buyQty, 2),
                    'sell_qty' => round($sellQty, 2),
                    'buy_cost' => round($buyCost, 2),
                    'sell_proceeds' => round($sellProceeds, 2),
                    'realized_pl' => round($realizedPL, 2),
                    'unrealized_pl' => round($unrealizedPL, 2),
                    'total_pl' => round($totalPL, 2),
                    'status' => $status,
                    'trades' => $symbolOrders->values(),
                ];
            })->values();

            if ($symbols->isEmpty()) {
                return null;
            }

            $totalPL = $symbols->sum('total_pl');
            $realizedPL = $symbols->sum('realized_pl');
            $unrealizedPL = $symbols->sum('unrealized_pl');
            $tradeCount = $symbols->sum(fn ($symbol) => count($symbol['trades']));
            $winCount = $symbols->filter(fn ($symbol) => $symbol['total_pl'] > 0)->count();
            $lossCount = $symbols->filter(fn ($symbol) => $symbol['total_pl'] < 0)->count();

            return [
                'date' => $date,
                'total_pl' => round($totalPL, 2),
                'realized_pl' => round($realizedPL, 2),
                'unrealized_pl' => round($unrealizedPL, 2),
                'trade_count' => $tradeCount,
                'symbol_count' => $symbols->count(),
                'win_count' => $winCount,
                'loss_count' => $lossCount,
                'symbols' => $symbols,
            ];
        })->filter()->values();

        $dailyData = $dailyPerformance->mapWithKeys(function (array $day) {
            return [
                $day['date'] => [
                    'total_pl' => $day['total_pl'],
                    'realized_pl' => $day['realized_pl'],
                    'unrealized_pl' => $day['unrealized_pl'],
                    'trade_count' => $day['trade_count'],
                    'symbol_count' => $day['symbol_count'],
                    'win_count' => $day['win_count'],
                    'loss_count' => $day['loss_count'],
                    'symbols' => $day['symbols'],
                ],
            ];
        })->all();

        $monthlyTotalPL = $dailyPerformance->sum('total_pl');
        $winningDays = $dailyPerformance->filter(fn ($day) => $day['total_pl'] > 0)->count();
        $losingDays = $dailyPerformance->filter(fn ($day) => $day['total_pl'] < 0)->count();

        return Inertia::render('alpaca-calendar/index', [
            'dailyData' => $dailyData,
            'year' => $year,
            'month' => $month,
            'mode' => $mode,
            'summary' => [
                'total_pl' => round($monthlyTotalPL, 2),
                'winning_days' => $winningDays,
                'losing_days' => $losingDays,
                'trading_days' => count($dailyData),
            ],
        ]);
    }
}
