<?php

use App\Console\Commands\WorkerHeartbeatWatchdog;
use App\Models\DisclaimerAcceptance;
use Illuminate\Support\Facades\Cache;

it('renders the worker heartbeat page from the latest snapshot', function () {
    DisclaimerAcceptance::recordAcceptance('127.0.0.1', 'Test');

    Cache::put(WorkerHeartbeatWatchdog::CACHE_KEY, [
        'captured_at_utc' => now('UTC')->toIso8601String(),
        'captured_at_est' => now('America/New_York')->format('Y-m-d H:i:s T'),
        'overall_status' => 'healthy',
        'summary' => [
            'total_processes' => 2,
            'running_processes' => 2,
            'stopped_processes' => 0,
            'fatal_processes' => 0,
            'queue_pending' => 0,
        ],
        'queues' => [],
        'supervisor_groups' => [
            [
                'name' => 'laravel-invest-tradingv2-workers',
                'total' => 2,
                'running' => 2,
                'stopped' => 0,
                'fatal' => 0,
                'processes' => [],
            ],
        ],
        'processes' => [
            [
                'name' => 'laravel-invest-tradingv2-workers:laravel-invest-tradingv2-workers_00',
                'group' => 'laravel-invest-tradingv2-workers',
                'status' => 'RUNNING',
                'pid' => 123456,
                'uptime' => '0:10:00',
                'details' => 'pid 123456, uptime 0:10:00',
            ],
        ],
        'activity' => [
            'last_bar_ts_est' => '2026-08-21 13:50:00',
            'last_alert_ts_est' => '2026-08-21 13:45:00',
        ],
        'notes' => [],
    ], now()->addMinutes(15));

    $response = $this->get('/worker-heartbeat');

    $response->assertSuccessful()
        ->assertSee('worker-heartbeat/index')
        ->assertSee('captured_at_est')
        ->assertSee('gate-check');
});
