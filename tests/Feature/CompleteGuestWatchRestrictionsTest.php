<?php

use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('restricts watch functionality for guest users', function () {
    $asset = \App\Models\AssetInfo::factory()->create();
    $guestUser = \App\Models\User::factory()->create(['role' => \App\UserRole::Guest]);
    $traderUser = \App\Models\User::factory()->create(['role' => \App\UserRole::Trader]);

    // Frontend: Guest should not see "Add to Watches" button on asset page
    $response = $this->actingAs($guestUser)
        ->get("/market-data/assets/{$asset->id}");

    $response->assertSuccessful();
    $response->assertInertia(fn ($page) => $page->has('asset')
        ->where('auth.isGuest', true)
    );

    // Frontend: Trader should see "Add to Watches" button
    $response = $this->actingAs($traderUser)
        ->get("/market-data/assets/{$asset->id}");

    $response->assertSuccessful();
    $response->assertInertia(fn ($page) => $page->has('asset')
        ->where('auth.isGuest', false)
    );

    // Backend: Guest should be blocked from watches routes
    $watchData = ['asset_info_id' => $asset->id];

    // POST /watches - blocked for guest
    $response = $this->withoutMiddleware([
        \App\Http\Middleware\CheckDisclaimerAcceptance::class,
        \Illuminate\Auth\Middleware\Authenticate::class,
    ])->actingAs($guestUser)
        ->post('/watches', $watchData);
    $response->assertForbidden();

    // GET /watches - blocked for guest
    $response = $this->withoutMiddleware([
        \App\Http\Middleware\CheckDisclaimerAcceptance::class,
        \Illuminate\Auth\Middleware\Authenticate::class,
    ])->actingAs($guestUser)
        ->get('/watches');
    $response->assertForbidden();

    // GET /watches/settings - blocked for guest
    $response = $this->withoutMiddleware([
        \App\Http\Middleware\CheckDisclaimerAcceptance::class,
        \Illuminate\Auth\Middleware\Authenticate::class,
    ])->actingAs($guestUser)
        ->get('/watches/settings');
    $response->assertForbidden();

    // Backend: Trader should access watches routes
    $response = $this->actingAs($traderUser)
        ->post('/watches', $watchData);
    $response->assertRedirect();

    $response = $this->actingAs($traderUser)
        ->get('/watches');
    $response->assertSuccessful();

    $response = $this->actingAs($traderUser)
        ->get('/watches/settings');
    $response->assertSuccessful();
});
