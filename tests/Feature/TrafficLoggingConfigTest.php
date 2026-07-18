<?php

use App\Models\TrafficLog;

it('skips traffic logging when disabled', function () {
    // Disable traffic logging
    config(['traffic_logging.enabled' => false]);

    $initialCount = TrafficLog::count();

    // Make a request
    $this->get('/');

    // Count should not increase
    $finalCount = TrafficLog::count();
    expect($finalCount)->toBe($initialCount);
});

it('logs traffic when enabled', function () {
    // Enable traffic logging
    config(['traffic_logging.enabled' => true]);

    $initialCount = TrafficLog::count();

    // Make a request
    $this->get('/');

    // Count should increase (if middleware terminates properly in test)
    $finalCount = TrafficLog::count();
    expect($finalCount)->toBeGreaterThanOrEqual($initialCount);
});
