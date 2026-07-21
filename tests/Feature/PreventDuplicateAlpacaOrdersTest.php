<?php

use App\Models\AlpacaOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('prevents duplicate orders for the same symbol within 5 minutes', function () {
    // Create first order for TEST symbol
    AlpacaOrder::create([
        'symbol' => 'TEST',
        'qty' => 10,
        'side' => 'buy',
        'order_type' => 'market',
        'status' => 'filled',
        'client_order_id' => 'order-1-'.uniqid(),
        'alpaca_order_id' => 'alpaca-1-'.uniqid(),
        'paper' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    expect(AlpacaOrder::where('symbol', 'TEST')->count())->toBe(1);

    // Try to create another order for TEST within 5 minutes
    // Simulate the check that would happen in PlaceAlpacaOrderForHighScoreAlerts
    $recentBuyOrder = AlpacaOrder::query()
        ->where('symbol', 'TEST')
        ->where('side', 'buy')
        ->whereIn('status', ['filled', 'new', 'accepted', 'pending_new', 'partially_filled'])
        ->where('created_at', '>=', now()->subMinutes(5))
        ->exists();

    // The check should return true (order exists)
    expect($recentBuyOrder)->toBeTrue();
});

it('allows orders for the same symbol after 5 minutes', function () {
    // Create first order 6 minutes ago
    AlpacaOrder::create([
        'symbol' => 'TEST',
        'qty' => 10,
        'side' => 'buy',
        'order_type' => 'market',
        'status' => 'filled',
        'client_order_id' => 'order-old-'.uniqid(),
        'alpaca_order_id' => 'alpaca-old-'.uniqid(),
        'paper' => true,
        'created_at' => now()->subMinutes(6),
        'updated_at' => now()->subMinutes(6),
    ]);

    // Check if recent order exists (within 5 minutes)
    $recentBuyOrder = AlpacaOrder::query()
        ->where('symbol', 'TEST')
        ->where('side', 'buy')
        ->whereIn('status', ['filled', 'new', 'accepted', 'pending_new', 'partially_filled'])
        ->where('created_at', '>=', now()->subMinutes(5))
        ->exists();

    // The check should return false (no order within 5 minutes)
    expect($recentBuyOrder)->toBeFalse();
});

it('prevents any buy order for same symbol on the same day when cooldown is zero', function () {
    // Create order 6 minutes ago (passes 5-min check but should still fail the same-day guard)
    AlpacaOrder::create([
        'symbol' => 'TEST',
        'qty' => 10,
        'side' => 'buy',
        'order_type' => 'market',
        'status' => 'filled',
        'client_order_id' => 'order-today-'.uniqid(),
        'alpaca_order_id' => 'alpaca-today-'.uniqid(),
        'paper' => true,
        'created_at' => now()->subMinutes(6),
        'updated_at' => now()->subMinutes(6),
    ]);

    // Check same-day order
    $todayBuyOrder = AlpacaOrder::query()
        ->where('symbol', 'TEST')
        ->where('side', 'buy')
        ->whereIn('status', ['filled', 'new', 'accepted', 'pending_new', 'partially_filled'])
        ->whereDate('created_at', now()->timezone('America/New_York')->format('Y-m-d'))
        ->exists();

    // The check should return true (order exists today)
    expect($todayBuyOrder)->toBeTrue();
});

it('allows orders for different symbols', function () {
    // Create order for TEST
    AlpacaOrder::create([
        'symbol' => 'TEST',
        'qty' => 10,
        'side' => 'buy',
        'order_type' => 'market',
        'status' => 'filled',
        'client_order_id' => 'order-test-'.uniqid(),
        'alpaca_order_id' => 'alpaca-test-'.uniqid(),
        'paper' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    // Check for recent order for AAPL (different symbol)
    $recentBuyOrder = AlpacaOrder::query()
        ->where('symbol', 'AAPL')
        ->where('side', 'buy')
        ->whereIn('status', ['filled', 'new', 'accepted', 'pending_new', 'partially_filled'])
        ->where('created_at', '>=', now()->subMinutes(5))
        ->exists();

    // The check should return false (no AAPL order)
    expect($recentBuyOrder)->toBeFalse();
});
