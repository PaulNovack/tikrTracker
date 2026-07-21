<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class MarketYfinance5minContinuousSync extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'market:yfinance-5min-continuous-sync 
                            {--batch-size=50 : Symbols per API request}
                            {--parallel-jobs=3 : Number of parallel batch jobs}
                            {--stagger-delay=2 : Seconds to stagger job starts (rate limiting)}
                            {--hours=0.5 : Hours of data to fetch (supports fractional like 0.5 for 30 minutes)}
                            {--max-symbols= : Maximum symbols to process (for testing)}
                            {--conservative : Use conservative rate limiting (slower but safer)}
                            {--detailed : Show detailed real-time output from subprocesses}
                            {--no-cache-warming : Skip cache warming after sync (faster for frequent runs)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Keep ALL symbols 5-minute data up-to-date using rate-limited parallel batch processing';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        // Check if another instance is already running
        $lockFile = storage_path('app/yfinance-5min-continuous-sync.lock');

        if (file_exists($lockFile)) {
            $pid = trim(file_get_contents($lockFile));

            // Check if the process is actually running
            if ($this->isProcessRunning($pid)) {
                $this->error('❌ Another instance of yfinance-5min-continuous-sync is already running (PID: '.$pid.')');
                $this->error('   If you believe this is incorrect, delete: '.$lockFile);

                Log::channel('scheduled')->warning('[YFinance 5-Minute Continuous Sync] Another instance already running', [
                    'running_pid' => $pid,
                    'lock_file' => $lockFile,
                    'attempted_at' => now()->toISOString(),
                ]);

                return 1;
            } else {
                // Lock file exists but process is not running, remove stale lock
                unlink($lockFile);
                $this->info('🧹 Removed stale lock file');
            }
        }

        // Create lock file with current process PID
        file_put_contents($lockFile, getmypid());

        // Track child processes for cleanup
        $childProcesses = [];

        // Register signal handlers for graceful shutdown
        if (function_exists('pcntl_signal')) {
            pcntl_signal(SIGTERM, function () use (&$childProcesses, $lockFile) {
                $this->info('🛑 Received SIGTERM, cleaning up child processes...');
                $this->cleanupChildProcesses($childProcesses);
                if (file_exists($lockFile)) {
                    unlink($lockFile);
                }
                exit(0);
            });

            pcntl_signal(SIGINT, function () use (&$childProcesses, $lockFile) {
                $this->info('🛑 Received SIGINT, cleaning up child processes...');
                $this->cleanupChildProcesses($childProcesses);
                if (file_exists($lockFile)) {
                    unlink($lockFile);
                }
                exit(0);
            });
        }

        // Register cleanup function to remove lock file on exit
        register_shutdown_function(function () use ($lockFile, &$childProcesses) {
            $this->cleanupChildProcesses($childProcesses);
            if (file_exists($lockFile)) {
                unlink($lockFile);
            }
        });

        // Remove execution time limit for long-running sync operations
        set_time_limit(0);
        ini_set('memory_limit', '1G');

        $batchSize = (int) $this->option('batch-size');
        $parallelJobs = (int) $this->option('parallel-jobs');
        $staggerDelay = (int) $this->option('stagger-delay');
        $hours = (float) $this->option('hours');
        $maxSymbols = $this->option('max-symbols') ? (int) $this->option('max-symbols') : null;
        $conservative = $this->option('conservative');
        $verbose = $this->option('detailed');

        // Apply conservative settings if requested
        if ($conservative) {
            $parallelJobs = min($parallelJobs, 2);
            $staggerDelay = max($staggerDelay, 3);
            $this->info("🛡️  Conservative mode: reduced to {$parallelJobs} jobs with {$staggerDelay}s stagger");
        }

        $this->info('🚀 Starting rate-limited 5-minute continuous sync for ALL symbols');
        $this->info("   Batch size: {$batchSize} symbols per API request");
        $this->info("   Parallel jobs: {$parallelJobs}");
        $this->info("   Stagger delay: {$staggerDelay}s between job starts");
        $this->info("   Hours: {$hours}");

        // Get total symbol count
        $totalSymbols = $this->countTargetSymbols();

        if ($maxSymbols) {
            $totalSymbols = min($totalSymbols, $maxSymbols);
            $this->info("   Limited to: {$totalSymbols} symbols (testing)");
        }

        Log::channel('scheduled')->info('[YFinance 5-Minute Continuous Sync] Starting sync process', [
            'pid' => getmypid(),
            'total_symbols' => $totalSymbols,
            'batch_size' => $batchSize,
            'parallel_jobs' => $parallelJobs,
            'hours' => $hours,
            'conservative_mode' => $conservative,
            'max_symbols_limit' => $maxSymbols,
            'started_at' => now()->toISOString(),
        ]);

        // Calculate batches needed
        $totalBatches = (int) ceil($totalSymbols / $batchSize);
        $batchesPerJob = (int) ceil($totalBatches / $parallelJobs);

        $this->info("   Total symbols: {$totalSymbols}");
        $this->info("   Total batches: {$totalBatches}");
        $this->info("   Batches per parallel job: {$batchesPerJob}");

        $startTime = microtime(true);
        $processes = [];
        $launchFailures = 0;

        // Launch parallel batch jobs with staggered starts for rate limiting
        for ($jobId = 0; $jobId < $parallelJobs; $jobId++) {
            $startOffset = $jobId * $batchesPerJob * $batchSize;
            $jobSymbolLimit = min($batchesPerJob * $batchSize, $totalSymbols - $startOffset);

            if ($jobSymbolLimit <= 0) {
                break; // No more symbols to process
            }

            // Stagger job starts to avoid rate limits
            if ($jobId > 0 && $staggerDelay > 0) {
                $this->info("⏱️  Staggering job {$jobId} start by {$staggerDelay}s...");
                sleep($staggerDelay);
            }

            $this->info("📦 Launching job {$jobId}: symbols {$startOffset}-".($startOffset + $jobSymbolLimit - 1));

            // Create the command for this parallel job
            $command = sprintf(
                'php artisan market:yfinance-stocks-5min-batch %s --batch-size=%d --limit=%d --offset=%d',
                $hours,
                $batchSize,
                $jobSymbolLimit,
                $startOffset
            );

            // Launch background process
            $process = proc_open(
                $command,
                [
                    0 => ['pipe', 'r'], // stdin
                    1 => ['pipe', 'w'], // stdout
                    2 => ['pipe', 'w'],  // stderr
                ],
                $pipes,
                base_path()
            );

            if (is_resource($process)) {
                $processes[$jobId] = [
                    'process' => $process,
                    'pipes' => $pipes,
                    'command' => $command,
                    'symbols' => $jobSymbolLimit,
                    'offset' => $startOffset,
                    'started_at' => microtime(true),
                ];

                // Track for cleanup
                $childProcesses[$jobId] = $process;
            } else {
                $launchFailures++;
                $errorMsg = "Failed to launch job {$jobId} with command: {$command}";
                $this->error("❌ {$errorMsg}");
                Log::channel('scheduled')->error('[YFinance 5-Minute Continuous Sync] Process launch failed', [
                    'job_id' => $jobId,
                    'command' => $command,
                    'symbols_limit' => $jobSymbolLimit,
                    'offset' => $startOffset,
                ]);
            }
        }

        $this->info('⏳ Waiting for '.count($processes).' parallel jobs to complete...');

        // Monitor and wait for completion with appropriate timeouts for large datasets
        $completedJobs = 0;
        $failedJobs = 0;
        $totalUpdates = 0;
        $lastVerboseOutput = time();
        $maxExecutionTime = 1800; // 30 minutes maximum (restored for large datasets)
        $processStartTime = microtime(true);
        $lastProgressCheck = time();

        while ($completedJobs < count($processes)) {
            // Check for timeout
            if ((microtime(true) - $processStartTime) > $maxExecutionTime) {
                $this->error("⏰ Maximum execution time ({$maxExecutionTime}s) reached, terminating remaining processes...");
                $this->cleanupChildProcesses($childProcesses);
                break;
            }

            // Handle pending signals
            if (function_exists('pcntl_signal_dispatch')) {
                pcntl_signal_dispatch();
            }

            // Use non-blocking approach to check multiple processes efficiently
            $readStreams = [];
            $writeStreams = [];
            $errorStreams = [];

            // Build arrays of streams to monitor
            foreach ($processes as $jobId => $processInfo) {
                if (isset($processInfo['completed'])) {
                    continue;
                }

                // Add stdout and stderr pipes to monitoring
                if (is_resource($processInfo['pipes'][1])) {
                    $readStreams[$jobId.'_out'] = $processInfo['pipes'][1];
                }
                if (is_resource($processInfo['pipes'][2])) {
                    $readStreams[$jobId.'_err'] = $processInfo['pipes'][2];
                }
            }

            // Non-blocking select to check for ready streams (with timeout)
            if (! empty($readStreams)) {
                $ready = stream_select($readStreams, $writeStreams, $errorStreams, 1); // 1 second timeout

                if ($ready === false) {
                    $this->warn('⚠️  stream_select failed, falling back to individual checks');
                }
            }

            foreach ($processes as $jobId => $processInfo) {
                if (isset($processInfo['completed'])) {
                    continue; // Already processed
                }

                // Check if process is still running with better error handling
                $status = @proc_get_status($processInfo['process']);

                // Handle cases where status might be unreliable
                if (! is_array($status)) {
                    $this->warn("⚠️  Unable to get status for job {$jobId}, checking alternative methods...");

                    // Try to read from pipes to see if process is responsive
                    $stillAlive = false;
                    if (is_resource($processInfo['pipes'][1])) {
                        stream_set_blocking($processInfo['pipes'][1], false);
                        $output = fread($processInfo['pipes'][1], 1);
                        if ($output !== false) {
                            $stillAlive = true;
                        }
                        stream_set_blocking($processInfo['pipes'][1], true);
                    }

                    if (! $stillAlive) {
                        $this->info("🔍 Job {$jobId} appears terminated, marking as completed");
                        $processes[$jobId]['completed'] = true;
                        $completedJobs++;
                        $this->closeProcessResources($processInfo);
                        unset($childProcesses[$jobId]);

                        continue;
                    }
                }

                // Check for process timeout (individual job timeout) - increased for large datasets
                $jobRuntime = microtime(true) - $processInfo['started_at'];
                if ($jobRuntime > 1200) { // 20 minutes per job max (increased from 10)
                    $this->error("⏰ Job {$jobId} exceeded maximum runtime (20 minutes), terminating...");

                    // Terminate the specific process more aggressively
                    if (is_resource($processInfo['process'])) {
                        proc_terminate($processInfo['process'], SIGTERM);
                        sleep(1); // Reduced wait time
                        proc_terminate($processInfo['process'], SIGKILL);
                        sleep(1);
                        @proc_close($processInfo['process']); // Force close
                    }

                    $this->closeProcessResources($processInfo);
                    $processes[$jobId]['completed'] = true;
                    $completedJobs++;
                    $failedJobs++;
                    unset($childProcesses[$jobId]);

                    continue;
                }

                // Show real-time output in verbose mode
                if ($verbose && time() - $lastVerboseOutput >= 5) {
                    // Non-blocking read of current output
                    if (is_resource($processInfo['pipes'][1]) && is_resource($processInfo['pipes'][2])) {
                        stream_set_blocking($processInfo['pipes'][1], false);
                        stream_set_blocking($processInfo['pipes'][2], false);

                        $currentOutput = stream_get_contents($processInfo['pipes'][1]);
                        $currentErrors = stream_get_contents($processInfo['pipes'][2]);

                        if (! empty($currentOutput)) {
                            $this->info("📊 [Job {$jobId}] ".trim($currentOutput));
                        }
                        if (! empty($currentErrors)) {
                            $this->warn("⚠️  [Job {$jobId}] ".trim($currentErrors));
                        }

                        stream_set_blocking($processInfo['pipes'][1], true);
                        stream_set_blocking($processInfo['pipes'][2], true);
                    }
                }

                if (! $status['running']) {
                    // Process completed - get final output with timeout protection
                    $output = '';
                    $errors = '';

                    // Use non-blocking reads with timeout to prevent hanging
                    if (is_resource($processInfo['pipes'][1])) {
                        stream_set_blocking($processInfo['pipes'][1], false);
                        $startRead = microtime(true);
                        while ((microtime(true) - $startRead) < 5) { // 5 second timeout
                            $chunk = fread($processInfo['pipes'][1], 8192);
                            if ($chunk === false || $chunk === '') {
                                break;
                            }
                            $output .= $chunk;
                        }
                        stream_set_blocking($processInfo['pipes'][1], true);
                    }

                    if (is_resource($processInfo['pipes'][2])) {
                        stream_set_blocking($processInfo['pipes'][2], false);
                        $startRead = microtime(true);
                        while ((microtime(true) - $startRead) < 5) { // 5 second timeout
                            $chunk = fread($processInfo['pipes'][2], 8192);
                            if ($chunk === false || $chunk === '') {
                                break;
                            }
                            $errors .= $chunk;
                        }
                        stream_set_blocking($processInfo['pipes'][2], true);
                    }

                    // Close pipes and process safely
                    $this->closeProcessResources($processInfo);
                    $exitCode = $status['exitcode'] ?? -1;

                    $jobRuntime = round(microtime(true) - $processInfo['started_at'], 2);

                    if ($exitCode === 0) {
                        // Extract update count from output
                        if (preg_match('/(\d+) total updates/', $output, $matches)) {
                            $updates = (int) $matches[1];
                            $totalUpdates += $updates;
                        }
                        $this->info("✅ Job {$jobId} completed: {$processInfo['symbols']} symbols processed in {$jobRuntime}s");
                    } else {
                        $failedJobs++;
                        $this->error("❌ Job {$jobId} failed (exit code: {$exitCode}) after {$jobRuntime}s");
                        if ($errors) {
                            $this->error('Errors: '.trim($errors));
                        }

                        Log::channel('scheduled')->error('[YFinance 5-Minute Continuous Sync] Job execution failed', [
                            'job_id' => $jobId,
                            'exit_code' => $exitCode,
                            'command' => $processInfo['command'],
                            'symbols' => $processInfo['symbols'],
                            'offset' => $processInfo['offset'],
                            'runtime_seconds' => $jobRuntime,
                            'stderr_output' => $errors ? trim($errors) : null,
                            'stdout_output' => $output ? trim($output) : null,
                        ]);
                    }

                    $processes[$jobId]['completed'] = true;
                    $completedJobs++;
                    unset($childProcesses[$jobId]);

                    $this->info("📊 Progress: {$completedJobs} / ".count($processes).' jobs completed');
                }
            }

            // Update verbose output timing
            if ($verbose && time() - $lastVerboseOutput >= 5) {
                $lastVerboseOutput = time();
            }

            // Show progress periodically even in non-verbose mode
            static $lastProgressUpdate = 0;
            if (time() - $lastProgressUpdate >= 30) { // Every 30 seconds
                $running = count($processes) - $completedJobs;
                $elapsed = round(microtime(true) - $processStartTime, 1);
                $this->info("⏳ Status: {$completedJobs} completed, {$running} running, {$elapsed}s elapsed");
                $lastProgressUpdate = time();

                // Check for stuck processes - if we've been running for more than 5 minutes
                // and no progress in the last 2 minutes, force check all processes
                if ($elapsed > 300 && time() - $lastProgressCheck > 120) {
                    $this->warn('⚠️  No progress detected, force-checking process states...');
                    foreach ($processes as $forceJobId => $forceProcessInfo) {
                        if (! isset($forceProcessInfo['completed'])) {
                            $forceRuntime = microtime(true) - $forceProcessInfo['started_at'];
                            $this->warn("   Job {$forceJobId} running for {$forceRuntime}s - checking if stuck");

                            // If a job has been running for over 5 minutes, mark it as potentially stuck
                            if ($forceRuntime > 300) {
                                $this->warn("   Job {$forceJobId} may be stuck, will terminate on next timeout check");
                            }
                        }
                    }
                }
            }

            // Track progress for stuck detection
            static $lastCompletedJobs = 0;
            if ($completedJobs != $lastCompletedJobs) {
                $lastProgressCheck = time();
                $lastCompletedJobs = $completedJobs;
            }

            // Adaptive sleep - shorter sleep when processes are finishing
            if ($completedJobs > 0) {
                usleep(200000); // 0.2 seconds when active
            } else {
                usleep(500000); // 0.5 seconds when waiting for first completion
            }
        }

        $duration = round(microtime(true) - $startTime, 2);
        $totalFailures = $failedJobs + $launchFailures;

        // Final cleanup of any remaining child processes
        $this->cleanupChildProcesses($childProcesses);

        if ($totalFailures > 0) {
            $this->error("⚠️  5-minute continuous sync completed with {$totalFailures} failure(s)");
            if ($launchFailures > 0) {
                $this->error("   Process launch failures: {$launchFailures}");
            }
            if ($failedJobs > 0) {
                $this->error("   Job execution failures: {$failedJobs}");
            }

            Log::channel('scheduled')->warning('[YFinance 5-Minute Continuous Sync] Completed with failures', [
                'total_failures' => $totalFailures,
                'launch_failures' => $launchFailures,
                'execution_failures' => $failedJobs,
                'total_symbols' => $totalSymbols,
                'successful_jobs' => count($processes),
                'parallel_jobs_requested' => $parallelJobs,
                'total_updates' => $totalUpdates,
                'execution_time_seconds' => $duration,
                'batch_size' => $batchSize,
                'hours' => $hours,
            ]);
        } else {
            $this->info('🎉 5-minute continuous sync completed successfully!');

            Log::channel('scheduled')->info('[YFinance 5-Minute Continuous Sync] Completed successfully', [
                'total_symbols' => $totalSymbols,
                'successful_jobs' => count($processes),
                'total_updates' => $totalUpdates,
                'execution_time_seconds' => $duration,
                'batch_size' => $batchSize,
                'hours' => $hours,
            ]);
        }

        $this->info("   Total symbols processed: {$totalSymbols}");
        $this->info('   Successful jobs: '.(count($processes)).' / '.($parallelJobs));
        $this->info("   Total updates: {$totalUpdates}");
        $this->info("   Execution time: {$duration}s");
        if ($duration > 0) {
            $this->info('   Parallel efficiency: '.round($totalSymbols / $duration, 0).' symbols/second');
        }

        // Trigger cache warming if we processed a significant amount
        if ($totalSymbols > 100) {
            $this->info('🔥 Starting cache warming in background...');

            // Start cache warming as background process
            $command = 'php artisan cache:warm-assets > /dev/null 2>&1 &';
            exec($command);

            $this->info('⚡ Cache warming started in background, continuing...');
        }

        // Return appropriate exit code based on job success/failure
        return $totalFailures > 0 ? self::FAILURE : self::SUCCESS;
    }

    public function countTargetSymbols(): int
    {
        // IMPORTANT: This must match the Python 5m batch script's symbol universe (python/config.py).
        // The 5m batch scripts should use asset_info.over_1mil=1.
        // If this diverges, we compute offsets beyond the Python list length and launch empty jobs.
        return (int) DB::table('asset_info')
            ->where('asset_type', 'stock')
            ->whereNull('deleted_at')
            ->where('over_1mil', 1)
            ->count();
    }

    /**
     * Check if a process is running by PID
     */
    private function isProcessRunning(int $pid): bool
    {
        // On Unix systems, we can use posix_kill with signal 0 to check if process exists
        return posix_kill($pid, 0);
    }

    /**
     * Check if a process is still running using multiple methods
     */
    private function isProcessStillRunning($process, string $jobId, array &$consecutiveFailedChecks): bool
    {
        if (! is_resource($process)) {
            return false;
        }

        // Method 1: Try proc_get_status (most reliable when it works)
        $status = @proc_get_status($process);
        if (is_array($status) && isset($status['running'])) {
            if (! $status['running']) {
                unset($consecutiveFailedChecks[$jobId]);

                return false;
            }
            // If running according to status, do additional checks
        } else {
            // proc_get_status failed, increment failed check counter
            $consecutiveFailedChecks[$jobId] = ($consecutiveFailedChecks[$jobId] ?? 0) + 1;

            // If we've had multiple consecutive failures, assume process is dead
            if ($consecutiveFailedChecks[$jobId] > 3) {
                $this->warn("⚠️  Job {$jobId}: proc_get_status failed {$consecutiveFailedChecks[$jobId]} times, assuming process is dead");

                return false;
            }
        }

        // Method 2: Try to detect if pipes are closed (indicates process ended)
        $pipesAlive = 0;
        if (isset($status['running']) && $status['running']) {
            // Check if we can still write to stdin (if process is truly running, this should work)
            // Note: We're not actually writing, just checking if the resource is valid
            if (is_resource($process)) {
                $pipesAlive++;
            }
        }

        return $pipesAlive > 0;
    }

    /**
     * Cleanup child processes on shutdown
     */
    private function cleanupChildProcesses(array &$childProcesses): void
    {
        if (empty($childProcesses)) {
            return;
        }

        $this->info('🧹 Cleaning up '.count($childProcesses).' child processes...');

        // First pass: Send SIGTERM to all processes
        foreach ($childProcesses as $jobId => $process) {
            if (is_resource($process)) {
                $this->info("📤 Sending SIGTERM to job {$jobId}");
                proc_terminate($process, SIGTERM);
            }
        }

        // Give processes time to terminate gracefully
        $this->info('⏳ Waiting 3 seconds for graceful termination...');
        sleep(3);

        // Second pass: Check which processes are still running and force kill
        $forcedKills = 0;
        foreach ($childProcesses as $jobId => $process) {
            if (is_resource($process)) {
                $status = @proc_get_status($process);
                if (is_array($status) && $status['running']) {
                    $this->warn("💥 Force killing stubborn job {$jobId}");
                    proc_terminate($process, SIGKILL);
                    $forcedKills++;
                }

                // Close the process handle
                @proc_close($process);
            }
        }

        if ($forcedKills > 0) {
            $this->warn("⚠️  Had to force kill {$forcedKills} stubborn processes");
        }

        $childProcesses = [];
        $this->info('✅ Process cleanup completed');
    }

    /**
     * Safely close process resources
     */
    private function closeProcessResources(array $processInfo): void
    {
        // Close pipes safely
        if (isset($processInfo['pipes'])) {
            foreach ($processInfo['pipes'] as $pipe) {
                if (is_resource($pipe)) {
                    fclose($pipe);
                }
            }
        }

        // Close process handle
        if (is_resource($processInfo['process'])) {
            proc_close($processInfo['process']);
        }
    }
}
