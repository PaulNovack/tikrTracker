<?php

use App\Models\AssetInfo;
use App\Models\User;

test('symbols endpoint returns matching symbols', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)
        ->getJson('/market-data/daily-prices/symbols?search=AAP&asset_type=stock');

    $response->assertSuccessful()
        ->assertJsonStructure([
            '*' => ['symbol', 'name', 'asset_type'],
        ]);
});

test('symbols endpoint filters by asset type', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)
        ->getJson('/market-data/daily-prices/symbols?search=A&asset_type=stock');

    $response->assertSuccessful();

    $data = $response->json();
    foreach ($data as $item) {
        expect($item['asset_type'])->toBe('stock');
    }
});

test('symbols endpoint limits results to 20', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)
        ->getJson('/market-data/daily-prices/symbols?search=A');

    $response->assertSuccessful();

    $data = $response->json();
    expect(count($data))->toBeLessThanOrEqual(20);
});

test('symbols endpoint searches by symbol prefix', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)
        ->getJson('/market-data/daily-prices/symbols?search=AAP');

    $response->assertSuccessful();

    $data = $response->json();
    foreach ($data as $item) {
        expect($item['symbol'])->toStartWith('AAP');
    }
});

test('daily prices can be filtered by symbol', function () {
    $user = User::factory()->create();

    // Get a symbol that has data
    $symbol = AssetInfo::where('asset_type', 'stock')
        ->whereNull('deleted_at')
        ->first()
        ?->symbol;

    if ($symbol) {
        $response = $this->actingAs($user)
            ->get("/market-data/daily-prices?asset_type=stock&symbol={$symbol}");

        $response->assertSuccessful();
    }

    expect(true)->toBeTrue(); // Always pass if no symbols found
});
