<?php

namespace App\Console\Commands\Market;

use App\Console\Commands\Market\Traits\ManagesCacheAfterDataUpdate;
use App\Models\AssetInfo;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\Process\Process;

class YFinanceCryptoHourlyBatchCommand extends Command
{
    use ManagesCacheAfterDataUpdate;

    protected $signature = 'market:yfinance-crypto-hourly-batch 
                            {hoursBack=48 : Hours of historical data to fetch}
                            {--batch-size=6 : Number of crypto symbols to process in each batch (all 6 cryptos in 1 API call)}
                            {--limit= : Limit number of symbols to process} 
                            {--offset=0 : Skip first N symbols (for batching)}';

    protected $description = 'Ultra-efficient crypto hourly batch sync - processes all 6 cryptos in 1 API call (83-90% API reduction)';

    public function handle(): int
    {
        $hoursBack = (int) $this->argument('hoursBack');
        $batchSize = (int) $this->option('batch-size');
        $limit = $this->option('limit') ? (int) $this->option('limit') : null;
        $offset = (int) $this->option('offset');

        $pythonDir = base_path('python');
        $venvPath = $pythonDir.'/venv/bin/python';
        $scriptPath = $pythonDir.'/yfinance_crypto_hourly_batch.py';

        // Validation
        if ($hoursBack < 2) {
            $this->error('Hours back must be at least 2');

            return self::FAILURE;
        }

        if ($batchSize < 1) {
            $this->error('Batch size must be at least 1');

            return self::FAILURE;
        }

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

        // Get crypto symbols from database with -USD suffix for yfinance
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
            ->map(fn ($symbol) => $symbol.'-USD') // Add -USD suffix for yfinance API
            ->toArray();

        if (empty($symbols)) {
            $this->warn('No crypto symbols found in database. Please run CryptoAssetsSeeder first.');

            return self::FAILURE;
        }

        // Calculate API efficiency
        $totalSymbols = count($symbols);
        $expectedBatches = (int) ceil($totalSymbols / $batchSize);
        $apiReduction = round((($totalSymbols - $expectedBatches) / $totalSymbols) * 100, 1);

        $this->info('=== Ultra-Efficient Crypto Hourly Batch Sync ===');
        $this->info("📅 Fetching last {$hoursBack} hours of crypto data...");
        $this->info("📈 Symbols: {$totalSymbols} cryptocurrencies from database");
        $this->info("🔄 Batch size: {$batchSize} symbols per API call");
        $this->info("🚀 Expected API calls: {$expectedBatches} (vs {$totalSymbols} individual calls)");
        $this->info("💰 API reduction: {$apiReduction}% fewer calls than individual processing");

        if ($limit || $offset > 0) {
            $this->info("📊 Batch processing: offset={$offset}, limit=".($limit ?: 'none'));
        }

        $this->newLine();

        // Calculate timeout based on batch count and complexity
        // More generous timeout for batch processing (min 10 minutes, 30 seconds per batch)
        $timeoutSeconds = max(600, $expectedBatches * 30);

        $startTime = microtime(true);

        // Create symbols environment variable for Python script
        $symbolsEnv = implode(',', $symbols);

        // Run Python script with batch size parameter
        $process = new Process(
            [$venvPath, $scriptPath, (string) $hoursBack, (string) $batchSize],
            $pythonDir,
            ['SYMBOLS' => $symbolsEnv], // Pass symbols via environment variable
            null,
            $timeoutSeconds
        );

        $process->run(function ($type, $buffer) {
            $this->handleProcessOutput($type, $buffer, 'Crypto Hourly Batch');
        });

        $executionTime = round(microtime(true) - $startTime, 2);

        if (! $process->isSuccessful()) {
            $this->error('Python batch script failed!');
            $this->error($process->getErrorOutput());

            return self::FAILURE;
        }

        $this->newLine();
        $this->info("✅ Crypto hourly batch sync completed successfully in {$executionTime}s");
        $this->info("🎯 Processing efficiency: {$totalSymbols} symbols in {$expectedBatches} API calls");

        // Invalidate watch cache for all crypto assets
        $this->invalidateWatchCache($symbols, 'crypto');

        // Warm caches after data update
        $this->warmCachesAfterDataUpdate('crypto');

        return self::SUCCESS;
    }

    /**
     * Handle process output with enhanced formatting for batch operations.
     */
    private function handleProcessOutput(string $type, string $buffer, string $prefix): void
    {
        $lines = explode("\n", trim($buffer));

        foreach ($lines as $line) {
            if (empty(trim($line))) {
                continue;
            }

            // Enhanced formatting for batch operations
            if (strpos($line, 'BATCH API CALL') !== false) {
                $this->line("<fg=yellow>🚀 [BATCH] {$line}</>");
            } elseif (strpos($line, 'Batch Results') !== false) {
                $this->line("<fg=green>📊 [RESULTS] {$line}</>");
            } elseif (strpos($line, 'API Efficiency') !== false) {
                $this->line("<fg=cyan>💰 [EFFICIENCY] {$line}</>");
            } elseif (strpos($line, 'ERROR:') !== false || strpos($line, '❌') !== false) {
                $this->line("<fg=red>[ERROR] {$line}</>");
            } elseif (strpos($line, '✅') !== false || strpos($line, 'completed') !== false) {
                $this->line("<fg=green>[SUCCESS] {$line}</>");
            } elseif (strpos($line, '⚠️') !== false || strpos($line, 'WARNING') !== false) {
                $this->line("<fg=yellow>[WARNING] {$line}</>");
            } else {
                $this->line("[{$prefix}] {$line}");
            }
        }
    }

    /**
     * Invalidate watch controller cache for crypto symbols.
     */
    private function invalidateWatchCache(array $symbols, string $assetType): void
    {
        $this->info('🗑️  Invalidating crypto watch cache...');

        foreach ($symbols as $symbol) {
            Cache::forget(sprintf('watch-chart-data:%s:%s', $symbol, $assetType));
            Cache::forget(sprintf('watch-price-stats:%s:%s', $symbol, $assetType));
            Cache::forget(sprintf('watch-latest-price:%s:%s', $symbol, $assetType));
        }

        $this->info("✅ Invalidated cache for {$assetType} symbols: ".implode(', ', $symbols));
    }
}
