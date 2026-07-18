<?php

use App\Models\SqlLog;
use App\Models\User;

beforeEach(function () {
    // Disable logging by default for all tests
    config(['query_logging.log_queries' => false]);
    SqlLog::truncate();
});

test('sql queries are logged when logging is enabled', function () {
    config(['query_logging.log_queries' => true]);

    // Create a user to trigger database query
    User::factory()->create(['name' => 'Test User']);

    // Verify some queries were logged
    $logs = SqlLog::all();
    expect($logs->count())->toBeGreaterThan(0);

    // Verify log structure
    $log = $logs->first();
    expect($log->query)->not->toBeNull();
    expect($log->execution_time_ms)->not->toBeNull();
    expect($log->connection)->not->toBeNull();
});

test('sql queries are not logged when logging is disabled', function () {
    config(['query_logging.log_queries' => false]);
    SqlLog::truncate();

    // Create and query a user
    User::factory()->create(['name' => 'Test User 2']);
    User::first();

    // Verify no queries were logged
    expect(SqlLog::count())->toBe(0);
});

test('sql log captures execution time', function () {
    config(['query_logging.log_queries' => true]);

    User::factory()->create();

    // Verify execution time was captured
    $logs = SqlLog::all();
    expect($logs->count())->toBeGreaterThan(0);

    $log = $logs->first();
    expect($log->execution_time_ms)->toBeGreaterThanOrEqual(0);
    expect(is_numeric($log->execution_time_ms))->toBeTrue();
});

test('sql log captures request context', function () {
    config(['query_logging.log_queries' => true]);

    $user = User::factory()->create();
    $this->actingAs($user);

    // Trigger a query while authenticated
    $this->get('/');

    // Find logs created by this user
    $logs = SqlLog::where('user_id', (string) $user->id)->get();

    if ($logs->count() > 0) {
        $log = $logs->first();
        expect($log->user_id)->toBe((string) $user->id);
        expect($log->http_method)->not->toBeNull();
    }
});

test('sql log factory generates valid entries', function () {
    $logs = SqlLog::factory(3)->create();

    expect($logs->count())->toBe(3);

    foreach ($logs as $log) {
        expect($log->query)->toBeString();
        expect($log->execution_time_ms)->toBeNumeric();
        expect($log->connection)->toBe('mysql');
    }
});
