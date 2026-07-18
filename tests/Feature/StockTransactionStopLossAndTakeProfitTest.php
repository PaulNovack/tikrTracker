<?php

use App\Models\StockTransaction;
use App\Models\User;

it('allows setting stop loss, break even, and trailing dollar amounts', function () {
    $user = User::factory()->create();

    $transaction = StockTransaction::factory()->create([
        'user_id' => $user->id,
        'type' => 'buy',
        'price_per_share' => 100.00,
        'stop_loss' => 95.00,
        'break_even' => 102.00,
        'trailing' => 105.00,
    ]);

    expect($transaction->stop_loss)->toBe('95.00')
        ->and($transaction->break_even)->toBe('102.00')
        ->and($transaction->trailing)->toBe('105.00');
});

it('allows nullable stop loss, break even, and trailing', function () {
    $user = User::factory()->create();

    $transaction = StockTransaction::factory()->create([
        'user_id' => $user->id,
        'type' => 'buy',
        'price_per_share' => 100.00,
        'stop_loss' => null,
        'break_even' => null,
        'trailing' => null,
    ]);

    expect($transaction->stop_loss)->toBeNull()
        ->and($transaction->break_even)->toBeNull()
        ->and($transaction->trailing)->toBeNull();
});

it('stores dollar amounts with 2 decimal precision', function () {
    $user = User::factory()->create();

    $transaction = StockTransaction::factory()->create([
        'user_id' => $user->id,
        'type' => 'buy',
        'price_per_share' => 250.00,
        'stop_loss' => 237.50,
        'break_even' => 255.25,
        'trailing' => 260.75,
    ]);

    expect($transaction->stop_loss)->toBe('237.50')
        ->and($transaction->break_even)->toBe('255.25')
        ->and($transaction->trailing)->toBe('260.75');
});

it('creates buy transactions with custom dollar amounts', function () {
    $transaction = StockTransaction::factory()->buy()->create([
        'price_per_share' => 150.00,
        'stop_loss' => 142.50,
        'break_even' => 156.00,
        'trailing' => 162.00,
    ]);

    expect($transaction->stop_loss)->toBe('142.50')
        ->and($transaction->break_even)->toBe('156.00')
        ->and($transaction->trailing)->toBe('162.00');
});
