<?php

use App\Services\Market\ConsecutiveBarsAnalysisService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('includes actual close data for historical analysis', function () {
    // Create test data for a historical date
    $testSymbol = 'TEST';
    $historicalDate = Carbon::now('America/New_York')->subDay();
    $analysisTime = $historicalDate->copy()->setTime(14, 0, 0); // 2:00 PM
    $closeTime = $historicalDate->copy()->setTime(15, 55, 0);   // 3:55 PM (close)

    // Insert test price data
    // Convert EST times to UTC for storage (generated columns will be computed automatically)
    $analysisTimeUtc = $analysisTime->copy()->addHours(5);
    $closeTimeUtc = $closeTime->copy()->addHours(5);

    DB::table('five_minute_prices')->insert([
        // Analysis time price
        [
            'symbol' => $testSymbol,
            'ts' => $analysisTimeUtc->format('Y-m-d H:i:s'),
            'price' => 100.00,
            'volume' => 1000000,
            'asset_type' => 'stock',
            'created_at' => now(),
            'updated_at' => now(),
        ],
        // Close time price
        [
            'symbol' => $testSymbol,
            'ts' => $closeTimeUtc->format('Y-m-d H:i:s'),
            'price' => 105.00,  // 5% gain
            'volume' => 1000000,
            'asset_type' => 'stock',
            'created_at' => now(),
            'updated_at' => now(),
        ],
    ]);

    // Insert consecutive bars for analysis
    for ($i = 1; $i <= 4; $i++) {
        $barTime = $analysisTime->copy()->subMinutes($i * 5);
        $barTimeUtc = $barTime->copy()->addHours(5); // Convert EST to UTC
        DB::table('five_minute_prices')->insert([
            'symbol' => $testSymbol,
            'ts' => $barTimeUtc->format('Y-m-d H:i:s'),
            'price' => 95.00 + ((5 - $i) * 1), // Increasing prices chronologically: 96, 97, 98, 99
            'volume' => 1000000,
            'asset_type' => 'stock',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    // Add asset info
    DB::table('asset_info')->insert([
        'symbol' => $testSymbol,
        'common_name' => 'Test Company',
        'asset_type' => 'stock',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $service = new ConsecutiveBarsAnalysisService;
    $results = $service->getAnalysisData($analysisTime->format('Y-m-d H:i:s'), 'stock', 4);

    // Should have results with actual close data
    expect($results['stocks'])->not->toBeEmpty();

    $testStock = collect($results['stocks'])->firstWhere('symbol', $testSymbol);
    expect($testStock)->not->toBeNull();
    expect($testStock['actualClosePrice'])->toBe(105.00);
    expect($testStock['actualClosePct'])->toBe(5.0); // 5% gain
});

it('does not include actual close data for current time analysis', function () {
    $service = new ConsecutiveBarsAnalysisService;

    // Analysis without historical date (current time)
    $results = $service->getAnalysisData(null, 'stock', 4);

    // Should not have actual close data for live analysis
    foreach ($results['stocks'] as $stock) {
        expect($stock)->not->toHaveKey('actualClosePrice');
        expect($stock)->not->toHaveKey('actualClosePct');
    }
});

it('handles missing close price data gracefully', function () {
    $testSymbol = 'NOCLOSE';
    $historicalDate = Carbon::now('America/New_York')->subDay();
    $analysisTime = $historicalDate->copy()->setTime(14, 0, 0);

    // Convert EST time to UTC for storage (add 5 hours)
    $analysisTimeUtc = $analysisTime->copy()->addHours(5);

    // Insert only analysis time data, no close time data
    DB::table('five_minute_prices')->insert([
        [
            'symbol' => $testSymbol,
            'ts' => $analysisTimeUtc->format('Y-m-d H:i:s'),
            'price' => 100.00,
            'volume' => 1000000,
            'asset_type' => 'stock',
            'created_at' => now(),
            'updated_at' => now(),
        ],
    ]);

    // Insert consecutive bars
    for ($i = 1; $i <= 4; $i++) {
        $barTime = $analysisTime->copy()->subMinutes($i * 5);
        $barTimeUtc = $barTime->copy()->addHours(5); // Convert EST to UTC
        DB::table('five_minute_prices')->insert([
            'symbol' => $testSymbol,
            'ts' => $barTimeUtc->format('Y-m-d H:i:s'),
            'price' => 95.00 + ((5 - $i) * 1), // Increasing prices chronologically
            'volume' => 1000000,
            'asset_type' => 'stock',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    DB::table('asset_info')->insert([
        'symbol' => $testSymbol,
        'common_name' => 'No Close Company',
        'asset_type' => 'stock',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $service = new ConsecutiveBarsAnalysisService;
    $results = $service->getAnalysisData($analysisTime->format('Y-m-d H:i:s'), 'stock', 4);

    $testStock = collect($results['stocks'])->firstWhere('symbol', $testSymbol);

    // Should have the stock but no actual close data
    expect($testStock)->not->toBeNull();
    expect($testStock)->not->toHaveKey('actualClosePrice');
    expect($testStock)->not->toHaveKey('actualClosePct');
});
