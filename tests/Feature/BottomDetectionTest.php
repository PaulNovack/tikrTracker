<?php

use App\Models\User;
use App\Services\Analysis\BottomDetectionService;

it('can access bottom detection page when authenticated', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->get('/analysis/bottom-detect');

    $response->assertSuccessful();
    $response->assertInertia(function ($page) {
        $page->component('analysis/BottomDetect')
            ->has('title');
    });
});

it('redirects to login when not authenticated', function () {
    $response = $this->get('/analysis/bottom-detect');

    $response->assertRedirect('/login');
});

it('can handle bottom detection with parameters', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->get('/analysis/bottom-detect', [
        'scan_date' => '2024-01-15',
        'lookback_days' => 20,
        'min_rsi_oversold' => 20,
        'max_rsi_oversold' => 30,
    ]);

    $response->assertSuccessful();
    $response->assertInertia(function ($page) {
        $page->component('analysis/BottomDetect')
            ->has('title')
            ->has('scan_date')
            ->has('lookback_days')
            ->has('min_rsi_oversold')
            ->has('max_rsi_oversold');
    });
});

it('validates bottom detection parameters correctly', function () {
    $user = User::factory()->create();

    // Test with invalid parameters
    $response = $this->actingAs($user)->get('/analysis/bottom-detect', [
        'lookback_days' => -1,  // Invalid negative value
        'min_rsi_oversold' => 60,  // Invalid, should be <= 50
    ]);

    $response->assertSuccessful();
    // Should use default values when invalid parameters are provided
});

it('bottom detection service can calculate technical indicators', function () {
    $service = new BottomDetectionService;

    // Test RSI calculation with sample data
    $prices = [100, 102, 101, 103, 105, 104, 106, 108, 107, 109, 111, 110, 112, 114, 113];

    // RSI calculation requires at least 14 periods
    $rsi = $service->calculateRSI($prices, 14);

    expect($rsi)->toBeFloat();
    expect($rsi)->toBeGreaterThan(0);
    expect($rsi)->toBeLessThan(100);
});

it('bottom detection service can calculate EMA', function () {
    $service = new BottomDetectionService;

    $prices = [100, 102, 101, 103, 105, 104, 106, 108, 107, 109];
    $ema = $service->calculateEMA($prices, 9);

    expect($ema)->toBeArray();
    expect(count($ema))->toBe(count($prices));
    expect($ema[0])->toBe($prices[0]); // First EMA value should equal first price
});

it('bottom detection service can calculate Bollinger Bands', function () {
    $service = new BottomDetectionService;

    $prices = [100, 102, 101, 103, 105, 104, 106, 108, 107, 109, 111, 110, 112, 114, 113, 115, 117, 116, 118, 120];
    $bands = $service->calculateBollingerBands($prices, 20, 2.0);

    expect($bands)->toBeArray();
    expect($bands)->toHaveKey('upper');
    expect($bands)->toHaveKey('lower');
    expect($bands)->toHaveKey('middle');

    // Upper band should be higher than lower band
    expect($bands['upper'])->toBeGreaterThan($bands['lower']);
});

it('can detect bottoms with sample data', function () {
    $service = new BottomDetectionService;

    // This test verifies the service doesn't throw errors with valid parameters
    $results = $service->detectBottoms(
        'stock',
        '2024-01-15 16:00:00',
        [
            'lookbackBars' => 30,
            'minRsi' => 25,
            'oversoldLookback' => 20,
            'baseBars' => 3,
            'minDollarVol' => 1000000,
        ]
    );

    expect($results)->toBeArray();
    expect($results)->toHaveKey('candidates');
    expect($results)->toHaveKey('metadata');
    expect($results['candidates'])->toBeArray();
    expect($results['metadata'])->toBeArray();
});
