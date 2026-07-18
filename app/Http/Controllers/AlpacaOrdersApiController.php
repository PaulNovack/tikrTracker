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
        $limit = (int) $request->get('limit', 100);
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

        // Build realizedSellPrices: match sells to buys by parent_order_id or chronologically
        $realizedSellPrices = [];
        $filledOrders = array_filter($orders, fn ($o) => ($o['status'] ?? '') === 'filled');

        // Build a lookup of buy IDs from the API data
        $buyIds = [];
        $buyOrders = [];
        foreach ($filledOrders as $o) {
            if (($o['side'] ?? '') === 'buy') {
                $buyIds[$o['id']] = true;
                $buyOrders[] = $o;
            }
        }

        // Phase 1: match sells to buys by parent_order_id
        $matchedSellIds = [];
        foreach ($filledOrders as $o) {
            if (($o['side'] ?? '') === 'sell' && ! empty($o['parent_order_id']) && isset($buyIds[$o['parent_order_id']])) {
                $parentId = $o['parent_order_id'];
                $matchedSellIds[$o['id']] = true;
                if (isset($realizedSellPrices[$parentId])) {
                    $existing = $realizedSellPrices[$parentId];
                    $totalQty = $existing['qty'] + (float) ($o['filled_qty'] ?? 0);
                    $weightedPrice = (($existing['price'] * $existing['qty']) + ((float) ($o['filled_avg_price'] ?? 0) * (float) ($o['filled_qty'] ?? 0))) / $totalQty;
                    $realizedSellPrices[$parentId] = ['price' => round($weightedPrice, 2), 'qty' => $totalQty];
                } else {
                    $realizedSellPrices[$parentId] = ['price' => (float) ($o['filled_avg_price'] ?? 0), 'qty' => (float) ($o['filled_qty'] ?? 0)];
                }
            }
        }

        // Phase 2: FIFO chronological match for unmatched sells
        $unmatchedSells = [];
        foreach ($filledOrders as $o) {
            if (($o['side'] ?? '') === 'sell' && $o['filled_qty'] > 0 && ! isset($matchedSellIds[$o['id']])) {
                $unmatchedSells[] = $o;
            }
        }

        if ($unmatchedSells !== [] && $buyOrders !== []) {
            // Sort chronologically
            usort($buyOrders, fn ($a, $b) => strtotime((string) ($a['submitted_at'] ?? '0')) <=> strtotime((string) ($b['submitted_at'] ?? '0')));
            usort($unmatchedSells, fn ($a, $b) => strtotime((string) ($a['submitted_at'] ?? '0')) <=> strtotime((string) ($b['submitted_at'] ?? '0')));

            // Per-symbol FIFO
            $symbolBuyQueue = [];
            foreach ($buyOrders as $b) {
                $symbolBuyQueue[$b['symbol']][] = ['id' => $b['id'], 'qty' => (float) ($b['filled_qty'] ?? 0)];
            }

            foreach ($unmatchedSells as $sell) {
                $sym = $sell['symbol'];
                if (empty($symbolBuyQueue[$sym])) {
                    continue;
                }
                $remainingQty = (float) ($sell['filled_qty'] ?? 0);
                while ($remainingQty > 0 && ! empty($symbolBuyQueue[$sym])) {
                    $buy = &$symbolBuyQueue[$sym][0];
                    $matched = min($remainingQty, $buy['qty']);
                    $buyId = $buy['id'];
                    if (isset($realizedSellPrices[$buyId])) {
                        $existing = $realizedSellPrices[$buyId];
                        $totalQty = $existing['qty'] + $matched;
                        $weightedPrice = (($existing['price'] * $existing['qty']) + ((float) ($sell['filled_avg_price'] ?? 0) * $matched)) / $totalQty;
                        $realizedSellPrices[$buyId] = ['price' => round($weightedPrice, 2), 'qty' => $totalQty];
                    } else {
                        $realizedSellPrices[$buyId] = ['price' => (float) ($sell['filled_avg_price'] ?? 0), 'qty' => $matched];
                    }
                    $remainingQty -= $matched;
                    $buy['qty'] -= $matched;
                    if ($buy['qty'] <= 0.001) {
                        array_shift($symbolBuyQueue[$sym]);
                    }
                }
            }
        }

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
