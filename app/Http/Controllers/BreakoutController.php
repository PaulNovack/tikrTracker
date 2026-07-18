<?php

namespace App\Http\Controllers;

use App\Services\UpwardMomentumService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class BreakoutController extends Controller
{
    public function __construct(
        private UpwardMomentumService $upwardMomentumService
    ) {}

    public function index(Request $request): Response
    {
        $time = $request->input('time');
        $assetType = $request->input('asset_type', 'stock');

        // Use EXACT same defaults as PHP script scan_upward_momentum.php
        $lookbackMinutes = (int) $request->input('lookback', 5);                     // PHP: $defaultLookbackMinutes = 5
        $minMovePct = (float) $request->input('min_move', 0.75);                     // PHP: $defaultMinMovePct = 0.75
        $noiseMultiplier = (float) $request->input('noise_multiplier', 1.5);        // PHP: $noiseMultiplier = 1.5
        $minBars = (int) $request->input('min_bars', 5);                             // PHP: $minBars = 5
        $minPrice = (float) $request->input('min_price', 1.00);                      // PHP: $minPrice = 1.00
        $minVolumeSum = (int) $request->input('min_volume_sum', 10000);              // PHP: $minVolumeSum = 10000
        $maxDistanceFromHighPct = (float) $request->input('max_distance_from_high', 0.25); // PHP: $maxDistanceFromHighPct = 0.25

        // Get upward momentum data
        $momentumData = $this->upwardMomentumService->scanUpwardMomentum(
            $time,
            $assetType,
            $lookbackMinutes,
            $minMovePct,
            $noiseMultiplier,
            $minBars,
            $minPrice,
            $minVolumeSum,
            $maxDistanceFromHighPct
        );

        return Inertia::render('analysis/Breakout', [
            'title' => 'Breakout Analysis',
            'time' => $time,
            'asset_type' => $assetType,
            'lookback_minutes' => $lookbackMinutes,
            'min_move_pct' => $minMovePct,
            'noise_multiplier' => $noiseMultiplier,
            'min_bars' => $minBars,
            'min_price' => $minPrice,
            'min_volume_sum' => $minVolumeSum,
            'max_distance_from_high_pct' => $maxDistanceFromHighPct,
            'momentum_data' => $momentumData,
        ]);
    }
}
