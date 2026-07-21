<?php

use App\Models\User;
use App\UserRole;

it('can access buy window scanner page', function () {
    $user = User::factory()->create(['role' => UserRole::Trader]);

    $response = $this->actingAs($user)->get('/buy-window');

    $response->assertSuccessful();
    $response->assertInertia(fn ($page) => $page
        ->component('BuyWindow')
        ->missing('scanResults') // No results on initial load
    );
});

it('can run buy window scan with default parameters', function () {
    $user = User::factory()->create(['role' => UserRole::Trader]);

    $response = $this->actingAs($user)->post('/buy-window/scan', []);

    $response->assertSuccessful();
    $response->assertInertia(fn ($page) => $page
        ->component('BuyWindow')
        ->has('scanResults')
        ->has('scanResults.ok')
        ->has('scanResults.inWindow')
        ->has('scanResults.windowET')
        ->has('scanResults.endET')
        ->has('scanResults.signals')
    );
});

it('validates scan parameters correctly', function () {
    $user = User::factory()->create(['role' => UserRole::Trader]);

    // Test invalid parameters
    $response = $this->actingAs($user)->post('/buy-window/scan', [
        'min_score' => 25, // Too high
        'lookback' => 20,  // Too low
        'asset_type' => 'invalid',
    ]);

    $response->assertSessionHasErrors(['min_score', 'lookback', 'asset_type']);
});

it('buy window scan service returns valid structure', function () {
    $service = new \App\Services\BuyWindowScanService;

    $result = $service->scan();

    expect($result)->toHaveKey('ok');
    expect($result)->toHaveKey('inWindow');
    expect($result)->toHaveKey('windowET');
    expect($result)->toHaveKey('endET');
    expect($result)->toHaveKey('signals');
    expect($result['windowET'])->toBe('10:00:00-11:30:00');
    expect($result['signals'])->toBeArray();
});

it('can run buy window scan with custom datetime', function () {
    $user = User::factory()->create(['role' => UserRole::Trader]);

    // Test with a specific datetime in the buy window
    $customDateTime = '2025-12-16 10:30:00';

    $response = $this->actingAs($user)->post('/buy-window/scan', [
        'as_of_est' => $customDateTime,
        'asset_type' => 'stock',
        'min_score' => 6,
    ]);

    $response->assertSuccessful();
    $response->assertInertia(fn ($page) => $page
        ->component('BuyWindow')
        ->has('scanResults')
        ->where('scanResults.inWindow', true) // Should be in window at 10:30 AM
    );
});

it('recognizes time outside buy window', function () {
    $user = User::factory()->create(['role' => UserRole::Trader]);

    // Test with time outside the buy window
    $customDateTime = '2025-12-16 14:30:00'; // 2:30 PM - outside window

    $response = $this->actingAs($user)->post('/buy-window/scan', [
        'as_of_est' => $customDateTime,
        'asset_type' => 'stock',
        'min_score' => 6,
    ]);

    $response->assertSuccessful();
    $response->assertInertia(fn ($page) => $page
        ->component('BuyWindow')
        ->has('scanResults')
        ->where('scanResults.inWindow', false) // Should be outside window at 2:30 PM
        ->where('scanResults.message', 'Outside best buy window; no signals fired.')
    );
});
