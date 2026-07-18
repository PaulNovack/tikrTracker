<?php

use App\Jobs\MonitorAlpacaOrderFillAndPlaceStopLoss;
use App\Models\AlpacaOrder;
use App\Services\AlpacaPythonService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class)->group('alpaca', 'stop-loss');

it('does not place stop loss when position no longer exists', function () {
    // Create a mock AlpacaPythonService
    $mockService = Mockery::mock(AlpacaPythonService::class);

    // Mock successful order status check (entry filled)
    $mockService->shouldReceive('checkOrderStatus')
        ->once()
        ->with('test-entry-order-123')
        ->andReturn([
            'success' => true,
            'output' => json_encode([
                'order' => [
                    'id' => 'test-entry-order-123',
                    'status' => 'filled',
                    'filled_qty' => 100,
                    'filled_avg_price' => 10.50,
                    'filled_at' => now()->toISOString(),
                ],
            ]),
        ]);

    // Mock position check - position does not exist (already sold)
    $mockService->shouldReceive('checkPosition')
        ->once()
        ->with('TEST')
        ->andReturn([
            'success' => true,
            'output' => json_encode([
                'position' => null, // No position exists
            ]),
        ]);

    // Should NOT call placeOrder since position is gone
    $mockService->shouldNotReceive('placeOrder');

    // Swap the service binding
    $this->app->instance(AlpacaPythonService::class, $mockService);

    // Create an entry order in the database
    $entryOrder = AlpacaOrder::create([
        'alpaca_order_id' => 'test-entry-order-123',
        'client_order_id' => 'client-123',
        'paper' => (bool) config('alpaca.paper_trading', true),
        'symbol' => 'TEST',
        'side' => 'buy',
        'qty' => 100,
        'status' => 'new',
        'order_type' => 'market',
        'time_in_force' => 'day',
        'submitted_at' => now(),
        'notes' => 'Test entry order',
    ]);

    // Create and handle the monitoring job
    $job = new MonitorAlpacaOrderFillAndPlaceStopLoss(
        entryAlpacaOrderDbId: $entryOrder->id,
        alertId: 12345,
        symbol: 'TEST',
        qty: 100,
        stopPrice: 10.00,
    );

    // Execute the job
    $job->handle($mockService);

    // Verify no stop loss order was created
    $stopLossCount = AlpacaOrder::where('order_type', 'stop')->count();
    expect($stopLossCount)->toBe(0);

    // Verify entry order was updated to filled
    $entryOrder->refresh();
    expect($entryOrder->status)->toBe('filled');
});

