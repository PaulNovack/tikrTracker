<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class MySqlHealthController extends Controller
{
    public function index(): \Inertia\Response
    {
        $metrics = $this->collectMetrics();

        return Inertia::render('mysql-health', [
            'metrics' => $metrics,
            'lastUpdated' => now()->format('Y-m-d H:i:s T'),
        ]);
    }

    public function api(): \Illuminate\Http\JsonResponse
    {
        $metrics = $this->collectMetrics();

        return response()->json([
            'metrics' => $metrics,
            'lastUpdated' => now()->format('Y-m-d H:i:s T'),
        ]);
    }

    public function killQuery(Request $request): \Illuminate\Http\JsonResponse
    {
        $validated = $request->validate([
            'pid' => ['required', 'integer', 'min:1'],
        ]);

        $pid = (int) $validated['pid'];

        try {
            DB::statement("KILL QUERY {$pid}");

            return response()->json([
                'success' => true,
                'message' => "Query {$pid} killed successfully.",
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to kill query: '.$e->getMessage(),
            ], 500);
        }
    }

    private function collectMetrics(): array
    {
        try {
            // Basic status metrics
            $uptime = $this->getStatusValue('Uptime');
            $threadsConnected = $this->getStatusValue('Threads_connected');
            $threadsRunning = $this->getStatusValue('Threads_running');
            $maxUsedConnections = $this->getStatusValue('Max_used_connections');
            $maxConnections = $this->getVariableValue('max_connections');
            $abortedConnects = $this->getStatusValue('Aborted_connects');
            $slowQueries = $this->getStatusValue('Slow_queries');
            $questions = $this->getStatusValue('Questions');

            // Buffer pool metrics
            $bufferPoolPagesDirty = $this->getStatusValue('Innodb_buffer_pool_pages_dirty');
            $bufferPoolPagesTotal = $this->getStatusValue('Innodb_buffer_pool_pages_total');

            // Calculate derived metrics
            $uptimeHours = round($uptime / 3600, 1);
            $connectionUsagePercent = round(($threadsConnected / $maxConnections) * 100, 1);
            $bufferPoolEfficiency = $bufferPoolPagesTotal > 0
                ? round((($bufferPoolPagesTotal - $bufferPoolPagesDirty) / $bufferPoolPagesTotal) * 100, 1)
                : 0;

            // Database size
            $dbSizeResult = DB::select('
                SELECT ROUND(SUM(data_length + index_length) / 1024 / 1024, 1) AS size_mb 
                FROM information_schema.tables 
                WHERE TABLE_SCHEMA = DATABASE()
            ');
            $dbSizeMB = $dbSizeResult[0]->size_mb ?? 0;

            // Active processes
            $processes = DB::select('SHOW FULL PROCESSLIST');
            $activeProcesses = collect($processes)->filter(function ($process) {
                return $process->Command !== 'Sleep' && $process->User !== 'event_scheduler';
            })->values();

            // Health status calculations
            $overallHealth = $this->calculateOverallHealth([
                'connection_usage' => $connectionUsagePercent,
                'slow_queries' => $slowQueries,
                'aborted_connects' => $abortedConnects,
                'buffer_efficiency' => $bufferPoolEfficiency,
            ]);

            return [
                'system' => [
                    'uptime_seconds' => $uptime,
                    'uptime_hours' => $uptimeHours,
                    'uptime_formatted' => $this->formatUptime($uptime),
                ],
                'connections' => [
                    'current' => $threadsConnected,
                    'running' => $threadsRunning,
                    'max_used' => $maxUsedConnections,
                    'max_allowed' => $maxConnections,
                    'usage_percent' => $connectionUsagePercent,
                    'aborted' => $abortedConnects,
                ],
                'performance' => [
                    'slow_queries' => $slowQueries,
                    'total_queries' => $questions,
                    'buffer_pool_dirty_pages' => $bufferPoolPagesDirty,
                    'buffer_pool_total_pages' => $bufferPoolPagesTotal,
                    'buffer_pool_efficiency' => $bufferPoolEfficiency,
                ],
                'storage' => [
                    'database_size_mb' => $dbSizeMB,
                    'database_size_gb' => round($dbSizeMB / 1024, 2),
                ],
                'processes' => [
                    'total' => count($processes),
                    'active' => count($activeProcesses),
                    'list' => $activeProcesses,
                ],
                'health' => [
                    'overall_status' => $overallHealth['status'],
                    'overall_score' => $overallHealth['score'],
                    'indicators' => $overallHealth['indicators'],
                ],
            ];
        } catch (\Exception $e) {
            return [
                'error' => 'Failed to collect metrics: '.$e->getMessage(),
            ];
        }
    }

    private function getStatusValue(string $variable): int
    {
        $escapedVariable = DB::connection()->getPdo()->quote($variable);
        $result = DB::select("SHOW STATUS LIKE {$escapedVariable}");

        return isset($result[0]) ? (int) $result[0]->Value : 0;
    }

    private function getVariableValue(string $variable): int
    {
        $escapedVariable = DB::connection()->getPdo()->quote($variable);
        $result = DB::select("SHOW VARIABLES LIKE {$escapedVariable}");

        return isset($result[0]) ? (int) $result[0]->Value : 0;
    }

    private function formatUptime(int $seconds): string
    {
        $days = floor($seconds / 86400);
        $hours = floor(($seconds % 86400) / 3600);
        $minutes = floor(($seconds % 3600) / 60);

        $parts = [];
        if ($days > 0) {
            $parts[] = "{$days}d";
        }
        if ($hours > 0) {
            $parts[] = "{$hours}h";
        }
        if ($minutes > 0) {
            $parts[] = "{$minutes}m";
        }

        return empty($parts) ? '< 1m' : implode(' ', $parts);
    }

    private function calculateOverallHealth(array $metrics): array
    {
        $indicators = [];
        $totalScore = 0;
        $maxScore = 0;

        // Connection usage (weight: 2)
        $connectionScore = $metrics['connection_usage'] < 50 ? 10 :
                          ($metrics['connection_usage'] < 75 ? 7 :
                          ($metrics['connection_usage'] < 90 ? 4 : 1));
        $indicators['connections'] = [
            'status' => $connectionScore >= 7 ? 'excellent' : ($connectionScore >= 4 ? 'good' : 'warning'),
            'score' => $connectionScore,
            'message' => "Connection usage: {$metrics['connection_usage']}%",
        ];
        $totalScore += $connectionScore * 2;
        $maxScore += 20;

        // Query performance (weight: 3)
        $queryScore = $metrics['slow_queries'] == 0 ? 10 :
                     ($metrics['slow_queries'] < 10 ? 6 : 2);
        $indicators['performance'] = [
            'status' => $queryScore >= 8 ? 'excellent' : ($queryScore >= 6 ? 'good' : 'warning'),
            'score' => $queryScore,
            'message' => "Slow queries: {$metrics['slow_queries']}",
        ];
        $totalScore += $queryScore * 3;
        $maxScore += 30;

        // Buffer efficiency (weight: 2)
        $bufferScore = $metrics['buffer_efficiency'] >= 95 ? 10 :
                      ($metrics['buffer_efficiency'] >= 85 ? 7 :
                      ($metrics['buffer_efficiency'] >= 75 ? 4 : 1));
        $indicators['buffer'] = [
            'status' => $bufferScore >= 7 ? 'excellent' : ($bufferScore >= 4 ? 'good' : 'warning'),
            'score' => $bufferScore,
            'message' => "Buffer efficiency: {$metrics['buffer_efficiency']}%",
        ];
        $totalScore += $bufferScore * 2;
        $maxScore += 20;

        // Connection stability (weight: 1)
        $stabilityScore = $metrics['aborted_connects'] == 0 ? 10 :
                         ($metrics['aborted_connects'] < 5 ? 6 : 2);
        $indicators['stability'] = [
            'status' => $stabilityScore >= 8 ? 'excellent' : ($stabilityScore >= 6 ? 'good' : 'warning'),
            'score' => $stabilityScore,
            'message' => "Aborted connections: {$metrics['aborted_connects']}",
        ];
        $totalScore += $stabilityScore * 1;
        $maxScore += 10;

        $overallScore = round(($totalScore / $maxScore) * 100);
        $overallStatus = $overallScore >= 85 ? 'excellent' :
                        ($overallScore >= 70 ? 'good' :
                        ($overallScore >= 50 ? 'warning' : 'critical'));

        return [
            'score' => $overallScore,
            'status' => $overallStatus,
            'indicators' => $indicators,
        ];
    }
}
