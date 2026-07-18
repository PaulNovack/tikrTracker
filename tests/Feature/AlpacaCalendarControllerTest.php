<?php

use App\Models\AlpacaOrder;

use function Pest\Laravel\get;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

it('aggregates all sell legs for a buy order on the calendar', function () {
    AlpacaOrder::query()->create([
        'alpaca_order_id' => 'buy-1',
        'symbol' => 'ABC',
        'side' => 'buy',
        'status' => 'filled',
        'filled_qty' => 10,
        'filled_avg_price' => 100,
        'filled_at' => '2026-06-11 15:00:00',
        'submitted_at' => '2026-06-11 14:59:00',
        'is_paper' => true,
    ]);

    AlpacaOrder::query()->create([
        'alpaca_order_id' => 'sell-1',
        'parent_alpaca_order_id' => 'buy-1',
        'symbol' => 'ABC',
        'side' => 'sell',
        'status' => 'filled',
        'filled_qty' => 5,
        'filled_avg_price' => 110,
        'filled_at' => '2026-06-11 18:00:00',
        'submitted_at' => '2026-06-11 17:59:00',
        'is_paper' => true,
    ]);

    AlpacaOrder::query()->create([
        'alpaca_order_id' => 'sell-2',
        'parent_alpaca_order_id' => 'buy-1',
        'symbol' => 'ABC',
        'side' => 'sell',
        'status' => 'filled',
        'filled_qty' => 5,
        'filled_avg_price' => 120,
        'filled_at' => '2026-06-11 19:00:00',
        'submitted_at' => '2026-06-11 18:59:00',
        'is_paper' => true,
    ]);

    $response = get('/alpaca-calendar?mode=all&month=6&year=2026');

    $response->assertSuccessful();
    $response->assertInertia(function ($page) {
        $page->component('alpaca-calendar/index')
            ->where('summary.trading_days', 1)
            ->where('summary.total_pl', 150.0)
            ->where('dailyData.2026-06-11.trade_count', 1)
            ->where('dailyData.2026-06-11.total_pl', 150.0);
    });
});
