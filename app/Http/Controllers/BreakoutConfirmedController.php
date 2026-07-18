<?php

namespace App\Http\Controllers;

use App\Services\ConfirmedMomentumService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class BreakoutConfirmedController extends Controller
{
    public function __construct(
        private ConfirmedMomentumService $confirmedMomentumService
    ) {}

    public function index(Request $request): Response
    {
        $time = $request->input('time', now()->format('Y-m-d H:i:s'));
        $assetType = $request->input('asset_type', 'stocks');

        // Use EXACT same defaults as PHP script
        $lookbackMinutes = (int) $request->input('lookback', 15);         // PHP script: $defaultLookbackMinutes = 15
        $minMovePct = (float) $request->input('min_move', 0.75);          // PHP script: $defaultMinMovePct = 0.75
        $noiseMultiplier = (float) $request->input('noise_multiplier', 1.5);        // PHP script: $noiseMultiplier = 1.5
        $maxDistanceFromHighPct = (float) $request->input('max_distance_from_high', 0.25);  // PHP script: $maxDistanceFromHighPct = 0.25
        $minPrice = (float) $request->input('min_price', 1.0);            // PHP script: $minPrice = 1.00
        $minVolumeSum1m = (int) $request->input('min_volume_sum', 10000);  // PHP script: $minVolumeSum1m = 10000
        $minBars1m = (int) $request->input('min_bars_1m', 5);             // PHP script: $minBars1m = 5
        $strongBodyMinPct = (float) $request->input('strong_body_min_pct', 0.3);      // PHP script: $strongBodyMinPct = 0.3
        $fiveMinBarsCount = (int) $request->input('five_min_bars_count', 5);          // PHP script: $fiveMinBarsCount = 5
        $fiveMinRangeFactor = (float) $request->input('five_min_range_factor', 5.0);  // PHP script: $fiveMinRangeFactor = 5
        $minBars5m = (int) $request->input('min_bars_5m', 3);             // PHP script: $minBars5m = 3

        // Convert frontend asset type to database format
        $dbAssetType = $assetType === 'stocks' ? 'stock' : $assetType;

        // Create Carbon instance in EST timezone since that's what the data uses
        $timeCarbon = Carbon::createFromFormat('Y-m-d H:i:s', $time, 'America/New_York');

        // Use parameters from request or PHP script defaults
        $results = $this->confirmedMomentumService->scanConfirmedMomentum(
            time: $timeCarbon,
            assetType: $dbAssetType,
            lookbackMinutes: $lookbackMinutes,
            minMovePct: $minMovePct,
            noiseMultiplier: $noiseMultiplier,
            maxDistanceFromHighPct: $maxDistanceFromHighPct,
            minPrice: $minPrice,
            minVolumeSum1m: $minVolumeSum1m,
            minBars1m: $minBars1m,
            strongBodyMinPct: $strongBodyMinPct,
            fiveMinBarsCount: $fiveMinBarsCount,
            fiveMinRangeFactor: $fiveMinRangeFactor,
            minBars5m: $minBars5m
        );

        return Inertia::render('analysis/BreakoutConfirmed', [
            'title' => 'Breakout Confirmed',
            'time' => $time,
            'assetType' => $assetType,
            'lookback' => $lookbackMinutes,
            'minMove' => $minMovePct,
            'noiseMultiplier' => $noiseMultiplier,
            'maxDistanceFromHigh' => $maxDistanceFromHighPct,
            'minPrice' => $minPrice,
            'minVolumeSum' => $minVolumeSum1m,
            'minBars1m' => $minBars1m,
            'strongBodyMinPct' => $strongBodyMinPct,
            'fiveMinBarsCount' => $fiveMinBarsCount,
            'fiveMinRangeFactor' => $fiveMinRangeFactor,
            'minBars5m' => $minBars5m,
            'results' => $results['candidates'],
            'metadata' => $results['metadata'],
        ]);
    }
}
