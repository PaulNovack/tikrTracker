<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class MarketYfinanceStocks1minWatched extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'market:yfinance-stocks-1min-watched 
                            {hours=2 : Hours of historical data to fetch}
                            {--batch-size=10 : Number of symbols to fetch per API request}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Fetch 1-minute data for watched stock symbols only (rate limiting friendly)';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        // Remove execution time limit for long-running sync operations
        set_time_limit(0);
        ini_set('memory_limit', '512M');

        $hours = (int) $this->argument('hours');
        $batchSize = (int) $this->option('batch-size');

        $this->info('🚀 Starting 1-minute sync for WATCHED SYMBOLS ONLY');
        $this->info("   Hours back: {$hours}");
        $this->info("   Batch size: {$batchSize} symbols per API request");
        $this->info('   Target: User-watched stocks (rate limit friendly)');

        // Build command arguments
        $pythonScript = base_path('python/yfinance_stocks_1min_watched.py');
        $command = [
            'python',
            $pythonScript,
            (string) $hours,
            (string) $batchSize,
        ];

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
            $this->info("✅ Watched symbols 1-minute sync completed in {$duration}s");

            return self::SUCCESS;
        } else {
            $this->error("❌ Watched symbols 1-minute sync failed with exit code {$returnCode}");

            return self::FAILURE;
        }
    }
}
