<?php

use App\Models\DisclaimerAcceptance;
use Illuminate\Foundation\Testing\DatabaseTransactions;

uses(DatabaseTransactions::class);

it('redirects to home page after accepting disclaimer', function () {
    // Clear all disclaimer acceptances
    DisclaimerAcceptance::truncate();

    // Visit the disclaimer page directly
    $response = $this->get('/disclaimer');
    $response->assertOk();

    // Simulate accepting the disclaimer
    $response = $this->post('/disclaimer/accept', [
        'disclaimer_accepted' => true,
        'cookies_accepted' => true,
    ]);

    // Should redirect to home page (default redirect)
    $response->assertRedirect('/');
});

it('redirects to intended page after accepting disclaimer', function () {
    // Clear all disclaimer acceptances
    DisclaimerAcceptance::truncate();

    // Simulate visiting a page that sets a redirect URL
    $this->withSession(['disclaimer_redirect_url' => '/dashboard']);

    // Accept the disclaimer
    $response = $this->post('/disclaimer/accept', [
        'disclaimer_accepted' => true,
        'cookies_accepted' => true,
    ]);

    // Should redirect to the intended page
    $response->assertRedirect('/dashboard');

    // Check that the session key was removed (pulled)
    expect(session()->has('disclaimer_redirect_url'))->toBeFalse();
});

it('stores disclaimer acceptance in database', function () {
    // Clear all disclaimer acceptances
    DisclaimerAcceptance::truncate();

    // Accept the disclaimer
    $response = $this->post('/disclaimer/accept', [
        'disclaimer_accepted' => true,
        'cookies_accepted' => true,
    ]);

    // Check that a record was created
    $this->assertDatabaseHas('disclaimer_acceptances', [
        'disclaimer_accepted' => true,
        'cookies_accepted' => true,
        'ip_address' => '127.0.0.1',
    ]);

    // Should redirect
    $response->assertRedirect('/');
});

it('validates disclaimer acceptance fields', function () {
    // Try to submit without required fields
    $response = $this->post('/disclaimer/accept', [
        'disclaimer_accepted' => false,
        'cookies_accepted' => false,
    ]);

    // Should get validation errors
    $response->assertSessionHasErrors(['disclaimer_accepted', 'cookies_accepted']);
});
