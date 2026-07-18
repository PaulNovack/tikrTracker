<?php

namespace App\Http\Controllers;

use App\Services\BuyPredictorService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class BuyPredictorController extends Controller
{
    public function __construct(
        private readonly BuyPredictorService $buyPredictorService
    ) {}

    public function index(Request $request): Response
    {
        $request->validate([
            'as_of' => 'nullable|date_format:Y-m-d\TH:i',
            'lookback_minutes' => 'nullable|integer|min:5|max:480',
            'asset_type' => 'nullable|in:stock,crypto',
            'min_score' => 'nullable|integer|min:1|max:20',
        ]);

        // Get analysis results if any analysis parameters are provided
        $results = null;
        if ($request->hasAny(['lookback_minutes', 'asset_type', 'min_score']) || $request->filled('as_of')) {
            // Convert datetime-local format to Carbon EST object, then back to string
            $asOfEst = $request->input('as_of');
            if ($asOfEst) {
                // Parse as EST datetime and convert to proper string format
                $carbonEst = \Carbon\Carbon::createFromFormat('Y-m-d\TH:i', $asOfEst, 'America/New_York');
                $asOfEst = $carbonEst->format('Y-m-d H:i:s');
            }

            $results = $this->buyPredictorService->analyze(
                asOfEst: $asOfEst,
                lookbackMinutes: $request->input('lookback_minutes', 90),
                assetType: $request->input('asset_type', 'stock'),
                minScore: $request->input('min_score', 5)
            );
        }

        return Inertia::render('BuyPredictor', [
            'title' => 'Buy Predictor',
            'description' => 'AI-powered analysis to predict optimal buy opportunities in the market',
            'results' => $results,
            'params' => [
                'as_of' => $request->input('as_of'),
                'lookback_minutes' => (int) $request->input('lookback_minutes', 90),
                'asset_type' => $request->input('asset_type', 'stock'),
                'min_score' => (int) $request->input('min_score', 5),
            ],
        ]);
    }

    public function analyze(Request $request)
    {
        $request->validate([
            'as_of' => 'nullable|date_format:Y-m-d\TH:i',
            'lookback_minutes' => 'integer|min:5|max:480',
            'asset_type' => 'required|in:stock,crypto',
            'min_score' => 'integer|min:1|max:20',
        ]);

        // Convert datetime-local format to standard format for the service
        $asOfEst = $request->input('as_of');
        if ($asOfEst) {
            $asOfEst = str_replace('T', ' ', $asOfEst).':00';
        }

        $results = $this->buyPredictorService->analyze(
            asOfEst: $asOfEst,
            lookbackMinutes: $request->input('lookback_minutes', 90),
            assetType: $request->input('asset_type'),
            minScore: $request->input('min_score', 5)
        );

        return response()->json($results);
    }
}
