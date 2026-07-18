<?php

declare(strict_types=1);

use App\Models\AssetInfo;
use App\Models\OneMinutePrice;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('returns candlestick data for the selected asset date range', function () {
    $asset = AssetInfo::factory()->create([
        'symbol' => 'CANDLE',
        'asset_type' => 'stock',
    ]);

    OneMinutePrice::query()->create([
        'symbol' => 'CANDLE',
        'asset_type' => 'stock',
        'ts' => Carbon::parse('2026-07-14 13:30:00', 'UTC'),
        'price' => 100,
        'open' => 99,
        'high' => 101,
        'low' => 98,
        'volume' => 1000,
    ]);

    OneMinutePrice::query()->create([
        'symbol' => 'CANDLE',
        'asset_type' => 'stock',
        'ts' => Carbon::parse('2026-07-14 13:35:00', 'UTC'),
        'price' => 102,
        'open' => 100,
        'high' => 103,
        'low' => 99,
        'volume' => 1500,
    ]);

    $response = $this->withoutMiddleware()
        ->getJson("/market-data/assets/{$asset->id}/candlestick-chart?date=2026-07-14&range=1D");

    $response->assertOk();
    $response->assertJson(fn ($json) => $json
        ->where('interval', '1m')
        ->where('range', '1D')
        ->where('date', '2026-07-14')
        ->where('data.0.open', 99)
        ->where('data.0.high', 101)
        ->where('data.0.low', 98)
        ->where('data.0.close', 100)
        ->where('data.0.volume', 1000)
        ->where('data.1.open', 100)
        ->where('data.1.high', 103)
        ->where('data.1.low', 99)
        ->where('data.1.close', 102)
        ->where('data.1.volume', 1500)
    );

    expect($response->json('data.0.time'))->toBe('2026-07-14 13:30:00');
    expect($response->json('data.1.time'))->toBe('2026-07-14 13:31:00');
});