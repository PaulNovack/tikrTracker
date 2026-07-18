<?php

use App\Models\StockTransaction;
use App\Models\User;

use function Pest\Laravel\actingAs;

it('allows users to create a buy transaction with stop loss and take profit', function () {
    $user = User::factory()->create();

    actingAs($user);

    $page = visit('/stock-transactions');

    $page->assertSee('Stock Transactions')
        ->click('Add Transaction')
        ->wait(1)
        ->assertPathIs('/stock-transactions/create')
        ->assertSee('Add Stock Transaction')
        ->select('type', 'buy')
        ->type('symbol', 'AAPL')
        ->type('quantity', '10')
        ->type('price_per_share', '150.00')
        ->type('stop_loss', '0.025')
        ->type('break_even', '0.04')
        ->type('trailing', '0.08')
        ->type('fee', '5.00')
        ->type('transaction_date', '2025-11-18T10:00')
        ->type('notes', 'First AAPL purchase')
        ->press('Add Transaction')
        ->wait(1)
        ->assertPathIs('/stock-transactions')
        ->assertSee('Stock transaction added successfully')
        ->assertSee('AAPL')
        ->assertSee('10.00000000')
        ->assertNoJavascriptErrors();

    $transaction = StockTransaction::where('user_id', $user->id)->first();
    expect($transaction->stop_loss)->toBe('0.0250')
        ->and($transaction->break_even)->toBe('0.0400')
        ->and($transaction->trailing)->toBe('0.0800');
});

it('allows users to create a sell transaction linked to a buy', function () {
    $user = User::factory()->create();
    $buyTransaction = StockTransaction::factory()->create([
        'user_id' => $user->id,
        'type' => 'buy',
        'symbol' => 'TSLA',
        'quantity' => 5,
        'price_per_share' => 200.00,
    ]);

    actingAs($user);

    $page = visit('/stock-transactions/create');

    $page->select('type', 'sell')
        ->wait(1)
        ->select('stock_buy_id', (string) $buyTransaction->id)
        ->wait(1)
        ->type('current_price_per_share', '250.00')
        ->type('fee', '3.00')
        ->type('transaction_date', '2025-11-18T14:00')
        ->press('Add Transaction')
        ->wait(1)
        ->assertPathIs('/stock-transactions')
        ->assertSee('Stock transaction added successfully')
        ->assertNoJavascriptErrors();

    $sellTransaction = StockTransaction::where('type', 'sell')
        ->where('stock_buy_id', $buyTransaction->id)
        ->first();

    expect($sellTransaction)->not->toBeNull()
        ->and($sellTransaction->symbol)->toBe('TSLA')
        ->and($sellTransaction->quantity)->toBe('5.00000000');
});

it('displays remaining quantity for buy transactions', function () {
    $user = User::factory()->create();
    $buyTransaction = StockTransaction::factory()->create([
        'user_id' => $user->id,
        'type' => 'buy',
        'symbol' => 'NVDA',
        'quantity' => 10,
        'price_per_share' => 500.00,
    ]);

    // Create partial sell
    StockTransaction::factory()->create([
        'user_id' => $user->id,
        'type' => 'sell',
        'stock_buy_id' => $buyTransaction->id,
        'symbol' => 'NVDA',
        'quantity' => 3,
        'price_per_share' => 500.00,
        'current_price_per_share' => 550.00,
    ]);

    actingAs($user);

    $page = visit('/stock-transactions');

    $page->assertSee('NVDA')
        ->assertSee('Remaining: 7.00')
        ->assertNoJavascriptErrors();
});

it('allows editing a stock transaction', function () {
    $user = User::factory()->create();
    $transaction = StockTransaction::factory()->create([
        'user_id' => $user->id,
        'type' => 'buy',
        'symbol' => 'MSFT',
        'quantity' => 8,
        'price_per_share' => 300.00,
        'notes' => 'Original note',
    ]);

    actingAs($user);

    $page = visit('/stock-transactions');

    $page->click("a[href='/stock-transactions/{$transaction->id}/edit']")
        ->wait(1)
        ->assertPathIs("/stock-transactions/{$transaction->id}/edit")
        ->assertSee('Edit Stock Transaction')
        ->type('quantity', '10')
        ->type('notes', 'Updated note')
        ->press('Update Transaction')
        ->wait(1)
        ->assertPathIs('/stock-transactions')
        ->assertSee('Stock transaction updated successfully')
        ->assertNoJavascriptErrors();

    expect($transaction->fresh()->quantity)->toBe('10.00000000');
});

it('calculates profit/loss for sell transactions', function () {
    $user = User::factory()->create();
    $buyTransaction = StockTransaction::factory()->create([
        'user_id' => $user->id,
        'type' => 'buy',
        'symbol' => 'AMD',
        'quantity' => 10,
        'price_per_share' => 100.00,
        'fee' => 5.00,
    ]);

    StockTransaction::factory()->create([
        'user_id' => $user->id,
        'type' => 'sell',
        'stock_buy_id' => $buyTransaction->id,
        'symbol' => 'AMD',
        'quantity' => 10,
        'price_per_share' => 100.00,
        'current_price_per_share' => 120.00,
        'fee' => 3.00,
    ]);

    actingAs($user);

    $page = visit('/stock-transactions');

    $page->assertSee('AMD')
        ->assertSee('sell')
        ->assertNoJavascriptErrors();
});
