<?php

namespace App\Services\Webull;

use Illuminate\Support\Str;
use RuntimeException;

class WebullTradingService
{
    public function __construct(
        private readonly WebullClient $client,
        private readonly string $accountId,
        private readonly string $defaultTif = 'DAY',
        private readonly bool $defaultExtendedHours = false,
        private readonly string $instrumentCategory = 'US_STOCK',
    ) {}

    /**
     * Buy shares (market or limit) and optionally place a separate stop-loss sell order.
     * Note: In DEV environment, order placement may not be supported.
     * For new symbols, try using symbol as instrument_id (some brokers accept this)
     */
    public function buyWithOptionalStopLoss(
        string $symbol,
        int $qty,
        ?float $limitPrice = null,
        ?float $stopLossPrice = null
    ): array {
        // Try to get instrument_id from existing positions
        $instrumentId = $this->getInstrumentIdFromPositions($symbol);

        // If we don't have instrument_id, use symbol as fallback
        // Some broker APIs accept symbol directly as instrument_id
        if (! $instrumentId) {
            \Log::info('No instrument_id found in positions, using symbol as instrument_id', [
                'symbol' => $symbol,
            ]);
            $instrumentId = $symbol;
        }

        // Place entry order
        $entryOrder = $this->placeOrder([
            'instrument_id' => $instrumentId,
            'symbol' => $symbol,
            'side' => 'BUY',
            'qty' => $qty,
            'order_type' => $limitPrice ? 'LIMIT' : 'MARKET',
            'limit_price' => $limitPrice ? (string) $limitPrice : null,
            'stop_price' => null,
            'tif' => $this->defaultTif,
            'extended_hours_trading' => $limitPrice ? $this->defaultExtendedHours : false,
        ]);

        $stopOrder = null;
        if ($stopLossPrice !== null) {
            // Place stop-loss as a separate SELL stop order
            $stopOrder = $this->placeOrder([
                'instrument_id' => $instrumentId,
                'symbol' => $symbol,
                'side' => 'SELL',
                'qty' => $qty,
                'order_type' => 'STOP_LOSS',
                'limit_price' => null,
                'stop_price' => (string) $stopLossPrice,
                'tif' => $this->defaultTif,
                'extended_hours_trading' => false,
            ]);
        }

        return [
            'entry' => $entryOrder,
            'stop_loss' => $stopOrder,
        ];
    }

    /**
     * Get instrument_id by looking up symbol in current positions
     */
    private function getInstrumentIdFromPositions(string $symbol): ?string
    {
        $positions = $this->listAllPositions();
        $position = collect($positions)->firstWhere('symbol', strtoupper($symbol));

        return $position['instrument_id'] ?? null;
    }

    public function sellMarket(string $symbol, int $qty): array
    {
        \Log::info('WebullTradingService::sellMarket called', [
            'symbol' => $symbol,
            'qty' => $qty,
        ]);

        // Get instrument_id from current positions
        $positions = $this->listAllPositions();
        $position = collect($positions)->firstWhere('symbol', $symbol);

        if (! $position) {
            throw new RuntimeException("No position found for symbol: {$symbol}");
        }

        $instrumentId = $position['instrument_id'] ?? null;
        if (! $instrumentId) {
            throw new RuntimeException("No instrument_id found for position: {$symbol}");
        }

        \Log::info('Got instrument ID from positions', ['symbol' => $symbol, 'instrumentId' => $instrumentId]);

        $orderParams = [
            'instrument_id' => $instrumentId,
            'symbol' => $symbol,
            'side' => 'SELL',
            'qty' => $qty,
            'order_type' => 'MARKET',
            'limit_price' => null,
            'stop_price' => null,
            'tif' => $this->defaultTif,
            'extended_hours_trading' => false,
        ];

        \Log::info('Placing sell market order', ['orderParams' => $orderParams]);
        $result = $this->placeOrder($orderParams);
        \Log::info('Sell market order placed', ['result' => $result]);

        return $result;
    }

