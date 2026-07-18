<?php

use App\Models\AssetInfo;
use App\Models\User;
use Illuminate\Support\Facades\Queue;

it('can create a new symbol with valid data', function () {
    $user = User::factory()->create();
    $user->update(['role' => \App\UserRole::Admin]);

    $response = $this->actingAs($user)
        ->postJson('/market-data/assets', [
            'symbol' => 'GOOG',
            'asset_type' => 'stock',
            'common_name' => 'Google Inc.',
            'description' => 'A technology company',
            'sector' => 'Technology',
        ]);

    $response->assertStatus(201);
    $response->assertJson([
        'message' => 'Asset created successfully',
    ]);

    $this->assertDatabaseHas('asset_info', [
        'symbol' => 'GOOG',
        'asset_type' => 'stock',
        'common_name' => 'Google Inc.',
    ]);
});

it('requires authentication to create a symbol', function () {
    $response = $this->postJson('/market-data/assets', [
        'symbol' => 'GOOG',
        'asset_type' => 'stock',
        'common_name' => 'Google Inc.',
    ]);

    $response->assertStatus(401);
});

it('validates symbol field is required', function () {
    $user = User::factory()->create();
    $user->update(['role' => \App\UserRole::Admin]);

    $response = $this->actingAs($user)
        ->postJson('/market-data/assets', [
            'asset_type' => 'stock',
            'common_name' => 'Google Inc.',
        ]);

    $response->assertStatus(422);
    $response->assertJsonValidationErrors('symbol');
});

it('validates asset_type is either stock or crypto', function () {
    $user = User::factory()->create();
    $user->update(['role' => \App\UserRole::Admin]);

    $response = $this->actingAs($user)
        ->postJson('/market-data/assets', [
            'symbol' => 'GOOG',
            'asset_type' => 'invalid',
            'common_name' => 'Google Inc.',
        ]);

    $response->assertStatus(422);
    $response->assertJsonValidationErrors('asset_type');
});

it('validates symbol must be uppercase', function () {
    $user = User::factory()->create();
    $user->update(['role' => \App\UserRole::Admin]);

    $response = $this->actingAs($user)
        ->postJson('/market-data/assets', [
            'symbol' => 'goog',
            'asset_type' => 'stock',
            'common_name' => 'Google Inc.',
        ]);

    $response->assertStatus(422);
    $response->assertJsonValidationErrors('symbol');
});

it('validates common_name is required', function () {
    $user = User::factory()->create();
    $user->update(['role' => \App\UserRole::Admin]);

    $response = $this->actingAs($user)
        ->postJson('/market-data/assets', [
            'symbol' => 'GOOG',
            'asset_type' => 'stock',
        ]);

    $response->assertStatus(422);
    $response->assertJsonValidationErrors('common_name');
});

it('prevents duplicate symbols with same asset type', function () {
    $user = User::factory()->create();
    $user->update(['role' => \App\UserRole::Admin]);

    // Create the first asset
    AssetInfo::create([
        'symbol' => 'GOOG',
        'asset_type' => 'stock',
        'common_name' => 'Google Inc.',
    ]);

    // Try to create a duplicate
    $response = $this->actingAs($user)
        ->postJson('/market-data/assets', [
            'symbol' => 'GOOG',
            'asset_type' => 'stock',
            'common_name' => 'Google Inc.',
        ]);

    $response->assertStatus(409);
    $response->assertJson([
        'error' => 'An asset with this symbol and type already exists in the system.',
    ]);
});

it('dispatches UpdateStockDataJob when creating a symbol', function () {
    Queue::fake();
    $user = User::factory()->create();
    $user->update(['role' => \App\UserRole::Admin]);

    $this->actingAs($user)
        ->postJson('/market-data/assets', [
            'symbol' => 'GOOG',
            'asset_type' => 'stock',
            'common_name' => 'Google Inc.',
        ]);

    Queue::assertPushed(\App\Jobs\Market\UpdateStockDataJob::class, function ($job) {
        return $job->symbol === 'GOOG' && $job->assetType === 'stock';
    });
});

it('creates crypto assets with valid data', function () {
    $user = User::factory()->create();
    $user->update(['role' => \App\UserRole::Admin]);

    $response = $this->actingAs($user)
        ->postJson('/market-data/assets', [
            'symbol' => 'BTC',
            'asset_type' => 'crypto',
            'common_name' => 'Bitcoin',
            'description' => 'A cryptocurrency',
        ]);

    $response->assertStatus(201);

    $this->assertDatabaseHas('asset_info', [
        'symbol' => 'BTC',
        'asset_type' => 'crypto',
    ]);
});

it('allows creating same symbol with different asset types', function () {
    $user = User::factory()->create();
    $user->update(['role' => \App\UserRole::Admin]);

    // Create stock
    $this->withoutMiddleware()
        ->actingAs($user)
        ->postJson('/market-data/assets', [
            'symbol' => 'BRK',
            'asset_type' => 'stock',
            'common_name' => 'Berkshire Hathaway',
        ]);

    // Create crypto with same symbol should work
    $response = $this->withoutMiddleware()
        ->actingAs($user)
        ->postJson('/market-data/assets', [
            'symbol' => 'BRK',
            'asset_type' => 'crypto',
            'common_name' => 'Some Crypto',
        ]);

    $response->assertStatus(201);

    $this->assertEquals(2, AssetInfo::where('symbol', 'BRK')->count());
});

it('prevents non-admin users from creating symbols', function () {
    $user = User::factory()->create();
    // Set user as Trader (not Admin)
    $user->update(['role' => \App\UserRole::Trader]);

    $response = $this->actingAs($user)
        ->postJson('/market-data/assets', [
            'symbol' => 'GOOG',
            'asset_type' => 'stock',
            'common_name' => 'Google Inc.',
        ]);

    $response->assertStatus(403);
    $response->assertJson([
        'error' => 'Only administrators can add new symbols.',
    ]);

    // Verify symbol was not created
    $this->assertDatabaseMissing('asset_info', [
        'symbol' => 'GOOG',
    ]);
});

it('allows admin users to create symbols', function () {
    $admin = User::factory()->create();
    $admin->update(['role' => \App\UserRole::Admin]);

    $response = $this->actingAs($admin)
        ->postJson('/market-data/assets', [
            'symbol' => 'TSLA',
            'asset_type' => 'stock',
            'common_name' => 'Tesla Inc.',
        ]);

    $response->assertStatus(201);

    $this->assertDatabaseHas('asset_info', [
        'symbol' => 'TSLA',
    ]);
});

it('prevents guest users from creating symbols', function () {
    $guest = User::factory()->create();
    $guest->update(['role' => \App\UserRole::Guest]);

    $response = $this->actingAs($guest)
        ->postJson('/market-data/assets', [
            'symbol' => 'AAPL',
            'asset_type' => 'stock',
            'common_name' => 'Apple Inc.',
        ]);

    $response->assertStatus(403);
});
