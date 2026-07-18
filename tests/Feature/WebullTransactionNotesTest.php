<?php

use App\Models\StockTransaction;
use App\Models\User;

it('can update transaction notes', function () {
    $user = User::factory()->create([
        'email_verified_at' => now(),
    ]);

    $transaction = StockTransaction::factory()->create([
        'user_id' => $user->id,
        'notes' => null,
    ]);

    $response = $this->actingAs($user)
        ->patchJson("/webull-transactions/{$transaction->id}/notes", [
            'notes' => 'This is my note',
        ]);

    $response->assertSuccessful()
        ->assertJson(['success' => true]);

    $this->assertDatabaseHas('stock_transactions', [
        'id' => $transaction->id,
        'notes' => 'This is my note',
    ]);
});

it('can clear transaction notes by setting to empty string', function () {
    $user = User::factory()->create([
        'email_verified_at' => now(),
    ]);

    $transaction = StockTransaction::factory()->create([
        'user_id' => $user->id,
        'notes' => 'Existing note',
    ]);

    $response = $this->actingAs($user)
        ->patchJson("/webull-transactions/{$transaction->id}/notes", [
            'notes' => '',
        ]);

    $response->assertSuccessful();

    $this->assertDatabaseHas('stock_transactions', [
        'id' => $transaction->id,
        'notes' => null, // Empty string is converted to null
    ]);
});

it('prevents updating notes for other users transactions', function () {
    $user1 = User::factory()->create([
        'email_verified_at' => now(),
    ]);

    $user2 = User::factory()->create([
        'email_verified_at' => now(),
    ]);

    $transaction = StockTransaction::factory()->create([
        'user_id' => $user1->id,
        'notes' => 'Original note',
    ]);

    $response = $this->actingAs($user2)
        ->patchJson("/webull-transactions/{$transaction->id}/notes", [
            'notes' => 'Hacked note',
        ]);

    $response->assertForbidden();

    $this->assertDatabaseHas('stock_transactions', [
        'id' => $transaction->id,
        'notes' => 'Original note',
    ]);
});

it('validates notes field length', function () {
    $user = User::factory()->create([
        'email_verified_at' => now(),
    ]);

    $transaction = StockTransaction::factory()->create([
        'user_id' => $user->id,
    ]);

    $longNote = str_repeat('x', 65536); // Exceeds TEXT field limit

    $response = $this->actingAs($user)
        ->patchJson("/webull-transactions/{$transaction->id}/notes", [
            'notes' => $longNote,
        ]);

    $response->assertStatus(422)
        ->assertJsonValidationErrors(['notes']);
});
