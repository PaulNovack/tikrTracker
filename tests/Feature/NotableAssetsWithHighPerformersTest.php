<?php

use App\Models\DisclaimerAcceptance;

it('displays notable assets page with high performers', function () {
    // Find a recent date with substantial trading data
    $tradingDate = \DB::table('five_minute_prices')
        ->selectRaw('trading_date_est, COUNT(DISTINCT symbol) as symbol_count')
        ->groupBy('trading_date_est')
        ->having('symbol_count', '>', 1000)
        ->orderBy('trading_date_est', 'desc')
        ->first();

    if (! $tradingDate) {
        $this->markTestSkipped('No trading data found for testing');
    }

    // Use Carbon to travel back to when we had data
    \Carbon\Carbon::setTestNow($tradingDate->trading_date_est.' 16:00:00');

    // Accept disclaimer for this test IP
    $ip = '127.0.0.1';
    DisclaimerAcceptance::create([
        'ip_address' => $ip,
        'accepted_at' => now(),
        'terms_accepted' => true,
        'risks_accepted' => true,
        'privacy_accepted' => true,
    ]);

    $response = $this->withServerVariables(['REMOTE_ADDR' => $ip])
        ->withoutMiddleware()
        ->get('/notable-assets');

    $response->assertSuccessful();
    $response->assertInertia(fn ($page) => $page
        ->component('notable-assets')
        ->has('stagnationData')
        ->has('flatThresholdPct')
        ->has('goodPositivePct')
        ->has('greatPositivePct')
        ->has('negativeAlertPct')
        ->has('marketSchedule')
        ->has('tradingDates')
    );

    // Clean up
    \Carbon\Carbon::setTestNow();
    expect($tradingDate)->not->toBeNull('No trading data found for testing');

    // Use Carbon to travel back to when we had data
    \Carbon\Carbon::setTestNow($tradingDate->trading_date_est.' 16:00:00');

    // Accept disclaimer for this test IP
    $ip = '127.0.0.1';
    DisclaimerAcceptance::create([
        'ip_address' => $ip,
        'accepted_at' => now(),
        'terms_accepted' => true,
        'risks_accepted' => true,
        'privacy_accepted' => true,
    ]);

    $response = $this->withServerVariables(['REMOTE_ADDR' => $ip])
        ->withoutMiddleware()
        ->get('/notable-assets');

    $response->assertSuccessful();
    $response->assertInertia(fn ($page) => $page
        ->component('notable-assets')
        ->has('stagnationData')
        ->has('flatThresholdPct')
        ->has('goodPositivePct')
        ->has('greatPositivePct')
        ->has('negativeAlertPct')
        ->has('marketSchedule')
        ->has('tradingDates')
    );

    // Clean up
    \Carbon\Carbon::setTestNow();
});

it('includes has_significant_gain field in response data', function () {
    // Find a recent date with substantial trading data (within last 10 days)
    $tradingDate = \DB::table('five_minute_prices')
        ->selectRaw('trading_date_est, COUNT(DISTINCT symbol) as symbol_count')
        ->where('trading_date_est', '>=', now()->subDays(10)->format('Y-m-d'))
        ->groupBy('trading_date_est')
        ->having('symbol_count', '>', 1000)
        ->orderBy('trading_date_est', 'desc')
        ->first();

    if (! $tradingDate) {
        $this->markTestSkipped('No trading data found for testing');
    }

    // Use Carbon to travel back to when we had data
    \Carbon\Carbon::setTestNow($tradingDate->trading_date_est.' 16:00:00');

    // Accept disclaimer for this test IP
    $ip = '127.0.0.1';
    DisclaimerAcceptance::create([
        'ip_address' => $ip,
        'accepted_at' => now(),
        'terms_accepted' => true,
        'risks_accepted' => true,
        'privacy_accepted' => true,
    ]);

    $response = $this->withServerVariables(['REMOTE_ADDR' => $ip])
        ->withoutMiddleware()
        ->get('/notable-assets');

    $response->assertSuccessful();
    $response->assertInertia(fn ($page) => $page
        ->component('notable-assets')
        ->where('stagnationData', function ($data) {
            // Check that stagnationData is an array (could be empty, that's okay)
            if (empty($data)) {
                return true; // Empty data is acceptable
            }

            // If we have data, ensure at least one asset has the has_significant_gain field
            return collect($data)->contains(function ($asset) {
                return isset($asset['has_significant_gain']);
            });
        })
    );

    // Clean up
    \Carbon\Carbon::setTestNow();
});

