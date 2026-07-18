<?php

use App\Services\Market\ConsecutiveBarsAnalysisService;
use Illuminate\Support\Facades\Config;

it('rejects bars with downward movement exceeding tolerance', function () {
    // Set tolerance to 0.1% (0.1)
    Config::set('market.consecutive_bars_downward_tolerance_pct', 0.1);

    $service = new ConsecutiveBarsAnalysisService;

    // Use reflection to access the private method
    $reflection = new ReflectionClass($service);
    $method = $reflection->getMethod('hasStrictlyIncreasingBars');
    $method->setAccessible(true);

    // Test data: bars with 0.2% downward movement (exceeds 0.1% tolerance)
    $bars = [
        ['price' => 100.00],
        ['price' => 101.00],  // +1%
        ['price' => 100.80],  // -0.2% (exceeds tolerance)
        ['price' => 101.50],  // +0.7%
    ];

    $result = $method->invoke($service, $bars, 4);

    expect($result)->toBeFalse();
});

it('accepts bars with downward movement within tolerance', function () {
    // Set tolerance to 0.2% (0.2)
    Config::set('market.consecutive_bars_downward_tolerance_pct', 0.2);

    $service = new ConsecutiveBarsAnalysisService;

    // Use reflection to access the private method
    $reflection = new ReflectionClass($service);
    $method = $reflection->getMethod('hasStrictlyIncreasingBars');
    $method->setAccessible(true);

    // Test data: bars with 0.1% downward movement (within 0.2% tolerance)
    $bars = [
        ['price' => 100.00],
        ['price' => 101.00],  // +1%
        ['price' => 100.90],  // -0.1% (within tolerance)
        ['price' => 101.50],  // +0.6%
    ];

    $result = $method->invoke($service, $bars, 4);

    expect($result)->toBeTrue();
});

it('works with zero tolerance (strict mode)', function () {
    // Set tolerance to 0% (strict mode)
    Config::set('market.consecutive_bars_downward_tolerance_pct', 0.0);

    $service = new ConsecutiveBarsAnalysisService;

    // Use reflection to access the private method
    $reflection = new ReflectionClass($service);
    $method = $reflection->getMethod('hasStrictlyIncreasingBars');
    $method->setAccessible(true);

    // Test data: any downward movement should be rejected
    $bars = [
        ['price' => 100.00],
        ['price' => 101.00],  // +1%
        ['price' => 100.99],  // -0.01% (any downward movement rejected)
        ['price' => 101.50],  // +0.5%
    ];

    $result = $method->invoke($service, $bars, 4);

    expect($result)->toBeFalse();
});

it('accepts strictly increasing bars regardless of tolerance', function () {
    // Set any tolerance
    Config::set('market.consecutive_bars_downward_tolerance_pct', 0.1);

    $service = new ConsecutiveBarsAnalysisService;

    // Use reflection to access the private method
    $reflection = new ReflectionClass($service);
    $method = $reflection->getMethod('hasStrictlyIncreasingBars');
    $method->setAccessible(true);

    // Test data: strictly increasing bars
    $bars = [
        ['price' => 100.00],
        ['price' => 101.00],  // +1%
        ['price' => 102.00],  // +1%
        ['price' => 103.00],  // +1%
    ];

    $result = $method->invoke($service, $bars, 4);

    expect($result)->toBeTrue();
});
