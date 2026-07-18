<?php

use App\Models\AssetInfo;
use App\Models\User;

uses()->group('asset-search');

beforeEach(function () {
    $this->user = User::factory()->create();
});

test('can search assets by symbol', function () {
    AssetInfo::factory()->create([
        'symbol' => 'AAPL',
        'common_name' => 'Apple Inc.',
        'asset_type' => 'stock',
    ]);

    AssetInfo::factory()->create([
        'symbol' => 'MSFT',
        'common_name' => 'Microsoft',
        'asset_type' => 'stock',
    ]);

    $response = $this->actingAs($this->user)
        ->getJson('/market-data/assets/search?search=AAP');

    $response->assertOk()
        ->assertJsonCount(1)
        ->assertJsonFragment(['symbol' => 'AAPL']);
});

test('can search assets by company name', function () {
    AssetInfo::factory()->create([
        'symbol' => 'AAPL',
        'common_name' => 'Apple Inc.',
        'asset_type' => 'stock',
    ]);

    $response = $this->actingAs($this->user)
        ->getJson('/market-data/assets/search?search=Apple');

    $response->assertOk()
        ->assertJsonCount(1)
        ->assertJsonFragment(['symbol' => 'AAPL']);
});

test('can filter search by asset type', function () {
    AssetInfo::factory()->create([
        'symbol' => 'BTC',
        'common_name' => 'Bitcoin',
        'asset_type' => 'crypto',
    ]);

    AssetInfo::factory()->create([
        'symbol' => 'AAPL',
        'common_name' => 'Apple Inc.',
        'asset_type' => 'stock',
    ]);

    $response = $this->actingAs($this->user)
        ->getJson('/market-data/assets/search?asset_type=crypto');

    $response->assertOk()
        ->assertJsonFragment(['symbol' => 'BTC'])
        ->assertJsonFragment(['common_name' => 'Bitcoin']);
});

test('search returns maximum 20 results', function () {
    // Create 25 assets directly without using factory
    foreach (range(1, 25) as $i) {
        AssetInfo::create([
            'symbol' => 'TEST'.str_pad($i, 3, '0', STR_PAD_LEFT),
            'asset_type' => 'stock',
            'common_name' => 'Test Company '.$i,
            'description' => 'Test description',
            'sector' => 'Technology',
        ]);
    }

    $response = $this->actingAs($this->user)
        ->getJson('/market-data/assets/search?search=Test');

    $response->assertOk()
        ->assertJsonCount(20);
});

test('search is case insensitive', function () {
    AssetInfo::factory()->create([
        'symbol' => 'AAPL',
        'common_name' => 'Apple Inc.',
        'asset_type' => 'stock',
    ]);

    $response = $this->actingAs($this->user)
        ->getJson('/market-data/assets/search?search=apple');

    $response->assertOk()
        ->assertJsonFragment(['symbol' => 'AAPL']);
});

test('daily prices has search endpoint', function () {
    AssetInfo::factory()->create([
        'symbol' => 'AAPL',
        'common_name' => 'Apple Inc.',
        'asset_type' => 'stock',
    ]);

    $response = $this->actingAs($this->user)
        ->getJson('/market-data/daily-prices/symbols?search=AAP');

    $response->assertOk()
        ->assertJsonFragment(['symbol' => 'AAPL']);
});

test('hourly prices has search endpoint', function () {
    AssetInfo::factory()->create([
        'symbol' => 'AAPL',
        'common_name' => 'Apple Inc.',
        'asset_type' => 'stock',
    ]);

    $response = $this->actingAs($this->user)
        ->getJson('/market-data/hourly-prices/symbols?search=AAP');

    $response->assertOk()
        ->assertJsonFragment(['symbol' => 'AAPL']);
});

test('example', function () {
    $response = $this->get('/');

    $response->assertStatus(200);
});
