<?php

namespace App\Http\Controllers\Analysis;

use App\Http\Controllers\Controller;
use App\Services\Market\BestPerformers5mService;
use App\Services\Market\BuyZoneFromTopPerformersService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class BuyZoneTopPerformersController extends Controller
{
    public function __construct(
        private readonly BestPerformers5mService $bestPerformersService,
        private readonly BuyZoneFromTopPerformersService $buyZoneService
    ) {}

    /**
     * Display buy zone candidates from top performers
     */
    public function index(Request $request): Response
    {
        $assetType = $request->get('assetType', 'stock');
        $days = (int) $request->get('days', 7);
        $topPerformersLimit = (int) $request->get('topPerformersLimit', 200);
        $testDateTime = $request->get('testDateTime'); // null if not provided = use current time

        // Step 1: Get top performers from the last N days
        $topPerformers = $this->bestPerformersService->getBestPerformers([
            'days' => $days,
            'assetType' => $assetType,
            'limit' => $topPerformersLimit,
            'minBars' => 200,
            'minVol' => 0,
            'rthOnly' => true,
            'tz' => 'America/New_York',
        ]);

        // Extract just the symbols
        $symbols = array_column($topPerformers, 'symbol');

        // Step 2: Filter to buy zone candidates
        $buyZoneCandidates = $this->buyZoneService->filterBuyZone($symbols, [
            'assetType' => $assetType,
            'days' => $days,
            'tz' => 'America/New_York',
            'testDateTime' => $testDateTime, // Pass through to service (null = now)
            // Use default thresholds from service
        ]);

        return Inertia::render('analysis/BuyZoneTopPerformers', [
            'candidates' => $buyZoneCandidates,
            'totalTopPerformers' => count($topPerformers),
            'filters' => [
                'assetType' => $assetType,
                'days' => $days,
                'topPerformersLimit' => $topPerformersLimit,
            ],
        ]);
    }
}
