<?php

use App\Models\AssetInfo;
use App\Models\MarketSchedule;
use App\Models\User;
use App\Models\Watch;
use App\UserRole;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('defaults to 1D timeframe on market days even after market closes', function () {
    // Create a trader user
    $user = User::factory()->create(['role' => UserRole::Trader]);

    // Create an asset and watch for testing
    $asset = AssetInfo::factory()->create([
        'symbol' => 'TEST',
        'asset_type' => 'stock',
        'common_name' => 'Test Company',
    ]);

    Watch::factory()->create([
        'user_id' => $user->id,
        'asset_info_id' => $asset->id,
    ]);

    // Create a market schedule for today that indicates it's a trading day
    $today = now()->toDateString();
    MarketSchedule::factory()->create([
        'date' => $today,
        'market_type' => 'stock',
        'status' => 'open',
        'opens_at' => Carbon::createFromTime(9, 30, 0, 'America/New_York'),
        'closes_at' => Carbon::createFromTime(16, 0, 0, 'America/New_York'),
        'reason' => null,
    ]);

    // Test after market closes but same day
    $this->travelTo(Carbon::today('America/New_York')->setTime(18, 0)); // 6 PM EST (after close)

    $response = $this->actingAs($user)->get('/watches');
    $response->assertSuccessful();

    $marketStatus = $response->viewData('page')['props']['marketStatus'];
    expect($marketStatus['defaultTimeRange'])->toBe('1D'); // Should be 1D on trading day
    expect($marketStatus['isMarketOpen'])->toBe(false);
    expect($marketStatus['isMarketDay'])->toBe(true);
});

it('defaults to Last Open Day on non-market days', function () {
    // Create a trader user
    $user = User::factory()->create(['role' => UserRole::Trader]);

    // Create an asset and watch for testing
    $asset = AssetInfo::factory()->create([
        'symbol' => 'TEST2',
        'asset_type' => 'stock',
        'common_name' => 'Test Company 2',
    ]);

    Watch::factory()->create([
        'user_id' => $user->id,
        'asset_info_id' => $asset->id,
    ]);

    // Create a market schedule for today that indicates it's NOT a trading day
    $today = now()->toDateString();
    MarketSchedule::factory()->create([
        'date' => $today,
        'market_type' => 'stock',
        'status' => 'closed',
        'opens_at' => null,
        'closes_at' => null,
        'reason' => 'Holiday',
    ]);

    $response = $this->actingAs($user)->get('/watches');
    $response->assertSuccessful();

    $marketStatus = $response->viewData('page')['props']['marketStatus'];
    expect($marketStatus['defaultTimeRange'])->toBe('Last Open Day');
    expect($marketStatus['isMarketOpen'])->toBe(false);
    expect($marketStatus['isMarketDay'])->toBe(false);
});
