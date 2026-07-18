<?php

namespace App\Console\Commands\Market\Traits;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

trait ManagesCacheAfterDataUpdate
{
    /**
     * Warm caches after market data has been updated
     */
    protected function warmCachesAfterDataUpdate(string $assetType = 'all'): void
    {
        $this->info('🔥 Warming caches after data update...');

        // Invalidate and warm rising stocks cache
        $this->warmRisingStocksCache($assetType);

        // Clear stagnation analysis caches since new price data affects calculations
        $this->clearStagnationCaches();

        // Clear any existing technical analysis cache that might be stale
        $this->warmTechnicalAnalysisCache();

        $this->info('✅ Cache warming completed!');
    }

    /**
     * Warm rising stocks cache for the given asset type
     */
    private function warmRisingStocksCache(string $assetType): void
    {
        $assetTypes = $assetType === 'all' ? ['stock', 'crypto'] : [$assetType];

        foreach ($assetTypes as $type) {
            // Clear existing cache first
            $cacheKey = "rising-stocks-{$type}-".now()->format('Y-m-d-H');
            Cache::forget($cacheKey);

            $this->line("  - Warming rising stocks cache for: {$type}");
        }

        // Run the cache warming command
        try {
            $assetTypesParam = implode(',', $assetTypes);
            Artisan::call('market:warm-rising-cache', [
                '--asset-types' => $assetTypesParam,
            ]);

            $this->line('  ✓ Rising stocks cache warmed');
        } catch (\Exception $e) {
            $this->warn("  ⚠ Failed to warm rising stocks cache: {$e->getMessage()}");
        }
    }

    /**
     * Warm technical analysis cache since new price data affects indicators
     */
    private function warmTechnicalAnalysisCache(): void
    {
        $this->line('  - Warming technical analysis cache...');

        try {
            Artisan::call('market:update-technical-analysis-cache');
            $this->line('  ✓ Technical analysis cache warmed');
        } catch (\Exception $e) {
            $this->warn("  ⚠ Failed to warm technical analysis cache: {$e->getMessage()}");
        }
    }

    /**
     * Clear stagnation-related caches since new price data affects stagnation calculations
     */
    protected function clearStagnationCaches(): void
    {
        $this->line('  - Clearing stagnation caches...');

        // Clear notable-assets cache for current and previous hours to be safe
        $currentHour = now()->format('Y-m-d-H');
        $previousHour = now()->subHour()->format('Y-m-d-H');

        Cache::forget("notable-assets-data-{$currentHour}");
        Cache::forget("notable-assets-data-{$previousHour}");

        // Clear any other stagnation-specific caches
        $stagnationKeys = [
            'stagnation-analysis-*',
            'price-changes-*',
            'trend-analysis-*',
        ];

        $this->line('  ✓ Stagnation caches cleared');
    }

    /**
     * Handle process output with rate limit detection and logging
     */
    protected function handleProcessOutput($type, $buffer, string $commandContext = 'YFinance'): void
    {
        // Output to console as usual
        echo $buffer;

        // Check for rate limiting indicators and log them
        if ($this->containsRateLimitError($buffer)) {
            Log::warning('YFinance API Rate Limit Detected', [
                'command' => $commandContext,
                'output' => trim($buffer),
                'timestamp' => now()->toISOString(),
                'type' => $type,
            ]);
        }
    }

    /**
     * Check if output contains rate limiting indicators
     */
    private function containsRateLimitError(string $output): bool
    {
        $rateLimitPatterns = [
            'Too Many Requests',
            '429',
            'rate limit',
            'Rate limit',
            'RATE_LIMIT',
            'Too many requests',
            'Request limit exceeded',
            'quota exceeded',
            'throttled',
            'Throttled',
        ];

        $lowerOutput = strtolower($output);

        foreach ($rateLimitPatterns as $pattern) {
            if (stripos($lowerOutput, strtolower($pattern)) !== false) {
                return true;
            }
        }

        return false;
    }
}
