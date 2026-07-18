<?php

use App\Models\AssetInfo;
use App\Models\Deposit;
use App\Models\StockTransaction;
use App\Models\User;

use function Pest\Laravel\actingAs;

it('validates all public pages load without errors', function () {
    $pages = visit([
        '/',
        '/login',
        '/register',
        '/forgot-password',
    ]);

    $pages->assertNoJavascriptErrors()
        ->assertNoConsoleLogs();
});

it('validates all authenticated pages load without errors', function () {
    $user = User::factory()->create();
    $deposit = Deposit::factory()->create(['user_id' => $user->id]);
    $transaction = StockTransaction::factory()->create(['user_id' => $user->id]);
    $asset = AssetInfo::factory()->create();

    actingAs($user);

    $pages = visit([
        '/dashboard',
        '/deposits',
        '/deposits/create',
        "/deposits/{$deposit->id}/edit",
        '/stock-transactions',
        '/stock-transactions/create',
        "/stock-transactions/{$transaction->id}/edit",
        '/market-data/assets',
        "/market-data/assets/{$asset->id}",
        '/market-data/daily-prices',
        '/settings/profile',
        '/settings/password',
        '/settings/appearance',
        '/settings/two-factor',
    ]);

    $pages->assertNoJavascriptErrors()
        ->assertNoConsoleLogs();
});

it('validates form submissions work across the app', function () {
    $user = User::factory()->create([
        'email' => 'smoke@test.com',
        'password' => bcrypt('password'),
        'two_factor_secret' => null,
    ]);

    // Test login form
    $page = visit('/login');
    $page->type('email', 'smoke@test.com')
        ->type('password', 'password')
        ->press('Log in')
        ->wait(1)
        ->assertPathIs('/dashboard')
        ->assertNoJavascriptErrors();

    // Test deposit creation
    $page = visit('/deposits/create');
    $page->type('amount', '100.00')
        ->click('#deposited_at')
        ->fill('deposited_at', '2025-11-18T10:00')
        ->press('Add Deposit')
        ->wait(2)
        ->assertPathIs('/deposits')
        ->assertNoJavascriptErrors();

    // Test stock transaction creation
    $page = visit('/stock-transactions/create');
    $page->select('type', 'buy')
        ->type('symbol', 'TEST')
        ->type('quantity', '1')
        ->type('price_per_share', '100.00')
        ->type('transaction_date', '2025-11-18T10:00')
        ->press('Add Transaction')
        ->wait(1)
        ->assertPathIs('/stock-transactions')
        ->assertNoJavascriptErrors();

    // Test profile update
    $page = visit('/settings/profile');
    $page->type('name', 'Updated Name')
        ->press('Save')
        ->wait(1)
        ->assertSee('Profile updated successfully')
        ->assertNoJavascriptErrors();
});
