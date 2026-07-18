<?php

use App\Models\User;
use App\UserRole;

use function Pest\Laravel\actingAs;

it('can access buy predictor page', function () {
    $user = User::factory()->create(['role' => UserRole::Trader]);

    $response = actingAs($user)->get('/buy-predictor');

    $response->assertSuccessful()
        ->assertInertia(fn ($page) => $page
            ->component('BuyPredictor')
            ->has('title')
            ->has('description')
            ->has('params')
        );
});

it('can run buy predictor analysis with parameters', function () {
    $user = User::factory()->create(['role' => UserRole::Trader]);

    $response = actingAs($user)->get('/buy-predictor', [
        'lookback_minutes' => 120,
        'asset_type' => 'stock',
        'min_score' => 6,
    ]);

    $response->assertSuccessful()
        ->assertInertia(fn ($page) => $page
            ->component('BuyPredictor')
            ->has('results')
            ->has('params') // Just check that params exist
        );
});

it('includes asset_id in analysis results for linking', function () {
    $user = User::factory()->create(['role' => UserRole::Trader]);

    $response = actingAs($user)->get('/buy-predictor', [
        'lookback_minutes' => 90,
        'asset_type' => 'stock',
        'min_score' => 8,
    ]);

    $response->assertSuccessful()
        ->assertInertia(fn ($page) => $page
            ->component('BuyPredictor')
            ->has('results.results.0.asset_id')
            ->has('results.results.0.symbol')
        );
});