it('places stop loss when position exists', function () {
    // Create a mock AlpacaPythonService
    $mockService = Mockery::mock(AlpacaPythonService::class);

    // Mock successful order status check (entry filled)
    $mockService->shouldReceive('checkOrderStatus')
        ->once()
        ->with('test-entry-order-456')
        ->andReturn([
            'success' => true,
            'output' => json_encode([
                'order' => [
                    'id' => 'test-entry-order-456',
                    'status' => 'filled',
                    'filled_qty' => 100,
                    'filled_avg_price' => 10.50,
                    'filled_at' => now()->toISOString(),
                ],
            ]),
        ]);

    // Mock position check - position EXISTS
    $mockService->shouldReceive('checkPosition')
        ->once()
        ->with('TEST')
        ->andReturn([
            'success' => true,
            'output' => json_encode([
                'position' => [
                    'symbol' => 'TEST',
                    'qty' => 100,
                    'avg_entry_price' => 10.50,
                ],
            ]),
        ]);

    // Mock getOrders - returns no existing stop orders
    $mockService->shouldReceive('getOrders')
        ->once()
        ->with('open', 100)
        ->andReturn([
            'success' => true,
            'output' => json_encode([
                'orders' => [],
            ]),
        ]);

    // Mock successful stop loss placement
    // Note: Job calculates 2% stop (0.98), not 1%, for safety buffer
    $expectedStopPrice = round(10.50 * 0.98, 2); // $10.29
    $mockService->shouldReceive('placeOrder')
        ->once()
        ->with(
            'TEST',  // symbol
            100,     // qty
            'sell',  // side
            $expectedStopPrice,  // stopPrice
            null,    // takeProfitPrice
            null,    // stopLimit
            true     // stopOnly
            // fractional is not passed, uses default
        )
        ->andReturn([
            'success' => true,
            'output' => json_encode([
                'order' => [
                    'id' => 'test-stop-order-789',
                    'status' => 'new',
                    'order_type' => 'stop',
                    'submitted_at' => now()->toISOString(),
                ],
            ]),
        ]);

    // Swap the service binding
    $this->app->instance(AlpacaPythonService::class, $mockService);

    // Create an entry order in the database
    $entryOrder = AlpacaOrder::create([
        'alpaca_order_id' => 'test-entry-order-456',
        'client_order_id' => 'client-456',
        'paper' => (bool) config('alpaca.paper_trading', true),
        'symbol' => 'TEST',
        'side' => 'buy',
        'qty' => 100,
        'status' => 'new',
        'order_type' => 'market',
        'time_in_force' => 'day',
        'submitted_at' => now(),
        'notes' => 'Test entry order',
    ]);

    // Create and handle the monitoring job
    $job = new MonitorAlpacaOrderFillAndPlaceStopLoss(
        entryAlpacaOrderDbId: $entryOrder->id,
        alertId: 12345,
        symbol: 'TEST',
        qty: 100,
        stopPrice: 10.00,
    );

    // Execute the job
    $job->handle($mockService);

    // Verify stop loss order WAS created
    $stopLossCount = AlpacaOrder::where('order_type', 'stop')->count();
    expect($stopLossCount)->toBe(1);

    // Verify entry order was updated to filled
    $entryOrder->refresh();
    expect($entryOrder->status)->toBe('filled');
});

it('does not place stop loss when another stop order already exists at Alpaca', function () {
    // Create a mock AlpacaPythonService
    $mockService = Mockery::mock(AlpacaPythonService::class);

    // Mock successful order status check (entry filled)
    $mockService->shouldReceive('checkOrderStatus')
        ->once()
        ->with('test-entry-order-789')
        ->andReturn([
            'success' => true,
            'output' => json_encode([
                'order' => [
                    'id' => 'test-entry-order-789',
                    'status' => 'filled',
                    'filled_qty' => 201,
                    'filled_avg_price' => 15.75,
                    'filled_at' => now()->toISOString(),
                ],
            ]),
        ]);

    // Mock position check - position EXISTS
    $mockService->shouldReceive('checkPosition')
        ->once()
        ->with('OLMA')
        ->andReturn([
            'success' => true,
            'output' => json_encode([
                'position' => [
                    'symbol' => 'OLMA',
                    'qty' => 201,
                    'avg_entry_price' => 15.75,
                ],
            ]),
        ]);

    // Mock getOrders - returns existing stop order
    $mockService->shouldReceive('getOrders')
        ->once()
        ->with('open', 100)
        ->andReturn([
            'success' => true,
            'output' => json_encode([
                'orders' => [
                    [
                        'id' => '5acd8d75-c5b8-46f1-b074-772f047f9188',
                        'symbol' => 'OLMA',
                        'side' => 'sell',
                        'order_type' => 'stop',
                        'qty' => 201,
                        'stop_price' => 15.44,
                        'status' => 'new',
                    ],
                ],
            ]),
        ]);

    // Should NOT call placeOrder since stop order already exists
    $mockService->shouldNotReceive('placeOrder');

    // Swap the service binding
    $this->app->instance(AlpacaPythonService::class, $mockService);

    // Create an entry order in the database
    $entryOrder = AlpacaOrder::create([
        'alpaca_order_id' => 'test-entry-order-789',
        'client_order_id' => 'client-789',
        'symbol' => 'OLMA',
        'side' => 'buy',
        'qty' => 201,
        'status' => 'new',
        'order_type' => 'market',
        'time_in_force' => 'day',
        'submitted_at' => now(),
        'notes' => 'Test entry order',
        'paper' => (bool) config('alpaca.paper_trading', true),
    ]);

    // Create and handle the monitoring job
    $job = new MonitorAlpacaOrderFillAndPlaceStopLoss(
        entryAlpacaOrderDbId: $entryOrder->id,
        alertId: 148437,
        symbol: 'OLMA',
        qty: 201,
        stopPrice: 15.44,
    );

    // Execute the job
    $job->handle($mockService);

    // Verify no NEW stop loss order was created in database
    $stopLossCount = AlpacaOrder::where('order_type', 'stop')
        ->where('symbol', 'OLMA')
        ->count();
    expect($stopLossCount)->toBe(0);

    // Verify entry order was updated to filled
    $entryOrder->refresh();
    expect($entryOrder->status)->toBe('filled');
});

