<?php

namespace App\Console\Commands\Market;

use App\Console\Commands\Market\Traits\ManagesCacheAfterDataUpdate;
use Illuminate\Console\Command;
use Symfony\Component\Process\Process;

class YFinanceStocksHourlyBatchCommand extends Command
{
    use ManagesCacheAfterDataUpdate;

    protected $signature = 'market:yfinance-stocks-hourly-batch {hoursBack=48} {--batch-size=100 : Number of symbols per batch request} {--limit= : Limit number of symbols to process} {--offset=0 : Skip first N symbols}';

    protected $description = 'Fetch hourly stock data using optimized batch requests (multiple symbols per API call)';

    public function handle(): int
    {
        $hoursBack = (int) $this->argument('hoursBack');
        $batchSize = (int) $this->option('batch-size');
        $limit = $this->option('limit') ? (int) $this->option('limit') : null;
        $offset = (int) $this->option('offset');

        $pythonDir = base_path('python');
        $venvPath = $pythonDir.'/venv/bin/python';
        $scriptPath = $pythonDir.'/yfinance_stocks_hourly_batch.py';

        // Check if venv exists
        if (! file_exists($venvPath)) {
            $this->error('Python virtual environment not found!');
            $this->line('Please run: cd python && python3 -m venv venv && source venv/bin/activate && pip install -r requirements.txt');

            return self::FAILURE;
        }

        if (! file_exists($scriptPath)) {
            $this->error("Python script not found: {$scriptPath}");

            return self::FAILURE;
        }

        $this->info('=== YFinance Stocks Hourly BATCH Sync ===');
        $this->line("Hours back: {$hoursBack}");
        $this->line("Batch size: {$batchSize} symbols per API request");

        if ($limit) {
            $this->line("Processing limit: {$limit} symbols (offset: {$offset})");
        }

        $this->newLine();

        // Prepare Python command arguments
        $arguments = [
            $venvPath,
            $scriptPath,
            $hoursBack,
            $batchSize,
        ];

        // Add limit and offset if specified
        if ($limit) {
            $arguments[] = $limit;
            $arguments[] = $offset;
        }

        // Start the Python process
        $process = new Process($arguments);
        $process->setWorkingDirectory($pythonDir);
        $process->setTimeout(3600); // 1 hour timeout for hourly batch processing

        try {
            $process->start();

            // Stream output in real-time
            foreach ($process as $type => $buffer) {
                $this->handleProcessOutput($type, $buffer, 'YFinance Stocks Hourly Batch');
            }

            if (! $process->isSuccessful()) {
                $this->error('Python script failed!');
                $this->line('Exit code: '.$process->getExitCode());

                return self::FAILURE;
            }

            $this->info('✓ Stock hourly batch sync completed successfully');

            // Invalidate relevant caches
            $this->invalidateHourlyCaches();
            $this->info('✓ Caches invalidated');

            return self::SUCCESS;

        } catch (\Exception $e) {
            $this->error('Error: '.$e->getMessage());

            return self::FAILURE;
        }
    }

    private function handleProcessOutput($type, $buffer, $prefix): void
    {
        $lines = explode("\n", trim($buffer));
        foreach ($lines as $line) {
            if (! empty(trim($line))) {
                $this->line("[{$prefix}] ".trim($line));
            }
        }
    }

    private function invalidateHourlyCaches(): void
    {
        // Clear hourly prices cache
        $tags = [
            'hourly_prices',
            'asset_pages',
            'market_data',
            'hourly-prices',  // For general hourly price listings
        ];

        foreach ($tags as $tag) {
            try {
                cache()->tags($tag)->flush();
            } catch (\Exception) {
                // Ignore cache errors - not all cache drivers support tags
            }
        }

        // Also clear specific cache patterns used by hourly data
        $patterns = [
            'asset-hourly-*',
            'hourly-prices:*',
            'watch-hourly-*',
            'rising_stocks_hourly_*',
        ];

        foreach ($patterns as $pattern) {
            try {
                cache()->forget($pattern);
            } catch (\Exception) {
                // Ignore cache errors
            }
        }
    }
}
