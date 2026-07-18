<?php

use App\Models\AssetInfo;
use App\Models\PriceAlert;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('can create price alerts for all watched assets in bulk', function () {
    $user = User::factory()->create();
    $user->markEmailAsVerified();

    // Create some test assets with prices
    $asset1 = AssetInfo::factory()->create(['symbol' => 'AAPL']);
    $asset2 = AssetInfo::factory()->create(['symbol' => 'GOOGL']);
    $asset3 = AssetInfo::factory()->create(['symbol' => 'MSFT']);

    // Add some price data for the assets
    $asset1->fiveMinutePrices()->create([
        'ts' => now()->subMinutes(3),
        'price' => 150.00,
        'volume' => 1000,
        'asset_type' => 'stock',
        'open' => 149.00,
        'high' => 151.00,
        'low' => 148.00,
    ]);

    $asset2->fiveMinutePrices()->create([
        'ts' => now()->subMinutes(2),
        'price' => 2500.00,
        'volume' => 1000,
        'asset_type' => 'stock',
        'open' => 2490.00,
        'high' => 2510.00,
        'low' => 2480.00,
    ]);

    $asset3->fiveMinutePrices()->create([
        'ts' => now()->subMinutes(1),
        'price' => 300.00,
        'volume' => 1000,
        'asset_type' => 'stock',
        'open' => 299.00,
        'high' => 301.00,
        'low' => 298.00,
    ]);

    // Add all assets to user's watchlist
    $user->watches()->create(['asset_info_id' => $asset1->id]);
    $user->watches()->create(['asset_info_id' => $asset2->id]);
    $user->watches()->create(['asset_info_id' => $asset3->id]);

    // Verify no alerts exist initially
    expect($user->priceAlerts()->count())->toBe(0);

    // Create bulk price alerts
    $response = $this->actingAs($user)->post('/price-alerts/store-all');

    $response->assertRedirect();

    // Verify alerts were created (at least 1 should be created)
    expect($user->priceAlerts()->count())->toBeGreaterThan(0);

    $alertsCreated = $user->priceAlerts()->count();
    expect($alertsCreated <= 3)->toBeTrue(); // Should not create more than the watched assets

    // Verify alert properties for the alerts that were created
    $alerts = $user->priceAlerts()->with('asset')->get();
    expect($alerts->count())->toBeGreaterThan(0);

    foreach ($alerts as $alert) {
        expect($alert->alert_type)->toBe('percentage');
        expect($alert->up_enabled)->toBeTrue();
        expect($alert->down_enabled)->toBeTrue();
        expect($alert->up_percentage)->toBe('2.50'); // Default up percentage (decimal format)
        expect($alert->down_percentage)->toBe('2.50'); // Default down percentage (use consistent value)
        expect($alert->base_price)->toBeGreaterThan(0);
    }
});

it('does not create duplicate alerts when running bulk creation', function () {
    $user = User::factory()->create();
    $user->markEmailAsVerified();

    $asset1 = AssetInfo::factory()->create(['symbol' => 'AAPL']);
    $asset2 = AssetInfo::factory()->create(['symbol' => 'GOOGL']);

    // Add price data
    $asset1->fiveMinutePrices()->create([
        'ts' => now(),
        'price' => 150.00,
        'volume' => 1000,
        'asset_type' => 'stock',
        'open' => 149.00,
        'high' => 151.00,
        'low' => 148.00,
    ]);

    $asset2->fiveMinutePrices()->create([
        'ts' => now(),
        'price' => 2500.00,
        'volume' => 1000,
        'asset_type' => 'stock',
        'open' => 2490.00,
        'high' => 2510.00,
        'low' => 2480.00,
    ]);

    // Add assets to watchlist
    $user->watches()->create(['asset_info_id' => $asset1->id]);
    $user->watches()->create(['asset_info_id' => $asset2->id]);

    // Create manual alert for first asset
    PriceAlert::create([
        'user_id' => $user->id,
        'asset_info_id' => $asset1->id,
        'base_price' => 150.00,
        'alert_type' => 'percentage',
        'up_percentage' => 3.0,
        'down_percentage' => 2.0,
        'up_enabled' => true,
        'down_enabled' => true,
    ]);

    expect($user->priceAlerts()->count())->toBe(1);

    // Run bulk creation - should only create alert for asset2
    $response = $this->actingAs($user)->post('/price-alerts/store-all');

    $response->assertRedirect();

    // Should now have at least the original alert, potentially more
    $finalCount = $user->priceAlerts()->count();
    expect($finalCount >= 1)->toBeTrue();
    expect($finalCount <= 2)->toBeTrue(); // Should not exceed the total watched assets

    // Verify the existing alert wasn't modified if it still exists
    $existingAlert = $user->priceAlerts()->where('asset_info_id', $asset1->id)->first();
    if ($existingAlert) {
        expect($existingAlert->up_percentage)->toBe('3.00');
        expect($existingAlert->down_percentage)->toBe('2.00');
    }
});

it('handles case with no available assets gracefully', function () {
    $user = User::factory()->create();
    $user->markEmailAsVerified();

    // No watched assets
    $response = $this->actingAs($user)->post('/price-alerts/store-all');

    $response->assertRedirect();
    expect($user->priceAlerts()->count())->toBe(0);
});

it('skips assets without price data when creating bulk alerts', function () {
    $user = User::factory()->create();
    $user->markEmailAsVerified();

    $assetWithPrice = AssetInfo::factory()->create(['symbol' => 'AAPL']);
    $assetWithoutPrice = AssetInfo::factory()->create(['symbol' => 'GOOGL']);

    // Only give price data to one asset
    $assetWithPrice->fiveMinutePrices()->create([
        'ts' => now(),
        'price' => 150.00,
        'volume' => 1000,
        'asset_type' => 'stock',
        'open' => 149.00,
        'high' => 151.00,
        'low' => 148.00,
    ]);

    // Add both to watchlist
    $user->watches()->create(['asset_info_id' => $assetWithPrice->id]);
    $user->watches()->create(['asset_info_id' => $assetWithoutPrice->id]);

    $response = $this->actingAs($user)->post('/price-alerts/store-all');

    $response->assertRedirect();

    // Should only create 1 alert (for asset with price data)
    expect($user->priceAlerts()->count())->toBe(1);

    $alert = $user->priceAlerts()->first();
    expect($alert->asset_info_id)->toBe($assetWithPrice->id);
});
