<?php

namespace App\Http\Controllers;

use App\Services\TightStopsAnalysisService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class PaulsPicksController extends Controller
{
    public function __construct(
        private TightStopsAnalysisService $tightStopsService
    ) {}

    /**
     * Display clean 2-hour uptrend scanner (Pipeline M v1400.0).
     */
    public function index(Request $request): Response
    {
        // Get parameters for analysis
        $analysisTime = $request->get('time'); // Optional time parameter
        $assetType = $request->get('filter', 'stock'); // stock or crypto
        $lookbackMinutes = (int) $request->get('lookback', 120); // default 2 hours
        $maxDrawdownPct = (float) $request->get('max_drawdown', 0.01); // 1%
        $minTrendPct = (float) $request->get('min_trend', 0.005); // 0.5%
        $onlyOver1Mil = $request->boolean('over_1mil', false); // Market cap over $1M filter

        // Run the tight stops analysis
        $picks = $this->tightStopsService->findBestPicksForTightStops(
            $analysisTime,
            $lookbackMinutes,
            $maxDrawdownPct,
            $minTrendPct,
            $assetType,
            $onlyOver1Mil
        );

        // Get analysis summary for display
        $analysisSummary = $this->tightStopsService->getAnalysisSummary(
            $analysisTime,
            $lookbackMinutes,
            $maxDrawdownPct,
            $minTrendPct,
            $assetType,
            $onlyOver1Mil
        );

        return Inertia::render('PaulsPicks/index', [
            'title' => 'Clean 2H Uptrend Scanner',
            'description' => 'Smooth 2-hour uptrends with minimal drawdowns (Pipeline M v1400.0)',
            'time' => $analysisTime,
            'picks' => $picks,
            'analysisSummary' => $analysisSummary,
            'totalPicks' => count($picks),
            'assetTypeFilter' => $assetType,
            'currentParams' => [
                'lookback' => $lookbackMinutes,
                'max_drawdown' => $maxDrawdownPct,
                'min_trend' => $minTrendPct,
                'over_1mil' => $onlyOver1Mil,
            ],
        ]);
    }
}