    public function placeStopLoss(string $symbol, int $qty, float $stopPrice): array
    {
        \Log::info('WebullTradingService::placeStopLoss called', [
            'symbol' => $symbol,
            'qty' => $qty,
            'stopPrice' => $stopPrice,
        ]);

        // Get instrument_id from current positions
        $positions = $this->listAllPositions();
        $position = collect($positions)->firstWhere('symbol', $symbol);

        if (! $position) {
            throw new RuntimeException("No position found for symbol: {$symbol}");
        }

        $instrumentId = $position['instrument_id'] ?? null;
        if (! $instrumentId) {
            throw new RuntimeException("No instrument_id found for position: {$symbol}");
        }

        \Log::info('Got instrument ID from positions', ['symbol' => $symbol, 'instrumentId' => $instrumentId]);

        $orderParams = [
            'instrument_id' => $instrumentId,
            'symbol' => $symbol,
            'side' => 'SELL',
            'qty' => $qty,
            'order_type' => 'STOP_LOSS',
            'limit_price' => null,
            'stop_price' => (string) $stopPrice,
            'tif' => $this->defaultTif,
            'extended_hours_trading' => false,
        ];

        \Log::info('Placing stop loss order', ['orderParams' => $orderParams]);
        $result = $this->placeOrder($orderParams);
        \Log::info('Stop loss order placed', ['result' => $result]);

        return $result;
    }

    /**
     * Webull: Get Instruments -> instrument_id :contentReference[oaicite:11]{index=11}
     */
    public function getInstrumentId(string $symbol): string
    {
        $params = [
            'symbols' => strtoupper($symbol),
            'category' => $this->instrumentCategory,
        ];

        \Log::info('Webull API: Getting instrument ID', [
            'endpoint' => '/instrument/list',
            'params' => $params,
        ]);

        $res = $this->client->get('/instrument/list', $params);

        \Log::info('Webull API: Instrument lookup response', [
            'status' => $res->status(),
            'body' => $res->body(),
        ]);

        if (! $res->successful()) {
            \Log::error('Webull API: Instrument lookup failed', [
                'status' => $res->status(),
                'body' => $res->body(),
                'params' => $params,
            ]);
            throw new RuntimeException("Instrument lookup failed: HTTP {$res->status()} {$res->body()}");
        }

        $json = $res->json();

        // This shape may vary; adjust if your response wrapper differs.
        // Expected: result list includes instrument_id :contentReference[oaicite:12]{index=12}
        $list = $json['result'] ?? $json['data'] ?? $json ?? null;

        if (! is_array($list) || empty($list)) {
            throw new RuntimeException("Instrument lookup returned empty payload: {$res->body()}");
        }

        $first = $list[0] ?? null;
        $instrumentId = $first['instrument_id'] ?? $first['instrumentId'] ?? null;

        if (! $instrumentId) {
            throw new RuntimeException("instrument_id not found in response: {$res->body()}");
        }

        return (string) $instrumentId;
    }

