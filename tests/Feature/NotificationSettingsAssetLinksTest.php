<?php

use App\Models\AssetInfo;
use App\Models\PriceAlert;
use App\Models\User;
use App\Models\Watch;
use App\UserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('provides full asset objects for linking in notifications settings', function () {
    // Create a trader user
    $user = User::factory()->create(['role' => UserRole::Trader]);

    // Create test assets
    $asset1 = AssetInfo::factory()->create([
        'symbol' => 'LINK1',
        'asset_type' => 'stock',
        'common_name' => 'Link Test Company 1',
    ]);

    $asset2 = AssetInfo::factory()->create([
        'symbol' => 'LINK2',
        'asset_type' => 'stock',
        'common_name' => 'Link Test Company 2',
    ]);

    // Create a price alert for asset1
    PriceAlert::factory()->create([
        'user_id' => $user->id,
        'asset_info_id' => $asset1->id,
        'alert_type' => 'percentage',
        'threshold_value' => 5.0,
        'enabled' => true,
    ]);

    // Create a watch for asset2
    Watch::factory()->create([
        'user_id' => $user->id,
        'asset_info_id' => $asset2->id,
    ]);

    // Make request to notifications settings
    $response = $this->actingAs($user)->get('/notifications/settings');
    $response->assertSuccessful();

    // Get the data from the Inertia response
    $priceAlerts = $response->viewData('page')['props']['priceAlerts'];
    $watchedAssets = $response->viewData('page')['props']['watchedAssets'];

    // Verify price alerts include full asset objects and all original alert fields
    expect($priceAlerts)->toHaveCount(1);
    $priceAlert = $priceAlerts[0];

    // Verify asset relationship exists for linking
    expect($priceAlert)->toHaveKey('asset');
    expect($priceAlert['asset'])->toHaveKey('id');
    expect($priceAlert['asset'])->toHaveKey('symbol');
    expect($priceAlert['asset']['symbol'])->toBe('LINK1');
    expect($priceAlert['asset']['common_name'])->toBe('Link Test Company 1');

    // Verify all price alert fields are preserved
    expect($priceAlert)->toHaveKey('above_price');
    expect($priceAlert)->toHaveKey('below_price');
    expect($priceAlert)->toHaveKey('base_price');
    expect($priceAlert)->toHaveKey('up_percentage');
    expect($priceAlert)->toHaveKey('down_percentage');
    expect($priceAlert)->toHaveKey('enabled');

    // Verify watched assets include full asset objects
    expect($watchedAssets)->toHaveCount(1);
    $watchedAsset = $watchedAssets[0];
    expect($watchedAsset)->toHaveKey('asset');
    expect($watchedAsset['asset'])->toHaveKey('id');
    expect($watchedAsset['asset'])->toHaveKey('symbol');
    expect($watchedAsset['asset']['symbol'])->toBe('LINK2');
    expect($watchedAsset['asset']['common_name'])->toBe('Link Test Company 2');

    // Verify that both have the necessary data for creating asset links
    expect($priceAlert['asset']['id'])->toBeInt();
    expect($watchedAsset['asset']['id'])->toBeInt();

    // Verify price alert maintains all necessary pricing fields
    expect($priceAlert['above_price'])->toBeNumeric();
    expect($priceAlert['below_price'])->toBeNumeric();
});
