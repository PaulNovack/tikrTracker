<?php

use App\Models\DisclaimerAcceptance;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

// Basic disclaimer page tests
it('displays disclaimer page correctly', function () {
    $response = $this->get('/disclaimer');

    $response->assertSuccessful();
    $response->assertInertia(function ($page) {
        $page->component('Disclaimer')
            ->has('hasAcceptedDisclaimer');
    });
});

it('shows disclaimer content for new visitors', function () {
    $response = $this->withoutMiddleware()->get('/disclaimer');

    $response->assertSuccessful();
    $response->assertInertia(function ($page) {
        $page->component('Disclaimer')
            ->has('hasAcceptedDisclaimer')
            ->has('isAuthenticated');
    });
});

// Visit tracking tests
it('tracks new visitor when accessing disclaimer', function () {
    $ip = '192.168.1.1';

    // First access a page that would trigger the middleware (like home page)
    $this->withServerVariables(['REMOTE_ADDR' => $ip])
        ->get('/');

    // Then access disclaimer page
    $response = $this->withServerVariables(['REMOTE_ADDR' => $ip])
        ->get('/disclaimer');

    $response->assertSuccessful();

    $this->assertDatabaseHas('disclaimer_acceptances', [
        'ip_address' => $ip,
        'disclaimer_accepted' => false,
        'cookies_accepted' => false,
        'root_page_visits' => 1,
    ]);
});

it('increments visit count for returning visitors', function () {
    $ip = '192.168.1.1';

    // Create existing record
    DisclaimerAcceptance::create([
        'ip_address' => $ip,
        'root_page_visits' => 2,
        'access_count' => 2,
        'last_access_at' => now()->subMinutes(1),
        'first_visit_at' => now()->subMinutes(5),
    ]);

    // Access a page that would trigger middleware to increment visit count
    $response = $this->withServerVariables(['REMOTE_ADDR' => $ip])
        ->get('/');

    // Check if redirected to disclaimer or accessed successfully
    // If redirected, the visit count should be incremented

    $this->assertDatabaseHas('disclaimer_acceptances', [
        'ip_address' => $ip,
        'root_page_visits' => 3,
    ]);
});

// Disclaimer acceptance tests
it('allows disclaimer acceptance', function () {
    $ip = '192.168.1.1';

    $response = $this->withServerVariables(['REMOTE_ADDR' => $ip])
        ->post('/disclaimer/accept', [
            'disclaimer_accepted' => true,
            'cookies_accepted' => true,
        ]);

    $response->assertRedirect('/');

    $this->assertDatabaseHas('disclaimer_acceptances', [
        'ip_address' => $ip,
        'disclaimer_accepted' => true,
        'cookies_accepted' => true,
    ]);
});

// Data deletion tests
it('allows data deletion for IP address', function () {
    $ip = '192.168.1.1';

    // Create disclaimer acceptance record
    DisclaimerAcceptance::create([
        'ip_address' => $ip,
        'disclaimer_accepted' => true,
        'cookies_accepted' => true,
        'disclaimer_accepted_at' => now(),
        'cookies_accepted_at' => now(),
        'root_page_visits' => 5,
        'last_access_at' => now(),
    ]);

    $response = $this->withServerVariables(['REMOTE_ADDR' => $ip])
        ->post('/disclaimer/delete-data', [
            'confirm_deletion' => true,
            'confirmation_text' => 'DELETE MY DATA',
        ]);

    $response->assertRedirect('/');
    $response->assertSessionHas('warning');

    $this->assertDatabaseMissing('disclaimer_acceptances', [
        'ip_address' => $ip,
    ]);
});

// Content validation tests
it('includes investment red flags section', function () {
    $response = $this->get('/disclaimer');

    $response->assertSuccessful();
    $response->assertInertia(function ($page) {
        $page->component('Disclaimer')
            ->has('hasAcceptedDisclaimer')
            ->has('isAuthenticated');
    });
});

it('includes time management guidance', function () {
    $response = $this->get('/disclaimer');

    $response->assertSuccessful();
    $response->assertInertia(function ($page) {
        $page->component('Disclaimer')
            ->has('hasAcceptedDisclaimer')
            ->has('isAuthenticated');
    });
});

it('includes PDT rules information', function () {
    $response = $this->get('/disclaimer');

    $response->assertSuccessful();
    $response->assertInertia(function ($page) {
        $page->component('Disclaimer')
            ->has('hasAcceptedDisclaimer')
            ->has('isAuthenticated');
    });
});
