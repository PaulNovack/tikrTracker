<?php

use App\Models\DisclaimerAcceptance;
use App\Models\User;

it('requires valid pid to kill process', function () {
    $user = User::factory()->create(['email_verified_at' => now()]);

    // Accept disclaimer to bypass middleware
    DisclaimerAcceptance::recordAcceptance('127.0.0.1', 'Test');

    $response = $this->actingAs($user)->postJson('/processes-running/kill', []);

    $response->assertStatus(422)
        ->assertJsonValidationErrors(['pid']);
});

it('returns error for non-existent process', function () {
    $user = User::factory()->create(['email_verified_at' => now()]);

    // Accept disclaimer to bypass middleware
    DisclaimerAcceptance::recordAcceptance('127.0.0.1', 'Test');

    $response = $this->actingAs($user)->postJson('/processes-running/kill', [
        'pid' => 99999,
    ]);

    $response->assertJson([
        'success' => false,
        'message' => 'Process not found or already terminated',
    ]);
});

it('validates pid is integer', function () {
    $user = User::factory()->create(['email_verified_at' => now()]);

    // Accept disclaimer to bypass middleware
    DisclaimerAcceptance::recordAcceptance('127.0.0.1', 'Test');

    $response = $this->actingAs($user)->postJson('/processes-running/kill', [
        'pid' => 'invalid',
    ]);

    $response->assertStatus(422)
        ->assertJsonValidationErrors(['pid']);
});
