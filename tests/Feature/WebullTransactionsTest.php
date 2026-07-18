<?php

use App\Models\StockTransaction;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;

uses(DatabaseTransactions::class);

it('can access webull transactions page when authenticated', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->get('/webull-transactions');

    $response->assertSuccessful();
    $response->assertInertia(fn ($page) => $page
        ->component('WebullTransactions')
    );
});

it('shows transactions with profit/loss calculations', function () {
    $user = User::factory()->create();

    // Create a buy transaction
    $buyTransaction = StockTransaction::factory()->create([
        'user_id' => $user->id,
        'symbol' => 'AAPL',
        'type' => 'buy',
        'quantity' => 100,
        'price_per_share' => 150.00,
        'total_amount' => 15000.00,
        'transaction_date' => now()->subDays(1),
    ]);

    // Create a sell transaction
    $sellTransaction = StockTransaction::factory()->create([
        'user_id' => $user->id,
        'symbol' => 'AAPL',
        'type' => 'sell',
        'quantity' => 100,
        'price_per_share' => 160.00,
        'total_amount' => 16000.00,
        'transaction_date' => now(),
        'stock_buy_id' => $buyTransaction->id,
    ]);

    $response = $this->actingAs($user)->get('/webull-transactions');

    $response->assertSuccessful();
    $response->assertInertia(fn ($page) => $page
        ->component('WebullTransactions')
        ->has('transactions.data')
    );
});

it('handles pagination correctly', function () {
    $user = User::factory()->create();

    // Create 30 transactions
    StockTransaction::factory()->count(30)->create([
        'user_id' => $user->id,
    ]);

    $response = $this->actingAs($user)->get('/webull-transactions');

    $response->assertSuccessful();
    $response->assertInertia(fn ($page) => $page
        ->component('WebullTransactions')
        ->has('transactions.data') // Just verify we have transactions data
        ->has('transactions.links') // And pagination links
    );
});

it('filters transactions by date range', function () {
    $user = User::factory()->create();

    // Create transactions with different dates
    StockTransaction::factory()->create([
        'user_id' => $user->id,
        'symbol' => 'AAPL',
        'type' => 'buy',
        'transaction_date' => '2024-01-01',
    ]);

    StockTransaction::factory()->create([
        'user_id' => $user->id,
        'symbol' => 'TSLA',
        'type' => 'buy',
        'transaction_date' => '2024-01-15',
    ]);

    StockTransaction::factory()->create([
        'user_id' => $user->id,
        'symbol' => 'MSFT',
        'type' => 'buy',
        'transaction_date' => '2024-01-31',
    ]);

    $response = $this->actingAs($user)->get('/webull-transactions?start_date=2024-01-10&end_date=2024-01-20');

    $response->assertSuccessful();

    $transactions = $response->viewData('page')['props']['transactions']['data'];
    expect($transactions)->toHaveCount(1);
    expect($transactions[0]['symbol'])->toBe('TSLA');
});

it('displays correct pagination counts with filters', function () {
    $user = User::factory()->create();

    // Create some transactions within and outside the date range
    StockTransaction::factory()->create([
        'user_id' => $user->id,
        'symbol' => 'AAPL',
        'type' => 'sell',
        'transaction_date' => '2024-01-15 10:30:00',
    ]);

    StockTransaction::factory()->create([
        'user_id' => $user->id,
        'symbol' => 'TSLA',
        'type' => 'sell',
        'transaction_date' => '2024-01-16 14:20:00',
    ]);

    StockTransaction::factory()->create([
        'user_id' => $user->id,
        'symbol' => 'MSFT',
        'type' => 'sell',
        'transaction_date' => '2024-02-01 09:15:00', // Outside range
    ]);

    $response = $this->actingAs($user)->get('/webull-transactions?start_date=2024-01-10&end_date=2024-01-20&type=sell');

    $response->assertSuccessful();
    $response->assertInertia(fn ($page) => $page
        ->component('WebullTransactions')
        ->has('transactions.data', 2) // Should have 2 transactions
        ->where('transactions.meta.total', 2) // Total should be 2
    );
});

it('calculates stats based on filtered date range', function () {
    $user = User::factory()->create();

    // Create buy transactions
    $buyJan = StockTransaction::factory()->create([
        'user_id' => $user->id,
        'symbol' => 'AAPL',
        'type' => 'buy',
        'price_per_share' => 100.00,
        'quantity' => 10,
        'transaction_date' => '2024-01-01',
    ]);

    $buyFeb = StockTransaction::factory()->create([
        'user_id' => $user->id,
        'symbol' => 'TSLA',
        'type' => 'buy',
        'price_per_share' => 200.00,
        'quantity' => 5,
        'transaction_date' => '2024-02-01',
    ]);

    // Create sell transactions - one in January range, one outside
    StockTransaction::factory()->create([
        'user_id' => $user->id,
        'symbol' => 'AAPL',
        'type' => 'sell',
        'price_per_share' => 110.00,  // $10 profit per share * 10 shares = $100 profit
        'quantity' => 10,
        'transaction_date' => '2024-01-15',
        'stock_buy_id' => $buyJan->id,
    ]);

    StockTransaction::factory()->create([
        'user_id' => $user->id,
        'symbol' => 'TSLA',
        'type' => 'sell',
        'price_per_share' => 250.00,  // $50 profit per share * 5 shares = $250 profit
        'quantity' => 5,
        'transaction_date' => '2024-02-15',
        'stock_buy_id' => $buyFeb->id,
    ]);

    // Filter to January range - should only include the AAPL trade
    $response = $this->actingAs($user)->get('/webull-transactions?start_date=2024-01-01&end_date=2024-01-31');

    $response->assertSuccessful();

    $stats = $response->viewData('page')['props']['stats'];
    expect($stats['total_trades'])->toBe(1);  // Only 1 sell transaction in January
    expect($stats['profitable_trades'])->toBe(1);  // The AAPL trade was profitable
    expect($stats['win_rate'])->toBe(100.0);  // 100% win rate
    expect($stats['total_profit'])->toBe(100.0);  // $10 * 10 shares profit
    expect($stats['total_loss'])->toBe(0);  // No losses in January (integer)
    expect($stats['net_profit_loss'])->toBe(100.0);  // Net profit
});
