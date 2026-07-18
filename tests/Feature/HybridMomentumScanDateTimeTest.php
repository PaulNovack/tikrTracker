<?php

use App\Models\User;
use App\UserRole;

it('can access hybrid momentum scan page with datetime selection', function () {
    $user = User::factory()->create(['role' => UserRole::Trader]);

    $response = $this->actingAs($user)->get('/hybrid-momentum-scan');

    $response->assertSuccessful();
    $response->assertInertia(fn ($page) => $page
        ->component('HybridMomentumScan')
        ->missing('scanResults') // No results on initial load
    );
});

it('can run hybrid momentum scan with custom datetime', function () {
    $user = User::factory()->create(['role' => UserRole::Trader]);

    // Test with a specific datetime
    $customDateTime = '2025-12-15 14:30:00';

    $response = $this->actingAs($user)->post('/hybrid-momentum-scan/scan', [
        'as_of_est' => $customDateTime,
        'asset_type' => 'stock',
        'min_score' => 5,
    ]);

    $response->assertSuccessful();
    $response->assertInertia(fn ($page) => $page
        ->component('HybridMomentumScan')
        ->has('scanResults')
        ->has('scanResults.results')
        ->has('scanResults.meta')
        ->where('scanResults.meta.asset_type', 'stock')
        ->where('scanResults.meta.min_score', 5)
    );
});

it('can run hybrid momentum scan without custom datetime (uses current time)', function () {
    $user = User::factory()->create(['role' => UserRole::Trader]);

    $response = $this->actingAs($user)->post('/hybrid-momentum-scan/scan', [
        'asset_type' => 'stock',
        'min_score' => 5,
        // No as_of_est parameter - should use current time
    ]);

    $response->assertSuccessful();
    $response->assertInertia(fn ($page) => $page
        ->component('HybridMomentumScan')
        ->has('scanResults')
        ->has('scanResults.results')
        ->has('scanResults.meta')
        ->where('scanResults.meta.asset_type', 'stock')
        ->where('scanResults.meta.min_score', 5)
    );
});
