<?php

namespace App\Http\Controllers;

use App\Services\Market\ConsecutiveBarsAnalysisService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Inertia\Inertia;
use Inertia\Response;

class Last4BarsUpController extends Controller
{
    public function __construct(
        private ConsecutiveBarsAnalysisService $analysisService
    ) {}

    /**
     * Display the Last 4 Bars Up analysis page.
     */
    public function index(Request $request): Response
    {
        // Get filter, time, and number of bars from request
        $assetTypeFilter = $request->get('filter', 'stock'); // Default to stocks
        $analysisTime = $request->get('time'); // Optional time parameter
        $numBars = (int) $request->get('bars', 4); // Number of bars, default 4

        // Validate bars parameter
        if ($numBars < 2) {
            $numBars = 4;
        }

        // Cache key for this request (include time and bars for unique caching)
        $timeKey = $analysisTime ? md5($analysisTime) : 'current';
        $cacheKey = "last_{$numBars}_bars_up_{$assetTypeFilter}_{$timeKey}";

        // Try to get from cache first (5 minutes for crypto, 2 minutes for stocks)
        $cacheDuration = $assetTypeFilter === 'crypto' ? 300 : 120;
        $data = Cache::get($cacheKey);

        if ($data === null) {
            // Cache miss - generate fresh data using the service
            $data = $this->analysisService->getAnalysisData($analysisTime, $assetTypeFilter, $numBars);

            // Cache the fresh data
            Cache::put($cacheKey, $data, $cacheDuration);

            // Optional: Log cache miss for monitoring
            \Log::info("Cache miss for last-{$numBars}-bars-up: {$assetTypeFilter}, time: {$analysisTime}");
        }

        return Inertia::render('Last4BarsUp/index', [
            'title' => "Last {$numBars} Bars Up",
            'description' => $data['description'],
            'stocks' => $data['stocks'],
            'timestamp' => $data['timestamp'],
            'timestampEst' => $data['timestampEst'],
            'assetTypeFilter' => $assetTypeFilter,
            'totalAnalyzed' => $data['totalAnalyzed'],
            'dataFreshness' => $data['dataFreshness'],
            'totalFound' => $data['totalFound'],
            'numBarsUsed' => $data['numBarsUsed'],
            'time' => $analysisTime, // Pass the time parameter to frontend
            'bars' => $numBars, // Pass the bars parameter to frontend
        ]);
    }
}
