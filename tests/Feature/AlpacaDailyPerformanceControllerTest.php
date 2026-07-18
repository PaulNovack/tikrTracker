<?php

declare(strict_types=1);

use App\Models\AlpacaOrder;
use App\Models\TradeAlert;
use function Pest\Laravel\get;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

it('uses per-pipeline thresholds when min ml percent is env mode', function () {
    config()->set('trading.auto_alpaca_orders.ml_threshold_pipeline_a', 0.70);
    config()->set('trading.auto_alpaca_orders.ml_threshold_pipeline_b', 0.50);

    $now = now();

    $tradeAlertA = TradeAlert::unguarded(function () use ($now) {
        return TradeAlert::create([
            'symbol' => 'AAA',
            'asset_type' => 'stock',
            'trading_date_est' => $now->toDateString(),
            'as_of_ts_est' => $now,
            'signal_type' => 'TEST_SIGNAL',
            'signal_ts_est' => $now,
            'entry_type' => 'TEST_ENTRY',
            'entry_ts_est' => $now,
            'entry' => 10,
            'stop' => 9,
            'ml_win_prob' => 0.60,
            'version' => 'v1',
            'pipeline_run' => 'A',
            'dedupe_key' => 'alpaca-daily-performance-a-'.uniqid(),
        ]);
    });

    $tradeAlertB = TradeAlert::unguarded(function () use ($now) {
        return TradeAlert::create([
            'symbol' => 'BBB',
            'asset_type' => 'stock',
            'trading_date_est' => $now->toDateString(),
            'as_of_ts_est' => $now,
            'signal_type' => 'TEST_SIGNAL',
            'signal_ts_est' => $now,
            'entry_type' => 'TEST_ENTRY',
            'entry_ts_est' => $now,
            'entry' => 10,
            'stop' => 9,
            'ml_win_prob' => 0.60,
            'version' => 'v1',
            'pipeline_run' => 'B',
            'dedupe_key' => 'alpaca-daily-performance-b-'.uniqid(),
        ]);
    });

    AlpacaOrder::query()->create([
        'alpaca_order_id' => 'order-a',
        'trade_alert_id' => $tradeAlertA->id,
        'symbol' => 'AAA',
        'side' => 'buy',
        'status' => 'filled',
        'order_type' => 'market',
        'qty' => 10,
        'filled_qty' => 10,
        'filled_avg_price' => 10,
        'submitted_at' => $now->copy()->subMinutes(5),
        'filled_at' => $now->copy()->subMinutes(4),
        'is_paper' => true,
        'created_at' => $now->copy()->subMinutes(5),
        'updated_at' => $now->copy()->subMinutes(5),
    ]);

    AlpacaOrder::query()->create([
        'alpaca_order_id' => 'order-b',
        'trade_alert_id' => $tradeAlertB->id,
        'symbol' => 'BBB',
        'side' => 'buy',
        'status' => 'filled',
        'order_type' => 'market',
        'qty' => 10,
        'filled_qty' => 10,
        'filled_avg_price' => 10,
        'submitted_at' => $now->copy()->subMinutes(3),
        'filled_at' => $now->copy()->subMinutes(2),
        'is_paper' => true,
        'created_at' => $now->copy()->subMinutes(3),
        'updated_at' => $now->copy()->subMinutes(3),
    ]);

    $response = get('/alpaca-daily-performance?ml_threshold=-1');

    $response->assertSuccessful();
    $response->assertInertia(fn ($page) => $page
        ->component('alpaca-daily-performance/index')
        ->where('filters.ml_threshold', -1)
        ->where('dailyPerformance', function (array $dailyPerformance) {
            return count($dailyPerformance) === 1
                && collect($dailyPerformance)->first()['symbol_count'] === 1
                && collect($dailyPerformance)->first()['symbols'][0]['symbol'] === 'BBB';
        })
    );
});
