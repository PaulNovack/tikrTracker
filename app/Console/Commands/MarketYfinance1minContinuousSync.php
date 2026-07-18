<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class MarketYfinance1minContinuousSync extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'market:yfinance-1min-continuous-sync 
                            {--batch-size=50 : Symbols per API request}
                            {--parallel-jobs=3 : Number of parallel batch jobs}
                            {--stagger-delay=2 : Seconds to stagger job starts (rate limiting)}
                            {--hours=1 : Hours of data to fetch}
                            {--max-symbols= : Maximum symbols to process (for testing)}
                            {--conservative : Use conservative rate limiting (slower but safer)}
                            {--detailed : Show detailed real-time output from subprocesses}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Keep 1-minute enabled symbols up-to-date using rate-limited parallel batch processing (top 1500 most liquid stocks)';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        // Check if another instance is already running
        $lockFile = storage_path('app/yfinance-1min-continuous-sync.lock');

        if (file_exists($lockFile)) {
            $pid = trim(file_get_contents($lockFile));

            // Check if the process is actually running
            if ($this->isProcessRunning($pid)) {
                $this->error('❌ Another instance of yfinance-1min-continuous-sync is already running (PID: '.$pid.')');
                $this->error('   If you believe this is incorrect, delete: '.$lockFile);

                Log::channel('scheduled')->warning('[YFinance 1-Minute Continuous Sync] Another instance already running', [
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

        // Register cleanup function to remove lock file on exit
        register_shutdown_function(function () use ($lockFile) {
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
        $hours = (int) $this->option('hours');
        $maxSymbols = $this->option('max-symbols') ? (int) $this->option('max-symbols') : null;
        $conservative = $this->option('conservative');
        $verbose = $this->option('detailed');

        // Apply conservative settings if requested
        if ($conservative) {
            $parallelJobs = min($parallelJobs, 2);
            $staggerDelay = max($staggerDelay, 3);
            $this->info("🛡️  Conservative mode: reduced to {$parallelJobs} jobs with {$staggerDelay}s stagger");
        }

        $this->info('🚀 Starting rate-limited continuous sync for 1-minute enabled symbols (top 1500 most liquid)');
        $this->info("   Batch size: {$batchSize} symbols per API request");
        $this->info("   Parallel jobs: {$parallelJobs}");
        $this->info("   Stagger delay: {$staggerDelay}s between job starts");
        $this->info("   Hours: {$hours}");

        // Get total symbol count (only 1-minute enabled stocks)
        $totalSymbols = DB::table('asset_info')
            ->where('asset_type', 'stock')
            ->where('1_min', 1)
            ->whereNull('deleted_at')
            ->count();

        if ($maxSymbols) {
            $totalSymbols = min($totalSymbols, $maxSymbols);
            $this->info("   Limited to: {$totalSymbols} symbols (testing)");
        }

        Log::channel('scheduled')->info('[YFinance 1-Minute Continuous Sync] Starting sync process', [
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

            // Create the command for this parallel job using full PHP path
            $phpPath = PHP_BINARY;
            $command = sprintf(
                '%s %s market:yfinance-stocks-1min-batch %d --batch-size=%d --limit=%d --offset=%d',
                escapeshellarg($phpPath),
                escapeshellarg(base_path('artisan')),
                $hours,
                $batchSize,
                $jobSymbolLimit,
                $startOffset
            );

            // Set environment variables for child process
            $env = array_merge($_ENV, [
                'PATH' => getenv('PATH'),
                'HOME' => getenv('HOME'),
                'PHP_BINARY' => $phpPath,
            ]);

            // Launch background process with proper environment and working directory
            $process = proc_open(
                $command,
                [
                    0 => ['pipe', 'r'], // stdin
                    1 => ['pipe', 'w'], // stdout
                    2 => ['pipe', 'w'],  // stderr
                ],
                $pipes,
                base_path(),
                $env
            );

            if (is_resource($process)) {
                // Get the process PID for better tracking
                $status = proc_get_status($process);
                $pid = $status['pid'] ?? null;

                $processes[$jobId] = [
                    'process' => $process,
                    'pipes' => $pipes,
                    'command' => $command,
                    'symbols' => $jobSymbolLimit,
                    'offset' => $startOffset,
                    'pid' => $pid,
                    'start_time' => time(),
                ];

                $this->info("✨ Job {$jobId} launched with PID: {$pid}");
            } else {
                $launchFailures++;
                $errorMsg = "Failed to launch job {$jobId} with command: {$command}";
                $this->error("❌ {$errorMsg}");
                Log::channel('scheduled')->error('[YFinance Continuous Sync] Process launch failed', [
                    'job_id' => $jobId,
                    'command' => $command,
                    'symbols_limit' => $jobSymbolLimit,
                    'offset' => $startOffset,
                ]);
            }
        }

        $this->info('⏳ Waiting for '.count($processes).' parallel jobs to complete...');

        // Monitor and wait for completion with timeout protection
        $completedJobs = 0;
        $failedJobs = 0;
        $totalUpdates = 0;
        $lastVerboseOutput = time();
        $maxJobTimeout = 900; // 15 minutes max per job

        while ($completedJobs < count($processes)) {
            foreach ($processes as $jobId => $processInfo) {
                if (isset($processInfo['completed'])) {
                    continue; // Already processed
                }

                // Check for timeout (15 minutes per job)
                $runtime = time() - $processInfo['start_time'];
                if ($runtime > $maxJobTimeout) {
                    $this->error("⏰ Job {$jobId} timed out after {$runtime}s - killing process");

                    // Kill the hanging process
                    if ($processInfo['pid']) {
                        posix_kill($processInfo['pid'], SIGTERM);
                        sleep(2); // Give it time to terminate gracefully

                        // Force kill if still running
                        if (posix_kill($processInfo['pid'], 0)) {
                            posix_kill($processInfo['pid'], SIGKILL);
                        }
                    }

                    // Clean up pipes
                    if (is_resource($processInfo['pipes'][0])) {
                        fclose($processInfo['pipes'][0]);
                    }
                    if (is_resource($processInfo['pipes'][1])) {
                        fclose($processInfo['pipes'][1]);
                    }
                    if (is_resource($processInfo['pipes'][2])) {
                        fclose($processInfo['pipes'][2]);
                    }
                    if (is_resource($processInfo['process'])) {
                        proc_close($processInfo['process']);
                    }

                    $processes[$jobId]['completed'] = true;
                    $failedJobs++;
                    $completedJobs++;

                    Log::channel('scheduled')->error('[YFinance Continuous Sync] Job timeout', [
                        'job_id' => $jobId,
                        'pid' => $processInfo['pid'],
                        'runtime_seconds' => $runtime,
                        'command' => $processInfo['command'],
                    ]);

                    continue;
                }

                // Check if process is still running
                $status = proc_get_status($processInfo['process']);

                // Show real-time output in verbose mode
                if ($verbose && time() - $lastVerboseOutput >= 5) {
                    // Non-blocking read of current output
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

                if (! $status['running']) {
                    // Process completed - get final output with non-blocking reads
                    stream_set_blocking($processInfo['pipes'][1], false);
                    stream_set_blocking($processInfo['pipes'][2], false);

                    $output = '';
                    $errors = '';

                    // Read all available output
                    while (($chunk = fgets($processInfo['pipes'][1])) !== false) {
                        $output .= $chunk;
                    }

                    while (($chunk = fgets($processInfo['pipes'][2])) !== false) {
                        $errors .= $chunk;
                    }

                    // Close pipes and process properly
                    if (is_resource($processInfo['pipes'][0])) {
                        fclose($processInfo['pipes'][0]);
                    }
                    if (is_resource($processInfo['pipes'][1])) {
                        fclose($processInfo['pipes'][1]);
                    }
                    if (is_resource($processInfo['pipes'][2])) {
                        fclose($processInfo['pipes'][2]);
                    }
                    $exitCode = proc_close($processInfo['process']);

                    $runtime = time() - $processInfo['start_time'];

                    if ($exitCode === 0) {
                        // Extract update count from output
                        if (preg_match('/(\d+) total updates/', $output, $matches)) {
                            $updates = (int) $matches[1];
                            $totalUpdates += $updates;
                        }
                        $this->info("✅ Job {$jobId} completed: {$processInfo['symbols']} symbols processed ({$runtime}s)");
                    } else {
                        $failedJobs++;
                        $this->error("❌ Job {$jobId} failed (exit code: {$exitCode}, runtime: {$runtime}s)");
                        if ($errors) {
                            $this->error('Errors: '.trim($errors));
                        }

                        Log::channel('scheduled')->error('[YFinance Continuous Sync] Job execution failed', [
                            'job_id' => $jobId,
                            'exit_code' => $exitCode,
                            'command' => $processInfo['command'],
                            'symbols' => $processInfo['symbols'],
                            'offset' => $processInfo['offset'],
                            'stderr_output' => $errors ? trim($errors) : null,
                            'stdout_output' => $output ? trim($output) : null,
                        ]);
                    }

                    $processes[$jobId]['completed'] = true;
                    $completedJobs++;
                }
            }

            // Update verbose output timing
            if ($verbose && time() - $lastVerboseOutput >= 5) {
                $lastVerboseOutput = time();
            }

            // Brief pause to avoid busy waiting
            usleep(500000); // 0.5 seconds
        }

        // Cleanup: Ensure all processes are properly terminated
        foreach ($processes as $jobId => $processInfo) {
            if (! isset($processInfo['completed'])) {
                $this->warn("🧹 Cleaning up incomplete job {$jobId}");

                if ($processInfo['pid'] && posix_kill($processInfo['pid'], 0)) {
                    posix_kill($processInfo['pid'], SIGTERM);
                    sleep(1);
                    if (posix_kill($processInfo['pid'], 0)) {
                        posix_kill($processInfo['pid'], SIGKILL);
                    }
                }

                // Close any remaining pipes/resources
                if (is_resource($processInfo['pipes'][0] ?? null)) {
                    fclose($processInfo['pipes'][0]);
                }
                if (is_resource($processInfo['pipes'][1] ?? null)) {
                    fclose($processInfo['pipes'][1]);
                }
                if (is_resource($processInfo['pipes'][2] ?? null)) {
                    fclose($processInfo['pipes'][2]);
                }
                if (is_resource($processInfo['process'] ?? null)) {
                    proc_close($processInfo['process']);
                }
            }
        }

        $duration = round(microtime(true) - $startTime, 2);
        $totalFailures = $failedJobs + $launchFailures;

        if ($totalFailures > 0) {
            $this->error("⚠️  Continuous sync completed with {$totalFailures} failure(s)");
            if ($launchFailures > 0) {
                $this->error("   Process launch failures: {$launchFailures}");
            }
            if ($failedJobs > 0) {
                $this->error("   Job execution failures: {$failedJobs}");
            }

            Log::channel('scheduled')->warning('[YFinance Continuous Sync] Completed with failures', [
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
            $this->info('🎉 Continuous sync completed successfully!');

            Log::channel('scheduled')->info('[YFinance Continuous Sync] Completed successfully', [
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
        $this->info('   Parallel efficiency: '.round($totalSymbols / $duration, 0).' symbols/second');

        // Return appropriate exit code based on job success/failure
        return $totalFailures > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Check if a process is running by PID
     */
    private function isProcessRunning(int $pid): bool
    {
        // On Unix systems, we can use posix_kill with signal 0 to check if process exists
        return posix_kill($pid, 0);
    }
}
