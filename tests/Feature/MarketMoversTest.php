<?php

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

it('can display market movers page', function () {
    $user = \App\Models\User::factory()->create(['role' => \App\UserRole::Trader]);

    $response = $this->actingAs($user)->get('/market-movers');

    $response->assertSuccessful();
    $response->assertInertia(function ($page) {
        $page->component('market-movers/index')
            ->has('data')
            ->has('days')
            ->has('avgStrength')
            ->has('startDate')
            ->has('endDate');
    });
});

it('can filter by days parameter', function () {
    $user = \App\Models\User::factory()->create(['role' => \App\UserRole::Trader]);

    $response = $this->actingAs($user)->get('/market-movers?days=7');

    $response->assertSuccessful();
    $response->assertInertia(function ($page) {
        $page->where('days', 7);
    });
});

it('limits days parameter between 1 and 365', function () {
    $user = \App\Models\User::factory()->create(['role' => \App\UserRole::Trader]);

    // Test maximum limit
    $response = $this->actingAs($user)->get('/market-movers?days=500');
    $response->assertSuccessful();
    $response->assertInertia(function ($page) {
        $page->where('days', 365);
    });

    // Test minimum limit
    $response = $this->actingAs($user)->get('/market-movers?days=0');
    $response->assertSuccessful();
    $response->assertInertia(function ($page) {
        $page->where('days', 1);
    });
});

it('defaults to 30 days when no parameter specified', function () {
    $user = \App\Models\User::factory()->create(['role' => \App\UserRole::Trader]);

    $response = $this->actingAs($user)->get('/market-movers');

    $response->assertSuccessful();
    $response->assertInertia(function ($page) {
        $page->where('days', 30);
    });
});

it('returns market movers data with top movers', function () {
    $user = \App\Models\User::factory()->create(['role' => \App\UserRole::Trader]);

    // Populate some test data
    \App\Models\MarketMover::create([
        'trading_date' => now()->subDays(1)->format('Y-m-d'),
        'bars_4pct_plus' => 100,
        'bars_5pct_plus' => 50,
        'bars_10pct_plus' => 10,
        'max_gain' => 15.5,
        'strength' => 50,
        'label' => 'MODERATE',
        'movers' => [
            ['symbol' => 'AAPL', 'gain_pct' => 10.5],
            ['symbol' => 'TSLA', 'gain_pct' => 8.3],
        ],
    ]);

    $response = $this->actingAs($user)->get('/market-movers?days=7');

    $response->assertSuccessful();
    $response->assertInertia(function ($page) {
        $page->has('data.0', function ($data) {
            $data->has('date')
                ->has('bars_4pct_plus')
                ->has('bars_5pct_plus')
                ->has('bars_10pct_plus')
                ->has('max_gain')
                ->has('strength')
                ->has('label')
                ->has('top_movers');
        });
    });
});

it('can export market movers data as csv', function () {
    $user = \App\Models\User::factory()->create(['role' => \App\UserRole::Trader]);

    // Populate some test data
    \App\Models\MarketMover::create([
        'trading_date' => now()->subDays(1)->format('Y-m-d'),
        'bars_4pct_plus' => 100,
        'bars_5pct_plus' => 50,
        'bars_10pct_plus' => 10,
        'max_gain' => 15.5,
        'strength' => 50,
        'label' => 'MODERATE',
        'movers' => [
            ['symbol' => 'AAPL', 'gain_pct' => 10.5],
        ],
    ]);

    $response = $this->actingAs($user)->get('/market-movers/export?days=7');

    $response->assertSuccessful();
    $response->assertHeader('Content-Type', 'text/csv; charset=utf-8');
});

it('requires authentication', function () {
    $response = $this->get('/market-movers');

    $response->assertRedirect('/login');
});
