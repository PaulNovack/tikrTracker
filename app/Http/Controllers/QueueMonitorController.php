<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Redis;
use Inertia\Inertia;
use Inertia\Response;

class QueueMonitorController extends Controller
{
    public function index(): Response
    {
        $queues = [
            [
                'name' => 'default',
                'key' => 'queues:default',
                'count' => $this->queueLen('queues:default'),
                'workers' => $this->countSupervisorWorkers('worker'),
            ],
            [
                'name' => 'backtest',
                'key' => 'queues:backtest',
                'count' => $this->queueLen('queues:backtest'),
                'workers' => $this->countSupervisorWorkers('backtest'),
            ],
            [
                'name' => 'ml-scoring',
                'key' => 'queues:ml-scoring',
                'count' => $this->queueLen('queues:ml-scoring'),
                'workers' => $this->countSupervisorWorkers('ml-scoring'),
            ],
            [
                'name' => 'ml-scoring-catchup',
                'key' => 'queues:ml-scoring-catchup',
                'count' => $this->queueLen('queues:ml-scoring-catchup'),
                'workers' => $this->countSupervisorWorkers('ml-scoring-catchup'),
            ],
        ];

        $supervisorProcesses = $this->getSupervisorProcessList();
        $redisInfo = $this->getRedisInfo();

        return Inertia::render('queue-monitor/index', [
            'queues' => $queues,
            'supervisorProcesses' => $supervisorProcesses,
            'redisInfo' => $redisInfo,
            'lastUpdated' => now('America/New_York')->format('Y-m-d H:i:s T'),
        ]);
    }

    private function queueLen(string $queueKey): int
    {
        try {
            return (int) Redis::connection()->llen($queueKey);
        } catch (\Throwable) {
            return 0;
        }
    }

    private function countSupervisorWorkers(string $programFilter): int
    {
        try {
            $result = Process::run('supervisorctl status 2>/dev/null');
            if (! $result->successful()) {
                return 0;
            }

            $lines = explode("\n", $result->output());

            return count(array_filter($lines, function (string $line) use ($programFilter): bool {
                return str_contains($line, $programFilter) && str_contains($line, 'RUNNING');
            }));
        } catch (\Throwable) {
            return 0;
        }
    }

    private function getSupervisorProcessList(): array
    {
        try {
            $result = Process::run('supervisorctl status 2>/dev/null');
            if (! $result->successful()) {
                return [];
            }

            $lines = array_filter(explode("\n", $result->output()));
            $processes = [];

            foreach ($lines as $line) {
                $line = trim($line);
                if ($line === '') {
                    continue;
                }

                $parts = preg_split('/\s+/', $line, 3);
                $name = $parts[0] ?? '';
                $status = $parts[1] ?? '';

                $processes[] = [
                    'name' => $name,
                    'status' => $status,
                    'details' => $parts[2] ?? '',
                ];
            }

            // Group by program name (strip _NN suffix)
            $grouped = [];
            foreach ($processes as $p) {
                $base = preg_replace('/_\d+$/', '', $p['name']);
                if (! isset($grouped[$base])) {
                    $grouped[$base] = [
                        'name' => $base,
                        'total' => 0,
                        'running' => 0,
                        'stopped' => 0,
                        'fatal' => 0,
                    ];
                }

                $grouped[$base]['total']++;
                match (strtoupper($p['status'])) {
                    'RUNNING' => $grouped[$base]['running']++,
                    'FATAL' => $grouped[$base]['fatal']++,
                    default => $grouped[$base]['stopped']++,
                };
            }

            return array_values($grouped);
        } catch (\Throwable) {
            return [];
        }
    }

    private function getRedisInfo(): array
    {
        try {
            $info = Redis::connection()->info();

            return [
                'used_memory' => $info['used_memory_human'] ?? 'N/A',
                'connected_clients' => (int) ($info['connected_clients'] ?? 0),
                'uptime_days' => (int) (($info['uptime_in_seconds'] ?? 0) / 86400),
                'keyspace_hits' => (int) ($info['keyspace_hits'] ?? 0),
                'keyspace_misses' => (int) ($info['keyspace_misses'] ?? 0),
                'total_commands_processed' => number_format((int) ($info['total_commands_processed'] ?? 0)),
                'instantaneous_ops_per_sec' => (int) ($info['instantaneous_ops_per_sec'] ?? 0),
            ];
        } catch (\Throwable) {
            return [];
        }
    }
}
