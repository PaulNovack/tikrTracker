<?php

use App\Models\User;
use App\Services\HybridMomentumScanService;
use App\UserRole;

use function Pest\Laravel\actingAs;

it('can access hybrid momentum scan page', function () {
    $user = User::factory()->create(['role' => UserRole::Trader]);

    $response = actingAs($user)->get('/hybrid-momentum-scan');

    $response->assertSuccessful()
        ->assertInertia(fn ($page) => $page
            ->component('HybridMomentumScan')
        );
});

it('can run hybrid momentum scan with default parameters', function () {
    $user = User::factory()->create(['role' => UserRole::Trader]);

    $response = actingAs($user)->post('/hybrid-momentum-scan/scan', [
        'asset_type' => 'stock',
        'min_score' => 5,
    ]);

    $response->assertSuccessful()
        ->assertInertia(fn ($page) => $page
            ->component('HybridMomentumScan')
            ->has('scanResults')
            ->has('scanResults.results')
            ->has('scanResults.meta')
            ->where('scanResults.meta.asset_type', 'stock')
            ->where('scanResults.meta.min_score', 5)
        );
});

it('validates scan parameters correctly', function () {
    $user = User::factory()->create(['role' => UserRole::Trader]);

    $response = actingAs($user)->post('/hybrid-momentum-scan/scan', [
        'asset_type' => 'invalid',
        'min_score' => 15, // above max
    ]);

    $response->assertSessionHasErrors(['asset_type', 'min_score']);
});

it('hybrid momentum scan service returns valid structure', function () {
    $service = new HybridMomentumScanService;

    $results = $service->scan(null, 'stock', 5);

    expect($results)->toHaveKeys(['results', 'meta'])
        ->and($results['meta'])->toHaveKeys(['as_of', 'asset_type', 'min_score', 'candidates_found'])
        ->and($results['meta']['asset_type'])->toBe('stock')
        ->and($results['meta']['min_score'])->toBe(5);

    if (! empty($results['results'])) {
        expect($results['results'][0])->toHaveKeys([
            'symbol', 'score', 'last_price', 'pct_60m', 'pct_30m', 'pct_15m',
            'volume_boost', 'dist_from_vwap_pct', 'atr_like', 'avg_dollar_volume',
            'topping_pattern', 'reasons', 'vwap',
        ]);
    }
});
