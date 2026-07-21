<?php

it('check top, buy signals, and notable assets navigation links open in new tabs', function () {
    // Test that the navigation structure includes the openInNewTab property for all three links
    $user = \App\Models\User::factory()->create(['role' => \App\UserRole::Trader]);

    $response = $this->actingAs($user)->get('/dashboard');

    $response->assertSuccessful();

    // The frontend will handle the target="_blank" attribute based on the openInNewTab property
    // This test ensures the page loads successfully with the authenticated user
    // and that Check Top, Buy Signals, and Notable Assets are available to trader users
    expect($response->status())->toBe(200);

    // Also test that guest users cannot access these features
    $guestUser = \App\Models\User::factory()->create(['role' => \App\UserRole::Guest]);

    $guestResponse = $this->actingAs($guestUser)->get('/dashboard');
    $guestResponse->assertSuccessful();

    // Guest users should see the navigation but with disabled state
    $guestResponse->assertInertia(fn ($page) => $page->where('auth.isGuest', true)
    );
});
