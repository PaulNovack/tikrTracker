<?php

namespace App\Services\Webull;

use Illuminate\Support\Str;

class WebullOrderService
{
    public function __construct(private readonly WebullTradeClient $client) {}

    /**
     * MARKET order
     */
    public function placeMarket(
        string $accountId,
        string $symbol,
        string $side,   // BUY | SELL
        int $qty,
        string $tif = 'DAY'
    ): array {
        $order = [
            'client_order_id' => $this->clientOrderId(),
            'instrument_type' => 'EQUITY',
            'symbol' => $symbol,
            'market' => 'US',
            'side' => $side,
            'order_type' => 'MARKET',
            'quantity' => (string) $qty,
            'support_trading_session' => 'CORE',
            'entrust_type' => 'QTY',
            'time_in_force' => $tif,
            'combo_type' => 'NORMAL',
        ];

        // For stock orders, new_orders is a single object, not an array
        return $this->client->placeOrder($accountId, $order);
    }

    /**
     * LIMIT order
     */
    public function placeLimit(
        string $accountId,
        string $symbol,
        string $side,
        int $qty,
        string $limitPrice,
        string $tif = 'DAY'
    ): array {
        $order = [
            'client_order_id' => $this->clientOrderId(),
            'instrument_type' => 'EQUITY',
            'symbol' => $symbol,
            'market' => 'US',
            'side' => $side,
            'order_type' => 'LIMIT',
            'limit_price' => $limitPrice,
            'quantity' => (string) $qty,
            'support_trading_session' => 'CORE',
            'entrust_type' => 'QTY',
            'time_in_force' => $tif,
            'combo_type' => 'NORMAL',
        ];

        // For stock orders, new_orders is a single object, not an array
        return $this->client->placeOrder($accountId, $order);
    }

    /**
     * STOP_LOSS or STOP_LOSS_LIMIT or TRAILING_STOP_LOSS
     * (Provide the right fields; Webull lists which combos are required.)
     */
    public function placeAdvanced(string $accountId, array $stockOrder): array
    {
        // You can add your own validator rules here.
        if (empty($stockOrder['client_order_id'])) {
            $stockOrder['client_order_id'] = $this->clientOrderId();
        }

        return $this->client->placeOrder($accountId, $stockOrder);
    }

    private function clientOrderId(): string
    {
        // Must be <= 40 chars and unique per docs
        return Str::uuid()->toString(); // 36 chars
    }
}