    /**
     * Place order endpoint: /openapi/trade/stock/order/place
     * Uses the correct Webull OpenAPI endpoint with new_orders array format
     */
    private function placeOrder(array $p): array
    {
        $order = [
            'client_order_id' => (string) Str::uuid(),
            'side' => $p['side'],
            'tif' => $p['tif'],
            'extended_hours_trading' => (bool) $p['extended_hours_trading'],
            'instrument_id' => (string) $p['instrument_id'],
            'order_type' => $p['order_type'],
            'qty' => (string) $p['qty'],
        ];

        // Only include when needed (API rules)
        if (! empty($p['limit_price'])) {
            $order['limit_price'] = (string) $p['limit_price'];
        }
        if (! empty($p['stop_price'])) {
            $order['stop_price'] = (string) $p['stop_price'];
        }

        // Webull API requires orders to be in a new_orders array
        $payload = [
            'account_id' => $this->accountId,
            'new_orders' => [$order],
        ];

        \Log::info('Webull API: Placing order', [
            'endpoint' => '/trade/orders/place',
            'account_id' => $this->accountId,
            'order' => $order,
        ]);

        $res = $this->client->post('/trade/orders/place', [], $payload);

        \Log::info('Webull API: Place order response', [
            'status' => $res->status(),
            'body' => $res->body(),
        ]);

        if (! $res->successful()) {
            \Log::error('Webull API: Place order failed', [
                'status' => $res->status(),
                'body' => $res->body(),
            ]);
            throw new RuntimeException("Place order failed: HTTP {$res->status()} {$res->body()}");
        }

        $json = $res->json();

        // Check for error_code in response even with 200 status
        if (isset($json['error_code']) && $json['error_code'] !== 0) {
            $errorMsg = $json['message'] ?? 'Unknown error';
            \Log::error('Webull API: Order placement error in response', [
                'error_code' => $json['error_code'],
                'message' => $errorMsg,
            ]);
            throw new RuntimeException("Order placement failed: {$json['error_code']} - {$errorMsg}");
        }

        return $json;
    }

    /**
     * List positions held (paged).
     * Endpoint: GET /account/positions :contentReference[oaicite:3]{index=3}
     */
    public function listPositions(int $pageSize = 100, ?string $lastInstrumentId = null): array
    {
        $query = [
            'account_id' => $this->accountId,
            'page_size' => $pageSize,
        ];

        if ($lastInstrumentId) {
            $query['last_instrument_id'] = $lastInstrumentId;
        }

        \Log::info('Webull API: Listing positions', [
            'endpoint' => '/account/positions',
            'query' => $query,
        ]);

        $res = $this->client->get('/account/positions', $query);

        \Log::info('Webull API: List positions response', [
            'status' => $res->status(),
            'body' => $res->body(),
        ]);

        if (! $res->successful()) {
            \Log::error('Webull API: List positions failed', [
                'status' => $res->status(),
                'body' => $res->body(),
                'query' => $query,
            ]);
            throw new RuntimeException("List positions failed: HTTP {$res->status()} {$res->body()}");
        }

        return $res->json();
    }

    /**
     * List all orders placed today (paged).
     * Endpoint: GET /trade/orders/list-today :contentReference[oaicite:4]{index=4}
     */
    public function listTodayOrders(int $pageSize = 100, ?string $lastClientOrderId = null): array
    {
        $query = [
            'account_id' => $this->accountId,
            'page_size' => $pageSize,
        ];

        if ($lastClientOrderId) {
            $query['last_client_order_id'] = $lastClientOrderId;
        }

        \Log::info('Webull API: Listing today orders', [
            'endpoint' => '/trade/orders/list-today',
            'query' => $query,
        ]);

        $res = $this->client->get('/trade/orders/list-today', $query);

        \Log::info('Webull API: List today orders response', [
            'status' => $res->status(),
            'body' => $res->body(),
        ]);

        if (! $res->successful()) {
            \Log::error('Webull API: List today orders failed', [
                'status' => $res->status(),
                'body' => $res->body(),
                'query' => $query,
            ]);
            throw new RuntimeException("List today orders failed: HTTP {$res->status()} {$res->body()}");
        }

        return $res->json();
    }

