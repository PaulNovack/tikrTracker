<?php

namespace App\Console\Commands\Market;

use App\Console\Commands\Market\Traits\ManagesCacheAfterDataUpdate;
use App\Models\AssetInfo;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\Process\Process;

class YFinanceCrypto5MinCommand extends Command
{
    use ManagesCacheAfterDataUpdate;

    protected $signature = 'market:yfinance-crypto-5min {hoursBack=24} {--limit= : Limit number of symbols to process} {--offset=0 : Skip first N symbols (for batching)} {--watched-only : Process only watched symbols (overrides config)}';

    protected $description = 'Fetch 5-minute interval cryptocurrency prices from Yahoo Finance using Python yfinance';

    public function handle(): int
    {
        $hoursBack = (int) $this->argument('hoursBack');
        $limit = $this->option('limit') ? (int) $this->option('limit') : null;
        $offset = (int) $this->option('offset');
        $pythonDir = base_path('python');
        $venvPath = $pythonDir.'/venv/bin/python';
        $scriptPath = $pythonDir.'/yfinance_crypto_5min.py';

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

        // Get crypto symbols from database, prioritizing watched symbols first
        $watchedSymbols = $this->getWatchedSymbols('crypto');

        // Check if we should only update watched symbols (for rate limiting)
        // Command line option --watched-only explicitly enables watched mode
        // If batching options (limit/offset) are used, process all symbols instead
        $hasLimitOrOffset = $limit || $offset > 0;
        $explicitWatchedOnly = $this->option('watched-only');
        $configWatchedOnly = (bool) config('app.only_watches_5minutes', false);

        // Watched-only mode is enabled if:
        // 1. Explicitly requested with --watched-only, OR
        // 2. Config is true AND no batching options are used
        $onlyWatched = $explicitWatchedOnly || ($configWatchedOnly && ! $hasLimitOrOffset);

        if ($onlyWatched) {
            $this->info('📍 Only Watched Mode: Processing watched symbols only (rate limiting enabled)');
            $prioritizedSymbols = $watchedSymbols;
            $unwatchedSymbols = [];
        } else {
            if ($hasLimitOrOffset) {
                $this->info("📊 Batch Mode: Processing all symbols with limit={$limit}, offset={$offset}");
            }

            $allSymbols = $this->getAllSymbols('crypto');

            // Remove watched symbols from all symbols to avoid duplicates
            $unwatchedSymbols = array_diff($allSymbols, $watchedSymbols);

            // Combine: watched symbols first, then unwatched
            $prioritizedSymbols = array_merge($watchedSymbols, $unwatchedSymbols);
        }

        // Apply offset and limit to the prioritized list
        if ($offset > 0) {
            $prioritizedSymbols = array_slice($prioritizedSymbols, $offset);
        }

        if ($limit) {
            $prioritizedSymbols = array_slice($prioritizedSymbols, 0, $limit);
        }

        $symbols = $prioritizedSymbols;

        if (empty($watchedSymbols) && empty($unwatchedSymbols)) {
            $this->warn('No crypto symbols found in database. Please run seeders first.');

            return self::FAILURE;
        }

        $this->info('=== YFinance Crypto 5-Minute Interval Sync ===');
        $this->info("Fetching last {$hoursBack} hours of 5-minute crypto data...");

        if ($onlyWatched) {
            $this->info('Mode: Watched symbols only ('.count($watchedSymbols).' symbols)');

            if (! empty($watchedSymbols)) {
                $this->line('📍 <comment>Processing '.count($watchedSymbols).' watched symbols only (rate limiting enabled)...</comment>');
                $this->processCryptoSymbolBatch($watchedSymbols, $hoursBack, 'watched crypto');

                // Clear and warm caches immediately after watched symbols
                $this->invalidateWatchCache('crypto');
                $this->warmCachesAfterDataUpdate('crypto');
            } else {
                $this->warn('No watched symbols found. Nothing to process.');
            }
        } else {
            // Original two-phase processing: watched first, then unwatched

            // Process watched symbols first if any exist
            if (! empty($watchedSymbols)) {
                $watchedToProcess = $watchedSymbols;

                // Apply offset and limit to watched symbols if we're doing batch processing
                if ($offset > 0) {
                    $watchedToProcess = array_slice($watchedToProcess, $offset);
                    $offset = max(0, $offset - count($watchedSymbols)); // Adjust offset for unwatched
                }

                if ($limit) {
                    $limitForWatched = min($limit, count($watchedToProcess));
                    $watchedToProcess = array_slice($watchedToProcess, 0, $limitForWatched);
                    $limit -= $limitForWatched; // Reduce limit for unwatched
                }

                if (! empty($watchedToProcess)) {
                    $this->line('📈 <comment>Processing '.count($watchedToProcess).' watched symbols first...</comment>');
                    $this->processCryptoSymbolBatch($watchedToProcess, $hoursBack, 'watched crypto');

                    // Clear and warm caches immediately after watched symbols
                    $this->invalidateWatchCache('crypto');
                    $this->warmCachesAfterDataUpdate('crypto');
                }
            }

            // Process remaining unwatched symbols if limit allows
            if ($limit === null || $limit > 0) {
                $unwatchedToProcess = $unwatchedSymbols;

                if ($offset > 0) {
                    $unwatchedToProcess = array_slice($unwatchedToProcess, $offset);
                }

                if ($limit) {
                    $unwatchedToProcess = array_slice($unwatchedToProcess, 0, $limit);
                }

                if (! empty($unwatchedToProcess)) {
                    $this->line('🔄 <comment>Processing '.count($unwatchedToProcess).' unwatched symbols...</comment>');
                    $this->processCryptoSymbolBatch($unwatchedToProcess, $hoursBack, 'unwatched crypto');

                    // Clear caches again after unwatched symbols (no need to warm again)
                    $this->invalidateWatchCache('crypto');
                }
            }
        }

        return self::SUCCESS;
    }

