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

        // Compute P&L from API data. Strategy per symbol:
        // 1. Sort fills chronologically
        // 2. Match sells to buys FIFO (for same-day buy→sell pairs)
        // 3. For sells without a FIFO match (closing prior-day positions),
        //    look up the parent buy's entry price from alpaca_orders DB
        $realizedSellPrices = [];
        $summaryPlDollar = 0.0;
        $summaryTotalBought = 0.0;

        // Include orders with filled_qty > 0 (matches working pages — catches
        // partially-filled-then-canceled orders that still transacted shares)
        $filledOrders = array_values(array_filter($orders, fn ($o) => (float) ($o['filled_qty'] ?? 0) > 0));

        // Collect sell IDs for DB parent lookup (used for sells without FIFO match)
        $allSellIds = [];
        foreach ($filledOrders as $o) {
            if (($o['side'] ?? '') === 'sell') {
                $allSellIds[] = $o['id'];
            }
        }

        // Batch fetch DB parent_alpaca_order_id for all sells
        $dbParentLinks = [];
        if ($allSellIds !== []) {
            $dbParentLinks = DB::table('alpaca_orders')
                ->whereIn('alpaca_order_id', $allSellIds)
                ->whereNotNull('parent_alpaca_order_id')
                ->pluck('parent_alpaca_order_id', 'alpaca_order_id')
                ->toArray();
        }

        // Group fills by symbol
        $bySymbol = [];
        foreach ($filledOrders as $o) {
            $sym = $o['symbol'] ?? '';
            if ($sym === '') {
                continue;
            }
            $bySymbol[$sym][] = $o;
        }

        foreach ($bySymbol as $symbol => $fills) {
            // Sort by filled_at chronologically
            usort($fills, function ($a, $b) {
                $ta = $a['filled_at'] ?? $a['submitted_at'] ?? '';
                $tb = $b['filled_at'] ?? $b['submitted_at'] ?? '';

                return $ta <=> $tb;
            });

            // FIFO buy queue: each entry is [buyId, buyPrice, remainingQty]
            $buyQueue = [];

            foreach ($fills as $o) {
                $side = $o['side'] ?? '';
                $qty = (float) ($o['filled_qty'] ?? 0);
                $price = (float) ($o['filled_avg_price'] ?? 0);

                if ($qty <= 0 || $price <= 0) {
                    continue;
                }

                if ($side === 'buy') {
                    $buyQueue[] = ['id' => $o['id'], 'price' => $price, 'remaining' => $qty];
                    $summaryTotalBought += $price * $qty;
                } elseif ($side === 'sell') {
                    $remainingSell = $qty;

                    // Step 1: FIFO match against today's buys
                    while ($remainingSell > 0 && $buyQueue !== []) {
                        $buy = &$buyQueue[0];
                        $matched = min($remainingSell, $buy['remaining']);

                        $buyId = $buy['id'];
                        $buyPrice = $buy['price'];

                        if (isset($realizedSellPrices[$buyId])) {
                            $ex = $realizedSellPrices[$buyId];
                            $totalQty = $ex['qty'] + $matched;
                            $weightedPrice = (($ex['price'] * $ex['qty']) + ($price * $matched)) / $totalQty;
                            $realizedSellPrices[$buyId] = ['price' => round($weightedPrice, 2), 'qty' => $totalQty];
                        } else {
                            $realizedSellPrices[$buyId] = ['price' => $price, 'qty' => $matched];
                        }

                        $summaryPlDollar += ($price - $buyPrice) * $matched;

                        $buy['remaining'] -= $matched;
                        $remainingSell -= $matched;

                        if ($buy['remaining'] <= 0) {
                            array_shift($buyQueue);
                        }
                    }

                    // Step 2: unmatched portion = closing a prior-day position
                    // Look up parent buy from DB for entry price
                    if ($remainingSell > 0) {
                        $parentId = $dbParentLinks[$o['id']] ?? null;
                        if ($parentId) {
                            $buyPrice = DB::table('alpaca_orders')
                                ->where('alpaca_order_id', $parentId)
                                ->where('side', 'buy')
                                ->whereNotNull('filled_avg_price')
                                ->value('filled_avg_price');

                            if ($buyPrice && (float) $buyPrice > 0) {
                                $summaryPlDollar += ($price - (float) $buyPrice) * $remainingSell;
                            }
                        }
                    }
                }
            }
        }

        // Add unrealized P&L for unmatched buys
        $currentPrices = $this->getCurrentPrices(array_column($orders, 'symbol'));
        foreach ($bySymbol as $symbol => $fills) {
            if (! isset($currentPrices[$symbol])) {
                continue;
            }
            $currentPrice = (float) $currentPrices[$symbol]['price'];

            foreach ($fills as $o) {
                if (($o['side'] ?? '') !== 'buy') {
                    continue;
                }
                $buyId = $o['id'];
                if (isset($realizedSellPrices[$buyId])) {
                    continue;
                }
                $buyPrice = (float) ($o['filled_avg_price'] ?? 0);
                $buyQty = (float) ($o['filled_qty'] ?? 0);
                if ($buyPrice <= 0 || $buyQty <= 0) {
                    continue;
                }
                $summaryPlDollar += ($currentPrice - $buyPrice) * $buyQty;
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
            'currentPrices' => $currentPrices,
            'ownedQuantities' => $ownedQuantities,
            'summaryPlDollar' => round($summaryPlDollar, 2),
            'summaryTotalBought' => round($summaryTotalBought, 2),
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
