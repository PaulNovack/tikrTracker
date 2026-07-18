<?php

use App\Models\AssetInfo;
use App\Models\User;
use App\Models\Watch;
use App\UserRole;

it('displays watches in alphabetical order by symbol', function () {
    // Create a trader user
    $user = User::factory()->create(['role' => UserRole::Trader]);

    // Create assets with different symbols (not in alphabetical order)
    $assetZ = AssetInfo::factory()->create([
        'symbol' => 'ZZZT',
        'asset_type' => 'stock',
        'common_name' => 'Z Company',
    ]);

    $assetA = AssetInfo::factory()->create([
        'symbol' => 'AAAA',
        'asset_type' => 'stock',
        'common_name' => 'A Company',
    ]);

    $assetM = AssetInfo::factory()->create([
        'symbol' => 'MMMM',
        'asset_type' => 'stock',
        'common_name' => 'M Company',
    ]);

    // Create watches in non-alphabetical order
    $watchZ = Watch::factory()->create([
        'user_id' => $user->id,
        'asset_info_id' => $assetZ->id,
    ]);

    $watchA = Watch::factory()->create([
        'user_id' => $user->id,
        'asset_info_id' => $assetA->id,
    ]);

    $watchM = Watch::factory()->create([
        'user_id' => $user->id,
        'asset_info_id' => $assetM->id,
    ]);

    // Make request to watches endpoint
    $response = $this->actingAs($user)->get('/watches');

    $response->assertSuccessful();

    // Get the watches data from the Inertia response
    $watches = $response->viewData('page')['props']['watches'];

    // Verify watches are in alphabetical order by symbol
    $symbols = collect($watches)->pluck('asset.symbol')->toArray();

    expect($symbols)->toEqual(['AAAA', 'MMMM', 'ZZZT']);

    // Verify correct assets are present
    expect($symbols)->toContain('AAAA');
    expect($symbols)->toContain('MMMM');
    expect($symbols)->toContain('ZZZT');
});
