<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class MarketYfinanceStocks1minHighVolume extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'market:yfinance-stocks-1min-high-volume 
                            {mode=high_volume : Collection mode: high_volume, full, missing}
                            {--batch-size=50 : Number of symbols per batch}
                            {--parallel-jobs=1 : Number of parallel batch jobs}
                            {--stagger-delay=2 : Seconds to stagger job starts (rate limiting)}
                            {--delay=1.0 : Delay between requests in seconds}
                            {--retry-attempts=3 : Number of retry attempts per symbol}
                            {--max-symbols= : Maximum symbols to process (for testing)}
                            {--no-checkpoint : Disable checkpoint system for real-time collection}
                            {--detailed : Show detailed real-time output from subprocesses}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Fetch 1-minute data for high-volume stocks using parallel batch processing for efficient real-time collection';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $mode = $this->argument('mode');

        // Create mode-specific lock file to prevent overlapping instances
        $lockFile = storage_path("app/yfinance-1min-{$mode}.lock");

        if (file_exists($lockFile)) {
            $pid = trim(file_get_contents($lockFile));

            if ($this->isProcessRunning($pid)) {
                $this->warn("⏩ Another 1-minute {$mode} sync already running (PID: {$pid})");

                return 0; // Exit gracefully for scheduler
            } else {
                unlink($lockFile);
                $this->info('🧹 Removed stale lock file');
            }
        }

        // Create lock file
        file_put_contents($lockFile, getmypid());

        // Cleanup on exit
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
        $delay = (float) $this->option('delay');
        $retryAttempts = (int) $this->option('retry-attempts');
        $maxSymbols = $this->option('max-symbols');

        $this->info('🚀 Starting 1-minute high-volume stock data collection');
        $this->info("   Mode: {$mode}");
        $this->info("   Batch size: {$batchSize}");
        $this->info("   Parallel jobs: {$parallelJobs}");
        $this->info("   Stagger delay: {$staggerDelay}s");
        $this->info("   Delay: {$delay}s between requests");
        $this->info("   Retry attempts: {$retryAttempts}");

        // Validate mode
        $validModes = ['high_volume', 'full', 'missing'];
        if (! in_array($mode, $validModes)) {
            $this->error("Invalid mode: {$mode}. Valid modes are: ".implode(', ', $validModes));

            // Clean up lock file before exit
            if (file_exists($lockFile)) {
                unlink($lockFile);
            }

            return self::FAILURE;
        }

        // Get expected symbol count for parallel job planning
        $expectedCount = $this->getExpectedSymbolCount($mode);
        $totalSymbols = $maxSymbols ? min($expectedCount, (int) $maxSymbols) : $expectedCount;

        if ($totalSymbols <= 0) {
            $this->error('❌ No symbols found for processing');
            if (file_exists($lockFile)) {
                unlink($lockFile);
            }

            return self::FAILURE;
        }

        // For single job, use direct execution; for multiple jobs, use parallel processing
        if ($parallelJobs == 1) {
            return $this->executeSingleJob($mode, $batchSize, $delay, $retryAttempts, $totalSymbols, $lockFile);
        } else {
            return $this->executeParallelJobs($mode, $batchSize, $parallelJobs, $staggerDelay, $totalSymbols, $lockFile);
        }

        if (! file_exists($pythonScript)) {
            $this->error("Python script not found: {$pythonScript}");

            // Clean up lock file before exit
            if (file_exists($lockFile)) {
                unlink($lockFile);
            }

            return self::FAILURE;
        }

        $command = [
            'python3',
            $pythonScript,
            '--mode='.$mode,
            '--batch-size='.$batchSize,
            '--delay='.$delay,
            '--retry-attempts='.$retryAttempts,
        ];

        // Add no-checkpoint flag for real-time scheduled collections
        if ($this->option('no-checkpoint')) {
            $command[] = '--no-checkpoint';
        }

        // Change to Python directory for proper imports and environment
        $pythonDir = base_path('python');
        $activationScript = $pythonDir.'/activate.sh';

        // Build full command with environment activation
        if (file_exists($activationScript)) {
            $fullCommand = "/bin/bash -c \"cd {$pythonDir} && source {$activationScript} && ".implode(' ', $command).'"';
        } else {
            $fullCommand = "/bin/bash -c \"cd {$pythonDir} && ".implode(' ', $command).'"';
        }

        $this->info('📡 Executing: '.implode(' ', array_slice($command, 1)));

        // Get expected symbol count for progress estimation
        $expectedCount = $this->getExpectedSymbolCount($mode);
        if ($expectedCount > 0) {
            $estimatedTime = $this->estimateCompletionTime($expectedCount, $batchSize, $delay);
            $this->info('   Expected symbols: '.number_format($expectedCount));
            $this->info("   Estimated completion time: {$estimatedTime}");
        }

        $startTime = microtime(true);

        // Execute the Python script with real-time output
        $process = proc_open(
            $fullCommand,
            [
                1 => ['pipe', 'w'], // stdout
                2 => ['pipe', 'w'], // stderr
            ],
            $pipes
        );

        if (! is_resource($process)) {
            $this->error('Failed to start Python process');

            // Clean up lock file before exit
            if (file_exists($lockFile)) {
                unlink($lockFile);
            }

            return self::FAILURE;
        }

        // Read output in real-time
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        while (true) {
            $status = proc_get_status($process);

            // Read stdout
            if ($stdout = stream_get_contents($pipes[1])) {
                $this->line($stdout);
            }

            // Read stderr
            if ($stderr = stream_get_contents($pipes[2])) {
                $this->error($stderr);
            }

            if (! $status['running']) {
                break;
            }

            usleep(100000); // 0.1 second
        }

        // Get any remaining output
        if ($stdout = stream_get_contents($pipes[1])) {
            $this->line($stdout);
        }
        if ($stderr = stream_get_contents($pipes[2])) {
            $this->error($stderr);
        }

        fclose($pipes[1]);
        fclose($pipes[2]);

        $returnCode = proc_close($process);
        $duration = round(microtime(true) - $startTime, 2);

        // Clean up lock file
        if (file_exists($lockFile)) {
            unlink($lockFile);
        }

        if ($returnCode === 0) {
            $this->info("✅ High-volume 1-minute data collection completed successfully in {$duration}s");

            // Show quick stats
            $this->showCollectionStats();

            return self::SUCCESS;
        } else {
            $this->error("❌ High-volume 1-minute data collection failed with exit code {$returnCode}");

            return self::FAILURE;
        }
    }

    /**
     * Get expected symbol count based on mode.
     */
    private function getExpectedSymbolCount(string $mode): int
    {
        try {
            $connection = \DB::connection();

            switch ($mode) {
                case 'high_volume':
                    // Top 1000 most active high-volume symbols
                    $query = "SELECT COUNT(*) as count FROM (
                        SELECT ai.symbol
                        FROM asset_info ai
                        LEFT JOIN five_minute_prices fmp ON ai.symbol = fmp.symbol
                        WHERE ai.asset_type = 'stock' 
                        AND ai.over_1mil = true
                        AND ai.deleted_at IS NULL
                        AND fmp.ts_est >= DATE_SUB(NOW(), INTERVAL 30 DAY)
                        GROUP BY ai.symbol
                        ORDER BY AVG(fmp.volume * fmp.price) DESC
                        LIMIT 1000
                    ) as subquery";
                    break;

                case 'missing':
                    // High-volume symbols missing 1-minute data
                    $query = "SELECT COUNT(*) as count
                        FROM asset_info ai
                        WHERE ai.asset_type = 'stock' 
                        AND ai.over_1mil = true
                        AND ai.deleted_at IS NULL
                        AND ai.symbol NOT IN (
                            SELECT DISTINCT symbol 
                            FROM one_minute_prices 
                            WHERE asset_type = 'stock'
                        )";
                    break;

                default: // 'full'
                    // All high-volume symbols
                    $query = "SELECT COUNT(*) as count
                        FROM asset_info
                        WHERE asset_type = 'stock' 
                        AND over_1mil = true
                        AND deleted_at IS NULL";
                    break;
            }

            $result = $connection->select($query);

            return $result[0]->count ?? 0;

        } catch (\Exception $e) {
            return 0;
        }
    }

    /**
     * Estimate completion time based on symbol count and settings.
     */
    private function estimateCompletionTime(int $symbolCount, int $batchSize, float $delay): string
    {
        // Rough estimates based on API rate limits and processing time
        $symbolsPerMinute = 60 / max($delay, 1.0); // Conservative estimate
        $totalMinutes = ceil($symbolCount / $symbolsPerMinute);

        if ($totalMinutes < 60) {
            return "{$totalMinutes} minutes";
        } else {
            $hours = floor($totalMinutes / 60);
            $minutes = $totalMinutes % 60;

            return "{$hours}h {$minutes}m";
        }
    }

    /**
     * Show collection statistics after completion.
     */
    private function showCollectionStats(): void
    {
        try {
            $connection = \DB::connection();

            // Get 1-minute data statistics
            $stats = $connection->select("
                SELECT 
                    COUNT(DISTINCT symbol) as symbols_with_1min_data,
                    COUNT(*) as total_1min_records,
                    MAX(datetime_est) as latest_data,
                    MIN(datetime_est) as earliest_data
                FROM one_minute_prices 
                WHERE asset_type = 'stock'
            ");

            if (! empty($stats)) {
                $stat = $stats[0];
                $this->info('📊 Collection Statistics:');
                $this->info('   Symbols with 1-min data: '.number_format($stat->symbols_with_1min_data));
                $this->info('   Total 1-min records: '.number_format($stat->total_1min_records));
                $this->info("   Latest data: {$stat->latest_data}");
            }

        } catch (\Exception $e) {
            $this->warn('Could not retrieve collection statistics');
        }
    }

    /**
     * Execute parallel jobs for high-volume data collection.
     */
    private function executeParallelJobs(string $mode, int $batchSize, int $parallelJobs, int $staggerDelay, int $totalSymbols, string $lockFile): int
    {
        // Calculate parallel job distribution
        $totalBatches = ceil($totalSymbols / $batchSize);
        $batchesPerJob = ceil($totalBatches / $parallelJobs);

        $this->info('📊 Parallel processing plan:');
        $this->info('   Total symbols: '.number_format($totalSymbols));
        $this->info("   Total batches: {$totalBatches}");
        $this->info("   Batches per parallel job: {$batchesPerJob}");

        $startTime = microtime(true);
        $processes = [];

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

            // Create the command for this parallel job using the batch command with 1 day of data
            $command = sprintf(
                'php artisan market:yfinance-stocks-1min-batch 1 --batch-size=%d --limit=%d --offset=%d',
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
                    2 => ['pipe', 'w'], // stderr
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
                    'start_time' => microtime(true),
                ];

                // Close stdin as we don't need it
                fclose($pipes[0]);

                $this->info("✅ Job {$jobId} launched successfully");
            } else {
                $this->error("❌ Failed to launch job {$jobId}");
            }
        }

        if (empty($processes)) {
            $this->error('❌ Failed to launch any parallel jobs');
            if (file_exists($lockFile)) {
                unlink($lockFile);
            }

            return self::FAILURE;
        }

        $this->info("🚀 All jobs launched! Processing {$totalSymbols} symbols with ".count($processes).' parallel jobs...');

        // Monitor parallel jobs
        $completedJobs = [];
        $lastProgressUpdate = 0;

        while (count($completedJobs) < count($processes)) {
            // Check each running process
            foreach ($processes as $jobId => $processData) {
                if (isset($completedJobs[$jobId])) {
                    continue; // Already completed
                }

                $process = $processData['process'];
                $pipes = $processData['pipes'];
                $status = proc_get_status($process);

                // Read output if available
                if ($this->option('detailed')) {
                    $output = stream_get_contents($pipes[1]);
                    $errors = stream_get_contents($pipes[2]);

                    if (! empty($output)) {
                        $this->line("Job {$jobId}: ".trim($output));
                    }
                    if (! empty($errors)) {
                        $this->error("Job {$jobId} Error: ".trim($errors));
                    }
                }

                // Check if process has finished
                if (! $status['running']) {
                    $duration = round(microtime(true) - $processData['start_time'], 2);
                    $exitCode = $status['exitcode'];

                    if ($exitCode === 0) {
                        $this->info("✅ Job {$jobId} completed successfully in {$duration}s ({$processData['symbols']} symbols)");
                    } else {
                        $this->error("❌ Job {$jobId} failed with exit code {$exitCode} after {$duration}s");
                    }

                    // Clean up
                    fclose($pipes[1]);
                    fclose($pipes[2]);
                    proc_close($process);

                    $completedJobs[$jobId] = [
                        'exit_code' => $exitCode,
                        'duration' => $duration,
                        'symbols' => $processData['symbols'],
                    ];
                }
            }

            // Progress update every 10 seconds
            if (time() - $lastProgressUpdate >= 10) {
                $completed = count($completedJobs);
                $total = count($processes);
                $this->info("⏳ Progress: {$completed}/{$total} jobs completed");
                $lastProgressUpdate = time();
            }

            // Short sleep to prevent CPU spinning
            usleep(500000); // 0.5 seconds
        }

        $totalDuration = round(microtime(true) - $startTime, 2);

        // Calculate success metrics
        $successfulJobs = array_filter($completedJobs, fn ($job) => $job['exit_code'] === 0);
        $failedJobs = count($completedJobs) - count($successfulJobs);
        $totalSymbolsProcessed = array_sum(array_column($successfulJobs, 'symbols'));

        // Clean up lock file
        if (file_exists($lockFile)) {
            unlink($lockFile);
        }

        // Report final results
        $this->info('');
        $this->info('📈 Final Results:');
        $this->info("   Total duration: {$totalDuration}s");
        $this->info('   Successful jobs: '.count($successfulJobs).'/'.count($processes));
        $this->info('   Symbols processed: '.number_format($totalSymbolsProcessed));
        $this->info('   Processing rate: '.round($totalSymbolsProcessed / max($totalDuration, 1)).' symbols/second');

        if ($failedJobs > 0) {
            $this->warn("⚠️  {$failedJobs} jobs failed - check logs for details");

            return self::FAILURE;
        }

        $this->info('🎉 All parallel jobs completed successfully!');

        return self::SUCCESS;
    }

    /**
     * Execute single job (fallback for when parallel-jobs=1).
     */
    private function executeSingleJob(string $mode, int $batchSize, float $delay, int $retryAttempts, int $totalSymbols, string $lockFile): int
    {
        $this->info("📡 Executing single job with {$totalSymbols} symbols...");

        // Use the batch command directly with 1 day of data
        $command = sprintf(
            'php artisan market:yfinance-stocks-1min-batch 1 --batch-size=%d --limit=%d',
            $batchSize,
            $totalSymbols
        );

        $startTime = microtime(true);

        // Execute the batch command
        $process = proc_open(
            $command,
            [
                1 => ['pipe', 'w'], // stdout
                2 => ['pipe', 'w'], // stderr
            ],
            $pipes,
            base_path()
        );

        if (! is_resource($process)) {
            $this->error('❌ Failed to start batch process');
            if (file_exists($lockFile)) {
                unlink($lockFile);
            }

            return self::FAILURE;
        }

        // Real-time output processing
        while (true) {
            $stdout = fgets($pipes[1]);
            if ($stdout !== false) {
                $this->line(rtrim($stdout));
            }

            $stderr = fgets($pipes[2]);
            if ($stderr !== false) {
                $this->error(rtrim($stderr));
            }

            // Check if process is still running
            $status = proc_get_status($process);
            if (! $status['running']) {
                // Read remaining output
                while (($stdout = fgets($pipes[1])) !== false) {
                    $this->line(rtrim($stdout));
                }
                while (($stderr = fgets($pipes[2])) !== false) {
                    $this->error(rtrim($stderr));
                }
                break;
            }

            usleep(50000); // 50ms
        }

        fclose($pipes[1]);
        fclose($pipes[2]);

        $returnCode = proc_close($process);
        $duration = round(microtime(true) - $startTime, 2);

        // Clean up lock file
        if (file_exists($lockFile)) {
            unlink($lockFile);
        }

        if ($returnCode === 0) {
            $this->info("✅ Single job completed successfully in {$duration}s");
            $this->showCollectionStats();

            return self::SUCCESS;
        } else {
            $this->error("❌ Single job failed with exit code {$returnCode}");

            return self::FAILURE;
        }
    }

    /**
     * Check if a process is running by PID
     */
    private function isProcessRunning(string $pid): bool
    {
        if (! $pid || ! is_numeric($pid)) {
            return false;
        }

        $result = shell_exec("ps -p {$pid} -o pid= 2>/dev/null");

        return ! empty(trim($result));
    }
}
