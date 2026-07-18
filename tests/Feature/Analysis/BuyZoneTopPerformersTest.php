<?php

use App\Models\User;

test('guests cannot access buy zone top performers page', function () {
    $response = $this->get('/analysis/buy-zone-top-performers');

    $response->assertRedirect('/login');
});

test('authenticated users can access buy zone top performers page', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->get('/analysis/buy-zone-top-performers');

    $response->assertSuccessful();
    $response->assertInertia(fn ($page) => $page
        ->component('analysis/BuyZoneTopPerformers')
        ->has('candidates')
        ->has('totalTopPerformers')
        ->has('filters')
    );
});

test('buy zone page applies asset type filter', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->get('/analysis/buy-zone-top-performers?assetType=crypto');

    $response->assertSuccessful();
    $response->assertInertia(fn ($page) => $page
        ->where('filters.assetType', 'crypto')
    );
});

test('buy zone page applies days filter', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->get('/analysis/buy-zone-top-performers?days=14');

    $response->assertSuccessful();
    $response->assertInertia(fn ($page) => $page
        ->where('filters.days', 14)
    );
});

test('buy zone candidates have required structure', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->get('/analysis/buy-zone-top-performers');

    $response->assertSuccessful();
    $response->assertInertia(fn ($page) => $page
        ->has('candidates', fn ($candidates) => $candidates->each(fn ($candidate) => $candidate->has('symbol')
            ->has('high_7d')
            ->has('low_7d')
            ->has('current_price')
            ->has('dist_from_7d_high_pct')
            ->has('retracement_pct')
            ->has('vwap_reclaimed')
            ->has('ema_state')
            ->has('rvol')
        )
        )
    );
});
