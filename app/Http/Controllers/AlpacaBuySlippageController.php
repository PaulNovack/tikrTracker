<?php

namespace App\Http\Controllers;

use App\Models\AlpacaOrder;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class AlpacaBuySlippageController extends Controller
{
    public function index(): Response
    {
        $today = now()->toDateString();
        $startDate = request('start_date', $today);
        $endDate = request('end_date', $today);

        // Get base query for orders
        $baseQuery = AlpacaOrder::query()
            ->where('side', 'buy')
            ->where('status', 'filled')
            ->whereNotNull('filled_avg_price')
            ->when($startDate, fn ($q) => $q->whereDate('created_at', '>=', $startDate))
            ->when($endDate, fn ($q) => $q->whereDate('created_at', '<=', $endDate))
            ->orderBy('created_at', 'desc');

        // Get paginated orders
        $orders = $baseQuery->paginate(100)->withQueryString();

        // For each order, find the closest 1-minute price
        $orders->getCollection()->transform(function ($order) {
            // Convert submitted_at from UTC to EST
            $submittedEst = \Carbon\Carbon::parse($order->submitted_at)
                ->setTimezone('America/New_York');

            // Calculate total order amount
            $order->total_amount = $order->filled_qty * $order->filled_avg_price;

            // Find closest 1-minute bar within ±5 minutes
            $closestPrice = DB::table('one_minute_prices')
                ->where('symbol', $order->symbol)
                ->whereBetween('ts_est', [
                    $submittedEst->copy()->subMinutes(5)->format('Y-m-d H:i:00'),
                    $submittedEst->copy()->addMinutes(5)->format('Y-m-d H:i:00'),
                ])
                ->orderByRaw('ABS(TIMESTAMPDIFF(SECOND, ts_est, ?))', [$submittedEst->format('Y-m-d H:i:s')])
                ->first(['price', 'ts_est']);

            if ($closestPrice) {
                $order->market_price_1m = $closestPrice->price;
                $order->market_price_timestamp = $closestPrice->ts_est;
                $order->slippage_dollars = $order->filled_avg_price - $closestPrice->price;
                $order->slippage_pct = (($order->filled_avg_price - $closestPrice->price) / $closestPrice->price) * 100;
            } else {
                $order->market_price_1m = null;
                $order->market_price_timestamp = null;
                $order->slippage_dollars = null;
                $order->slippage_pct = null;
            }

            return $order;
        });

        // Calculate summary statistics from the transformed orders collection
        $allOrders = $orders->getCollection()->filter(fn ($order) => $order->market_price_1m !== null);

        $stats = (object) [
            'total_orders' => $allOrders->count(),
            'avg_slippage_pct' => $allOrders->isNotEmpty() ? $allOrders->avg('slippage_pct') : null,
            'min_slippage_pct' => $allOrders->isNotEmpty() ? $allOrders->min('slippage_pct') : null,
            'max_slippage_pct' => $allOrders->isNotEmpty() ? $allOrders->max('slippage_pct') : null,
            'total_slippage_dollars' => $allOrders->sum(fn ($order) => $order->slippage_dollars * $order->filled_qty),
        ];

        return Inertia::render('alpaca-buy-slippage/index', [
            'orders' => $orders,
            'statistics' => $stats,
            'filters' => [
                'start_date' => $startDate,
                'end_date' => $endDate,
            ],
        ]);
    }
}
