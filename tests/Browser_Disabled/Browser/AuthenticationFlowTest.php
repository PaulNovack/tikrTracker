<?php

use App\Models\User;

it('allows users to register a new account', function () {
    $page = visit('/register');

    $page->assertSee('Create an account')
        ->type('name', 'John Doe')
        ->type('email', 'john@example.com')
        ->type('password', 'password123')
        ->type('password_confirmation', 'password123')
        ->press('Create account')
        ->wait(1)
        ->assertPathIs('/dashboard')
        ->assertSee('Dashboard')
        ->assertNoJavascriptErrors();

    expect(User::where('email', 'john@example.com')->exists())->toBeTrue();
});

it('allows users to log in and log out', function () {
    $user = User::factory()->create([
        'email' => 'test@example.com',
        'password' => bcrypt('password123'),
        'two_factor_secret' => null,
        'two_factor_recovery_codes' => null,
    ]);

    $page = visit('/login');

    $page->assertSee('Log in to your account')
        ->type('email', 'test@example.com')
        ->type('password', 'password123')
        ->press('Log in')
        ->wait(1)
        ->assertPathIs('/dashboard')
        ->assertSee('Dashboard')
        ->assertNoJavascriptErrors();

    // Test logout - open user menu and click logout
    $page->click('[data-test="logout-button"]')
        ->wait(1)
        ->assertPathIs('/login')
        ->assertSee('Log in to your account');
});

it('shows validation errors for invalid login', function () {
    $page = visit('/login');

    $page->type('email', 'invalid@example.com')
        ->type('password', 'wrongpassword')
        ->press('Log in')
        ->wait(1)
        ->assertSee('These credentials do not match our records')
        ->assertNoJavascriptErrors();
});

it('allows password reset flow', function () {
    $user = User::factory()->create([
        'email' => 'reset@example.com',
    ]);

    $page = visit('/login');

    $page->click('Forgot password?')
        ->wait(1)
        ->assertPathIs('/forgot-password')
        ->assertSee('Forgot password')
        ->type('email', 'reset@example.com')
        ->press('Email password reset link')
        ->wait(1)
        ->assertSee('We have emailed your password reset link')
        ->assertNoJavascriptErrors();
});
