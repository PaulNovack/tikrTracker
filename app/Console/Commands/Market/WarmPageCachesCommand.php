<?php

namespace App\Console\Commands\Market;

use App\Http\Controllers\Market\TechnicalAnalysisController;
use App\Http\Controllers\NotableAssetController;
use App\Http\Controllers\RisingController;
use App\Http\Controllers\RisingHourController;
use App\Http\Controllers\StagnationController;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

class WarmPageCachesCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'market:warm-page-caches
                            {--pages=rising,notable-assets,stagnation,technical-analysis,rising-hour : Comma-separated pages to warm}
                            {--asset-types=stock,crypto,all : Asset type filters for rising page}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Warm caches for all major pages (rising, notable-assets, stagnation, technical-analysis, rising-hour) after market close';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $pages = explode(',', $this->option('pages'));
        $assetTypes = explode(',', $this->option('asset-types'));

        $this->info('🔥 Warming page caches after market close...');

        $startTime = microtime(true);

        foreach ($pages as $page) {
            $page = trim($page);

            match ($page) {
                'rising' => $this->warmRisingCache($assetTypes),
                'notable-assets' => $this->warmNotableAssetsCache(),
                'stagnation' => $this->warmStagnationCache(),
                'technical-analysis' => $this->warmTechnicalAnalysisCache(),
                'rising-hour' => $this->warmRisingHourCache($assetTypes),
                default => $this->warn("Unknown page: {$page}")
            };
        }

        $duration = round(microtime(true) - $startTime, 2);
        $this->info("🎉 All page caches warmed in {$duration} seconds!");

        return self::SUCCESS;
    }

    /**
     * Warm the rising stocks cache for all asset type filters
     */
    private function warmRisingCache(array $assetTypes): void
    {
        $this->line('Warming Rising page cache...');

        foreach ($assetTypes as $assetType) {
            $assetType = trim($assetType);

            $this->line("  - Rising stocks ({$assetType})");

            // Simulate the request that would populate cache
            $request = request()->merge(['filter' => $assetType]);

            try {
                $controller = new RisingController;
                $controller->index($request);
                $this->line("    ✓ Cache warmed for {$assetType}");
            } catch (\Exception $e) {
                $this->error("    ✗ Failed to warm {$assetType}: ".$e->getMessage());
            }
        }
    }

    /**
     * Warm the notable assets page cache
     */
    private function warmNotableAssetsCache(): void
    {
        $this->line('Warming Notable Assets page cache...');

        try {
            $controller = new NotableAssetController;
            $request = new \Illuminate\Http\Request;
            $controller->index($request);
            $this->line('  ✓ Notable Assets cache warmed');
        } catch (\Exception $e) {
            $this->error('  ✗ Failed to warm Notable Assets: '.$e->getMessage());
        }
    }

    /**
     * Warm the stagnation page cache for all users' watchlists
     */
    private function warmStagnationCache(): void
    {
        $this->line('Warming Stagnation page cache for all users...');

        // Get all users with their watchlists
        $usersWithWatches = \App\Models\User::with(['watches.asset'])
            ->whereHas('watches')
            ->get();

        $this->line("  Found {$usersWithWatches->count()} users with watchlists");

        $successCount = 0;
        $errorCount = 0;

        foreach ($usersWithWatches as $user) {
            $symbols = $user->watches
                ->map(fn ($watch) => $watch->asset->symbol)
                ->filter()
                ->values()
                ->toArray();

            if (empty($symbols)) {
                continue;
            }

            $symbolsStr = implode(',', array_slice($symbols, 0, 5)); // Show first 5 for logging
            $this->line("  - User {$user->id}: {$symbolsStr}... (".count($symbols).' symbols)');

            try {
                // Simulate authenticated user request
                auth()->login($user);

                $controller = new StagnationController;
                $controller->index();

                $successCount++;
                $this->line('    ✓ Cache warmed');
            } catch (\Exception $e) {
                $errorCount++;
                $this->line('    ✗ Failed: '.$e->getMessage());
            } finally {
                auth()->logout();
            }
        }

        $this->line("  Summary: {$successCount} success, {$errorCount} errors");

        // Also warm some common symbol combinations for new users
        $this->warmCommonStagnationCombos();
    }

    /**
     * Warm cache for common symbol combinations for users without watchlists
     */
    private function warmCommonStagnationCombos(): void
    {
        $this->line('  Warming common symbol combinations...');

        $commonCombos = [
            ['AAPL', 'MSFT', 'GOOGL', 'AMZN', 'TSLA'], // Tech giants
            ['NVDA', 'META', 'NFLX', 'AMD', 'CRM'],    // Growth stocks
            ['BTC-USD', 'ETH-USD', 'XRP-USD'],          // Major crypto
            ['SPY', 'QQQ', 'IWM', 'VTI'],             // ETFs
        ];

        foreach ($commonCombos as $symbols) {
            $symbolsStr = implode(',', $symbols);

            try {
                // Create a request with symbols parameter to bypass watchlist requirement
                $request = request()->merge(['symbols' => $symbolsStr]);

                $controller = new StagnationController;
                $controller->index($request);

                $this->line('    ✓ Common combo cached: '.implode(', ', $symbols));
            } catch (\Exception $e) {
                $this->line('    ✗ Failed combo: '.$e->getMessage());
            }
        }
    }

    /**
     * Warm the technical analysis page cache
     */
    private function warmTechnicalAnalysisCache(): void
    {
        $this->line('Warming Technical Analysis page cache...');

        try {
            // Clear existing cache first
            Cache::forget('technical-analysis:results');

            // Trigger the analysis which will cache the results
            $controller = new TechnicalAnalysisController;
            $reflection = new \ReflectionClass($controller);
            $method = $reflection->getMethod('performAnalysis');
            $method->setAccessible(true);

            $data = $method->invoke($controller);

            // DO NOT cache entire results array to Redis — it is too large
            // (5000+ assets × indicators) and causes Predis strlen() timeouts.
            // The controller regenerates on cache miss in < 2 seconds.

            $this->line('  ✓ Technical Analysis cache warmed');
            $this->line("    - Total assets analyzed: {$data['summary']['totalAssets']}");
            $this->line("    - Strong Buy: {$data['summary']['strongBuy']}");
            $this->line("    - Buy: {$data['summary']['buy']}");
            $this->line("    - Hold: {$data['summary']['hold']}");
            $this->line("    - Sell: {$data['summary']['sell']}");
            $this->line("    - Strong Sell: {$data['summary']['strongSell']}");
        } catch (\Exception $e) {
            $this->error('  ✗ Failed to warm Technical Analysis: '.$e->getMessage());
        }
    }

    /**
     * Warm the rising hour page cache for all asset type filters
     */
    private function warmRisingHourCache(array $assetTypes): void
    {
        $this->line('Warming Rising Hour page cache...');

        foreach ($assetTypes as $assetType) {
            $assetType = trim($assetType);

            $this->line("  - Rising Hour ({$assetType})");

            // Clear existing cache first
            Cache::forget("rising_hour_{$assetType}");

            // Simulate the request that would populate cache
            $request = request()->merge(['filter' => $assetType]);

            try {
                $controller = new RisingHourController;
                $controller->index($request);
                $this->line("    ✓ Cache warmed for {$assetType}");
            } catch (\Exception $e) {
                $this->error("    ✗ Failed to warm {$assetType}: ".$e->getMessage());
            }
        }
    }
}
