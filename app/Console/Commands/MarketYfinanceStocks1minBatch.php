<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class MarketYfinanceStocks1minBatch extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'market:yfinance-stocks-1min-batch 
                            {hours=24 : Hours of historical data to fetch}
                            {--batch-size=50 : Number of symbols to fetch per API request}
                            {--limit= : Maximum number of symbols to process}
                            {--offset=0 : Number of symbols to skip}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Fetch 1-minute stock data using optimized batch requests (multiple symbols per API call)';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        // Remove execution time limit for long-running sync operations
        set_time_limit(0);
        ini_set('memory_limit', '1G');

        $hours = (int) $this->argument('hours');
        $batchSize = (int) $this->option('batch-size');
        $limit = $this->option('limit') ? (int) $this->option('limit') : null;
        $offset = (int) $this->option('offset');

        $this->info('🚀 Starting optimized batch 1-minute stock sync');
        $this->info("   Hours back: {$hours}");
        $this->info("   Batch size: {$batchSize} symbols per API request");

        if ($limit) {
            $this->info("   Limit: {$limit} symbols");
        }
        if ($offset > 0) {
            $this->info("   Offset: {$offset} symbols");
        }

        // Build command arguments
        $pythonScript = base_path('python/yfinance_stocks_1min_batch.py');
        $command = [
            'python',
            $pythonScript,
            (string) $hours,
            (string) $batchSize,
        ];

        // Add limit/offset if specified
        if ($limit !== null || $offset > 0) {
            $command[] = $limit ?? '999999'; // Large number if no limit
            $command[] = (string) $offset;
        }

        // Change to Python directory for proper imports
        $pythonDir = base_path('python');

        // Activate Python environment and run script
        $activationScript = $pythonDir.'/activate.sh';
        $fullCommand = "/bin/bash -c \"cd {$pythonDir} && source {$activationScript} && ".implode(' ', $command).'"';

        $this->info('📡 Executing: '.implode(' ', array_slice($command, 1)));

        $startTime = microtime(true);

        // Execute the Python script
        $output = [];
        $returnCode = 0;
        exec($fullCommand.' 2>&1', $output, $returnCode);

        $duration = round(microtime(true) - $startTime, 2);

        // Display output
        foreach ($output as $line) {
            $this->line($line);
        }

        if ($returnCode === 0) {
            $this->info("✅ Batch sync completed successfully in {$duration}s");

            return self::SUCCESS;
        } else {
            $this->error("❌ Batch sync failed with exit code {$returnCode}");

            return self::FAILURE;
        }
    }
}
