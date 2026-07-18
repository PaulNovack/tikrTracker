<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class MarketYfinanceStocks1minContinuous extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'market:yfinance-stocks-1min-continuous 
                            {--batch-size=100 : Symbols per batch request}
                            {--max-symbols= : Maximum symbols to process (for testing)}
                            {--delay=0.5 : Delay between batches in seconds}
                            {--fast : Use faster settings for real-time updates}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Continuous 1-minute data sync for stocks with 1-minute processing enabled (top 1500 most liquid)';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        // Check if another instance is already running
        $lockFile = storage_path('app/yfinance-1min-continuous.lock');

        if (file_exists($lockFile)) {
            $pid = trim(file_get_contents($lockFile));

            if ($this->isProcessRunning($pid)) {
                $this->warn('⏩ Another 1-minute continuous sync already running (PID: '.$pid.')');

                Log::channel('scheduled')->info('[1-Min Continuous Sync] Skipped - already running', [
                    'running_pid' => $pid,
                    'attempted_at' => now()->toISOString(),
                ]);

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

        // Set execution limits
        set_time_limit(300); // 5 minutes max
        ini_set('memory_limit', '512M');

        $batchSize = (int) $this->option('batch-size');
        $maxSymbols = $this->option('max-symbols') ? (int) $this->option('max-symbols') : null;
        $delay = (float) $this->option('delay');
        $fast = $this->option('fast');

        if ($fast) {
            $batchSize = min($batchSize * 2, 200); // Double batch size
            $delay = max($delay * 0.5, 0.2); // Halve delay
            $this->info("🚀 Fast mode: batch-size={$batchSize}, delay={$delay}s");
        }

        // Get 1-minute enabled symbols count
        $totalSymbols = DB::table('asset_info')
            ->where('asset_type', 'stock')
            ->where('1_min', 1)
            ->whereNull('deleted_at')
            ->count();

        if ($maxSymbols) {
            $totalSymbols = min($totalSymbols, $maxSymbols);
        }

        $this->info('🎯 Starting 1-minute continuous sync for 1-minute enabled symbols');
        $this->info('   1-minute enabled symbols: '.number_format($totalSymbols));
        $this->info("   Batch size: {$batchSize}");
        $this->info("   Delay: {$delay}s");

        Log::channel('scheduled')->info('[1-Min Continuous Sync] Starting', [
            'pid' => getmypid(),
            'total_symbols' => $totalSymbols,
            'batch_size' => $batchSize,
            'delay' => $delay,
            'fast_mode' => $fast,
            'started_at' => now()->toISOString(),
            'filter' => '1_min=1 (top 1500 most liquid stocks)',
        ]);

        $startTime = microtime(true);

        // Use the simple Python script in high-volume mode
        $pythonScript = base_path('python/yfinance_stocks_1min_simple.py');
        $pythonDir = base_path('python');

        $mode = $maxSymbols ? 'high_volume' : 'full'; // Use high_volume for testing, full for production

        $command = [
            'python3', 'yfinance_stocks_1min_simple.py',
            '--mode='.$mode,
            '--batch-size='.$batchSize,
            '--delay='.$delay,
            '--retry-attempts=2',
        ];

        // Change to Python directory for proper imports and environment
        $pythonDir = base_path('python');
        $activationScript = $pythonDir.'/activate.sh';

        // Build full command with environment activation (same as other commands)
        if (file_exists($activationScript)) {
            $fullCommand = "/bin/bash -c \"cd {$pythonDir} && source {$activationScript} && ".implode(' ', $command).'"';
        } else {
            $fullCommand = "/bin/bash -c \"cd {$pythonDir} && ".implode(' ', $command).'"';
        }

        $this->info('📡 Executing 1-minute data collection...');

        if ($this->option('verbose')) {
            $this->line("Command: {$fullCommand}");
        }

        // Execute with real-time output
        $process = proc_open(
            $fullCommand,
            [
                1 => ['pipe', 'w'], // stdout
                2 => ['pipe', 'w'], // stderr
            ],
            $pipes
        );

        if (! is_resource($process)) {
            $this->error('❌ Failed to start Python process');

            if (file_exists($lockFile)) {
                unlink($lockFile);
            }

            return 1;
        }

        // Read output in real-time
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $output = '';
        $errorOutput = '';

        while (true) {
            $status = proc_get_status($process);

            // Read stdout
            if ($stdout = stream_get_contents($pipes[1])) {
                $output .= $stdout;
                if ($this->option('verbose')) {
                    $this->line($stdout);
                }
            }

            // Read stderr
            if ($stderr = stream_get_contents($pipes[2])) {
                $errorOutput .= $stderr;
                if ($this->option('verbose')) {
                    $this->error($stderr);
                }
            }

            if (! $status['running']) {
                break;
            }

            usleep(100000); // 0.1 second
        }

        // Get remaining output
        if ($stdout = stream_get_contents($pipes[1])) {
            $output .= $stdout;
            if ($this->option('verbose')) {
                $this->line($stdout);
            }
        }
        if ($stderr = stream_get_contents($pipes[2])) {
            $errorOutput .= $stderr;
            if ($this->option('verbose')) {
                $this->error($stderr);
            }
        }

        fclose($pipes[1]);
        fclose($pipes[2]);

        $returnCode = proc_close($process);
        $duration = round(microtime(true) - $startTime, 2);

        // Parse results from output
        $successCount = 0;
        $failureCount = 0;

        if (preg_match('/Successful: (\d+)/', $output, $matches)) {
            $successCount = (int) $matches[1];
        }
        if (preg_match('/Failed: (\d+)/', $output, $matches)) {
            $failureCount = (int) $matches[1];
        }

        // Remove lock file
        if (file_exists($lockFile)) {
            unlink($lockFile);
        }

        if ($returnCode === 0) {
            $this->info("✅ 1-minute continuous sync completed in {$duration}s");
            $this->info('   Successful: '.number_format($successCount));
            if ($failureCount > 0) {
                $this->warn("   Failed: {$failureCount}");
            }

            Log::channel('scheduled')->info('[1-Min Continuous Sync] Completed successfully', [
                'duration_seconds' => $duration,
                'successful_symbols' => $successCount,
                'failed_symbols' => $failureCount,
                'completed_at' => now()->toISOString(),
            ]);

            return 0;
        } else {
            $this->error("❌ 1-minute continuous sync failed (exit code: {$returnCode})");

            if (! empty($errorOutput)) {
                $this->error('Error output: '.trim($errorOutput));
            }

            Log::channel('scheduled')->error('[1-Min Continuous Sync] Failed', [
                'exit_code' => $returnCode,
                'duration_seconds' => $duration,
                'error_output' => trim($errorOutput),
                'failed_at' => now()->toISOString(),
            ]);

            return 1;
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
