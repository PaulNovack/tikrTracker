<?php

namespace App\Http\Controllers;

use App\Services\AlpacaPythonService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class AlpacaOrdersApiController extends Controller
{
    public function __construct(
        private AlpacaPythonService $alpacaPythonService
    ) {}

    /**
     * Display orders from Alpaca API
     */
    public function index(Request $request): Response
    {
        $today = Carbon::today('America/New_York')->toDateString();
        $status = $request->get('status', 'all');
        $limit = min(500, (int) $request->get('limit', 500));
        $startDate = $request->get('start_date');
        $endDate = $request->get('end_date');
        $onlyOwned = $request->boolean('only_owned');

        // If no dates provided, default to today EST
        if (! $startDate && ! $endDate) {
            $startDate = $today;
            $endDate = $today;
        }

        // Convert EST dates to UTC boundaries for Alpaca API
        // e.g. 2026-06-29 EST = 2026-06-29T04:00:00Z (EDT) to 2026-06-30T03:59:59Z
        $apiStart = $startDate
            ? Carbon::parse($startDate, 'America/New_York')->startOfDay()->tz('UTC')->toIso8601ZuluString()
            : null;
        $apiEnd = $endDate
            ? Carbon::parse($endDate, 'America/New_York')->endOfDay()->tz('UTC')->toIso8601ZuluString()
            : null;

        $result = $this->alpacaPythonService->getOrders($status, $limit, $apiStart, $apiEnd);

        $orders = [];
        $error = null;
        $ownedQuantities = [];

        if ($result['success']) {
            $data = json_decode($result['output'], true);
            if ($data && isset($data['orders'])) {
                $orders = $data['orders'];
            }
        } else {
            $error = $result['error'] ?? 'Failed to fetch orders from Alpaca API';
        }

        // Build realizedSellPrices using ONLY our DB's parent_alpaca_order_id
        // (which is now reliably populated). Alpaca's parent_order_id is empty
        // for standalone stops, so using it would cause wrong FIFO matching.
        $realizedSellPrices = [];
        $filledOrders = array_filter($orders, fn ($o) => ($o['status'] ?? '') === 'filled');

        // Cross-reference DB for parent_alpaca_order_id on standalone stops
        $sellAlpacaIds = [];
        foreach ($filledOrders as $o) {
            if (($o['side'] ?? '') === 'sell') {
                $sellAlpacaIds[] = $o['id'];
            }
        }
        $dbParentLinks = [];
        if ($sellAlpacaIds !== []) {
            $dbParentLinks = DB::table('alpaca_orders')
                ->whereIn('alpaca_order_id', $sellAlpacaIds)
                ->whereNotNull('parent_alpaca_order_id')
                ->pluck('parent_alpaca_order_id', 'alpaca_order_id')
                ->toArray();
        }

        // Build valid buy IDs from the Alpaca data (same-day trades only)
        $validBuyIds = [];
        foreach ($filledOrders as $o) {
            if (($o['side'] ?? '') === 'buy') {
                $validBuyIds[$o['id']] = true;
            }
        }

        // Phase 1: match sells to buys by DB parent_alpaca_order_id (only if parent is a valid buy)
        foreach ($filledOrders as $o) {
            if (($o['side'] ?? '') !== 'sell') {
                continue;
            }
            $parentId = $dbParentLinks[$o['id']] ?? null;
            if (! $parentId || ! isset($validBuyIds[$parentId])) {
                continue;
            }

            if (isset($realizedSellPrices[$parentId])) {
                $existing = $realizedSellPrices[$parentId];
                $totalQty = $existing['qty'] + (float) ($o['filled_qty'] ?? 0);
                $weightedPrice = (($existing['price'] * $existing['qty']) + ((float) ($o['filled_avg_price'] ?? 0) * (float) ($o['filled_qty'] ?? 0))) / $totalQty;
                $realizedSellPrices[$parentId] = ['price' => round($weightedPrice, 2), 'qty' => $totalQty];
            } else {
                $realizedSellPrices[$parentId] = ['price' => (float) ($o['filled_avg_price'] ?? 0), 'qty' => (float) ($o['filled_qty'] ?? 0)];
            }
        }

        // Phase 2 is NOT needed — all trades are same-day and sells have parent_alpaca_order_id set.
        // Any remaining unmatched buys are open positions (unrealized P&L).

        return Inertia::render('alpaca-orders-api/index', [
            'orders' => $orders,
            'error' => $error,
            'status' => $status,
            'limit' => $limit,
            'filters' => [
                'start_date' => $startDate,
                'end_date' => $endDate,
                'only_owned' => $onlyOwned,
            ],
            'currentPrices' => $this->getCurrentPrices(array_column($orders, 'symbol')),
            'ownedQuantities' => $ownedQuantities,
            'realizedSellPrices' => $realizedSellPrices,
        ]);
    }

    /**
     * Get current prices for given symbols from one_minute_prices
     */
    private function getCurrentPrices(array $symbols): array
    {
        if (empty($symbols)) {
            return [];
        }

        $prices = DB::table('one_minute_prices')
            ->select('symbol', 'price', 'ts_est')
            ->whereIn('symbol', array_unique($symbols))
            ->whereRaw('(symbol, ts_est) IN (SELECT symbol, MAX(ts_est) FROM one_minute_prices WHERE symbol IN ("'.implode('","', array_unique($symbols)).'") GROUP BY symbol)')
            ->get()
            ->keyBy('symbol');

        return $prices->map(function ($price) {
            return [
                'price' => $price->price,
                'timestamp' => $price->ts_est,
            ];
        })->toArray();
    }
}
