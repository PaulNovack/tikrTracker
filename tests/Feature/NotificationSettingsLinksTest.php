<?php

use App\Models\AssetInfo;
use App\Models\PriceAlert;
use App\Models\User;
use App\Models\Watch;
use App\UserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('renders notifications settings page with asset links', function () {
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

    // Verify the page loads and contains the expected data structure
    $response->assertInertia(function ($page) use ($asset1, $asset2) {
        $page->component('notifications-settings')
            ->has('priceAlerts', 1)
            ->has('watchedAssets', 1)
            ->where('priceAlerts.0.asset.id', $asset1->id)
            ->where('priceAlerts.0.asset.symbol', 'LINK1')
            ->where('watchedAssets.0.asset.id', $asset2->id)
            ->where('watchedAssets.0.asset.symbol', 'LINK2');
    });
});

it('provides asset data needed for wayfinder routing', function () {
    // Create a trader user
    $user = User::factory()->create(['role' => UserRole::Trader]);

    // Create an asset
    $asset = AssetInfo::factory()->create([
        'symbol' => 'WAYFINDER',
        'asset_type' => 'stock',
        'common_name' => 'Wayfinder Test Company',
    ]);

    // Create a price alert
    PriceAlert::factory()->create([
        'user_id' => $user->id,
        'asset_info_id' => $asset->id,
    ]);

    $response = $this->actingAs($user)->get('/notifications/settings');
    $response->assertSuccessful();

    // Verify that the asset ID is available for wayfinder URL generation
    $priceAlerts = $response->viewData('page')['props']['priceAlerts'];
    expect($priceAlerts[0]['asset']['id'])->toBe($asset->id);
    expect($priceAlerts[0]['asset']['symbol'])->toBe('WAYFINDER');

    // The frontend can now use: showAsset.url(priceAlert.asset.id)
    // to generate URLs like: /market-data/assets/{id}
});