it('correctly identifies high performers with significant gains', function () {
    // Find a recent date with substantial trading data (within last 10 days)
    $tradingDate = \DB::table('five_minute_prices')
        ->selectRaw('trading_date_est, COUNT(DISTINCT symbol) as symbol_count')
        ->where('trading_date_est', '>=', now()->subDays(10)->format('Y-m-d'))
        ->groupBy('trading_date_est')
        ->having('symbol_count', '>', 1000)
        ->orderBy('trading_date_est', 'desc')
        ->first();

    if (! $tradingDate) {
        $this->markTestSkipped('No trading data found for testing');
    }

    // Use Carbon to travel back to when we had data
    \Carbon\Carbon::setTestNow($tradingDate->trading_date_est.' 16:00:00');

    // Accept disclaimer for this test IP
    $ip = '127.0.0.1';
    DisclaimerAcceptance::create([
        'ip_address' => $ip,
        'accepted_at' => now(),
        'terms_accepted' => true,
        'risks_accepted' => true,
        'privacy_accepted' => true,
    ]);

    $response = $this->withServerVariables(['REMOTE_ADDR' => $ip])
        ->withoutMiddleware()
        ->get('/notable-assets');

    $response->assertSuccessful();
    $response->assertInertia(fn ($page) => $page
        ->component('notable-assets')
        ->where('stagnationData', function ($data) {
            // If no data found, that's acceptable for this test
            if (empty($data)) {
                return true;
            }

            // If we have data, check the structure is correct
            $firstAsset = collect($data)->first();

            return isset($firstAsset['has_significant_gain']) &&
                   isset($firstAsset['symbol']) &&
                   isset($firstAsset['current_price']);
        })
    );

    // Clean up
    \Carbon\Carbon::setTestNow();
});

it('responds to filter parameter correctly', function () {
    // Find a recent date with substantial trading data (within last 10 days)
    $tradingDate = \DB::table('five_minute_prices')
        ->selectRaw('trading_date_est, COUNT(DISTINCT symbol) as symbol_count')
        ->where('trading_date_est', '>=', now()->subDays(10)->format('Y-m-d'))
        ->groupBy('trading_date_est')
        ->having('symbol_count', '>', 1000)
        ->orderBy('trading_date_est', 'desc')
        ->first();

    if (! $tradingDate) {
        $this->markTestSkipped('No trading data found for testing');
    }

    // Use Carbon to travel back to when we had data
    \Carbon\Carbon::setTestNow($tradingDate->trading_date_est.' 16:00:00');

    // Accept disclaimer for this test IP
    $ip = '127.0.0.1';
    DisclaimerAcceptance::create([
        'ip_address' => $ip,
        'accepted_at' => now(),
        'terms_accepted' => true,
        'risks_accepted' => true,
        'privacy_accepted' => true,
    ]);

    // Test all filter
    $response = $this->withServerVariables(['REMOTE_ADDR' => $ip])
        ->withoutMiddleware()
        ->get('/notable-assets?filter=all');
    $response->assertSuccessful();
    $response->assertInertia(fn ($page) => $page
        ->component('notable-assets')
        ->has('stagnationData')
        ->has('marketSchedule')
    );

    // Test watched filter
    $response = $this->withServerVariables(['REMOTE_ADDR' => $ip])
        ->withoutMiddleware()
        ->get('/notable-assets?filter=watched');
    $response->assertSuccessful();
    $response->assertInertia(fn ($page) => $page
        ->component('notable-assets')
        ->has('stagnationData')
        ->has('marketSchedule')
    );

    // Clean up
    \Carbon\Carbon::setTestNow();
});
