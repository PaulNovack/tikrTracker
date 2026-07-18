<?php

namespace App\Console\Commands\Market;

use App\Console\Commands\Market\Traits\ManagesCacheAfterDataUpdate;
use App\Models\AssetInfo;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\Process\Process;

class YFinanceStocksDailyCommand extends Command
{
    use ManagesCacheAfterDataUpdate;

    protected $signature = 'market:yfinance-stocks-daily {daysBack=90} {--limit= : Limit number of symbols to process} {--offset=0 : Skip first N symbols (for batching)}';

    protected $description = 'Fetch daily stock prices from Yahoo Finance using Python yfinance';

    public function handle(): int
    {
        $daysBack = (int) $this->argument('daysBack');
        $limit = $this->option('limit') ? (int) $this->option('limit') : null;
        $offset = (int) $this->option('offset');

        $pythonDir = base_path('python');
        $venvPath = $pythonDir.'/venv/bin/python';
        $scriptPath = $pythonDir.'/yfinance_stocks_daily.py';

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

        // Get stock symbols from database
        $symbolsQuery = AssetInfo::where('asset_type', 'stock')
            ->whereNull('deleted_at')
            ->orderBy('symbol');

        if ($limit) {
            $symbolsQuery->skip($offset)->take($limit);
        }

        $symbols = $symbolsQuery->pluck('symbol')->toArray();

        if (empty($symbols)) {
            $this->warn('No stock symbols found in database. Please run seeders first.');

            return self::FAILURE;
        }

        $symbolsEnv = implode(',', $symbols);

        $this->info('=== YFinance Stocks Daily Sync ===');
        $this->info("Fetching last {$daysBack} days of stock data...");

        if ($limit) {
            $this->info('Symbols: '.count($symbols)." stocks (batch: offset {$offset}, limit {$limit})");
        } else {
            $this->info('Symbols: '.count($symbols).' stocks from database');
        }

        $this->newLine();

        // Calculate timeout based on number of symbols (roughly 1 second per symbol + buffer)
        $symbolCount = count($symbols);
        $timeoutSeconds = max(1800, $symbolCount * 2); // Minimum 30 minutes, or 2 seconds per symbol
        $timeoutMinutes = round($timeoutSeconds / 60);

        $this->info("Estimated timeout: {$timeoutMinutes} minutes for {$symbolCount} symbols");
        $this->newLine();

        // Run Python script with venv Python
        $process = new Process(
            [$venvPath, $scriptPath, (string) $daysBack],
            $pythonDir,
            ['SYMBOLS' => $symbolsEnv], // Pass symbols via environment variable
            null,
            $timeoutSeconds
        );

        $process->run(function ($type, $buffer) {
            $this->handleProcessOutput($type, $buffer, 'YFinance Stocks Daily');
        });

        if (! $process->isSuccessful()) {
            $this->error('Python script failed!');
            $this->error($process->getErrorOutput());

            return self::FAILURE;
        }

        $this->newLine();
        $this->info('✓ Stock daily sync completed successfully');

        // Clear cached daily prices for stocks
        // Since we're using database cache driver (no tags support),
        // we'll use Laravel's cache flush or let cache expire naturally
        $this->clearDailyPricesCache('stock');
        $this->info('✓ Cache cleared for stock daily prices');

        // Warm caches after data update for better page performance
        $this->warmCachesAfterDataUpdate('stock');

        return self::SUCCESS;
    }

    /**
     * Clear daily prices cache for a specific asset type.
     */
    private function clearDailyPricesCache(string $assetType): void
    {
        // Clear cache entries by pattern (database driver doesn't support tags)
        // We'll clear common cache keys - cache will auto-expire after 5 minutes anyway
        foreach (['stock', 'all'] as $type) {
            for ($page = 1; $page <= 10; $page++) {
                Cache::forget("daily-prices:type={$type}:symbol=all:page={$page}");
            }
        }
    }
}
