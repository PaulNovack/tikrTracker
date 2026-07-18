<?php

namespace App\Http\Controllers;

use App\Services\GainersLosersAnalysisService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class GainersLosersController extends Controller
{
    public function __construct(
        private GainersLosersAnalysisService $gainersLosersService
    ) {}

    /**
     * Display the Gainers and Losers analysis page.
     */
    public function index(Request $request): Response
    {
        // Get parameters from request
        $tradingDate = $request->get('date'); // Optional date parameter
        $assetType = $request->get('filter', 'stock'); // Default to stocks
        $topCount = (int) $request->get('count', 50); // Number of gainers/losers to show

        // Validate parameters
        if ($topCount < 1) {
            $topCount = 50;
        }

        if (! in_array($assetType, ['stock', 'crypto'])) {
            $assetType = 'stock';
        }

        // Get gainers and losers data
        $data = $this->gainersLosersService->getGainersAndLosers(
            $tradingDate,
            $assetType,
            $topCount
        );

        // Get analysis summary
        $summary = $this->gainersLosersService->getAnalysisSummary(
            $data['trading_date'],
            $assetType
        );

        return Inertia::render('GainersLosers/index', [
            'title' => 'Daily Gainers & Losers',
            'description' => 'Top performing and worst performing assets from market open to close',
            'gainers' => $data['gainers'],
            'losers' => $data['losers'],
            'tradingDate' => $data['trading_date'],
            'assetTypeFilter' => $assetType,
            'topCount' => $topCount,
            'summary' => $summary,
        ]);
    }
}
