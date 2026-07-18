<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class BackfillOneMinuteIndicators extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'backfill:one-minute-indicators
                            {--symbol= : Filter by specific symbol (e.g., AAPL)}
                            {--asset-type= : Filter by asset type (stock or crypto)}
                            {--from-date= : Start date (YYYY-MM-DD)}
                            {--to-date= : End date (YYYY-MM-DD)}
                            {--limit= : Maximum records to process}
                            {--batch-size=100 : Batch size for commits}
                            {--table=one_minute_prices : Target table (one_minute_prices or one_minute_prices_full)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Backfill technical indicators (VWAP, EMA, ATR) for existing one_minute_prices records';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        set_time_limit(0);
        ini_set('memory_limit', '2G');

        $this->info('🚀 Starting one_minute_prices technical indicators backfill');
        $this->info('');

        $pythonDir = base_path('python');
        $pythonScript = $pythonDir.'/backfill_one_minute_indicators.py';
        $venvPython = $pythonDir.'/venv/bin/python';

        if (! file_exists($venvPython)) {
            $this->error("Python venv not found at: {$venvPython}");

            return 1;
        }

        $command = [$pythonScript];

        // Add optional filters
        if ($symbol = $this->option('symbol')) {
            $command[] = "--symbol={$symbol}";
            $this->info("   Symbol filter: {$symbol}");
        }

        if ($assetType = $this->option('asset-type')) {
            $command[] = "--asset-type={$assetType}";
            $this->info("   Asset type filter: {$assetType}");
        }

        if ($fromDate = $this->option('from-date')) {
            $command[] = "--from-date={$fromDate}";
            $this->info("   From date: {$fromDate}");
        }

        if ($toDate = $this->option('to-date')) {
            $command[] = "--to-date={$toDate}";
            $this->info("   To date: {$toDate}");
        }

        if ($limit = $this->option('limit')) {
            $command[] = "--limit={$limit}";
            $this->info("   Limit: {$limit} records");
        }

        $batchSize = $this->option('batch-size');
        $command[] = "--batch-size={$batchSize}";
        $this->info("   Batch size: {$batchSize}");

        $table = $this->option('table') ?: 'one_minute_prices';
        $command[] = "--table={$table}";
        $this->info("   Table: {$table}");

        $this->info('');

        $fullCommand = [$venvPython, $pythonScript];
        foreach (array_slice($command, 2) as $arg) {
            $fullCommand[] = $arg;
        }

        $this->info('📡 Executing Python backfill script...');
        $this->info('');

        $startTime = microtime(true);

        // Execute the Python script with real-time output
        $descriptors = [
            0 => ['pipe', 'r'], // stdin
            1 => ['pipe', 'w'], // stdout
            2 => ['pipe', 'w'],  // stderr
        ];

        $process = proc_open($fullCommand, $descriptors, $pipes, $pythonDir);

        if (is_resource($process)) {
            fclose($pipes[0]); // Close stdin

            // Read output in real-time
            stream_set_blocking($pipes[1], false);
            stream_set_blocking($pipes[2], false);

            while (true) {
                $stdout = fgets($pipes[1]);
                $stderr = fgets($pipes[2]);

                if ($stdout !== false) {
                    $this->line(rtrim($stdout));
                }

                if ($stderr !== false) {
                    $this->error(rtrim($stderr));
                }

                // Check if process is still running
                $status = proc_get_status($process);
                if (! $status['running']) {
                    // Read any remaining output
                    while ($stdout = fgets($pipes[1])) {
                        $this->line(rtrim($stdout));
                    }
                    while ($stderr = fgets($pipes[2])) {
                        $this->error(rtrim($stderr));
                    }
                    break;
                }

                usleep(10000); // Sleep 10ms to avoid busy waiting
            }

            fclose($pipes[1]);
            fclose($pipes[2]);

            $returnCode = proc_close($process);
        } else {
            $this->error('❌ Failed to start Python process');

            return self::FAILURE;
        }

        $duration = round(microtime(true) - $startTime, 2);

        $this->info('');

        if ($returnCode === 0) {
            $this->info("✅ Backfill completed successfully in {$duration}s");

            return self::SUCCESS;
        } else {
            $this->error("❌ Backfill failed with exit code {$returnCode}");

            return self::FAILURE;
        }
    }
}
