<?php

namespace App\Console\Commands\Market;

use App\Console\Commands\Market\Traits\ManagesCacheAfterDataUpdate;
use App\Models\AssetInfo;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\Process\Process;

class YFinanceCryptoHourlyCommand extends Command
{
    use ManagesCacheAfterDataUpdate;

    protected $signature = 'market:yfinance-crypto-hourly {hoursBack=48} {--limit= : Limit number of symbols to process} {--offset=0 : Skip first N symbols (for batching)}';

    protected $description = 'Fetch hourly crypto prices from Yahoo Finance using Python yfinance';

    public function handle(): int
    {
        $hoursBack = (int) $this->argument('hoursBack');
        $limit = $this->option('limit') ? (int) $this->option('limit') : null;
        $offset = (int) $this->option('offset');
        $pythonDir = base_path('python');
        $venvPath = $pythonDir.'/venv/bin/python';
        $scriptPath = $pythonDir.'/yfinance_crypto_hourly.py';

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

        // Get crypto symbols from database with optional batching
        $query = AssetInfo::where('asset_type', 'crypto')
            ->whereNull('deleted_at')
            ->orderBy('symbol');

        if ($offset > 0) {
            $query->skip($offset);
        }

        if ($limit) {
            $query->take($limit);
        }

        $symbols = $query->pluck('symbol')
            ->map(fn ($symbol) => $symbol.'-USD')
            ->toArray();

        if (empty($symbols)) {
            $this->warn('No crypto symbols found in database. Please run CryptoAssetsSeeder first.');

            return self::FAILURE;
        }

        $symbolsEnv = implode(',', $symbols);

        $this->info('=== YFinance Crypto Hourly Sync ===');
        $this->info("Fetching last {$hoursBack} hours of crypto data...");
        $this->info('Symbols: '.count($symbols).' cryptocurrencies from database');
        if ($limit || $offset > 0) {
            $this->info("Batch processing: offset={$offset}, limit=".($limit ?: 'none'));
        }
        $this->newLine();

        // Calculate timeout based on symbol count (min 30 minutes, 2 seconds per symbol)
        $timeoutSeconds = max(1800, count($symbols) * 2);

        // Run Python script with venv Python
        $process = new Process(
            [$venvPath, $scriptPath, (string) $hoursBack],
            $pythonDir,
            ['SYMBOLS' => $symbolsEnv], // Pass symbols via environment variable
            null,
            $timeoutSeconds
        );

        $process->run(function ($type, $buffer) {
            $this->handleProcessOutput($type, $buffer, 'YFinance Crypto Hourly');
        });

        if (! $process->isSuccessful()) {
            $this->error('Python script failed!');
            $this->error($process->getErrorOutput());

            return self::FAILURE;
        }

        $this->newLine();
        $this->info('✓ Crypto hourly sync completed successfully');

        // Invalidate watch cache for all crypto assets since data has been updated
        $this->invalidateWatchCache('crypto');

        // Warm caches after data update for better page performance
        $this->warmCachesAfterDataUpdate('crypto');

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
