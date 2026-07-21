<?php

use App\Models\AssetInfo;
use App\Models\FiveMinutePrice;
use App\Models\User;
use App\UserRole;

it('filters out soft-deleted symbols from check-top analysis', function () {
    // Create a trader user
    $user = User::factory()->create(['role' => UserRole::Trader]);

    // Create active and soft-deleted assets
    $activeAsset = AssetInfo::factory()->create([
        'symbol' => 'ACTIVE',
        'asset_type' => 'stock',
        'common_name' => 'Active Company',
        'deleted_at' => null,
    ]);

    $deletedAsset = AssetInfo::factory()->create([
        'symbol' => 'DELETED',
        'asset_type' => 'stock',
        'common_name' => 'Deleted Company',
        'deleted_at' => now(),
    ]);

    // Create price data for both symbols with rising prices
    $baseTime = now()->subMinutes(15);

    foreach (['ACTIVE', 'DELETED'] as $symbol) {
        for ($i = 0; $i <= 15; $i += 5) {
            FiveMinutePrice::factory()->create([
                'symbol' => $symbol,
                'asset_type' => 'stock',
                'ts' => $baseTime->copy()->addMinutes($i),
                'price' => 100 + ($i * 2), // Rising price
                'open' => 100 + ($i * 2),
                'high' => 102 + ($i * 2),
                'low' => 98 + ($i * 2),
                'volume' => 10000,
            ]);
        }
    }

    // Make request to check-top endpoint
    $response = $this->actingAs($user)->get('/check-top');

    $response->assertSuccessful();

    // Get the stocks data from the Inertia response
    $stocks = $response->viewData('page')['props']['stocks'];

    // Verify that only the active symbol appears in results
    $symbols = collect($stocks)->pluck('symbol')->toArray();

    expect($symbols)->toContain('ACTIVE');
    expect($symbols)->not->toContain('DELETED');

    // Verify the active symbol has the expected data
    $activeStock = collect($stocks)->firstWhere('symbol', 'ACTIVE');
    expect($activeStock)->not->toBeNull();
    expect($activeStock['company_name'])->toBe('Active Company');
    expect($activeStock['totalPercentChange'])->toBeGreaterThan(0);
});

it('returns empty results when no symbols meet rising criteria', function () {
    $user = User::factory()->create(['role' => UserRole::Trader]);

    // Create an active asset with flat/declining prices
    AssetInfo::factory()->create([
        'symbol' => 'FLAT',
        'asset_type' => 'stock',
        'common_name' => 'Flat Company',
        'deleted_at' => null,
    ]);

    $baseTime = now()->subMinutes(15);

    // Create flat price data (no rising trend)
    for ($i = 0; $i <= 15; $i += 5) {
        FiveMinutePrice::factory()->create([
            'symbol' => 'FLAT',
            'asset_type' => 'stock',
            'ts' => $baseTime->copy()->addMinutes($i),
            'price' => 100, // Flat price
            'open' => 100,
            'high' => 100,
            'low' => 100,
            'volume' => 10000,
        ]);
    }

    $response = $this->actingAs($user)->get('/check-top');

    $response->assertSuccessful();

    $stocks = $response->viewData('page')['props']['stocks'];
    expect($stocks)->toBeEmpty();
});
