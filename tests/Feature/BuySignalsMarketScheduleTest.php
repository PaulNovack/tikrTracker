<?php

use App\Models\MarketSchedule;
use App\Services\BuySignalsService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    // Create test market schedule data for fictional dates to avoid conflicts
    MarketSchedule::create([
        'date' => '2025-01-15', // Wednesday
        'market_type' => 'stock',
        'status' => 'open',
        'opens_at' => '09:30:00',
        'closes_at' => '16:00:00',
        'is_early_close' => false,
    ]);

    MarketSchedule::create([
        'date' => '2025-01-18', // Saturday
        'market_type' => 'stock',
        'status' => 'closed',
        'reason' => 'Weekend',
        'opens_at' => null,
        'closes_at' => null,
        'is_early_close' => false,
    ]);

    MarketSchedule::create([
        'date' => '2025-01-20', // Monday - Holiday
        'market_type' => 'stock',
        'status' => 'holiday',
        'reason' => 'Martin Luther King Jr. Day',
        'opens_at' => null,
        'closes_at' => null,
        'is_early_close' => false,
    ]);

    MarketSchedule::create([
        'date' => '2025-01-16', // Thursday - Half Day
        'market_type' => 'stock',
        'status' => 'half_day',
        'reason' => 'Test Half Day',
        'opens_at' => '09:30:00',
        'closes_at' => '13:00:00',
        'is_early_close' => true,
    ]);

    MarketSchedule::create([
        'date' => '2025-01-17', // Friday - Normal Day
        'market_type' => 'stock',
        'status' => 'open',
        'opens_at' => '09:30:00',
        'closes_at' => '16:00:00',
        'is_early_close' => false,
    ]);
});

it('correctly identifies market as open during regular hours', function () {
    $service = new BuySignalsService;

    // Test during regular market hours (12:00 PM EST on Jan 15)
    $testTime = Carbon::parse('2025-01-15 12:00:00', 'America/New_York');

    $reflection = new ReflectionClass($service);
    $method = $reflection->getMethod('isMarketClosed');
    $method->setAccessible(true);

    $isMarketClosed = $method->invoke($service, $testTime);

    expect($isMarketClosed)->toBeFalse();
});

it('correctly identifies market as closed before opening hours', function () {
    $service = new BuySignalsService;

    // Test before market opens (8:00 AM EST on Jan 15)
    $testTime = Carbon::parse('2025-01-15 08:00:00', 'America/New_York');

    $reflection = new ReflectionClass($service);
    $method = $reflection->getMethod('isMarketClosed');
    $method->setAccessible(true);

    $isMarketClosed = $method->invoke($service, $testTime);

    expect($isMarketClosed)->toBeTrue();
});

it('correctly identifies market as closed after hours', function () {
    $service = new BuySignalsService;

    // Test after market closes (5:00 PM EST on Jan 15)
    $testTime = Carbon::parse('2025-01-15 17:00:00', 'America/New_York');

    $reflection = new ReflectionClass($service);
    $method = $reflection->getMethod('isMarketClosed');
    $method->setAccessible(true);

    $isMarketClosed = $method->invoke($service, $testTime);

    expect($isMarketClosed)->toBeTrue();
});

it('correctly identifies market as closed on weekends', function () {
    $service = new BuySignalsService;

    // Test on weekend (Jan 18 is Saturday)
    $testTime = Carbon::parse('2025-01-18 12:00:00', 'America/New_York');

    $reflection = new ReflectionClass($service);
    $method = $reflection->getMethod('isMarketClosed');
    $method->setAccessible(true);

    $isMarketClosed = $method->invoke($service, $testTime);

    expect($isMarketClosed)->toBeTrue();
});

it('correctly identifies market as closed on holidays', function () {
    $service = new BuySignalsService;

    // Test on Martin Luther King Jr. Day
    $testTime = Carbon::parse('2025-01-20 12:00:00', 'America/New_York');

    $reflection = new ReflectionClass($service);
    $method = $reflection->getMethod('isMarketClosed');
    $method->setAccessible(true);

    $isMarketClosed = $method->invoke($service, $testTime);

    expect($isMarketClosed)->toBeTrue();
});

it('handles half day trading correctly', function () {
    $service = new BuySignalsService;

    // Test during half day hours (11:00 AM EST on Jan 16)
    $testTime = Carbon::parse('2025-01-16 11:00:00', 'America/New_York');

    $reflection = new ReflectionClass($service);
    $method = $reflection->getMethod('isMarketClosed');
    $method->setAccessible(true);

    $isMarketClosed = $method->invoke($service, $testTime);

    expect($isMarketClosed)->toBeFalse();

    // Test after half day close (2:00 PM EST)
    $testTimeAfter = Carbon::parse('2025-01-16 14:00:00', 'America/New_York');
    $isMarketClosedAfter = $method->invoke($service, $testTimeAfter);

    expect($isMarketClosedAfter)->toBeTrue();
});

it('returns current time when market is open', function () {
    $service = new BuySignalsService;

    $reflection = new ReflectionClass($service);
    $method = $reflection->getMethod('getAppropriateEntryTime');
    $method->setAccessible(true);

    // During market hours - should return current time
    $marketTime = Carbon::parse('2025-01-15 12:00:00', 'America/New_York');
    $entryTime = $method->invoke($service, $marketTime);

    expect($entryTime->format('Y-m-d H:i:s'))->toBe('2025-01-15 12:00:00');
});

it('returns next market open when market is closed', function () {
    $service = new BuySignalsService;

    $reflection = new ReflectionClass($service);
    $method = $reflection->getMethod('getAppropriateEntryTime');
    $method->setAccessible(true);

    // After market hours on Jan 15 - should return next trading day at 9:30 AM
    $afterHours = Carbon::parse('2025-01-15 18:00:00', 'America/New_York');
    $nextEntryTime = $method->invoke($service, $afterHours);

    // Should be Jan 16 (Thursday - half day) at 9:30 AM (next trading day)
    expect($nextEntryTime->format('Y-m-d H:i:s'))->toBe('2025-01-16 09:30:00');
});
