<?php

use App\Models\AlpacaOrder;
use App\Services\AlpacaPythonService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['alpaca.unfilled_order_cancel_minutes' => 30]);
});

it('cancels stale unfilled buy limit orders past the threshold', function () {
    $mock = $this->mock(AlpacaPythonService::class);
    $mock->shouldReceive('cancelOrderById')
        ->once()
        ->andReturn(['success' => true, 'output' => '']);
    $mock->shouldReceive('checkOrderStatus')->andReturn(['success' => false, 'error' => 'no orders']);

    $staleOrder = AlpacaOrder::factory()->create([
        'side' => 'buy',
        'order_type' => 'limit',
        'status' => 'new',
        'submitted_at' => now()->subMinutes(35),
        'stop_price' => 50.00,
    ]);

    $this->artisan('alpaca:sync-stop-loss-orders --all-orders')
        ->assertSuccessful();

    $staleOrder->refresh();
    expect($staleOrder->status)->toBe('canceled');
    expect($staleOrder->canceled_at)->not->toBeNull();
});

it('does not cancel buy limit orders within the threshold', function () {
    $mock = $this->mock(AlpacaPythonService::class);
    $mock->shouldReceive('cancelOrderById')->never();
    $mock->shouldReceive('checkOrderStatus')->andReturn(['success' => false, 'error' => 'no orders']);

    AlpacaOrder::factory()->create([
        'side' => 'buy',
        'order_type' => 'limit',
        'status' => 'new',
        'submitted_at' => now()->subMinutes(10),
        'stop_price' => 50.00,
    ]);

    $this->artisan('alpaca:sync-stop-loss-orders --all-orders')
        ->assertSuccessful();
});

it('does not cancel stale orders when threshold is disabled', function () {
    config(['alpaca.unfilled_order_cancel_minutes' => 0]);

    $mock = $this->mock(AlpacaPythonService::class);
    $mock->shouldReceive('cancelOrderById')->never();
    $mock->shouldReceive('checkOrderStatus')->andReturn(['success' => false, 'error' => 'no orders']);

    AlpacaOrder::factory()->create([
        'side' => 'buy',
        'order_type' => 'limit',
        'status' => 'new',
        'submitted_at' => now()->subHours(2),
        'stop_price' => 50.00,
    ]);

    $this->artisan('alpaca:sync-stop-loss-orders --all-orders')
        ->assertSuccessful();
});

it('does not cancel stale sell stop orders', function () {
    $mock = $this->mock(AlpacaPythonService::class);
    $mock->shouldReceive('cancelOrderById')->never();
    $mock->shouldReceive('checkOrderStatus')->andReturn(['success' => false, 'error' => 'no orders']);

    AlpacaOrder::factory()->stopLoss()->create([
        'status' => 'new',
        'submitted_at' => now()->subHours(2),
    ]);

    $this->artisan('alpaca:sync-stop-loss-orders --all-orders')
        ->assertSuccessful();
});

it('does not cancel in dry run mode', function () {
    $mock = $this->mock(AlpacaPythonService::class);
    $mock->shouldReceive('cancelOrderById')->never();
    $mock->shouldReceive('checkOrderStatus')->andReturn(['success' => false, 'error' => 'no orders']);

    $staleOrder = AlpacaOrder::factory()->create([
        'side' => 'buy',
        'order_type' => 'limit',
        'status' => 'new',
        'submitted_at' => now()->subMinutes(35),
        'stop_price' => 50.00,
    ]);

    $this->artisan('alpaca:sync-stop-loss-orders --all-orders --dry-run')
        ->assertSuccessful();

    $staleOrder->refresh();
    expect($staleOrder->status)->toBe('new');
});
