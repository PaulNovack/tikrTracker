<?php

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

it('can display gainers and losers page', function () {
    $user = \App\Models\User::factory()->create(['role' => \App\UserRole::Trader]);

    // Use a date when we know we have data
    $response = $this->actingAs($user)->get('/analysis/gainers-losers?date=2025-12-05');

    $response->assertSuccessful();
    $response->assertInertia(function ($page) {
        $page->component('GainersLosers/Index')
            ->has('title')
            ->has('description')
            ->has('gainers')
            ->has('losers')
            ->has('tradingDate')
            ->has('assetTypeFilter')
            ->has('topCount')
            ->has('summary');
    });
});

it('can filter by asset type', function () {
    $user = \App\Models\User::factory()->create(['role' => \App\UserRole::Trader]);

    // Use a date when we know we have data for crypto
    $response = $this->actingAs($user)->get('/analysis/gainers-losers?filter=crypto&date=2025-12-05');

    $response->assertSuccessful();
    $response->assertInertia(function ($page) {
        $page->where('assetTypeFilter', 'crypto');
    });
});

it('defaults to stock filter when no filter specified', function () {
    $user = \App\Models\User::factory()->create(['role' => \App\UserRole::Trader]);

    // Use a date when we know we have data
    $response = $this->actingAs($user)->get('/analysis/gainers-losers?date=2025-12-05');

    $response->assertSuccessful();
    $response->assertInertia(function ($page) {
        $page->where('assetTypeFilter', 'stock');
    });
});

it('can handle custom count parameter', function () {
    $user = \App\Models\User::factory()->create(['role' => \App\UserRole::Trader]);

    // Use a date when we know we have data
    $response = $this->actingAs($user)->get('/analysis/gainers-losers?count=25&date=2025-12-05');

    $response->assertSuccessful();
    $response->assertInertia(function ($page) {
        $page->where('topCount', 25);
    });
});

it('validates count parameter within bounds', function () {
    $user = \App\Models\User::factory()->create(['role' => \App\UserRole::Trader]);

    // Test count too high - use a date when we know we have data
    $response = $this->actingAs($user)->get('/analysis/gainers-losers?count=150&date=2025-12-05');
    $response->assertSuccessful();
    $response->assertInertia(function ($page) {
        $page->where('topCount', 50); // Should default to 50
    });

    // Test count too low - use a date when we know we have data
    $response = $this->actingAs($user)->get('/analysis/gainers-losers?count=-5&date=2025-12-05');
    $response->assertSuccessful();
    $response->assertInertia(function ($page) {
        $page->where('topCount', 50); // Should default to 50
    });
});
