<?php

use Illuminate\Support\Facades\DB;

beforeEach(function () {
    // Seed a minimal daily_prices row so avg_volume lookup returns a value
    DB::table('daily_prices')->insert([
        'symbol' => 'TSST',
        'asset_type' => 'stock',
        'date' => '2026-04-16',
        'price' => 10.50,
        'volume' => 500000,
    ]);

    // Opening bar + a later bar, both with change_from_open NULL
    DB::table('five_minute_prices')->insert([
        [
            'symbol' => 'TSST',
            'asset_type' => 'stock',
            'ts_est' => '2026-04-17 09:30:00',
            'trading_date_est' => '2026-04-17',
            'trading_time_est' => '09:30:00',
            'open' => 10.00,
            'high' => 10.20,
            'low' => 9.90,
            'price' => 10.00,
            'volume' => 10000,
            'vwap' => 10.00,
            'above_vwap' => 1,
            'ema9' => 10.00,
            'ema21' => 10.00,
            'ema9_above_ema21' => 1,
            'ema9_ema21_spread' => 0,
            'atr' => 0.10,
            'atr_pct' => 1.00,
            'change_from_open' => null,
            'relative_volume' => null,
        ],
        [
            'symbol' => 'TSST',
            'asset_type' => 'stock',
            'ts_est' => '2026-04-17 10:00:00',
            'trading_date_est' => '2026-04-17',
            'trading_time_est' => '10:00:00',
            'open' => 10.00,
            'high' => 10.60,
            'low' => 10.20,
            'price' => 10.50,
            'volume' => 20000,
            'vwap' => 10.25,
            'above_vwap' => 1,
            'ema9' => 10.30,
            'ema21' => 10.10,
            'ema9_above_ema21' => 1,
            'ema9_ema21_spread' => 0.20,
            'atr' => 0.10,
            'atr_pct' => 0.95,
            'change_from_open' => null,
            'relative_volume' => null,
        ],
    ]);
});

afterEach(function () {
    DB::table('five_minute_prices')->where('symbol', 'TSST')->delete();
    DB::table('daily_prices')->where('symbol', 'TSST')->delete();
});

it('populates change_from_open and relative_volume for a given date', function () {
    $this->artisan('indicators:calculate-momentum', ['--date' => '2026-04-17'])
        ->assertSuccessful();

    $bar = DB::table('five_minute_prices')
        ->where('symbol', 'TSST')
        ->where('ts_est', '2026-04-17 10:00:00')
        ->first();

    expect($bar->change_from_open)->not->toBeNull()
        ->and((float) $bar->change_from_open)->toBe(5.0) // (10.50 - 10.00) / 10.00 * 100
        ->and($bar->relative_volume)->not->toBeNull();
});

it('open bar has change_from_open of zero', function () {
    $this->artisan('indicators:calculate-momentum', ['--date' => '2026-04-17'])
        ->assertSuccessful();

    $openBar = DB::table('five_minute_prices')
        ->where('symbol', 'TSST')
        ->where('ts_est', '2026-04-17 09:30:00')
        ->first();

    expect((float) $openBar->change_from_open)->toBe(0.0);
});

it('handles missing --date by defaulting to today', function () {
    $this->artisan('indicators:calculate-momentum')
        ->assertSuccessful();
});
