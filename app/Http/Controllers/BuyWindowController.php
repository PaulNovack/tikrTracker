<?php

namespace App\Http\Controllers;

use App\Services\OptimalBuyPredictorService;
use Illuminate\Http\Request;
use Inertia\Inertia;

class BuyWindowController extends Controller
{
    public function __construct(private OptimalBuyPredictorService $optimalBuyPredictorService) {}

    /**
     * Show the Buy Window scan page - now using optimal 11.533% algorithm
     */
    public function index()
    {
        return Inertia::render('BuyWindow');
    }

    /**
     * Run the optimal buy predictor scan
     */
    public function scan(Request $request)
    {
        $validated = $request->validate([
            'as_of_est' => 'nullable|string|date_format:Y-m-d H:i:s',
            'asset_type' => 'nullable|string|in:stock,crypto',
            'min_score' => 'nullable|integer|min:1|max:20',
            'lookback' => 'nullable|integer|min:60|max:480',
            'limit' => 'nullable|integer|min:10|max:100',
        ]);

        $asOfEst = $validated['as_of_est'] ?? null;
        $assetType = $validated['asset_type'] ?? 'stock';
        $minScore = $validated['min_score'] ?? 5; // Lowered to match CLI default
        $lookback = $validated['lookback'] ?? 90; // Optimal for 10:15 AM timing
        $limit = $validated['limit'] ?? 50;

        $scanResults = $this->optimalBuyPredictorService->scan(
            $asOfEst,
            $assetType,
            $minScore,
            $lookback,
            $limit,
            true // Store signals in database
        );

        // Return JSON for AJAX requests, Inertia for form submissions
        if ($request->wantsJson()) {
            return response()->json($scanResults);
        }

        return Inertia::render('BuyWindow', [
            'scanResults' => $scanResults,
        ]);
    }
}
