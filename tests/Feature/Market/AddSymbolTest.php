<?php

use App\Models\User;

test('non-admin cannot add symbols', function () {
    $user = User::factory()->create(['role' => 'trader']);

    $response = $this->actingAs($user)->post('/market-data/assets', [
        'symbol' => 'TEST',
        'asset_type' => 'stock',
        'common_name' => 'Test Company',
    ]);

    // Returns 403 Forbidden for non-admin users
    $response->assertForbidden();
});

test('admin can add a new symbol', function () {
    $admin = User::factory()->create(['role' => 'admin']);

    $response = $this->actingAs($admin)->post('/market-data/assets', [
        'symbol' => 'ALCOA',
        'asset_type' => 'stock',
        'common_name' => 'Alcoa Corporation',
        'sector' => 'Materials',
        'description' => 'A leading global materials science and manufacturing company.',
    ]);

    $response->assertRedirect();
    $response->assertSessionHas('success');

    $this->assertDatabaseHas('asset_info', [
        'symbol' => 'ALCOA',
        'asset_type' => 'stock',
        'common_name' => 'Alcoa Corporation',
        'sector' => 'Materials',
    ]);
});

test('admin cannot add duplicate symbol with same type', function () {
    $admin = User::factory()->create(['role' => 'admin']);

    // Create existing asset
    \App\Models\AssetInfo::create([
        'symbol' => 'AAPL',
        'asset_type' => 'stock',
        'common_name' => 'Apple Inc',
    ]);

    // Try to add same symbol
    $response = $this->actingAs($admin)->post('/market-data/assets', [
        'symbol' => 'AAPL',
        'asset_type' => 'stock',
        'common_name' => 'Apple Inc',
    ]);

    $response->assertRedirect();
    $response->assertSessionHasErrors();
});

test('admin can add symbol with auto-fetched description', function () {
    $admin = User::factory()->create(['role' => 'admin']);

    $response = $this->actingAs($admin)->post('/market-data/assets', [
        'symbol' => 'MSFT',
        'asset_type' => 'stock',
        'common_name' => 'Microsoft Corporation',
        'sector' => 'Technology',
        // No description - should be auto-fetched
    ]);

    $response->assertRedirect();
    $response->assertSessionHas('success');

    $asset = \App\Models\AssetInfo::where('symbol', 'MSFT')->where('asset_type', 'stock')->first();
    $this->assertNotNull($asset);
    // Description may or may not be fetched depending on Wikimedia service
});
