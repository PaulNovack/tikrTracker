<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class MarketYfinanceStocks1minHighVolumeContinuous extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'market:yfinance-stocks-1min-high-volume-continuous 
                            {--batch-size=100 : Symbols per API request}
                            {--parallel-jobs=3 : Number of parallel batch jobs}
                            {--stagger-delay=1 : Seconds to stagger job starts (rate limiting)}
                            {--hours=0.02 : Hours of data to fetch (0.02 = ~1 minute)}
                            {--max-symbols= : Maximum symbols to process (for testing)}
                            {--conservative : Use conservative rate limiting (slower but safer)}
                            {--detailed : Show detailed real-time output from subprocesses}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Keep ALL high-volume symbols (>$1M daily) 1-minute data up-to-date using parallel batch processing';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        // Check if another instance is already running
        $lockFile = storage_path('app/yfinance-1min-high-volume-continuous.lock');

        if (file_exists($lockFile)) {
            $pid = trim(file_get_contents($lockFile));

            // Check if the process is actually running
            if ($this->isProcessRunning($pid)) {
                $this->error('❌ Another instance of 1-minute high-volume continuous sync is already running (PID: '.$pid.')');
                $this->error('   If you believe this is incorrect, delete: '.$lockFile);

                Log::channel('scheduled')->warning('[1-Min High-Volume Continuous Sync] Another instance already running', [
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
            $staggerDelay = max($staggerDelay, 2);
            $this->info("🛡️  Conservative mode: reduced to {$parallelJobs} jobs with {$staggerDelay}s stagger");
        }

        $this->info('🚀 Starting 1-minute continuous sync for ALL high-volume symbols');
        $this->info("   Batch size: {$batchSize} symbols per API request");
        $this->info("   Parallel jobs: {$parallelJobs}");
        $this->info("   Stagger delay: {$staggerDelay}s between job starts");
        $this->info("   Hours: {$hours} (~1 minute of fresh data)");

        // Get total HIGH-VOLUME symbol count (>$1M daily trading volume)
        $totalSymbols = DB::table('asset_info')
            ->where('asset_type', 'stock')
            ->where('over_1mil', true)
            ->whereNull('deleted_at')
            ->count();

        if ($maxSymbols) {
            $totalSymbols = min($totalSymbols, $maxSymbols);
            $this->info("   Limited to: {$totalSymbols} symbols (testing)");
        }

        Log::channel('scheduled')->info('[1-Min High-Volume Continuous Sync] Starting sync process', [
            'pid' => getmypid(),
            'total_high_volume_symbols' => $totalSymbols,
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

        $this->info('   Total high-volume symbols: '.number_format($totalSymbols));
        $this->info("   Total batches: {$totalBatches}");
        $this->info("   Batches per parallel job: {$batchesPerJob}");

        // Estimate completion time
        $estimatedSeconds = ($totalBatches * 2) + ($parallelJobs * $staggerDelay); // Conservative estimate
        $this->info("   Estimated completion: {$estimatedSeconds}s");

        $startTime = microtime(true);
        $jobStartTime = $startTime;

        // Track job statistics
        $jobStats = [];

        // Create and manage parallel jobs
        for ($jobId = 1; $jobId <= $parallelJobs; $jobId++) {
            // Calculate the range of batches for this job
            $startBatch = ($jobId - 1) * $batchesPerJob;
            $endBatch = min($jobId * $batchesPerJob - 1, $totalBatches - 1);

            if ($startBatch > $totalBatches - 1) {
                break; // No more batches for this job
            }

            $startOffset = $startBatch * $batchSize;
            $symbolsForThisJob = min($batchSize * $batchesPerJob, $totalSymbols - $startOffset);

            // Build the Python command for this specific job
            $pythonScript = base_path('python/yfinance_stocks_1min_simple.py');
            $pythonDir = base_path('python');

            $command = [
                'cd', $pythonDir, '&&',
                'source', 'activate.sh', '&&',
                'python3', 'yfinance_stocks_1min_simple.py',
                '--mode=full', // Use all high-volume symbols
                '--batch-size='.$batchSize,
                '--delay=0.5',
                '--retry-attempts=2',
            ];

            $fullCommand = implode(' ', $command);

            $this->info("🔄 Starting Job {$jobId}/{$parallelJobs}: {$symbolsForThisJob} symbols (offset {$startOffset})");

            if ($verbose) {
                $this->info("   Command: {$fullCommand}");
            }

            // Start the process
            $descriptors = [
                0 => ['pipe', 'r'], // stdin
                1 => ['pipe', 'w'], // stdout
                2 => ['pipe', 'w'], // stderr
            ];

            $process = proc_open($fullCommand, $descriptors, $pipes);

            if (is_resource($process)) {
                $childProcesses[] = $process;

                // Store job information for tracking
                $jobStats[$jobId] = [
                    'process' => $process,
                    'pipes' => $pipes,
                    'symbols' => $symbolsForThisJob,
                    'start_time' => microtime(true),
                    'completed' => false,
                ];

                // Close stdin since we don't need it
                fclose($pipes[0]);

                // Make stdout and stderr non-blocking
                stream_set_blocking($pipes[1], false);
                stream_set_blocking($pipes[2], false);

                $this->info("   Job {$jobId} started (PID: ".proc_get_status($process)['pid'].')');
            } else {
                $this->error("❌ Failed to start Job {$jobId}");

                continue;
            }

            // Stagger the job starts to avoid overwhelming the API
            if ($jobId < $parallelJobs && $staggerDelay > 0) {
                $this->info("   ⏱️ Staggering {$staggerDelay}s before next job...");
                sleep($staggerDelay);
            }
        }

        // Monitor all jobs until completion
        $this->info('📊 Monitoring parallel jobs...');

        $completedJobs = 0;
        $totalJobs = count($jobStats);

        while ($completedJobs < $totalJobs) {
            foreach ($jobStats as $jobId => &$job) {
                if ($job['completed']) {
                    continue;
                }

                $process = $job['process'];
                $pipes = $job['pipes'];

                // Read output if available
                if ($verbose) {
                    $stdout = stream_get_contents($pipes[1]);
                    $stderr = stream_get_contents($pipes[2]);

                    if (! empty($stdout)) {
                        $this->info("[Job {$jobId}] {$stdout}");
                    }
                    if (! empty($stderr)) {
                        $this->error("[Job {$jobId}] {$stderr}");
                    }
                }

                // Check if process has finished
                $status = proc_get_status($process);
                if (! $status['running']) {
                    $job['completed'] = true;
                    $job['end_time'] = microtime(true);
                    $job['duration'] = round($job['end_time'] - $job['start_time'], 2);
                    $job['exit_code'] = $status['exitcode'];

                    // Close pipes and process
                    fclose($pipes[1]);
                    fclose($pipes[2]);
                    proc_close($process);

                    $completedJobs++;

                    if ($job['exit_code'] === 0) {
                        $this->info("✅ Job {$jobId} completed successfully ({$job['symbols']} symbols in {$job['duration']}s)");
                    } else {
                        $this->error("❌ Job {$jobId} failed with exit code {$job['exit_code']} ({$job['duration']}s)");
                    }

                    Log::channel('scheduled')->info("[1-Min High-Volume Continuous Sync] Job {$jobId} completed", [
                        'job_id' => $jobId,
                        'symbols_processed' => $job['symbols'],
                        'duration_seconds' => $job['duration'],
                        'exit_code' => $job['exit_code'],
                        'success' => $job['exit_code'] === 0,
                    ]);
                }
            }

            // Brief pause to avoid excessive CPU usage
            usleep(100000); // 0.1 second

            // Handle signals if available
            if (function_exists('pcntl_signal_dispatch')) {
                pcntl_signal_dispatch();
            }
        }

        // Calculate final statistics
        $totalDuration = microtime(true) - $startTime;
        $successfulJobs = collect($jobStats)->where('exit_code', 0)->count();
        $failedJobs = $totalJobs - $successfulJobs;

        $this->info('');
        $this->info('🎯 High-Volume 1-Minute Continuous Sync Summary');
        $this->info('='.str_repeat('=', 50));
        $this->info('   Total high-volume symbols processed: '.number_format($totalSymbols));
        $this->info("   Parallel jobs: {$totalJobs}");
        $this->info("   Successful jobs: {$successfulJobs}");
        $this->info("   Failed jobs: {$failedJobs}");
        $this->info('   Total duration: '.round($totalDuration, 2).'s');
        $this->info('   Average rate: '.round($totalSymbols / max($totalDuration, 1), 1).' symbols/second');

        // Log final summary
        Log::channel('scheduled')->info('[1-Min High-Volume Continuous Sync] Process completed', [
            'total_high_volume_symbols' => $totalSymbols,
            'parallel_jobs' => $totalJobs,
            'successful_jobs' => $successfulJobs,
            'failed_jobs' => $failedJobs,
            'total_duration_seconds' => round($totalDuration, 2),
            'symbols_per_second' => round($totalSymbols / max($totalDuration, 1), 2),
            'completed_at' => now()->toISOString(),
        ]);

        // Remove lock file
        if (file_exists($lockFile)) {
            unlink($lockFile);
        }

        if ($failedJobs > 0) {
            $this->error("❌ {$failedJobs} jobs failed - check logs for details");

            return 1;
        }

        $this->info('✅ All jobs completed successfully!');

        return 0;
    }

    /**
     * Check if a process is running by PID
     */
    private function isProcessRunning(string $pid): bool
    {
        if (! $pid || ! is_numeric($pid)) {
            return false;
        }

        // Check if process exists using ps command
        $result = shell_exec("ps -p {$pid} -o pid= 2>/dev/null");

        return ! empty(trim($result));
    }

    /**
     * Clean up child processes
     */
    private function cleanupChildProcesses(array $childProcesses): void
    {
        foreach ($childProcesses as $process) {
            if (is_resource($process)) {
                $status = proc_get_status($process);
                if ($status['running']) {
                    $this->info("🧹 Terminating child process PID: {$status['pid']}");
                    proc_terminate($process, SIGTERM);

                    // Give process time to terminate gracefully
                    sleep(2);

                    // Force kill if still running
                    $status = proc_get_status($process);
                    if ($status['running']) {
                        proc_terminate($process, SIGKILL);
                        $this->info("🔨 Force killed process PID: {$status['pid']}");
                    }
                }
                proc_close($process);
            }
        }
    }
}