    /**
     * Process a batch of crypto symbols through the Python script
     */
    private function processCryptoSymbolBatch(array $symbols, int $hoursBack, string $description): void
    {
        if (empty($symbols)) {
            return;
        }

        $pythonDir = base_path('python');
        $venvPath = $pythonDir.'/venv/bin/python';
        $scriptPath = $pythonDir.'/yfinance_crypto_5min.py';

        // Add -USD suffix for crypto symbols
        $symbolsWithSuffix = array_map(fn ($symbol) => $symbol.'-USD', $symbols);
        $symbolsEnv = implode(',', $symbolsWithSuffix);

        $this->info('Processing '.count($symbols)." {$description}...");
        $this->newLine();

        // Calculate timeout based on symbol count (min 30 minutes, 2 seconds per symbol)
        $timeoutSeconds = max(1800, count($symbols) * 2);
        $timeoutMinutes = round($timeoutSeconds / 60);

        $this->info("Estimated timeout: {$timeoutMinutes} minutes for ".count($symbols).' symbols');
        $this->newLine();

        // Run Python script with venv Python
        $process = new Process(
            [$venvPath, $scriptPath, (string) $hoursBack],
            $pythonDir,
            ['SYMBOLS' => $symbolsEnv], // Pass symbols via environment variable
            null,
            $timeoutSeconds
        );

        $process->run(function ($type, $buffer) use ($description) {
            $this->handleProcessOutput($type, $buffer, "YFinance Crypto 5min - {$description}");
        });

        if (! $process->isSuccessful()) {
            $this->error("Python script failed for {$description}!");
            $this->error($process->getErrorOutput());

            return;
        }

        $this->newLine();
        $this->info("✓ {$description} sync completed successfully");
        $this->newLine();
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

    /**
     * Get symbols that are being watched by users for the given asset type
     */
    private function getWatchedSymbols(string $assetType): array
    {
        return \DB::table('watches')
            ->join('asset_info', 'watches.asset_info_id', '=', 'asset_info.id')
            ->where('asset_info.asset_type', $assetType)
            ->whereNull('asset_info.deleted_at')
            ->distinct()
            ->pluck('asset_info.symbol')
            ->toArray();
    }

    /**
     * Get all symbols for the given asset type
     */
    private function getAllSymbols(string $assetType): array
    {
        return AssetInfo::where('asset_type', $assetType)
            ->whereNull('deleted_at')
            ->orderBy('symbol')
            ->pluck('symbol')
            ->toArray();
    }
}
