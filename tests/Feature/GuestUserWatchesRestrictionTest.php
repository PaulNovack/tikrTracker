<?php

it('hides add to watches button for guest users on asset detail page', function () {
    $asset = \App\Models\AssetInfo::factory()->create();
    $guestUser = \App\Models\User::factory()->create(['role' => \App\UserRole::Guest]);
    $traderUser = \App\Models\User::factory()->create(['role' => \App\UserRole::Trader]);

    // Guest user should see the page but without watches functionality
    $response = $this->actingAs($guestUser)
        ->get("/market-data/assets/{$asset->id}");

    $response->assertSuccessful();
    // The response should contain asset info but render with isGuest = true
    $response->assertInertia(fn ($page) => $page->has('asset')
        ->where('auth.isGuest', true)
    );

    // Trader user should see the page with watches functionality
    $response = $this->actingAs($traderUser)
        ->get("/market-data/assets/{$asset->id}");

    $response->assertSuccessful();
    $response->assertInertia(fn ($page) => $page->has('asset')
        ->where('auth.isGuest', false)
    );
});
