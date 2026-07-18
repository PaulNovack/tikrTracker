<?php

namespace App\Console\Commands\Market;

use App\Console\Commands\Market\Traits\ManagesCacheAfterDataUpdate;
use App\Models\AssetInfo;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\Process\Process;

class YFinanceStocksHourlyCommand extends Command
{
    use ManagesCacheAfterDataUpdate;

    protected $signature = 'market:yfinance-stocks-hourly {hoursBack=48} {--limit= : Limit number of symbols to process} {--offset=0 : Skip first N symbols (for batching)}';

    protected $description = 'Fetch hourly stock prices from Yahoo Finance using Python yfinance';

    public function handle(): int
    {
        $hoursBack = (int) $this->argument('hoursBack');
        $limit = $this->option('limit') ? (int) $this->option('limit') : null;
        $offset = (int) $this->option('offset');

        $pythonDir = base_path('python');
        $venvPath = $pythonDir.'/venv/bin/python';
        $scriptPath = $pythonDir.'/yfinance_stocks_hourly.py';

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

        $this->info('=== YFinance Stocks Hourly Sync ===');
        $this->info("Fetching last {$hoursBack} hours of stock data...");

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
            [$venvPath, $scriptPath, (string) $hoursBack],
            $pythonDir,
            ['SYMBOLS' => $symbolsEnv], // Pass symbols via environment variable
            null,
            $timeoutSeconds
        );

        $process->run(function ($type, $buffer) {
            $this->handleProcessOutput($type, $buffer, 'YFinance Stocks Hourly');
        });

        if (! $process->isSuccessful()) {
            $this->error('Python script failed!');
            $this->error($process->getErrorOutput());

            return self::FAILURE;
        }

        $this->newLine();
        $this->info('✓ Stock hourly sync completed successfully');

        // Invalidate watch cache for all stock assets since data has been updated
        $this->invalidateWatchCache('stock');

        // Warm caches after data update for better page performance
        $this->warmCachesAfterDataUpdate('stock');

        return self::SUCCESS;
    }

    /**
     * Invalidate watch controller cache for the given asset type.
     * This ensures users see fresh data when new market prices are fetched.
     */
    private function invalidateWatchCache(string $assetType): void
    {
        $this->info('Invalidating watch cache...');

        $symbols = AssetInfo::where('asset_type', $assetType)
            ->whereNull('deleted_at')
            ->pluck('symbol')
            ->toArray();

        foreach ($symbols as $symbol) {
            Cache::forget(sprintf('watch-chart-data:%s:%s', $symbol, $assetType));
            Cache::forget(sprintf('watch-price-stats:%s:%s', $symbol, $assetType));
            Cache::forget(sprintf('watch-latest-price:%s:%s', $symbol, $assetType));
        }

        $this->info('✓ Invalidated cache for '.count($symbols)." {$assetType} symbols");
    }
}
