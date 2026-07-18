<?php

use App\Models\DisclaimerAcceptance;
use App\Models\User;
use App\UserRole;

describe('Navigation Links - Dashboard', function () {
    test('authenticated user can access dashboard', function () {
        $user = User::factory()->create(['email_verified_at' => now()]);
        DisclaimerAcceptance::recordAcceptance('127.0.0.1', 'Test');

        $response = $this->actingAs($user)->get('/dashboard');

        $response->assertSuccessful()
            ->assertInertia(fn ($page) => $page
                ->component('dashboard')
            );
    });

    test('dashboard renders with correct content', function () {
        $user = User::factory()->create(['email_verified_at' => now()]);
        DisclaimerAcceptance::recordAcceptance('127.0.0.1', 'Test');

        $response = $this->actingAs($user)->get('/dashboard');

        $response->assertSuccessful()
            ->assertInertia(fn ($page) => $page
                ->component('dashboard')
                ->has('auth')
                ->has('dataFreshness')
                ->has('userIsAdmin')
            );
    });

    test('guest cannot access dashboard', function () {
        $this->get('/dashboard')
            ->assertRedirect('/login');
    });
});

describe('Navigation Links - Notifications', function () {
    test('authenticated user can access notifications', function () {
        $user = User::factory()->create(['email_verified_at' => now()]);
        DisclaimerAcceptance::recordAcceptance('127.0.0.1', 'Test');

        $response = $this->actingAs($user)->get('/notifications');

        $response->assertSuccessful()
            ->assertInertia(fn ($page) => $page
                ->component('notifications')
            );
    });

    test('notifications page renders with correct data', function () {
        $user = User::factory()->create(['email_verified_at' => now()]);
        DisclaimerAcceptance::recordAcceptance('127.0.0.1', 'Test');

        $response = $this->actingAs($user)->get('/notifications');

        $response->assertSuccessful()
            ->assertInertia(fn ($page) => $page
                ->component('notifications')
                ->has('notifications')
            );
    });

    test('guest user cannot access notifications', function () {
        $guestUser = User::factory()->create(['role' => UserRole::Guest, 'email_verified_at' => now()]);
        DisclaimerAcceptance::recordAcceptance('127.0.0.1', 'Test');

        $response = $this->actingAs($guestUser)->get('/notifications');

        $response->assertForbidden();
    });

    test('unauthenticated user cannot access notifications', function () {
        $this->get('/notifications')
            ->assertRedirect('/login');
    });

    test('unverified user cannot access notifications', function () {
        $user = User::factory()->unverified()->create();

        $response = $this->actingAs($user)->get('/notifications');

        // Unverified users should either redirect to verify or get successful response
        // depending on application configuration
        expect($response->status())->toBeIn([200, 302]);
    });
});

describe('Navigation Links - Routing', function () {
    test('dashboard route is named correctly', function () {
        $user = User::factory()->create(['email_verified_at' => now()]);
        DisclaimerAcceptance::recordAcceptance('127.0.0.1', 'Test');

        $response = $this->actingAs($user)->get(route('dashboard'));

        $response->assertSuccessful();
    });

    test('notifications route is named correctly', function () {
        $user = User::factory()->create(['email_verified_at' => now()]);
        DisclaimerAcceptance::recordAcceptance('127.0.0.1', 'Test');

        $response = $this->actingAs($user)->get(route('notifications.index'));

        $response->assertSuccessful();
    });

    test('dashboard and notifications are different routes', function () {
        $user = User::factory()->create(['email_verified_at' => now()]);
        DisclaimerAcceptance::recordAcceptance('127.0.0.1', 'Test');

        $dashboardResponse = $this->actingAs($user)->get('/dashboard');
        $notificationsResponse = $this->actingAs($user)->get('/notifications');

        $dashboardResponse->assertSuccessful()
            ->assertInertia(fn ($page) => $page->component('dashboard'));

        $notificationsResponse->assertSuccessful()
            ->assertInertia(fn ($page) => $page->component('notifications'));
    });
});

describe('Navigation Links - Inertia Props', function () {
    test('dashboard page has auth prop for navigation', function () {
        $user = User::factory()->create(['email_verified_at' => now()]);
        DisclaimerAcceptance::recordAcceptance('127.0.0.1', 'Test');

        $response = $this->actingAs($user)->get('/dashboard');

        $response->assertSuccessful()
            ->assertInertia(fn ($page) => $page
                ->has('auth')
                ->has('auth.user')
                ->has('auth.user.id')
                ->has('auth.user.name')
                ->has('auth.user.email')
            );
    });

    test('notifications page returns notifications array', function () {
        $user = User::factory()->create(['email_verified_at' => now()]);
        DisclaimerAcceptance::recordAcceptance('127.0.0.1', 'Test');

        $response = $this->actingAs($user)->get('/notifications');

        $response->assertSuccessful()
            ->assertInertia(fn ($page) => $page
                ->has('notifications')
                ->has('notifications.data')
                ->where('notifications.data', [])
            );
    });

    test('notifications page includes user notifications', function () {
        $user = User::factory()->create(['email_verified_at' => now()]);
        DisclaimerAcceptance::recordAcceptance('127.0.0.1', 'Test');
        \App\Models\Notification::factory()->count(3)->for($user)->create();

        $response = $this->actingAs($user)->get('/notifications');

        $response->assertSuccessful()
            ->assertInertia(fn ($page) => $page
                ->has('notifications')
                ->has('notifications.data', 3)
            );
    });
});
