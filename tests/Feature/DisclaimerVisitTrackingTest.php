<?php

use App\Models\DisclaimerAcceptance;
use Illuminate\Foundation\Testing\DatabaseTransactions;

uses(DatabaseTransactions::class);

beforeEach(function () {
    // Clear all disclaimer acceptances before each test
    DisclaimerAcceptance::truncate();
});

it('creates tracking record on first visit and does not show disclaimer', function () {
    $ipAddress = '192.168.1.1';

    // First, increment page visit (this is what middleware does)
    DisclaimerAcceptance::incrementPageVisit($ipAddress);

    // Then check should not show disclaimer
    $shouldShow = DisclaimerAcceptance::shouldShowDisclaimer($ipAddress);
    expect($shouldShow)->toBeFalse();

    // Verify record was created
    $record = DisclaimerAcceptance::where('ip_address', $ipAddress)->first();
    expect($record)->not->toBeNull();
    expect($record->root_page_visits)->toBe(1);
    expect($record->first_visit_at)->not->toBeNull();
    expect($record->time_threshold_triggered)->toBeFalse();
});

it('shows disclaimer after 5 root page visits', function () {
    $ipAddress = '192.168.1.2';

    // First visit creates record
    DisclaimerAcceptance::incrementPageVisit($ipAddress);

    // Increment visits to reach the limit
    for ($i = 2; $i <= 5; $i++) {
        DisclaimerAcceptance::incrementPageVisit($ipAddress);
    }

    // Should now show disclaimer
    $shouldShow = DisclaimerAcceptance::shouldShowDisclaimer($ipAddress);
    expect($shouldShow)->toBeTrue();

    // Verify threshold was triggered
    $record = DisclaimerAcceptance::where('ip_address', $ipAddress)->first();
    expect($record->time_threshold_triggered)->toBeTrue();
    expect($record->time_threshold_triggered_at)->not->toBeNull();
});

it('shows disclaimer after 30 seconds even with few visits', function () {
    $ipAddress = '192.168.1.3';

    // Create record with first_visit_at set to 31 seconds ago
    DisclaimerAcceptance::create([
        'ip_address' => $ipAddress,
        'user_agent' => 'Test Browser',
        'first_visit_at' => now()->subSeconds(31),
        'root_page_visits' => 2, // Less than 5
        'last_access_at' => now(),
        'access_count' => 2,
    ]);

    // Should show disclaimer due to time threshold
    $shouldShow = DisclaimerAcceptance::shouldShowDisclaimer($ipAddress);
    expect($shouldShow)->toBeTrue();

    // Verify threshold was triggered
    $record = DisclaimerAcceptance::where('ip_address', $ipAddress)->first();
    expect($record->time_threshold_triggered)->toBeTrue();
});

it('does not show disclaimer once accepted', function () {
    $ipAddress = '192.168.1.4';

    // Create record that would normally trigger disclaimer
    DisclaimerAcceptance::create([
        'ip_address' => $ipAddress,
        'user_agent' => 'Test Browser',
        'first_visit_at' => now()->subSeconds(60),
        'root_page_visits' => 10,
        'time_threshold_triggered' => true,
        'time_threshold_triggered_at' => now()->subSeconds(30),
        'last_access_at' => now(),
        'access_count' => 10,
    ]);

    // Should show disclaimer before acceptance
    $shouldShow = DisclaimerAcceptance::shouldShowDisclaimer($ipAddress);
    expect($shouldShow)->toBeTrue();

    // Accept disclaimer
    DisclaimerAcceptance::recordAcceptance($ipAddress, 'Test Browser');

    // Should not show disclaimer after acceptance
    $shouldShow = DisclaimerAcceptance::shouldShowDisclaimer($ipAddress);
    expect($shouldShow)->toBeFalse();
});

it('properly increments root page visits only for unaccepted users', function () {
    $ipAddress = '192.168.1.5';

    // Create initial record
    DisclaimerAcceptance::incrementPageVisit($ipAddress);

    // Increment visits a few times
    DisclaimerAcceptance::incrementPageVisit($ipAddress);
    DisclaimerAcceptance::incrementPageVisit($ipAddress);

    $record = DisclaimerAcceptance::where('ip_address', $ipAddress)->first();
    expect($record->root_page_visits)->toBe(3);

    // Accept disclaimer
    DisclaimerAcceptance::recordAcceptance($ipAddress, 'Test Browser');

    // Try to increment visits after acceptance - should not increment
    DisclaimerAcceptance::incrementPageVisit($ipAddress);

    $record->refresh();
    expect($record->root_page_visits)->toBe(3); // Should remain the same
});

it('tracks access count and last access time', function () {
    $ipAddress = '192.168.1.6';

    // Create initial record
    DisclaimerAcceptance::incrementPageVisit($ipAddress);
    $originalTime = now();

    sleep(1); // Wait a second

    // Increment visits
    DisclaimerAcceptance::incrementPageVisit($ipAddress);

    $record = DisclaimerAcceptance::where('ip_address', $ipAddress)->first();
    expect($record->access_count)->toBeGreaterThan(1);
    expect($record->last_access_at->getTimestamp())->toBeGreaterThan($originalTime->getTimestamp());
});
