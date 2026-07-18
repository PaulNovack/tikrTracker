<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Process;
use Inertia\Inertia;
use Inertia\Response;

class ProcessMonitorController extends Controller
{
    public function index(): Response
    {
        $processes = [
            'scheduled_commands' => $this->getScheduledCommandStatus(),
            'python_processes' => $this->getPythonProcesses(),
            'supervisor_processes' => $this->getSupervisorProcesses(),
            'cache_locks' => $this->getCacheLocks(),
            'last_updated' => now()->toISOString(),
        ];

        return Inertia::render('ProcessesRunning/index', [
            'processes' => $processes,
        ]);
    }

    public function killProcess(Request $request)
    {
        $request->validate([
            'pid' => 'required|integer',
        ]);

        $pid = $request->input('pid');

        try {
            // Check if process exists first
            $checkResult = Process::run("ps -p {$pid}");
            if (! $checkResult->successful()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Process not found or already terminated',
                ], 404);
            }

            // Try graceful termination first (SIGTERM)
            $result = Process::run("kill {$pid}");

            if ($result->successful()) {
                // Wait a moment and check if process is still running
                sleep(1);
                $checkResult = Process::run("ps -p {$pid}");

                if (! $checkResult->successful()) {
                    return response()->json([
                        'success' => true,
                        'message' => 'Process terminated successfully',
                    ]);
                }

                // If still running, force kill (SIGKILL)
                $forceResult = Process::run("kill -9 {$pid}");

                return response()->json([
                    'success' => $forceResult->successful(),
                    'message' => $forceResult->successful()
                        ? 'Process forcefully terminated'
                        : 'Failed to terminate process',
                ]);
            }

