<?php

namespace App\Http\Controllers\Analysis;

use App\Http\Controllers\Controller;
use App\Services\Market\BestPerformers5mService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class BestGains7DaysController extends Controller
{
    public function __construct(
        private readonly BestPerformers5mService $bestPerformersService
    ) {}

    /**
     * Display the best performing stocks over the last 7 days
     */
    public function index(Request $request): Response
    {
        $assetType = $request->get('assetType', 'stock');
        $days = (int) $request->get('days', 7);
        $limit = (int) $request->get('limit', 200);
        $minBars = (int) $request->get('minBars', 200);
        $rthOnly = (bool) $request->get('rthOnly', true);

        $performers = $this->bestPerformersService->getBestPerformers([
            'days' => $days,
            'assetType' => $assetType,
            'limit' => $limit,
            'minBars' => $minBars,
            'rthOnly' => $rthOnly,
            'tz' => 'America/New_York',
        ]);

        return Inertia::render('analysis/BestGains7Days', [
            'performers' => $performers,
            'filters' => [
                'assetType' => $assetType,
                'days' => $days,
                'limit' => $limit,
                'minBars' => $minBars,
                'rthOnly' => $rthOnly,
            ],
        ]);
    }
}
