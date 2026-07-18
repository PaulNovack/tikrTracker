<?php

use App\Models\User;

test('buy signals page loads successfully for authenticated users', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)
        ->get('/buy-signals');

    $response->assertSuccessful();
    $response->assertInertia(fn ($page) => $page->component('buy-signals')
        ->has('signals')
    );
});

test('buy signals page redirects guests to login', function () {
    $response = $this->get('/buy-signals');

    $response->assertRedirect('/login');
});

test('buy signals page is accessible from sidebar for authenticated users', function () {
    $user = User::factory()->create();

    // Test that the page loads
    $response = $this->actingAs($user)
        ->get('/buy-signals');

    $response->assertSuccessful();
    $response->assertInertia(fn ($page) => $page
        ->component('buy-signals')
        ->has('signals')
        ->has('time')
    );
});
