<?php

use App\Models\AssetInfo;
use App\Models\User;
use App\Models\Watch;

it('can access my hour page when authenticated', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get('/my-hour')
        ->assertSuccessful()
        ->assertInertia(fn ($page) => $page->component('MyHour'));
});

it('cannot access my hour page when not authenticated', function () {
    $this->get('/my-hour')
        ->assertRedirect('/login');
});

it('shows empty watchlist message when user has no watchlist items', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get('/my-hour')
        ->assertSuccessful()
        ->assertInertia(fn ($page) => $page
            ->component('MyHour')
            ->has('stocks', 0)
            ->where('totalAnalyzed', 0)
        );
});

it('filters watchlist by asset type', function () {
    $user = User::factory()->create();

    // Create stock and crypto assets
    $stockAsset = AssetInfo::factory()->create(['asset_type' => 'stock']);
    $cryptoAsset = AssetInfo::factory()->create(['asset_type' => 'crypto']);

    // Add both to user's watchlist
    Watch::factory()->create([
        'user_id' => $user->id,
        'asset_info_id' => $stockAsset->id,
    ]);

    Watch::factory()->create([
        'user_id' => $user->id,
        'asset_info_id' => $cryptoAsset->id,
    ]);

    // Test stock filter
    $this->actingAs($user)
        ->get('/my-hour?filter=stock')
        ->assertSuccessful()
        ->assertInertia(fn ($page) => $page
            ->component('MyHour')
            ->where('assetTypeFilter', 'stock')
        );

    // Test crypto filter
    $this->actingAs($user)
        ->get('/my-hour?filter=crypto')
        ->assertSuccessful()
        ->assertInertia(fn ($page) => $page
            ->component('MyHour')
            ->where('assetTypeFilter', 'crypto')
        );
});
