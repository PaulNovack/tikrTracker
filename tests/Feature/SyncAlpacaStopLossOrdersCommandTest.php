<?php

use App\Models\AlpacaOrder;
use App\Services\AlpacaPythonService;
use Illuminate\Support\Facades\Log;

use function Pest\Laravel\mock;

it('checks open stop loss orders and updates filled status', function () {
    Log::spy();

    // Create a pending stop loss order
    $order = AlpacaOrder::factory()->create([
        'alpaca_order_id' => 'test-order-123',
        'order_type' => 'stop',
        'side' => 'sell',
        'status' => 'pending_new',
        'symbol' => 'AAPL',
        'stop_price' => 150.00,
        'filled_qty' => 0,
        'filled_avg_price' => null,
        'filled_at' => null,
    ]);

    // Mock AlpacaPythonService to return filled status
    $mockService = mock(AlpacaPythonService::class);
    $mockService->shouldReceive('checkOrderStatus')
        ->once()
        ->with('test-order-123')
        ->andReturn([
            'success' => true,
            'output' => json_encode([
                'order' => [
                    'status' => 'filled',
                    'filled_qty' => 10,
                    'filled_avg_price' => 149.50,
                    'filled_at' => now()->toISOString(),
                ],
            ]),
        ]);

    // Run the command
    $this->artisan('alpaca:sync-stop-loss-orders')
        ->expectsOutput('Checking open stop loss orders...')
        ->expectsOutput('Found 1 open orders to check.')
        ->assertExitCode(0);

    // Verify order was updated
    $order->refresh();
    expect($order->status)->toBe('filled')
        ->and((float) $order->filled_qty)->toBe(10.0)
        ->and((float) $order->filled_avg_price)->toBe(149.5)
        ->and($order->filled_at)->not->toBeNull();

    // Verify logging
    Log::shouldHaveReceived('info')
        ->with(\Mockery::pattern('/Stop loss order.*status changed/'), \Mockery::any())
        ->once();
});

it('checks open stop loss orders and updates canceled status', function () {
    Log::spy();

    // Create a pending stop loss order
    $order = AlpacaOrder::factory()->create([
        'alpaca_order_id' => 'test-order-456',
        'order_type' => 'stop',
        'side' => 'sell',
        'status' => 'new',
        'symbol' => 'MSFT',
        'stop_price' => 300.00,
    ]);

    // Mock AlpacaPythonService to return canceled status
    $mockService = mock(AlpacaPythonService::class);
    $mockService->shouldReceive('checkOrderStatus')
        ->once()
        ->with('test-order-456')
        ->andReturn([
            'success' => true,
            'output' => json_encode([
                'order' => [
                    'status' => 'canceled',
                    'canceled_at' => now()->toISOString(),
                ],
            ]),
        ]);

    // Run the command
    $this->artisan('alpaca:sync-stop-loss-orders')
        ->assertExitCode(0);

    // Verify order was updated
    $order->refresh();
    expect($order->status)->toBe('canceled')
        ->and($order->canceled_at)->not->toBeNull();
});

it('updates pending_cancel orders to canceled status', function () {
    Log::spy();

    // Create a pending_cancel stop loss order (simulating BE scenario)
    $order = AlpacaOrder::factory()->create([
        'alpaca_order_id' => 'test-order-pending-cancel',
        'order_type' => 'stop',
        'side' => 'sell',
        'status' => 'pending_cancel',
        'symbol' => 'BE',
        'stop_price' => 137.35,
    ]);

    // Mock AlpacaPythonService to return canceled status
    $mockService = mock(AlpacaPythonService::class);
    $mockService->shouldReceive('checkOrderStatus')
        ->once()
        ->with('test-order-pending-cancel')
        ->andReturn([
            'success' => true,
            'output' => json_encode([
                'order' => [
                    'status' => 'canceled',
                    'canceled_at' => now()->toISOString(),
                ],
            ]),
        ]);

    // Run the command
    $this->artisan('alpaca:sync-stop-loss-orders')
        ->assertExitCode(0);

    // Verify order was updated from pending_cancel to canceled
    $order->refresh();
    expect($order->status)->toBe('canceled')
        ->and($order->canceled_at)->not->toBeNull();
});

