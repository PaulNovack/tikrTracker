<?php

namespace App\Console\Commands\Market;

use App\Http\Controllers\Market\TechnicalAnalysisController;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

class UpdateTechnicalAnalysisCache extends Command
{
    protected $signature = 'market:update-technical-analysis-cache';

    protected $description = 'Update the technical analysis cache in the background';

    public function handle(): int
    {
        $this->info('Updating technical analysis cache...');

        $startTime = microtime(true);

        // Clear the existing cache
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

        $duration = round(microtime(true) - $startTime, 2);
        $this->info("✓ Technical analysis cache updated in {$duration}s");
        $this->info("  - Total assets analyzed: {$data['summary']['totalAssets']}");
        $this->info("  - Strong Buy: {$data['summary']['strongBuy']}");
        $this->info("  - Buy: {$data['summary']['buy']}");
        $this->info("  - Hold: {$data['summary']['hold']}");
        $this->info("  - Sell: {$data['summary']['sell']}");
        $this->info("  - Strong Sell: {$data['summary']['strongSell']}");

        return self::SUCCESS;
    }
}