    /**
     * List open/pending orders (paged).
     * Endpoint: GET /trade/orders/list-open :contentReference[oaicite:5]{index=5}
     */
    public function listOpenOrders(int $pageSize = 100, ?string $lastClientOrderId = null): array
    {
        $query = [
            'account_id' => $this->accountId,
            'page_size' => $pageSize,
        ];

        if ($lastClientOrderId) {
            $query['last_client_order_id'] = $lastClientOrderId;
        }

        \Log::info('Webull API: Listing open orders', [
            'endpoint' => '/trade/orders/list-open',
            'query' => $query,
        ]);

        $res = $this->client->get('/trade/orders/list-open', $query);

        \Log::info('Webull API: List open orders response', [
            'status' => $res->status(),
            'body' => $res->body(),
        ]);

        if (! $res->successful()) {
            \Log::error('Webull API: List open orders failed', [
                'status' => $res->status(),
                'body' => $res->body(),
                'query' => $query,
            ]);
            throw new RuntimeException("List open orders failed: HTTP {$res->status()} {$res->body()}");
        }

        return $res->json();
    }

    /**
     * Convenience: fetch ALL pages of positions.
     * Uses last_instrument_id paging :contentReference[oaicite:6]{index=6}
     */
    public function listAllPositions(int $pageSize = 100): array
    {
        $all = [];
        $last = null;

        while (true) {
            $page = $this->listPositions($pageSize, $last);

            $holdings = $page['holdings'] ?? [];
            foreach ($holdings as $h) {
                $all[] = $h;
                // last_instrument_id for next page comes from the last returned holding's instrument_id :contentReference[oaicite:7]{index=7}
                $last = $h['instrument_id'] ?? $last;
            }

            $hasNext = $page['has_next'] ?? $page['hasNext'] ?? false;
            if (! $hasNext || empty($holdings)) {
                break;
            }
        }

        // Log the first position to see available fields
        if (! empty($all)) {
            \Log::info('Webull Position Sample:', ['first_position' => $all[0]]);
        }

        return $all;
    }

    /**
     * Convenience: fetch ALL pages of today's orders.
     * Uses last_client_order_id paging :contentReference[oaicite:8]{index=8}
     */
    public function listAllTodayOrders(int $pageSize = 100): array
    {
        $all = [];
        $last = null;

        while (true) {
            $page = $this->listTodayOrders($pageSize, $last);

            $orders = $page['orders'] ?? [];
            foreach ($orders as $o) {
                // Flatten order data - merge order-level fields with first item
                $item = $o['items'][0] ?? [];
                $flatOrder = array_merge($item, [
                    'client_order_id' => $o['client_order_id'] ?? null,
                    'order_id' => $o['order_id'] ?? null,
                    'combo_type' => $o['combo_type'] ?? null,
                    'tif' => $o['tif'] ?? null,
                    'extended_hours_trading' => $o['extended_hours_trading'] ?? false,
                ]);
                $all[] = $flatOrder;
                $last = $o['client_order_id'] ?? $last; // paging token
            }

            $hasNext = $page['has_next'] ?? $page['hasNext'] ?? false;
            if (! $hasNext || empty($orders)) {
                break;
            }
        }

        return $all;
    }

    /**
     * Convenience: fetch ALL pages of open orders.
     * Uses last_client_order_id paging :contentReference[oaicite:10]{index=10}
     */
    public function listAllOpenOrders(int $pageSize = 100): array
    {
        $all = [];
        $last = null;

        while (true) {
            $page = $this->listOpenOrders($pageSize, $last);

            $orders = $page['orders'] ?? [];
            foreach ($orders as $o) {
                // Flatten order data - merge order-level fields with first item
                $item = $o['items'][0] ?? [];
                $flatOrder = array_merge($item, [
                    'client_order_id' => $o['client_order_id'] ?? null,
                    'order_id' => $o['order_id'] ?? null,
                    'combo_type' => $o['combo_type'] ?? null,
                    'tif' => $o['tif'] ?? null,
                    'extended_hours_trading' => $o['extended_hours_trading'] ?? false,
                ]);
                $all[] = $flatOrder;
                $last = $o['client_order_id'] ?? $last; // paging token
            }

            $hasNext = $page['has_next'] ?? $page['hasNext'] ?? false;
            if (! $hasNext || empty($orders)) {
                break;
            }
        }

        return $all;
    }
}
