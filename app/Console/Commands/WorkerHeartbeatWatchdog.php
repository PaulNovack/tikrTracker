<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Redis;

class WorkerHeartbeatWatchdog extends Command
{
    public const CACHE_KEY = 'worker-heartbeat:snapshot';

    protected $signature = 'monitor:worker-heartbeat';

    protected $description = 'Capture a worker heartbeat snapshot for the worker status page';

    public function handle(): int
    {
        $capturedAtUtc = now('UTC');
        $capturedAtEst = $capturedAtUtc->copy()->setTimezone('America/New_York');
        $parsedSupervisor = [
            'groups' => [],
            'processes' => [],
        ];

        $snapshot = [
            'captured_at_utc' => $capturedAtUtc->toIso8601String(),
            'captured_at_est' => $capturedAtEst->format('Y-m-d H:i:s T'),
            'overall_status' => 'healthy',
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

        $supervisorResult = Process::run('supervisorctl status 2>/dev/null');
        $supervisorOutput = trim($supervisorResult->output());

        if ($supervisorOutput !== '') {
            $parsedSupervisor = $this->parseSupervisorStatus($supervisorOutput);
            $snapshot['supervisor_groups'] = $parsedSupervisor['groups'];
            $snapshot['processes'] = $parsedSupervisor['processes'];
        } else {
            $snapshot['notes'][] = 'Unable to read supervisorctl status.';
            $snapshot['overall_status'] = 'warning';
        }

        $queueDefinitions = [
            ['name' => 'default', 'key' => 'queues:default', 'worker_filter' => 'worker'],
            ['name' => 'backtest', 'key' => 'queues:backtest', 'worker_filter' => 'backtest'],
            ['name' => 'ml-scoring', 'key' => 'queues:ml-scoring', 'worker_filter' => 'ml-scoring-worker'],
            ['name' => 'ml-scoring-catchup', 'key' => 'queues:ml-scoring-catchup', 'worker_filter' => 'ml-scoring-catchup-worker'],
            ['name' => 'gate-check', 'key' => 'queues:gate-check', 'worker_filter' => 'tradingv2-workers'],
        ];

        foreach ($queueDefinitions as $definition) {
            $pending = 0;
            $queueStatus = 'idle';
            $error = null;

            try {
                $pending = (int) Redis::connection()->llen($definition['key']);
                if ($pending > 0) {
                    $queueStatus = 'processing';
                }
            } catch (\Throwable $exception) {
                $error = $exception->getMessage();
                $queueStatus = 'error';
                $snapshot['overall_status'] = 'warning';
            }

            $workerCount = $this->countWorkers($parsedSupervisor['processes'] ?? [], $definition['worker_filter']);

            if ($pending > 0 && $workerCount === 0) {
                $queueStatus = 'stalled';
                $snapshot['overall_status'] = 'critical';
            }

            $snapshot['queues'][] = [
                'name' => $definition['name'],
                'key' => $definition['key'],
                'pending' => $pending,
                'workers' => $workerCount,
                'status' => $queueStatus,
                'error' => $error,
            ];

            $snapshot['summary']['queue_pending'] += $pending;
        }

        $snapshot['summary']['total_processes'] = count($snapshot['processes']);
        $snapshot['summary']['running_processes'] = count(array_filter(
            $snapshot['processes'],
            static fn (array $process): bool => $process['status'] === 'RUNNING'
        ));
        $snapshot['summary']['stopped_processes'] = count(array_filter(
            $snapshot['processes'],
            static fn (array $process): bool => $process['status'] !== 'RUNNING' && $process['status'] !== 'FATAL'
        ));
        $snapshot['summary']['fatal_processes'] = count(array_filter(
            $snapshot['processes'],
            static fn (array $process): bool => $process['status'] === 'FATAL'
        ));

        if ($snapshot['summary']['fatal_processes'] > 0) {
            $snapshot['overall_status'] = 'critical';
        } elseif ($snapshot['summary']['stopped_processes'] > 0 && $snapshot['overall_status'] === 'healthy') {
            $snapshot['overall_status'] = 'warning';
        }

        try {
            $snapshot['activity']['last_bar_ts_est'] = DB::table('one_minute_prices')->max('ts_est');
        } catch (\Throwable $exception) {
            $snapshot['notes'][] = 'Unable to read latest bar timestamp: '.$exception->getMessage();
            $snapshot['overall_status'] = 'warning';
        }

        try {
            $snapshot['activity']['last_alert_ts_est'] = DB::table('trade_alerts')->max('signal_ts_est');
        } catch (\Throwable $exception) {
            $snapshot['notes'][] = 'Unable to read latest alert timestamp: '.$exception->getMessage();
            $snapshot['overall_status'] = 'warning';
        }

        Cache::put(self::CACHE_KEY, $snapshot, now()->addMinutes(15));

        Log::channel('scheduled')->info('[WorkerHeartbeatWatchdog] Snapshot captured', [
            'overall_status' => $snapshot['overall_status'],
            'running_processes' => $snapshot['summary']['running_processes'],
            'fatal_processes' => $snapshot['summary']['fatal_processes'],
            'queue_pending' => $snapshot['summary']['queue_pending'],
        ]);

        $this->info('Worker heartbeat snapshot captured.');

        return self::SUCCESS;
    }

    /**
     * @return array{groups: array<int, array<string, mixed>>, processes: array<int, array<string, mixed>>}
     */
    private function parseSupervisorStatus(string $output): array
    {
        $groups = [];
        $processes = [];

        foreach (preg_split('/\r?\n/', trim($output)) ?: [] as $line) {
            $line = trim($line);

            if ($line === '') {
                continue;
            }

            if (! preg_match('/^(?<name>\S+)\s+(?<status>\S+)\s+(?<details>.*)$/', $line, $matches)) {
                continue;
            }

            $name = $matches['name'];
            $status = strtoupper($matches['status']);
            $details = $matches['details'];
            $groupName = explode(':', $name, 2)[0];
            $pid = null;
            $uptime = null;

            if (preg_match('/pid\s+(?<pid>\d+)/', $details, $pidMatches)) {
                $pid = (int) $pidMatches['pid'];
            }

            if (preg_match('/uptime\s+(?<uptime>.+)$/', $details, $uptimeMatches)) {
                $uptime = trim($uptimeMatches['uptime']);
            }

            $processes[] = [
                'name' => $name,
                'group' => $groupName,
                'status' => $status,
                'pid' => $pid,
                'uptime' => $uptime,
                'details' => $details,
            ];

            if (! isset($groups[$groupName])) {
                $groups[$groupName] = [
                    'name' => $groupName,
                    'total' => 0,
                    'running' => 0,
                    'stopped' => 0,
                    'fatal' => 0,
                    'processes' => [],
                ];
            }

            $groups[$groupName]['total']++;
            $groups[$groupName]['processes'][] = [
                'name' => $name,
                'status' => $status,
                'pid' => $pid,
                'uptime' => $uptime,
            ];

            match ($status) {
                'RUNNING' => $groups[$groupName]['running']++,
                'FATAL' => $groups[$groupName]['fatal']++,
                default => $groups[$groupName]['stopped']++,
            };
        }

        return [
            'groups' => array_values($groups),
            'processes' => $processes,
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $processes
     */
    private function countWorkers(array $processes, string $filter): int
    {
        return count(array_filter(
            $processes,
            static fn (array $process) => str_contains($process['name'], $filter) && $process['status'] === 'RUNNING'
        ));
    }
}
