<?php

declare(strict_types=1);

use App\Models\AlpacaOrder;
use function Pest\Laravel\get;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

it('hides reconciled sell rows while keeping the real stop-loss sell visible', function () {
    AlpacaOrder::query()->create([
        'alpaca_order_id' => 'buy-1',
        'symbol' => 'ABC',
        'side' => 'buy',
        'status' => 'filled',
        'filled_qty' => 10,
        'filled_avg_price' => 100,
        'filled_at' => '2026-07-15 15:00:00',
        'submitted_at' => '2026-07-15 14:59:00',
        'is_paper' => true,
        'created_at' => '2026-07-15 14:59:00',
        'updated_at' => '2026-07-15 14:59:00',
    ]);

    AlpacaOrder::query()->create([
        'alpaca_order_id' => 'sell-1',
        'parent_alpaca_order_id' => 'buy-1',
        'symbol' => 'ABC',
        'side' => 'sell',
        'status' => 'filled',
        'filled_qty' => 10,
        'filled_avg_price' => 95,
        'stop_price' => 95,
        'filled_at' => '2026-07-15 15:30:00',
        'submitted_at' => '2026-07-15 15:29:00',
        'is_paper' => true,
        'created_at' => '2026-07-15 15:29:00',
        'updated_at' => '2026-07-15 15:29:00',
    ]);

    AlpacaOrder::query()->create([
        'alpaca_order_id' => 'reconciled-566d413e-4046-4f27-8f8f-5f5ba7eb2676',
        'parent_alpaca_order_id' => 'buy-1',
        'symbol' => 'ABC',
        'side' => 'sell',
        'status' => 'filled',
        'filled_qty' => 10,
        'filled_avg_price' => 94,
        'filled_at' => '2026-07-15 15:31:00',
        'submitted_at' => '2026-07-15 15:31:00',
        'is_paper' => true,
        'created_at' => '2026-07-15 15:31:00',
        'updated_at' => '2026-07-15 15:31:00',
    ]);

    $response = get('/alpaca-orders?start_date=2026-07-15&end_date=2026-07-15');

    $response->assertSuccessful();
    $response->assertInertia(fn ($page) => $page
        ->component('alpaca-orders/index')
        ->where('orders.data', function (array $orders) {
            return count($orders) === 2
                && collect($orders)->contains(fn (array $order) => $order['alpaca_order_id'] === 'sell-1' && $order['stop_price'] === '95')
                && collect($orders)->doesntContain(fn (array $order) => str_starts_with($order['alpaca_order_id'] ?? '', 'reconciled-'));
        })
    );
});