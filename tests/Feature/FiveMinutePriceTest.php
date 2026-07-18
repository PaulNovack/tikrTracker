<?php

use App\Models\AssetInfo;
use App\Models\FiveMinutePrice;
use Carbon\Carbon;

it('can create five minute price records', function () {
    $price = FiveMinutePrice::factory()->create([
        'symbol' => 'AAPL',
        'asset_type' => 'stock',
        'price' => 180.50,
        'volume' => 1000000,
    ]);

    expect($price->symbol)->toBe('AAPL')
        ->and($price->asset_type)->toBe('stock')
        ->and($price->price)->toBe('180.50000000')
        ->and($price->volume)->toBe(1000000);
});

it('casts ts to datetime', function () {
    $price = FiveMinutePrice::factory()->create([
        'ts' => '2025-11-19 14:05:00',
    ]);

    expect($price->ts)->toBeInstanceOf(Carbon::class);
});

it('casts price to decimal', function () {
    $price = FiveMinutePrice::factory()->create([
        'price' => 123.456789,
    ]);

    expect($price->price)->toBeString()
        ->and($price->price)->toBe('123.45678900');
});

it('casts volume to integer', function () {
    $price = FiveMinutePrice::factory()->create([
        'volume' => '5000000',
    ]);

    expect($price->volume)->toBeInt()
        ->and($price->volume)->toBe(5000000);
});

it('belongs to asset info', function () {
    $asset = AssetInfo::factory()->create([
        'symbol' => 'TSLA',
        'asset_type' => 'stock',
    ]);

    $price = FiveMinutePrice::factory()->create([
        'symbol' => 'TSLA',
        'asset_type' => 'stock',
    ]);

    expect($price->assetInfo)->not->toBeNull()
        ->and($price->assetInfo->symbol)->toBe('TSLA')
        ->and($price->assetInfo->asset_type)->toBe('stock');
});

it('enforces unique constraint on symbol, asset_type, and ts', function () {
    FiveMinutePrice::factory()->create([
        'symbol' => 'BTC',
        'asset_type' => 'crypto',
        'ts' => '2025-11-19 14:05:00',
    ]);

    // Attempting to create duplicate should fail
    FiveMinutePrice::factory()->create([
        'symbol' => 'BTC',
        'asset_type' => 'crypto',
        'ts' => '2025-11-19 14:05:00',
    ]);
})->throws(Exception::class);

it('can create multiple records for same symbol at different timestamps', function () {
    FiveMinutePrice::factory()->create([
        'symbol' => 'ETH',
        'asset_type' => 'crypto',
        'ts' => '2025-11-19 14:00:00',
    ]);

    FiveMinutePrice::factory()->create([
        'symbol' => 'ETH',
        'asset_type' => 'crypto',
        'ts' => '2025-11-19 14:05:00',
    ]);

    expect(FiveMinutePrice::where('symbol', 'ETH')->count())->toBe(2);
});

it('can query by symbol', function () {
    FiveMinutePrice::factory()->count(5)->create(['symbol' => 'MSFT']);
    FiveMinutePrice::factory()->count(3)->create(['symbol' => 'GOOGL']);

    $prices = FiveMinutePrice::where('symbol', 'MSFT')->get();

    expect($prices)->toHaveCount(5)
        ->and($prices->first()->symbol)->toBe('MSFT');
});

it('can query by asset type', function () {
    FiveMinutePrice::factory()->count(4)->stock()->create();
    FiveMinutePrice::factory()->count(6)->crypto()->create();

    $stocks = FiveMinutePrice::where('asset_type', 'stock')->get();
    $cryptos = FiveMinutePrice::where('asset_type', 'crypto')->get();

    expect($stocks)->toHaveCount(4)
        ->and($cryptos)->toHaveCount(6);
});

it('can query by timestamp range', function () {
    $now = Carbon::now();

    FiveMinutePrice::factory()->create(['ts' => $now->copy()->subHours(2)]);
    FiveMinutePrice::factory()->create(['ts' => $now->copy()->subHours(1)]);
    FiveMinutePrice::factory()->create(['ts' => $now->copy()->subMinutes(30)]);

    $recent = FiveMinutePrice::where('ts', '>=', $now->copy()->subHours(1))->get();

    expect($recent)->toHaveCount(2);
});

it('orders by timestamp correctly', function () {
    $ts1 = Carbon::now()->subHours(2);
    $ts2 = Carbon::now()->subHours(1);
    $ts3 = Carbon::now()->subMinutes(30);

    FiveMinutePrice::factory()->create(['ts' => $ts2, 'symbol' => 'AAPL']);
    FiveMinutePrice::factory()->create(['ts' => $ts1, 'symbol' => 'AAPL']);
    FiveMinutePrice::factory()->create(['ts' => $ts3, 'symbol' => 'AAPL']);

    $prices = FiveMinutePrice::where('symbol', 'AAPL')
        ->orderBy('ts', 'desc')
        ->get();

    expect($prices->first()->ts->timestamp)->toBe($ts3->timestamp)
        ->and($prices->last()->ts->timestamp)->toBe($ts1->timestamp);
});

it('handles null volume gracefully', function () {
    $price = FiveMinutePrice::factory()->create([
        'volume' => null,
    ]);

    expect($price->volume)->toBeNull();
});

it('factory creates realistic stock data', function () {
    $price = FiveMinutePrice::factory()->stock()->create();

    expect($price->asset_type)->toBe('stock')
        ->and($price->symbol)->toBeIn(['AAPL', 'TSLA', 'MSFT', 'GOOGL', 'AMZN'])
        ->and($price->price)->toBeString()
        ->and($price->volume)->toBeInt()
        ->and($price->ts)->toBeInstanceOf(Carbon::class);
});

it('factory creates realistic crypto data', function () {
    $price = FiveMinutePrice::factory()->crypto()->create();

    expect($price->asset_type)->toBe('crypto')
        ->and($price->symbol)->toBeIn(['BTC', 'ETH', 'SOL', 'XRP', 'DOGE'])
        ->and($price->price)->toBeString()
        ->and($price->volume)->toBeInt()
        ->and($price->ts)->toBeInstanceOf(Carbon::class);
});
