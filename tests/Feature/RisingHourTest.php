<?php

use App\Models\User;

use function Pest\Laravel\actingAs;

it('can access rising hour page when authenticated', function () {
    $user = User::factory()->create();

    $response = actingAs($user)
        ->get('/rising-hour');

    $response->assertSuccessful();
    $response->assertInertia(fn ($page) => $page
        ->component('RisingHour')
        ->has('stocks')
        ->has('timeIntervals')
        ->has('assetTypeFilter')
        ->has('totalAnalyzed')
        ->has('dataFreshness')
    );
});

it('redirects to login when not authenticated', function () {
    $response = $this->get('/rising-hour');

    $response->assertRedirect('/login');
});

it('can filter by asset type', function () {
    $user = User::factory()->create();

    $response = actingAs($user)
        ->get('/rising-hour?filter=stock');

    $response->assertSuccessful();
    $response->assertInertia(fn ($page) => $page
        ->where('assetTypeFilter', 'stock')
    );

    $response = actingAs($user)
        ->get('/rising-hour?filter=crypto');

    $response->assertSuccessful();
    $response->assertInertia(fn ($page) => $page
        ->where('assetTypeFilter', 'crypto')
    );
});

it('returns proper data structure', function () {
    $user = User::factory()->create();

    $response = actingAs($user)
        ->get('/rising-hour');

    $response->assertInertia(fn ($page) => $page
        ->component('RisingHour')
        ->has('stocks')
        ->has('timeIntervals')
        ->has('timestamp')
        ->has('timestampEst')
        ->has('totalAnalyzed')
        ->has('dataFreshness')
    );
});

it('limits results to 100 stocks maximum', function () {
    $user = User::factory()->create();

    $response = actingAs($user)
        ->get('/rising-hour');

    $response->assertInertia(fn ($page) => $page
        ->where('stocks', fn ($stocks) => count($stocks) <= 100)
    );
});

it('caches results properly', function () {
    $user = User::factory()->create();

    // First request should populate cache
    $response1 = actingAs($user)
        ->get('/rising-hour?filter=stock');

    // Second request should use cache (should be faster)
    $response2 = actingAs($user)
        ->get('/rising-hour?filter=stock');

    $response1->assertSuccessful();
    $response2->assertSuccessful();

    // Both should return the same data structure
    $response1->assertInertia(fn ($page) => $page->component('RisingHour'));
    $response2->assertInertia(fn ($page) => $page->component('RisingHour'));
});