            return response()->json([
                'success' => false,
                'message' => 'Failed to send termination signal',
            ], 500);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error terminating process: '.$e->getMessage(),
            ], 500);
        }
    }

    private function getScheduledCommandStatus(): array
    {
        $commands = [];

        // Check for Laravel schedule locks (withoutOverlapping commands)
        $scheduleLocks = $this->getScheduleLocks();

        // Define our known scheduled commands
        $knownCommands = [
            // Market Data Sync
            'alpaca:sync-1m' => 'Alpaca 1-Minute Data Sync',
            'alpaca:sync-5m' => 'Alpaca 5-Minute Data Sync',

            // Indicators & Calculations
            'indicators:calculate-5m' => '5-Minute Indicator Calculation',
            'indicators:calculate-hourly' => 'Hourly Indicator Calculation',
            'indicators:calculate-daily' => 'Daily Indicator Calculation',

            // Trading Pipelines (live cron)
            'trade:pipeline-b' => 'Pipeline B (Live Cron)',
            'trade:pipeline-d' => 'Pipeline D (Live Cron)',
            'trade:pipeline-e' => 'Pipeline E (Live Cron)',
            'trade:pipeline-h' => 'Pipeline H (Live Cron)',
            'trade:pipeline-i' => 'Pipeline I (Live Cron)',
            'trade:pipeline-k' => 'Pipeline K (Live Cron)',
            'trade:pipeline-l' => 'Pipeline L (Live Cron)',
            'trade:pipeline-n' => 'Pipeline N (Live Cron)',
            'trade:pipeline-o' => 'Pipeline O (Live Cron)',

            // Cache Management
            'market:warm-page-caches' => 'Page Cache Warming',
            'cache:trading-day-prices' => 'Trading Day Price Caching',
            'cache:warm-assets' => 'Asset Cache Warming',

            // Market Hours
            'market-hours:sync-and-cache' => 'Market Hours Sync & Cache',

            // Legacy (disabled but keeping for reference)
            'market:yfinance-continuous-sync' => '5-Minute Market Data Sync (Legacy)',
            'market:yfinance-stocks-1min-watched' => '1-Minute Watched Stocks Sync (Legacy)',
        ];

        foreach ($knownCommands as $signature => $description) {
            $isLocked = $this->isCommandLocked($signature, $scheduleLocks);

            $commands[] = [
                'name' => $description,
                'signature' => $signature,
                'status' => $isLocked ? 'running' : 'idle',
                'locked_since' => $isLocked ? $this->getLockTime($signature, $scheduleLocks) : null,
                'type' => 'laravel_scheduled',
            ];
        }

        return $commands;
    }

    private function getPythonProcesses(): array
    {
        $processes = [];

        try {
            // Get actual Python processes first
            $result = Process::timeout(10)->run('ps aux | grep -E "python.*\.py" | grep -v grep');

            if ($result->successful()) {
                $lines = array_filter(explode("\n", $result->output()));

                foreach ($lines as $line) {
                    $parts = preg_split('/\s+/', trim($line), 11);
                    if (count($parts) >= 11) {
                        $pid = $parts[1];
                        $cpu = $parts[2];
                        $memory = $parts[3];
                        $startTime = $parts[8];
                        $command = $parts[10];

                        // Extract script name and description
                        $scriptInfo = $this->identifyPythonScript($command);

                        // Skip VS Code Python Extension processes
                        if ($scriptInfo['name'] === 'VS Code Python Extension') {
                            continue;
                        }

                        $processes[] = [
                            'pid' => $pid,
                            'name' => $scriptInfo['name'],
                            'description' => $scriptInfo['description'],
                            'command' => $command,
                            'cpu_usage' => $cpu.'%',
                            'memory_usage' => $memory.'%',
                            'start_time' => $startTime,
                            'status' => 'running',
                            'type' => 'python_process',
                            'runtime' => $this->calculateRuntime($startTime),
                        ];
                    }
                }
            }

            // Get Laravel Artisan processes related to market data
            $artisanResult = Process::timeout(10)->run('ps aux | grep -E "(artisan.*market|artisan.*alpaca|yfinance|artisan.*cache|artisan.*pipelines|artisan.*indicators)" | grep -v grep');

            if ($artisanResult->successful()) {
                $lines = array_filter(explode("\n", $artisanResult->output()));

                foreach ($lines as $line) {
                    $parts = preg_split('/\s+/', trim($line), 11);
                    if (count($parts) >= 11) {
                        $pid = $parts[1];
                        $cpu = $parts[2];
                        $memory = $parts[3];
                        $startTime = $parts[8];
                        $command = $parts[10];

                        // Only include if it's actually a Laravel command
                        if (strpos($command, 'artisan') !== false) {
                            $artisanInfo = $this->identifyArtisanCommand($command);

                            $processes[] = [
                                'pid' => $pid,
                                'name' => $artisanInfo['name'],
                                'description' => $artisanInfo['description'],
                                'command' => $command,
                                'cpu_usage' => $cpu.'%',
                                'memory_usage' => $memory.'%',
                                'start_time' => $startTime,
                                'status' => 'running',
                                'type' => 'artisan_command',
                                'runtime' => $this->calculateRuntime($startTime),
                            ];
                        }
                    }
                }
            }
        } catch (\Exception $e) {
            // If we can't get process info, return empty array
            $processes[] = [
                'name' => 'Process Monitor Error',
                'status' => 'error',
                'error' => $e->getMessage(),
                'type' => 'error',
            ];
        }

        return $processes;
    }

    private function getSupervisorProcesses(): array
    {
        $processes = [];

        try {
            $result = Process::timeout(10)->run('supervisorctl status');

            // Exit code 0 = all running, 3 = some stopped — both return valid output
            // If permission denied (session doesn't have supervisor group yet), try sg
            $output = $result->output();
            if (str_contains($output.$result->errorOutput(), 'PermissionError') || str_contains($output.$result->errorOutput(), 'Permission denied')) {
                $result = Process::timeout(10)->run('sg supervisor -c "supervisorctl status"');
                $output = $result->output();
            }

            if (empty(trim($output))) {
                return [[
                    'name' => 'Supervisor',
                    'status' => 'error',
                    'pid' => null,
                    'uptime' => null,
                    'details' => 'No output from supervisorctl — is supervisor running?',
                    'description' => 'Supervisor process manager',
                ]];
            }

            $lines = array_filter(explode("\n", trim($output)));

            foreach ($lines as $line) {
                if (! preg_match('/^(\S+)\s+(\S+)\s*(.*)$/', trim($line), $matches)) {
                    continue;
                }

                $name = $matches[1];
                $status = strtolower($matches[2]);
                $details = trim($matches[3]);

                $pid = null;
                $uptime = null;

                if (preg_match('/pid (\d+), uptime ([\d:]+)/', $details, $detailMatches)) {
                    $pid = $detailMatches[1];
                    $uptime = $detailMatches[2];
                }

                $processes[] = [
                    'name' => $name,
                    'status' => $status,
                    'pid' => $pid,
                    'uptime' => $uptime,
                    'details' => $details,
                    'description' => $this->describeSupervisorProgram($name),
                ];
            }
        } catch (\Exception $e) {
            $processes[] = [
                'name' => 'Supervisor Error',
                'status' => 'error',
                'pid' => null,
                'uptime' => null,
                'details' => $e->getMessage(),
                'description' => 'Failed to query supervisorctl',
            ];
        }

        return $processes;
    }

    private function describeSupervisorProgram(string $name): string
    {
        if (str_contains($name, 'worker')) {
            return 'Laravel queue worker (database driver)';
        }

        if (str_contains($name, 'reverb')) {
            return 'Laravel Reverb WebSocket server';
        }

        if (preg_match('/backtest-([a-z])/i', $name, $m)) {
            $letter = strtoupper($m[1]);

            return "Pipeline {$letter} continuous backtest loop (rolling -15min to +12min window)";
        }

        return 'Supervisor managed process';
    }

    private function getCacheLocks(): array
    {
        $locks = [];

        try {
            // 1. Check for custom application lock files in storage/app/
            $appLockFiles = glob(storage_path('app/*.lock')) ?: [];

            foreach ($appLockFiles as $file) {
                $key = basename($file);
                $mtime = filemtime($file);
                $age = time() - $mtime;
                $size = filesize($file);

                // Try to read content safely
                $content = '';
                if ($size > 0 && $size < 1024) { // Only read small files
                    $content = file_get_contents($file);
                }

                $locks[] = [
                    'key' => $key,
                    'ttl' => null,
                    'expires_in' => 'Active for: '.$this->formatDuration($age),
                    'type' => 'app_lock',
                    'size' => $size,
                    'content' => $content ?: 'Binary/Large file',
                    'path' => $file,
                ];
            }

            // 2. Check what cache driver we're using for framework locks
            $cacheDriver = config('cache.default');
            $store = Cache::getStore();

            if ($cacheDriver === 'redis' && method_exists($store, 'getRedis')) {
                // Redis cache - get all lock keys
                $cacheKeys = $store->getRedis()->keys('*lock*') ?? [];

                foreach ($cacheKeys as $key) {
                    $ttl = $store->getRedis()->ttl($key);
                    $value = Cache::get($key);

                    $locks[] = [
                        'key' => $key,
                        'ttl' => $ttl,
                        'expires_in' => $ttl > 0 ? $ttl.' seconds' : 'Never',
                        'type' => 'redis_lock',
                    ];
                }
            } elseif ($cacheDriver === 'file') {
                // File cache - check for schedule lock files
                $cachePath = storage_path('framework/cache/data');
                $lockFiles = glob($cachePath.'/*schedule*') ?: [];

                foreach ($lockFiles as $file) {
                    $key = basename($file);
                    $mtime = filemtime($file);
                    $age = time() - $mtime;

                    $locks[] = [
                        'key' => $key,
                        'ttl' => null,
                        'expires_in' => 'File age: '.$this->formatDuration($age),
                        'type' => 'framework_lock',
                    ];
                }
            }

            // 3. Add info if no locks found
            if (empty($locks)) {
                $locks[] = [
                    'key' => 'No active locks detected',
                    'status' => 'idle',
                    'expires_in' => 'All scheduled commands are idle',
                    'type' => 'cache_info',
                ];
            }

        } catch (\Exception $e) {
            $locks[] = [
                'key' => 'Lock Detection Error',
                'status' => 'error',
                'expires_in' => $e->getMessage(),
                'type' => 'cache_info',
            ];
        }

        return $locks;
    }

    private function getScheduleLocks(): array
    {
        // Try to get schedule locks from cache
        try {
            $cacheDriver = config('cache.default');
            $store = Cache::getStore();

            if ($cacheDriver === 'redis' && method_exists($store, 'getRedis')) {
                // Redis cache
                $prefix = 'framework/schedule-';
                $keys = $store->getRedis()->keys($prefix.'*');

                return array_map(function ($key) use ($store) {
                    return [
                        'key' => $key,
                        'value' => $store->getRedis()->get($key),
                        'ttl' => $store->getRedis()->ttl($key),
                    ];
                }, $keys);
            } elseif ($cacheDriver === 'file') {
                // File cache - check for framework schedule files
                $cachePath = storage_path('framework/cache/data');
                $lockFiles = glob($cachePath.'/framework*schedule*') ?: [];

                return array_map(function ($file) {
                    return [
                        'key' => basename($file),
                        'value' => file_get_contents($file),
                        'ttl' => time() - filemtime($file), // Age instead of TTL
                    ];
                }, $lockFiles);
            }

            // For other cache drivers, we can't easily inspect locks
            return [];
        } catch (\Exception $e) {
            return [];
        }
    }

    private function isCommandLocked(string $signature, array $locks): bool
    {
        foreach ($locks as $lock) {
            if (str_contains($lock['key'], md5($signature)) || str_contains($lock['key'], $signature)) {
                return true;
            }
        }

        return false;
    }

    private function getLockTime(string $signature, array $locks): ?string
    {
        foreach ($locks as $lock) {
            if (str_contains($lock['key'], md5($signature)) || str_contains($lock['key'], $signature)) {
                // Calculate approximate lock time based on TTL
                $approximateLockTime = now()->subSeconds($lock['ttl'] ?? 0);

                return $approximateLockTime->toISOString();
            }
        }

        return null;
    }

    private function identifyPythonScript(string $command): array
    {
        // Check for VS Code Python extension first
        if (strpos($command, 'ms-python') !== false) {
            return [
                'name' => 'VS Code Python Extension',
                'description' => 'VS Code Python Language Server',
            ];
        }

        // Extract script name from command
        if (preg_match('/([^\/\s]+\.py)/', $command, $matches)) {
            $scriptName = $matches[1];

            // Map script names to descriptions
            $scriptDescriptions = [
                'yfinance_stocks_5min.py' => 'Stock Market 5-min Data Fetcher',
                'yfinance_stocks_hourly.py' => 'Stock Market Hourly Data Fetcher',
                'yfinance_stocks_daily.py' => 'Stock Market Daily Data Fetcher',
                'yfinance_crypto_5min.py' => 'Cryptocurrency 5-min Data Fetcher',
                'yfinance_crypto_hourly.py' => 'Cryptocurrency Hourly Data Fetcher',
                'yfinance_crypto_daily.py' => 'Cryptocurrency Daily Data Fetcher',
            ];

            return [
                'name' => $scriptName,
                'description' => $scriptDescriptions[$scriptName] ?? 'Custom Python Script',
            ];
        }

        return [
            'name' => 'Python Process',
            'description' => 'Unknown Python Process',
        ];
    }

    private function identifyArtisanCommand(string $command): array
    {
        // Alpaca Data Syncs
        if (strpos($command, 'alpaca:sync-1m') !== false) {
            return [
                'name' => 'Alpaca 1min Sync',
                'description' => 'Alpaca 1-minute bar data synchronization',
            ];
        }

        if (strpos($command, 'alpaca:sync-5m') !== false) {
            return [
                'name' => 'Alpaca 5min Sync',
                'description' => 'Alpaca 5-minute bar data synchronization',
            ];
        }

        // Indicator Calculations
        if (strpos($command, 'indicators:calculate-5m') !== false) {
            return [
                'name' => '5min Indicators',
                'description' => '5-minute technical indicator calculation',
            ];
        }

        if (strpos($command, 'indicators:calculate-hourly') !== false) {
            return [
                'name' => 'Hourly Indicators',
                'description' => 'Hourly technical indicator calculation',
            ];
        }

        if (strpos($command, 'indicators:calculate-daily') !== false) {
            return [
                'name' => 'Daily Indicators',
                'description' => 'Daily technical indicator calculation',
            ];
        }

        // Trading Pipelines (legacy pipelines: commands)
        if (strpos($command, 'pipelines:a') !== false) {
            return [
                'name' => 'Pipeline A',
                'description' => 'v60.2 Hybrid Scanner trading pipeline',
            ];
        }

        if (strpos($command, 'pipelines:c') !== false) {
            return [
                'name' => 'Pipeline C',
                'description' => 'Breakout Scanner trading pipeline',
            ];
        }

        // Trading Pipelines (trade:pipeline-* commands)
        if (preg_match('/trade:pipeline-([a-z])/i', $command, $m)) {
            $letter = strtoupper($m[1]);
            $isBacktest = str_contains($command, '--backtest');
            $mode = $isBacktest ? ' (Backtest)' : ' (Live)';

            return [
                'name' => "Pipeline {$letter}{$mode}",
                'description' => "Pipeline {$letter} trading algorithm{$mode}",
            ];
        }

        // Market Hours
        if (strpos($command, 'market-hours:sync-and-cache') !== false) {
            return [
                'name' => 'Market Hours Sync',
                'description' => 'Market hours synchronization and caching',
            ];
        }

        // Legacy yfinance commands
        if (strpos($command, 'market:yfinance-continuous-sync') !== false) {
            return [
                'name' => 'Market Data Sync',
                'description' => 'Continuous market data synchronization coordinator',
            ];
        }

        if (strpos($command, 'market:yfinance-stocks-5min-batch') !== false) {
            preg_match('/--offset=(\d+)/', $command, $matches);
            $offset = $matches[1] ?? '0';

            return [
                'name' => 'Stock 5min Batch',
                'description' => "5-minute stock data batch processor (offset: {$offset})",
            ];
        }

        if (strpos($command, 'market:yfinance-stocks-hourly') !== false) {
            return [
                'name' => 'Stock Hourly Sync',
                'description' => 'Hourly stock market data synchronization',
            ];
        }

        if (strpos($command, 'market:yfinance-stocks-daily') !== false) {
            return [
                'name' => 'Stock Daily Sync',
                'description' => 'Daily stock market data synchronization',
            ];
        }

        if (strpos($command, 'market:yfinance-crypto') !== false) {
            return [
                'name' => 'Crypto Data Sync',
                'description' => 'Cryptocurrency market data synchronization',
            ];
        }

        if (strpos($command, 'cache:warm-assets') !== false) {
            return [
                'name' => 'Asset Cache Warming',
                'description' => 'Background asset page cache warming process',
            ];
        }

        if (strpos($command, 'schedule:run') !== false) {
            return [
                'name' => 'Laravel Scheduler',
                'description' => 'Laravel task scheduler daemon',
            ];
        }

        if (strpos($command, 'queue:work') !== false) {
            return [
                'name' => 'Queue Worker',
                'description' => 'Laravel job queue processor',
            ];
        }

        // Extract basic artisan command name
        preg_match('/artisan\s+([^\s]+)/', $command, $matches);
        $commandName = $matches[1] ?? 'artisan';

        return [
            'name' => 'Laravel Command',
            'description' => "Laravel artisan command: {$commandName}",
        ];
    }

    private function formatDuration(int $seconds): string
    {
        if ($seconds < 60) {
            return $seconds.'s';
        } elseif ($seconds < 3600) {
            return floor($seconds / 60).'m '.($seconds % 60).'s';
        } else {
            $hours = floor($seconds / 3600);
            $minutes = floor(($seconds % 3600) / 60);

            return $hours.'h '.$minutes.'m';
        }
    }

    private function calculateRuntime(string $startTime): string
    {
        try {
            // Parse different time formats that ps might return
            if (preg_match('/(\d{2}):(\d{2})/', $startTime, $matches)) {
                // Format: HH:MM (today)
                $hour = (int) $matches[1];
                $minute = (int) $matches[2];

                // Get system timezone from system date command
                $systemTimezone = trim(shell_exec('date +%Z')) ?: 'America/Chicago';

                // Create DateTime objects in system timezone (same as ps command)
                $currentTime = new \DateTime('now', new \DateTimeZone($systemTimezone));
                $startDateTime = new \DateTime('today', new \DateTimeZone($systemTimezone));
                $startDateTime->setTime($hour, $minute, 0);

                // If start time is in the future, it must be from yesterday
                if ($startDateTime > $currentTime) {
                    $startDateTime->modify('-1 day');
                }

                $interval = $currentTime->diff($startDateTime);

                if ($interval->d > 0) {
                    return $interval->d.'d '.$interval->h.'h '.$interval->i.'m';
                } elseif ($interval->h > 0) {
                    return $interval->h.'h '.$interval->i.'m';
                } else {
                    return $interval->i.'m '.$interval->s.'s';
                }
            } else {
                // Other formats (like Dec02, etc.) - fallback
                return 'Unknown format';
            }
        } catch (\Exception $e) {
            return 'Error: '.$e->getMessage();
        }
    }
}
