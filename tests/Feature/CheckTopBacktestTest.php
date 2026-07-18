<?php

use App\Models\AssetInfo;
use App\Models\FiveMinutePrice;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;

uses(DatabaseTransactions::class);

it('can access check-top page', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->get('/check-top');

    $response->assertSuccessful();
    $response->assertInertia(fn ($page) => $page
        ->component('check-top/Index')
    );
});

it('filters data by backtest time', function () {
    $user = User::factory()->create();

    // Create asset info
    AssetInfo::factory()->create([
        'symbol' => 'AAPL',
        'asset_type' => 'stock',
        'common_name' => 'Apple Inc.',
    ]);

    // Create price data for different times today
    $baseTime = Carbon::today()->addHours(10); // 10 AM today
    $backtestTime = Carbon::today()->addHours(11); // 11 AM today (1 hour later)
    $laterTime = Carbon::today()->addHours(12); // 12 PM today (2 hours later)

    // Create prices showing a stock rising between base time and backtest time
    FiveMinutePrice::factory()->create([
        'symbol' => 'AAPL',
        'asset_type' => 'stock',
        'ts' => $baseTime->format('Y-m-d H:i:s'),
        'price' => 100.00,
        'open' => 100.00,
        'volume' => 1000,
    ]);

    FiveMinutePrice::factory()->create([
        'symbol' => 'AAPL',
        'asset_type' => 'stock',
        'ts' => $backtestTime->format('Y-m-d H:i:s'),
        'price' => 105.00, // 5% rise - should be detected
        'open' => 100.00,
        'volume' => 1500,
    ]);

    // Create later data (should not be included in backtest)
    FiveMinutePrice::factory()->create([
        'symbol' => 'AAPL',
        'asset_type' => 'stock',
        'ts' => $laterTime->format('Y-m-d H:i:s'),
        'price' => 95.00, // Later dropped - backtest shouldn't see this
        'open' => 100.00,
        'volume' => 2000,
    ]);

    // Test without backtest (should use latest data)
    $response = $this->actingAs($user)->get('/check-top?filter=stock');
    $response->assertSuccessful();

    // Test with backtest time (should use data up to that time only)
    $response = $this->actingAs($user)->get('/check-top?filter=stock&backtest_time='.urlencode($backtestTime->format('Y-m-d H:i:s')));

    $response->assertSuccessful();
    $response->assertInertia(fn ($page) => $page
        ->component('check-top/Index')
        ->has('backtestTime')
        ->where('isBacktesting', true)
        ->where('backtestTime', $backtestTime->format('Y-m-d H:i:s'))
    );
});

it('handles invalid backtest times gracefully', function () {
    $user = User::factory()->create();

    // Test with future time (should handle gracefully)
    $futureTime = Carbon::now()->addDays(1)->format('Y-m-d H:i:s');
    $response = $this->actingAs($user)->get('/check-top?filter=stock&backtest_time='.urlencode($futureTime));

    $response->assertSuccessful();
    $response->assertInertia(fn ($page) => $page
        ->component('check-top/Index')
    );
});
