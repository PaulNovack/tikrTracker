<?php

use App\Models\Deposit;
use App\Models\StockTransaction;
use App\Models\User;

use function Pest\Laravel\actingAs;

it('displays the dashboard with key metrics', function () {
    $user = User::factory()->create();

    Deposit::factory()->create([
        'user_id' => $user->id,
        'amount' => 5000.00,
    ]);

    StockTransaction::factory()->create([
        'user_id' => $user->id,
        'type' => 'buy',
        'quantity' => 10,
        'price_per_share' => 100.00,
    ]);

    actingAs($user);

    $page = visit('/dashboard');

    $page->assertSee('Dashboard')
        ->assertSee('Total Deposits')
        ->assertSee('Stock Transactions')
        ->assertNoJavascriptErrors();
});

it('allows users to update their profile', function () {
    $user = User::factory()->create([
        'name' => 'Old Name',
        'email' => 'old@example.com',
    ]);

    actingAs($user);

    $page = visit('/settings/profile');

    $page->assertSee('Profile')
        ->type('name', 'New Name')
        ->type('email', 'new@example.com')
        ->press('Save')
        ->wait(1)
        ->assertSee('Profile updated successfully')
        ->assertNoJavascriptErrors();

    expect($user->fresh()->name)->toBe('New Name')
        ->and($user->fresh()->email)->toBe('new@example.com');
});

it('allows users to change their password', function () {
    $user = User::factory()->create([
        'password' => bcrypt('oldpassword'),
    ]);

    actingAs($user);

    $page = visit('/settings/password');

    $page->assertSee('Change Password')
        ->type('current_password', 'oldpassword')
        ->type('password', 'newpassword123')
        ->type('password_confirmation', 'newpassword123')
        ->press('Update Password')
        ->wait(1)
        ->assertSee('Password updated successfully')
        ->assertNoJavascriptErrors();
});

it('allows users to toggle appearance theme', function () {
    $user = User::factory()->create();

    actingAs($user);

    $page = visit('/settings/appearance');

    $page->assertSee('Appearance')
        ->click('input[value="dark"]')
        ->wait(1)
        ->assertSee('Appearance updated successfully')
        ->assertNoJavascriptErrors();
});

it('allows users to enable two-factor authentication', function () {
    $user = User::factory()->create();

    actingAs($user);

    $page = visit('/settings/two-factor');

    $page->assertSee('Two-Factor Authentication')
        ->press('Enable')
        ->wait(1)
        ->assertSee('Please confirm access to your account')
        ->type('password', 'password')
        ->press('Confirm')
        ->wait(1)
        ->assertSee('Two-factor authentication is now enabled')
        ->assertNoJavascriptErrors();

    expect($user->fresh()->two_factor_secret)->not->toBeNull();
});

it('navigates through all main sections', function () {
    $user = User::factory()->create();

    actingAs($user);

    $page = visit('/dashboard');

    // Navigate to deposits
    $page->click('a[href="/deposits"]')
        ->wait(1)
        ->assertPathIs('/deposits')
        ->assertSee('Deposits');

    // Navigate to stock transactions
    $page->click('a[href="/stock-transactions"]')
        ->wait(1)
        ->assertPathIs('/stock-transactions')
        ->assertSee('Stock Transactions');

    // Navigate to market data
    $page->click('a[href="/market-data/assets"]')
        ->wait(1)
        ->assertPathIs('/market-data/assets')
        ->assertSee('Market Data');

    // Navigate to daily prices
    $page->click('a[href="/market-data/daily-prices"]')
        ->wait(1)
        ->assertPathIs('/market-data/daily-prices')
        ->assertSee('Daily Prices');

    // Back to dashboard
    $page->click('a[href="/dashboard"]')
        ->wait(1)
        ->assertPathIs('/dashboard')
        ->assertSee('Dashboard')
        ->assertNoJavascriptErrors();
});

it('displays empty states when no data exists', function () {
    $user = User::factory()->create();

    actingAs($user);

    $page = visit('/deposits');

    $page->assertSee('No deposits found')
        ->assertNoJavascriptErrors();

    $page = visit('/stock-transactions');
    $page->assertSee('No transactions found')
        ->assertNoJavascriptErrors();
});
