<?php

use App\Models\SqlLog;
use App\Services\QueryLogger;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;

uses(DatabaseTransactions::class);

beforeEach(function () {
    // Enable query logging for tests
    config(['query_logging.log_queries' => true]);
});

it('marks cache table queries as cached_data true', function () {
    $queryLogger = new QueryLogger;

    // Simulate a cache table SELECT query using the real connection
    $event = new QueryExecuted(
        'select * from `cache` where `key` = ?',
        ['test-key'],
        0,
        DB::connection()
    );

    $queryLogger->handle($event);

    $log = SqlLog::where('query', 'like', '%from cache%')->first();
    expect($log)->not->toBeNull();
    expect($log->cached_data)->toBeTrue();
});

it('marks cache_locks table queries as cached_data true', function () {
    $queryLogger = new QueryLogger;

    // Simulate a cache_locks table query
    $event = new QueryExecuted(
        'insert into `cache_locks` (`key`, `owner`, `expiration`) values (?, ?, ?)',
        ['lock-key', 'owner-123', 1234567890],
        0,
        DB::connection()
    );

    $queryLogger->handle($event);

    $log = SqlLog::where('query', 'like', '%cache_locks%')->first();
    expect($log)->not->toBeNull();
    expect($log->cached_data)->toBeTrue();
});

it('marks non-cache table queries as cached_data false', function () {
    $queryLogger = new QueryLogger;

    // Simulate a regular table query
    $event = new QueryExecuted(
        'select * from `users` where `id` = ?',
        [1],
        0,
        DB::connection()
    );

    $queryLogger->handle($event);

    $log = SqlLog::where('query', 'like', '%from users%')->first();
    expect($log)->not->toBeNull();
    expect($log->cached_data)->toBeFalse();
});

it('handles queries without backticks', function () {
    $queryLogger = new QueryLogger;

    // Simulate a cache query without backticks
    $event = new QueryExecuted(
        'delete from cache where key = ?',
        ['expired-key'],
        0,
        DB::connection()
    );

    $queryLogger->handle($event);

    $log = SqlLog::where('query', 'like', '%delete from cache%')->first();
    expect($log)->not->toBeNull();
    expect($log->cached_data)->toBeTrue();
});

it('correctly identifies cache table in UPDATE statements', function () {
    $queryLogger = new QueryLogger;

    // Simulate a cache table UPDATE query
    $event = new QueryExecuted(
        'update cache set value = ? where key = ?',
        ['new-value', 'cache-key'],
        0,
        DB::connection()
    );

    $queryLogger->handle($event);

    $log = SqlLog::where('query', 'like', '%update cache%')->first();
    expect($log)->not->toBeNull();
    expect($log->cached_data)->toBeTrue();
});

it('does not mark tables with cache in the name as cache queries', function () {
    $queryLogger = new QueryLogger;

    // Simulate a query on a table that contains 'cache' in the name but isn't the cache table
    $event = new QueryExecuted(
        'select * from `user_cache_settings` where `user_id` = ?',
        [1],
        0,
        DB::connection()
    );

    $queryLogger->handle($event);

    $log = SqlLog::where('query', 'like', '%user_cache_settings%')->first();
    expect($log)->not->toBeNull();
    expect($log->cached_data)->toBeFalse();
});
