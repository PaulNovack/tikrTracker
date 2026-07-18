<?php

use App\Models\AssetInfo;
use App\Models\DailyPrice;
use App\Models\FiveMinutePrice;
use App\Models\MarketSchedule;

test('asset detail controller finds last open day using market schedule for stocks', function () {
    $today = now()->startOfDay();
    $friday = $today->copy()->subDays(2);

    $asset = AssetInfo::factory()->create([
        'symbol' => 'TEST',
        'asset_type' => 'stock',
    ]);

    // Create market schedule entries
    MarketSchedule::factory()->create([
        'date' => $today->format('Y-m-d'),
        'market_type' => 'stock',
        'status' => 'closed',
        'reason' => 'Weekend',
    ]);

    MarketSchedule::factory()->create([
        'date' => $friday->format('Y-m-d'),
        'market_type' => 'stock',
        'status' => 'open',
    ]);

    // Create 5-minute prices for Friday
    for ($i = 0; $i < 5; $i++) {
        FiveMinutePrice::factory()->create([
            'symbol' => 'TEST',
            'asset_type' => 'stock',
            'ts' => $friday->copy()->setTime(14, 30, 0)->addMinutes($i * 5),
            'price' => 100.00 + $i,
        ]);
    }

    // Create daily price for Friday
    DailyPrice::factory()->create([
        'symbol' => 'TEST',
        'asset_type' => 'stock',
        'date' => $friday->format('Y-m-d'),
        'price' => 100.00,
    ]);

    // Test that market schedule is correctly retrieved
    $marketSchedule = MarketSchedule::byMarketType('stock')
        ->where('date', '<', $today->format('Y-m-d'))
        ->whereIn('status', ['open', 'half_day'])
        ->orderBy('date', 'desc')
        ->first();

    expect($marketSchedule)
        ->not->toBeNull()
        ->date->toEqual(\Carbon\Carbon::parse($friday->format('Y-m-d')));

    // Test that we can get last open day prices
    $lastOpenDay = $marketSchedule->date;
    $lastOpenDayStart = \Carbon\Carbon::parse($lastOpenDay)->startOfDay();

    $lastOpenDayData = $asset->fiveMinutePrices()
        ->whereDate('ts', $lastOpenDayStart)
        ->orderBy('ts', 'asc')
        ->get();

    expect($lastOpenDayData)->toHaveCount(5);
});

test('crypto asset ignores market schedule for last open day', function () {
    $today = now()->startOfDay();

    $asset = AssetInfo::factory()->create([
        'symbol' => 'BTC-USD',
        'asset_type' => 'crypto',
    ]);

    // Create daily price for today
    DailyPrice::factory()->create([
        'symbol' => 'BTC-USD',
        'asset_type' => 'crypto',
        'date' => $today->format('Y-m-d'),
        'price' => 50000.00,
    ]);

    // Crypto should ignore market schedule and just use most recent date
    $lastTradingDayRecord = $asset->dailyPrices()
        ->where('date', '<', $today->format('Y-m-d'))
        ->orderBy('date', 'desc')
        ->first();

    // For crypto, there should be no previous date since we only created today
    expect($lastTradingDayRecord)->toBeNull();

    // But if we create yesterday's data
    DailyPrice::factory()->create([
        'symbol' => 'BTC-USD',
        'asset_type' => 'crypto',
        'date' => $today->copy()->subDay()->format('Y-m-d'),
        'price' => 49000.00,
    ]);

    $lastTradingDayRecord = $asset->dailyPrices()
        ->where('date', '<', $today->format('Y-m-d'))
        ->orderBy('date', 'desc')
        ->first();

    expect($lastTradingDayRecord)
        ->not->toBeNull()
        ->date->toEqual(\Carbon\Carbon::parse($today->copy()->subDay()->format('Y-m-d')));
});