it('does not place stop loss when stop order already exists in database', function () {
    // Create a mock AlpacaPythonService
    $mockService = Mockery::mock(AlpacaPythonService::class);

    // Create a trade alert first (required for foreign key)
    $tradeAlert = \App\Models\TradeAlert::unguarded(function () {
        return \App\Models\TradeAlert::create([
            'symbol' => 'DBST',
            'asset_type' => 'stock',
            'trading_date_est' => now()->toDateString(),
            'as_of_ts_est' => now(),
            'signal_type' => 'TEST_SIGNAL',
            'signal_ts_est' => now(),
            'entry_type' => 'TEST_ENTRY',
            'entry_ts_est' => now(),
            'entry' => 20.00,
            'stop' => 19.60,
            'ml_win_prob' => 0.65,
            'dedupe_key' => 'test-db-check-'.uniqid(),
        ]);
    });

    // Mock successful order status check (entry filled)
    $mockService->shouldReceive('checkOrderStatus')
        ->once()
        ->with('test-entry-order-999')
        ->andReturn([
            'success' => true,
            'output' => json_encode([
                'order' => [
                    'id' => 'test-entry-order-999',
                    'status' => 'filled',
                    'filled_qty' => 50,
                    'filled_avg_price' => 20.00,
                    'filled_at' => now()->toISOString(),
                ],
            ]),
        ]);

    // Mock position check - position EXISTS
    $mockService->shouldReceive('checkPosition')
        ->once()
        ->with('DBST')
        ->andReturn([
            'success' => true,
            'output' => json_encode([
                'position' => [
                    'symbol' => 'DBST',
                    'qty' => 50,
                    'avg_entry_price' => 20.00,
                ],
            ]),
        ]);

    // Should NOT call getOrders or placeOrder since DB check happens first
    $mockService->shouldNotReceive('getOrders');
    $mockService->shouldNotReceive('placeOrder');

    // Swap the service binding
    $this->app->instance(AlpacaPythonService::class, $mockService);

    // Create an entry order in the database
    $entryOrder = AlpacaOrder::create([
        'alpaca_order_id' => 'test-entry-order-999',
        'client_order_id' => 'client-999',
        'symbol' => 'DBST',
        'side' => 'buy',
        'qty' => 50,
        'status' => 'new',
        'order_type' => 'market',
        'time_in_force' => 'day',
        'submitted_at' => now(),
        'notes' => 'Test entry order',
        'paper' => (bool) config('alpaca.paper_trading', true),
        'trade_alert_id' => $tradeAlert->id,
    ]);

    // Create existing stop order in database for same alert
    AlpacaOrder::create([
        'alpaca_order_id' => 'existing-stop-123',
        'client_order_id' => 'stop-client-123',
        'symbol' => 'DBST',
        'side' => 'sell',
        'qty' => 50,
        'status' => 'new',
        'order_type' => 'stop',
        'stop_price' => 19.60,
        'time_in_force' => 'gtc',
        'submitted_at' => now(),
        'notes' => 'Existing stop order',
        'paper' => (bool) config('alpaca.paper_trading', true),
        'trade_alert_id' => $tradeAlert->id, // Same alert ID
    ]);

    // Create and handle the monitoring job
    $job = new MonitorAlpacaOrderFillAndPlaceStopLoss(
        entryAlpacaOrderDbId: $entryOrder->id,
        alertId: $tradeAlert->id,
        symbol: 'DBST',
        qty: 50,
        stopPrice: 19.60,
    );

    // Execute the job
    $job->handle($mockService);

    // Verify only ONE stop loss order exists (the pre-existing one)
    $stopLossCount = AlpacaOrder::where('order_type', 'stop')
        ->where('symbol', 'DBST')
        ->count();
    expect($stopLossCount)->toBe(1);

    // Verify entry order was updated to filled
    $entryOrder->refresh();
    expect($entryOrder->status)->toBe('filled');
});

