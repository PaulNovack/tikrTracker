<?php

use App\Services\Market\ConsecutiveBarsAnalysisService;
use Illuminate\Support\Facades\Config;

it('applies pre-analysis filtering when enabled', function () {
    // Enable pre-analysis filtering
    Config::set('market.enable_pre_analysis_filtering', true);
    Config::set('market.pre_analysis_min_volume_ratio', 1.3);
    Config::set('market.pre_analysis_max_total_volume', 250000);

    $service = new ConsecutiveBarsAnalysisService;

    // Use reflection to test the private method
    $reflection = new ReflectionClass($service);
    $method = $reflection->getMethod('passesPreAnalysisFiltering');
    $method->setAccessible(true);

    // Mock a symbol that should pass (low volume, good ratio)
    DB::shouldReceive('table->where->where->where->orderBy->get')
        ->andReturn(collect([
            (object) ['ts_est' => '2025-12-05 09:30:00', 'price' => 100, 'volume' => 50000],
            (object) ['ts_est' => '2025-12-05 09:35:00', 'price' => 101, 'volume' => 45000],
            (object) ['ts_est' => '2025-12-05 09:40:00', 'price' => 102, 'volume' => 40000], // Early: 135k
            (object) ['ts_est' => '2025-12-05 09:45:00', 'price' => 103, 'volume' => 30000],
            (object) ['ts_est' => '2025-12-05 09:50:00', 'price' => 104, 'volume' => 25000],
            (object) ['ts_est' => '2025-12-05 09:55:00', 'price' => 105, 'volume' => 20000], // Later: 75k
        ]));

    $result = $method->invoke($service, 'GOODSTOCK', '2025-12-05 10:00:00');

    // Volume ratio: 135k / 75k = 1.8 (> 1.3) ✓
    // Total volume: 210k (< 250k) ✓
    expect($result)->toBeTrue();
});

it('filters out stocks that do not meet pre-analysis criteria', function () {
    Config::set('market.enable_pre_analysis_filtering', true);
    Config::set('market.pre_analysis_min_volume_ratio', 1.3);
    Config::set('market.pre_analysis_max_total_volume', 250000);

    $service = new ConsecutiveBarsAnalysisService;

    $reflection = new ReflectionClass($service);
    $method = $reflection->getMethod('passesPreAnalysisFiltering');
    $method->setAccessible(true);

    // Mock a symbol that should fail (high volume, poor ratio)
    DB::shouldReceive('table->where->where->where->orderBy->get')
        ->andReturn(collect([
            (object) ['ts_est' => '2025-12-05 09:30:00', 'price' => 100, 'volume' => 80000],
            (object) ['ts_est' => '2025-12-05 09:35:00', 'price' => 101, 'volume' => 90000],
            (object) ['ts_est' => '2025-12-05 09:40:00', 'price' => 102, 'volume' => 100000], // Early: 270k
            (object) ['ts_est' => '2025-12-05 09:45:00', 'price' => 103, 'volume' => 120000],
            (object) ['ts_est' => '2025-12-05 09:50:00', 'price' => 104, 'volume' => 130000],
            (object) ['ts_est' => '2025-12-05 09:55:00', 'price' => 105, 'volume' => 140000], // Later: 390k
        ]));

    $result = $method->invoke($service, 'BADSTOCK', '2025-12-05 10:00:00');

    // Volume ratio: 270k / 390k = 0.69 (< 1.3) ✗
    // Total volume: 660k (> 250k) ✗
    expect($result)->toBeFalse();
});

it('skips pre-analysis filtering when disabled', function () {
    Config::set('market.enable_pre_analysis_filtering', false);

    $service = new ConsecutiveBarsAnalysisService;

    $reflection = new ReflectionClass($service);
    $method = $reflection->getMethod('passesPreAnalysisFiltering');
    $method->setAccessible(true);

    // Should return true for any symbol when filtering is disabled
    $result = $method->invoke($service, 'ANYSYMBOL', null);

    expect($result)->toBeTrue();
});

it('skips pre-analysis filtering for current time analysis', function () {
    Config::set('market.enable_pre_analysis_filtering', true);

    $service = new ConsecutiveBarsAnalysisService;

    $reflection = new ReflectionClass($service);
    $method = $reflection->getMethod('passesPreAnalysisFiltering');
    $method->setAccessible(true);

    // Should return true for current time analysis (null estDateTime)
    $result = $method->invoke($service, 'ANYSYMBOL', null);

    expect($result)->toBeTrue();
});
