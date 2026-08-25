<?php

namespace App\Http\Controllers;

use App\Console\Commands\WorkerHeartbeatWatchdog;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Inertia\Inertia;
use Inertia\Response;

class WorkerHeartbeatController extends Controller
{
    public function index(): Response
    {
        $snapshot = Cache::get(WorkerHeartbeatWatchdog::CACHE_KEY, $this->emptySnapshot());

        return Inertia::render('worker-heartbeat/index', [
            'snapshot' => $snapshot,
            'snapshotAgeSeconds' => $this->snapshotAgeSeconds($snapshot['captured_at_utc'] ?? null),
            'lastUpdated' => $snapshot['captured_at_est'] ?? 'Never',
        ]);
    }

    private function emptySnapshot(): array
    {
        return [
            'captured_at_utc' => null,
            'captured_at_est' => null,
            'overall_status' => 'unknown',
            'summary' => [
                'total_processes' => 0,
                'running_processes' => 0,
                'stopped_processes' => 0,
                'fatal_processes' => 0,
                'queue_pending' => 0,
            ],
            'queues' => [],
            'supervisor_groups' => [],
            'processes' => [],
            'activity' => [
                'last_bar_ts_est' => null,
                'last_alert_ts_est' => null,
            ],
            'notes' => [],
        ];
    }

    private function snapshotAgeSeconds(?string $capturedAtUtc): ?int
    {
        if ($capturedAtUtc === null) {
            return null;
        }

        try {
            return Carbon::parse($capturedAtUtc, 'UTC')->diffInSeconds(now('UTC'));
        } catch (\Throwable) {
            return null;
        }
    }
}