it('places stop loss for partial fill when limit order expires', function () {
    $mockService = Mockery::mock(AlpacaPythonService::class);

    // Entry order expired at EOD with a partial fill (50 of 100 shares)
    $mockService->shouldReceive('checkOrderStatus')
        ->once()
        ->with('partial-fill-order-999')
        ->andReturn([
            'success' => true,
            'output' => json_encode([
                'order' => [
                    'id' => 'partial-fill-order-999',
                    'status' => 'expired',
                    'filled_qty' => 50,
                    'filled_avg_price' => 20.00,
                    'filled_at' => now()->toISOString(),
                ],
            ]),
        ]);

    // Position exists for the partial fill
    $mockService->shouldReceive('checkPosition')
        ->once()
        ->with('PFIL')
        ->andReturn([
            'success' => true,
            'output' => json_encode([
                'position' => ['qty' => 50],
            ]),
        ]);

    // Should check for existing stop orders (deduplication)
    $mockService->shouldReceive('getOrders')
        ->with('open', 100)
        ->andReturn([
            'success' => true,
            'output' => json_encode(['orders' => []]),
        ]);

    // Should place a stop loss for the 50 filled shares
    $mockService->shouldReceive('placeOrder')
        ->once()
        ->withArgs(function ($symbol, $qty, $side, $stopPrice, $stopOnly) {
            return $symbol === 'PFIL'
                && $qty == 50
                && $side === 'sell'
                && $stopOnly === true
                && $stopPrice < 20.00;
        })
        ->andReturn([
            'success' => true,
            'output' => json_encode([
                'mode' => 'stop_only',
                'order' => [
                    'id' => 'stop-partial-999',
                    'status' => 'accepted',
                    'order_type' => 'stop',
                    'side' => 'sell',
                    'qty' => 50,
                    'stop_price' => 19.60,
                    'symbol' => 'PFIL',
                    'client_order_id' => 'client-stop-999',
                    'time_in_force' => 'gtc',
                    'submitted_at' => now()->toISOString(),
                    'asset_class' => 'us_equity',
                    'order_class' => '',
                ],
            ]),
        ]);

    $this->app->instance(AlpacaPythonService::class, $mockService);

    $tradeAlert = \App\Models\TradeAlert::unguarded(function () {
        return \App\Models\TradeAlert::create([
            'symbol' => 'PFIL',
            'asset_type' => 'stock',
            'trading_date_est' => now()->toDateString(),
            'as_of_ts_est' => now(),
            'signal_type' => 'TEST_SIGNAL',
            'signal_ts_est' => now(),
            'entry_type' => 'TEST_ENTRY',
            'entry_ts_est' => now(),
            'entry' => 20.00,
            'stop' => 19.60,
            'atr' => 0.40,
            'ml_win_prob' => 0.65,
            'dedupe_key' => 'test-partial-fill-'.uniqid(),
        ]);
    });

    $entryOrder = AlpacaOrder::create([
        'alpaca_order_id' => 'partial-fill-order-999',
        'client_order_id' => 'client-pfil-999',
        'paper' => (bool) config('alpaca.paper_trading', true),
        'symbol' => 'PFIL',
        'side' => 'buy',
        'qty' => 100,
        'status' => 'new',
        'order_type' => 'limit',
        'limit_price' => 20.10,
        'time_in_force' => 'day',
        'submitted_at' => now(),
        'trade_alert_id' => $tradeAlert->id,
        'notes' => 'Partial fill limit order',
    ]);

    $job = new MonitorAlpacaOrderFillAndPlaceStopLoss(
        entryAlpacaOrderDbId: $entryOrder->id,
        alertId: $tradeAlert->id,
        symbol: 'PFIL',
        qty: 100,
        stopPrice: 19.60,
    );

    $job->handle($mockService);

    // Stop loss should have been placed for the filled portion
    $stopLossCount = AlpacaOrder::where('order_type', 'stop')
        ->where('symbol', 'PFIL')
        ->count();
    expect($stopLossCount)->toBe(1);
});
