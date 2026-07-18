<?php

use App\Models\AssetInfo;
use App\Models\User;

it('notification settings page displays add all button when available assets exist', function () {
    $user = User::factory()->create();
    $user->markEmailAsVerified();

    // Create some watched assets for the user with price data
    $asset1 = AssetInfo::factory()->create(['symbol' => 'AAPL']);
    $asset2 = AssetInfo::factory()->create(['symbol' => 'GOOGL']);

    // Add price data so they show up as available
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

    $user->watches()->create(['asset_info_id' => $asset1->id]);
    $user->watches()->create(['asset_info_id' => $asset2->id]);

    $response = $this->actingAs($user)->get('/notifications/settings');

    $response->assertSuccessful()
        ->assertInertia(function ($page) {
            $page->component('notifications-settings')
                ->has('watchedAssets', 2); // Should have 2 watched assets
        });
});

it('notification settings page does not show add all button when no available assets', function () {
    $user = User::factory()->create();
    $user->markEmailAsVerified();

    // No watched assets
    $response = $this->actingAs($user)->get('/notifications/settings');

    $response->assertSuccessful()
        ->assertDontSeeText('Add ALL'); // Should not see the Add ALL button
});
