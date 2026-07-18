<?php

use App\Models\DisclaimerAcceptance;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    Cache::flush();
});

test('daily prices are cached for 5 minutes', function () {
    $user = User::factory()->create(['email_verified_at' => now()]);
    DisclaimerAcceptance::recordAcceptance('127.0.0.1', 'Test');

    // First request - should hit database
    $queryCount = 0;
    DB::listen(function () use (&$queryCount) {
        $queryCount++;
    });

    $this->actingAs($user)
        ->get('/market-data/daily-prices')
        ->assertSuccessful();

    $firstQueryCount = $queryCount;

    // Second request - should use cache
    $queryCount = 0;

    $this->actingAs($user)
        ->get('/market-data/daily-prices')
        ->assertSuccessful();

    // Should have fewer queries due to caching
    expect($queryCount)->toBeLessThan($firstQueryCount);
});

test('daily prices cache key includes asset type filter', function () {
    $user = User::factory()->create(['email_verified_at' => now()]);
    DisclaimerAcceptance::recordAcceptance('127.0.0.1', 'Test');

    // Request with stock filter
    $this->actingAs($user)
        ->get('/market-data/daily-prices?asset_type=stock')
        ->assertSuccessful();

    // Verify cache key exists
    expect(Cache::has('daily-prices:type=stock:symbol=all:page=1'))->toBeTrue();

    Cache::flush();

    // Request with crypto filter
    $this->actingAs($user)
        ->get('/market-data/daily-prices?asset_type=crypto')
        ->assertSuccessful();

    // Verify different cache key
    expect(Cache::has('daily-prices:type=crypto:symbol=all:page=1'))->toBeTrue();
});

test('daily prices cache key includes pagination', function () {
    $user = User::factory()->create(['email_verified_at' => now()]);
    DisclaimerAcceptance::recordAcceptance('127.0.0.1', 'Test');

    // Request page 1
    $this->actingAs($user)
        ->get('/market-data/daily-prices?page=1')
        ->assertSuccessful();

    expect(Cache::has('daily-prices:type=stock:symbol=all:page=1'))->toBeTrue();

    // Request page 2
    $this->actingAs($user)
        ->get('/market-data/daily-prices?page=2')
        ->assertSuccessful();

    expect(Cache::has('daily-prices:type=stock:symbol=all:page=2'))->toBeTrue();
});