it('handles API errors gracefully', function () {
    Log::spy();

    // Create a pending stop loss order
    $order = AlpacaOrder::factory()->create([
        'alpaca_order_id' => 'test-order-error',
        'order_type' => 'stop',
        'side' => 'sell',
        'status' => 'pending_new',
        'symbol' => 'NVDA',
    ]);

    // Mock AlpacaPythonService to return error
    $mockService = mock(AlpacaPythonService::class);
    $mockService->shouldReceive('checkOrderStatus')
        ->once()
        ->with('test-order-error')
        ->andReturn([
            'success' => false,
            'error' => 'API rate limit exceeded',
        ]);

    // Run the command
    $this->artisan('alpaca:sync-stop-loss-orders')
        ->assertExitCode(0);

    // Verify order was NOT updated
    $order->refresh();
    expect($order->status)->toBe('pending_new');

    // Verify error was logged
    Log::shouldHaveReceived('warning')
        ->with(\Mockery::pattern('/Failed to check stop loss order status/'), \Mockery::any())
        ->once();
});

it('marks order canceled when alpaca reports order not found', function () {
    Log::spy();

    $order = AlpacaOrder::factory()->create([
        'alpaca_order_id' => 'test-order-not-found',
        'order_type' => 'stop',
        'side' => 'sell',
        'status' => 'pending_new',
        'symbol' => 'AMD',
        'canceled_at' => null,
    ]);

    $mockService = mock(AlpacaPythonService::class);
    $mockService->shouldReceive('checkOrderStatus')
        ->once()
        ->with('test-order-not-found')
        ->andReturn([
            'success' => false,
            'error' => 'alpaca.common.exceptions.APIError: {"code":40410000,"message":"order not found for test-order-not-found"}',
        ]);

    $this->artisan('alpaca:sync-stop-loss-orders')
        ->assertExitCode(0);

    $order->refresh();
    expect($order->status)->toBe('canceled')
        ->and($order->canceled_at)->not->toBeNull();

    Log::shouldHaveReceived('warning')
        ->with(\Mockery::pattern('/Stop loss order not found in Alpaca, marking canceled locally/'), \Mockery::any())
        ->once();
});

it('runs in dry-run mode without updating database', function () {
    // Create a pending stop loss order
    $order = AlpacaOrder::factory()->create([
        'alpaca_order_id' => 'test-order-dry',
        'order_type' => 'stop',
        'side' => 'sell',
        'status' => 'pending_new',
        'symbol' => 'TSLA',
    ]);

    // Mock AlpacaPythonService to return filled status
    $mockService = mock(AlpacaPythonService::class);
    $mockService->shouldReceive('checkOrderStatus')
        ->once()
        ->with('test-order-dry')
        ->andReturn([
            'success' => true,
            'output' => json_encode([
                'order' => [
                    'status' => 'filled',
                    'filled_qty' => 5,
                    'filled_avg_price' => 250.00,
                    'filled_at' => now()->toISOString(),
                ],
            ]),
        ]);

    // Run the command with --dry-run
    $this->artisan('alpaca:sync-stop-loss-orders --dry-run')
        ->expectsOutput('Running in DRY RUN mode - no changes will be made')
        ->assertExitCode(0);

    // Verify order was NOT updated
    $order->refresh();
    expect($order->status)->toBe('pending_new');
});

it('skips orders with unchanged status', function () {
    // Create a pending stop loss order
    $order = AlpacaOrder::factory()->create([
        'alpaca_order_id' => 'test-order-unchanged',
        'order_type' => 'stop',
        'side' => 'sell',
        'status' => 'new',
        'symbol' => 'GOOGL',
    ]);

    // Mock AlpacaPythonService to return same status
    $mockService = mock(AlpacaPythonService::class);
    $mockService->shouldReceive('checkOrderStatus')
        ->once()
        ->with('test-order-unchanged')
        ->andReturn([
            'success' => true,
            'output' => json_encode([
                'order' => [
                    'status' => 'new', // Same as current status
                ],
            ]),
        ]);

    // Run the command
    $this->artisan('alpaca:sync-stop-loss-orders')
        ->assertExitCode(0);

    // Verify updated_at wasn't changed
    $originalUpdatedAt = $order->updated_at;
    $order->refresh();
    expect($order->updated_at->equalTo($originalUpdatedAt))->toBeTrue();
});

it('reports no orders when none are pending', function () {
    // Don't create any orders

    // Run the command
    $this->artisan('alpaca:sync-stop-loss-orders')
        ->expectsOutput('No open stop loss orders found.')
        ->assertExitCode(0);
});
