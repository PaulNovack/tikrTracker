<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

it('can execute the scan chart patterns command', function () {
    // Create a test asset in asset_info
    DB::table('asset_info')->insert([
        'symbol' => 'AAPL',
        'asset_type' => 'stock',
        'company_name' => 'Apple Inc.',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    // Create some sample 5-minute price data to work with
    $baseTime = now()->setTimezone('America/New_York')->subHours(2);

    for ($i = 0; $i < 50; $i++) {
        $timestamp = $baseTime->copy()->addMinutes($i * 5);
        $price = 150.0 + sin($i * 0.1) * 5; // Create some price movement

        DB::table('five_minute_prices')->insert([
            'symbol' => 'AAPL',
            'asset_type' => 'stock',
            'ts_est' => $timestamp->format('Y-m-d H:i:s'),
            'open' => $price,
            'high' => $price + 0.5,
            'low' => $price - 0.5,
            'price' => $price + 0.2,
            'volume' => 1000000,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    // Run the command with debug mode for single symbol
    $this->artisan('market:scan-chart-patterns', [
        'asset_type' => 'stock',
        '--debugSymbol' => 'AAPL',
        '--minScore' => '0.0', // Lower threshold to catch any patterns
        '--lookback' => '300', // 5 hours of data
        '--maxSymbols' => '1',
    ])->assertExitCode(0);

    // The command should complete successfully (even if no patterns found)
    // This tests that the command doesn't crash and handles data correctly
    expect(true)->toBeTrue();
});

it('handles empty data gracefully', function () {
    // Run command with no data - should not crash
    $this->artisan('market:scan-chart-patterns', [
        'asset_type' => 'stock',
        '--debugSymbol' => 'NONEXISTENT',
        '--maxSymbols' => '1',
    ])->assertExitCode(0);

    expect(true)->toBeTrue();
});

it('validates asset_type parameter', function () {
    // Invalid asset type should return error code 1
    $this->artisan('market:scan-chart-patterns', [
        'asset_type' => 'invalid',
    ])->assertExitCode(1);
});

it('can write trade signals to database', function () {
    // Ensure the trade_signals table exists and can be written to
    expect(DB::getSchemaBuilder()->hasTable('trade_signals'))->toBeTrue();

    // Test basic insert capability
    $signalData = [
        'symbol' => 'TEST',
        'asset_type' => 'stock',
        'asof_ts_est' => now()->format('Y-m-d H:i:s'),
        'pattern' => 'BULL_DOUBLE_BOTTOM',
        'levels_json' => json_encode(['test' => 'data']),
        'score' => 7.5,
        'triggered' => 0,
        'timeframe' => '5m',
        'created_at' => now(),
        'updated_at' => now(),
    ];

    DB::table('trade_signals')->insert($signalData);

    expect(DB::table('trade_signals')->where('symbol', 'TEST')->count())->toBe(1);
});
