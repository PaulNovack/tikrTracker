<?php

use App\Models\DisclaimerAcceptance;
use Illuminate\Foundation\Testing\DatabaseTransactions;

uses(DatabaseTransactions::class);

it('shows disclaimer notice for users who have accepted', function () {
    // Create a disclaimer acceptance record
    DisclaimerAcceptance::recordAcceptance('127.0.0.1', 'Test Browser');

    // Visit the home page
    $response = $this->get('/');

    // Should be able to access the page without redirect (since disclaimer was accepted)
    $response->assertOk();
    $response->assertInertia(fn ($assert) => $assert->component('home')
    );
});

it('allows users to delete their personal data', function () {
    // Create a disclaimer acceptance record
    DisclaimerAcceptance::recordAcceptance('127.0.0.1', 'Test Browser');

    // Verify record exists
    $this->assertDatabaseHas('disclaimer_acceptances', [
        'ip_address' => '127.0.0.1',
        'disclaimer_accepted' => true,
        'cookies_accepted' => true,
    ]);

    // Submit data deletion request
    $response = $this->post('/disclaimer/delete-data', [
        'confirm_deletion' => true,
        'confirmation_text' => 'DELETE MY DATA',
    ]);

    // Should redirect to home
    $response->assertRedirect('/');
    $response->assertSessionHas('warning');

    // Verify record was deleted
    $this->assertDatabaseMissing('disclaimer_acceptances', [
        'ip_address' => '127.0.0.1',
    ]);
});

it('validates data deletion request', function () {
    // Create a disclaimer acceptance record
    DisclaimerAcceptance::recordAcceptance('127.0.0.1', 'Test Browser');

    // Try to delete without proper confirmation
    $response = $this->post('/disclaimer/delete-data', [
        'confirm_deletion' => false,
        'confirmation_text' => 'wrong text',
    ]);

    // Should get validation errors
    $response->assertSessionHasErrors(['confirm_deletion', 'confirmation_text']);

    // Verify record still exists
    $this->assertDatabaseHas('disclaimer_acceptances', [
        'ip_address' => '127.0.0.1',
    ]);
});

it('requires exact confirmation text for deletion', function () {
    // Create a disclaimer acceptance record
    DisclaimerAcceptance::recordAcceptance('127.0.0.1', 'Test Browser');

    // Try to delete with wrong confirmation text
    $response = $this->post('/disclaimer/delete-data', [
        'confirm_deletion' => true,
        'confirmation_text' => 'delete my data', // lowercase
    ]);

    // Should get validation error
    $response->assertSessionHasErrors(['confirmation_text']);

    // Verify record still exists
    $this->assertDatabaseHas('disclaimer_acceptances', [
        'ip_address' => '127.0.0.1',
    ]);
});
