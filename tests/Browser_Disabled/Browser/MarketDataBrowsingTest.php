<?php

use App\Models\AssetInfo;
use App\Models\DailyPrice;
use App\Models\User;

use function Pest\Laravel\actingAs;

it('displays all assets on the market data page', function () {
    $user = User::factory()->create();

    $stock = AssetInfo::factory()->stock()->create([
        'symbol' => 'AAPL',
        'common_name' => 'Apple Inc.',
    ]);

    $crypto = AssetInfo::factory()->crypto()->create([
        'symbol' => 'BTC',
        'common_name' => 'Bitcoin',
    ]);

    actingAs($user);

    $page = visit('/market-data/assets');

    $page->assertSee('Market Data')
        ->assertSee('AAPL')
        ->assertSee('Apple Inc.')
        ->assertSee('stock')
        ->assertSee('BTC')
        ->assertSee('Bitcoin')
        ->assertSee('crypto')
        ->assertNoJavascriptErrors();
});

it('allows viewing individual asset details with price history', function () {
    $user = User::factory()->create();

    $asset = AssetInfo::factory()->stock()->create([
        'symbol' => 'TSLA',
        'common_name' => 'Tesla, Inc.',
        'description' => 'Tesla designs, manufactures, and sells electric vehicles.',
    ]);

    DailyPrice::factory()->count(10)->create([
        'symbol' => 'TSLA',
        'asset_type' => 'stock',
        'price' => 250.00,
    ]);

    actingAs($user);

    $page = visit("/market-data/assets/{$asset->id}");

    $page->assertSee('TSLA')
        ->assertSee('Tesla, Inc.')
        ->assertSee('Tesla designs, manufactures, and sells electric vehicles.')
        ->assertSee('Recent Price History')
        ->assertSee('$250.00')
        ->assertNoJavascriptErrors();
});

it('displays stocks and crypto separately', function () {
    $user = User::factory()->create();

    AssetInfo::factory()->stock()->count(3)->create();
    AssetInfo::factory()->crypto()->count(2)->create();

    actingAs($user);

    $page = visit('/market-data/assets');

    $page->assertSee('Stocks')
        ->assertSee('Cryptocurrencies')
        ->assertNoJavascriptErrors();
});

it('shows daily prices with filtering', function () {
    $user = User::factory()->create();

    $asset = AssetInfo::factory()->stock()->create([
        'symbol' => 'GOOGL',
    ]);

    DailyPrice::factory()->count(30)->create([
        'symbol' => 'GOOGL',
        'asset_type' => 'stock',
    ]);

    actingAs($user);

    $page = visit('/market-data/daily-prices');

    $page->assertSee('Daily Prices')
        ->assertSee('GOOGL')
        ->assertNoJavascriptErrors();
});

it('navigates between assets and price data', function () {
    $user = User::factory()->create();

    $asset = AssetInfo::factory()->stock()->create([
        'symbol' => 'NVDA',
        'common_name' => 'NVIDIA Corporation',
    ]);

    DailyPrice::factory()->create([
        'symbol' => 'NVDA',
        'asset_type' => 'stock',
        'price' => 500.00,
    ]);

    actingAs($user);

    $page = visit('/market-data/assets');

    $page->assertSee('NVDA')
        ->click("a[href='/market-data/assets/{$asset->id}']")
        ->wait(1)
        ->assertPathIs("/market-data/assets/{$asset->id}")
        ->assertSee('NVIDIA Corporation')
        ->assertSee('$500.00')
        ->click('a[href="/market-data/assets"]')
        ->wait(1)
        ->assertPathIs('/market-data/assets')
        ->assertSee('Market Data')
        ->assertNoJavascriptErrors();
});
