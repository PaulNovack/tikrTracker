<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class MarketYfinanceRotatingSync extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'market:yfinance-rotating-sync 
                            {--chunk-size=200 : Number of symbols per chunk}
                            {--hours=1 : Hours of data to fetch}
                            {--batch-size=50 : Symbols per API request}
                            {--reset : Reset rotation counter to start from beginning}
                            {--status : Show current rotation status}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Intelligently rotate through all stock symbols in chunks for continuous 5-minute data updates';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $chunkSize = (int) $this->option('chunk-size');
        $hours = (int) $this->option('hours');
        $batchSize = (int) $this->option('batch-size');
        $reset = $this->option('reset');
        $status = $this->option('status');

        // Get total count of active stock symbols
        $totalSymbols = DB::table('asset_info')
            ->where('asset_type', 'stock')
            ->whereNull('deleted_at')
            ->count();

        if ($totalSymbols === 0) {
            $this->error('❌ No stock symbols found in database');

            return self::FAILURE;
        }

        $totalChunks = (int) ceil($totalSymbols / $chunkSize);
        $cacheKey = 'yfinance_rotation_chunk';

        if ($reset) {
            Cache::forget($cacheKey);
            $this->info('🔄 Rotation counter reset to beginning');

            return self::SUCCESS;
        }

        // Get current chunk number (0-based)
        $currentChunk = Cache::get($cacheKey, 0);

        if ($status) {
            $this->displayStatus($totalSymbols, $totalChunks, $currentChunk, $chunkSize);

            return self::SUCCESS;
        }

        // Calculate offset and ensure we don't exceed total symbols
        $offset = $currentChunk * $chunkSize;

        if ($offset >= $totalSymbols) {
            // We've completed all chunks, reset to beginning
            $currentChunk = 0;
            $offset = 0;
            $this->info('🔄 Completed full rotation, starting from beginning');
        }

        // Calculate actual limit (don't exceed available symbols)
        $actualLimit = min($chunkSize, $totalSymbols - $offset);

        $this->info('🚀 Starting rotating 5-minute sync');
        $this->info('   Chunk: {'.($currentChunk + 1)."}/{$totalChunks}");
        $this->info("   Symbols: {$actualLimit} (offset: {$offset})");
        $this->info("   Hours: {$hours}");
        $this->info("   Batch size: {$batchSize}");

        // Execute the batch command with calculated parameters
        $exitCode = $this->call('market:yfinance-stocks-5min-batch', [
            'hours' => $hours,
            '--batch-size' => $batchSize,
            '--limit' => $actualLimit,
            '--offset' => $offset,
        ]);

        if ($exitCode === 0) {
            // Move to next chunk for next execution
            $nextChunk = ($currentChunk + 1) % $totalChunks;
            Cache::put($cacheKey, $nextChunk, now()->addDays(30));

            $coverage = round((($currentChunk + 1) / $totalChunks) * 100, 1);
            $this->info('✅ Chunk completed successfully');
            $this->info("📊 Progress: {$coverage}% of total symbols covered");

            if ($nextChunk === 0) {
                $this->info('🎉 Full rotation completed! Next run will start from beginning.');
            } else {
                $this->info('➡️  Next run will process chunk '.($nextChunk + 1)."/{$totalChunks}");
            }

            return self::SUCCESS;
        } else {
            $this->error('❌ Batch sync failed, not advancing chunk counter');

            return self::FAILURE;
        }
    }

    /**
     * Display current rotation status.
     */
    private function displayStatus(int $totalSymbols, int $totalChunks, int $currentChunk, int $chunkSize): void
    {
        $this->info('📊 Rotation Status');
        $this->info("   Total symbols: {$totalSymbols}");
        $this->info("   Chunk size: {$chunkSize}");
        $this->info("   Total chunks: {$totalChunks}");
        $this->info('   Current chunk: '.($currentChunk + 1)."/{$totalChunks}");

        $progress = round(($currentChunk / $totalChunks) * 100, 1);
        $this->info("   Progress: {$progress}%");

        $offset = $currentChunk * $chunkSize;
        $endRange = min($offset + $chunkSize - 1, $totalSymbols - 1);
        $this->info("   Next batch: symbols {$offset}-{$endRange}");

        // Estimate full rotation time
        $estimatedMinutes = $totalChunks * 5; // Assuming 5-minute intervals
        $estimatedHours = round($estimatedMinutes / 60, 1);
        $this->info("   Full rotation time: ~{$estimatedHours} hours ({$totalChunks} chunks × 5min)");
    }
}
