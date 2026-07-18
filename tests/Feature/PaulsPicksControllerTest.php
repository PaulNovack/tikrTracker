<?php

use App\Models\User;

it('can display pauls picks page', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->get('/pauls-picks');

    $response->assertSuccessful();
    $response->assertInertia(fn ($page) => $page
        ->component('PaulsPicks/Index')
        ->has('title')
        ->has('description')
        ->has('picks')
        ->has('analysisSummary')
        ->has('totalPicks')
        ->has('assetTypeFilter')
        ->has('currentParams')
    );
});

it('can handle time parameter for historical analysis', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->get('/pauls-picks?time=2025-12-05 10:00:00');

    $response->assertSuccessful();
    $response->assertInertia(fn ($page) => $page
        ->component('PaulsPicks/Index')
        ->where('time', '2025-12-05 10:00:00')
    );
});

it('can filter by asset type', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->get('/pauls-picks?filter=crypto');

    $response->assertSuccessful();
    $response->assertInertia(fn ($page) => $page
        ->component('PaulsPicks/Index')
        ->where('assetTypeFilter', 'crypto')
    );
});

it('defaults to stock filter when no filter specified', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->get('/pauls-picks');

    $response->assertSuccessful();
    $response->assertInertia(fn ($page) => $page
        ->component('PaulsPicks/Index')
        ->where('assetTypeFilter', 'stock')
    );
});

it('can handle custom analysis parameters', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->get('/pauls-picks?lookback=180&max_drawdown=0.02&min_trend=0.01');

    $response->assertSuccessful();
    $response->assertInertia(fn ($page) => $page
        ->component('PaulsPicks/Index')
        ->where('currentParams.lookback', 180)
        ->where('currentParams.max_drawdown', 0.02)
        ->where('currentParams.min_trend', 0.01)
    );
});
