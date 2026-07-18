<?php

use App\Models\User;
use Illuminate\Support\Facades\Hash;

it('can log in as guest user', function () {
    // Ensure guest user exists (create if needed)
    $guestUser = User::firstOrCreate(
        ['email' => 'guest@tikrtracker.com'],
        [
            'name' => 'Guest User',
            'password' => Hash::make('password'),
        ]
    );

    // Visit guest login route
    $response = $this->get(route('guest-login'));

    // Should redirect to dashboard
    $response->assertRedirect(route('dashboard'));

    // Should be authenticated as guest user
    $this->assertAuthenticated();
    expect(auth()->user()->email)->toBe('guest@tikrtracker.com');
});
