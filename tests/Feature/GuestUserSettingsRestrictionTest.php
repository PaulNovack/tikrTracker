<?php

it('hides settings menu option for guest users in user dropdown', function () {
    $guestUser = \App\Models\User::factory()->create(['role' => \App\UserRole::Guest]);
    $traderUser = \App\Models\User::factory()->create(['role' => \App\UserRole::Trader]);

    // Test on a page that renders the user menu - let's use the dashboard

    // Guest user should see user dropdown but without Settings option
    $response = $this->actingAs($guestUser)
        ->get('/dashboard');

    $response->assertSuccessful();
    $response->assertInertia(fn ($page) => $page->where('auth.isGuest', true)
        ->has('auth.user')
    );

    // Trader user should see user dropdown with Settings option available
    $response = $this->actingAs($traderUser)
        ->get('/dashboard');

    $response->assertSuccessful();
    $response->assertInertia(fn ($page) => $page->where('auth.isGuest', false)
        ->has('auth.user')
    );
});

it('blocks guest users from accessing settings pages directly', function () {
    $guestUser = \App\Models\User::factory()->create(['role' => \App\UserRole::Guest]);
    $traderUser = \App\Models\User::factory()->create(['role' => \App\UserRole::Trader]);

    // Guest users should be blocked from profile settings
    $response = $this->actingAs($guestUser)
        ->get('/settings/profile');

    $response->assertForbidden();

    // Trader users should access profile settings
    $response = $this->actingAs($traderUser)
        ->get('/settings/profile');

    $response->assertSuccessful();
});
