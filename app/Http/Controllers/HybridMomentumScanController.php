<?php

namespace App\Http\Controllers;

use App\Services\HybridMomentumScanService;
use Illuminate\Http\Request;
use Inertia\Inertia;

class HybridMomentumScanController extends Controller
{
    public function __construct(private HybridMomentumScanService $hybridMomentumScanService) {}

    /**
     * Show the Hybrid Momentum Scan page
     */
    public function index()
    {
        return Inertia::render('HybridMomentumScan');
    }

    /**
     * Run the hybrid momentum scan
     */
    public function scan(Request $request)
    {
        $validated = $request->validate([
            'as_of_est' => 'nullable|string|date_format:Y-m-d H:i:s',
            'asset_type' => 'nullable|string|in:stock,etf',
            'min_score' => 'nullable|integer|min:0|max:10',
        ]);

        $asOfEst = $validated['as_of_est'] ?? null;
        $assetType = $validated['asset_type'] ?? 'stock';
        $minScore = $validated['min_score'] ?? 5;

        $scanResults = $this->hybridMomentumScanService->scan($asOfEst, $assetType, $minScore);

        return Inertia::render('HybridMomentumScan', [
            'scanResults' => $scanResults,
        ]);
    }
}
