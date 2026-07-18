<?php

namespace App\Http\Controllers\Analysis;

use App\Http\Controllers\Controller;
use App\Services\Analysis\BottomDetectionService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class BottomDetectionController extends Controller
{
    public function __construct(
        private BottomDetectionService $bottomDetectionService
    ) {}

    public function index(Request $request): Response
    {
        $assetType = $request->input('filter', 'stock');
        $asOf = $request->input('asOf');

        // Parse additional options from request
        $options = [
            'lookbackBars' => (int) ($request->input('lookbackBars', 260)),
            'minRsi' => (float) ($request->input('minRsi', 28)),
            'bbLen' => (int) ($request->input('bbLen', 20)),
            'bbK' => (float) ($request->input('bbK', 2.0)),
            'oversoldLookback' => (int) ($request->input('oversoldLookback', 80)),
            'baseBars' => (int) ($request->input('baseBars', 9)),
            'lowTolPct' => (float) ($request->input('lowTolPct', 0.0015)),
            'requireVolContraction' => $request->boolean('requireVolContraction', true),
            'volContractRatio' => (float) ($request->input('volContractRatio', 0.90)),
            'requireRisingLows' => $request->boolean('requireRisingLows', true),
            'emaFast' => (int) ($request->input('emaFast', 9)),
            'requireBreakBaseHigh' => $request->boolean('requireBreakBaseHigh', false),
            'minDollarVol' => (float) ($request->input('minDollarVol', 0)),
            'maxGainFromBottomPct' => (float) ($request->input('maxGainFromBottomPct', 15.0)),
        ];

        try {
            $candidates = $this->bottomDetectionService->detectBottoms($assetType, $asOf, $options);

            // Transform data for frontend display
            $results = collect($candidates)->map(function ($candidate) {
                return [
                    'symbol' => $candidate['symbol'],
                    'asset_id' => $candidate['asset_id'],
                    'price' => round($candidate['price'], 4),
                    'baseLow' => round($candidate['baseLow'], 4),
                    'gainFromBottomPct' => round($candidate['gainFromBottomPct'], 2),
                    'rsi' => round($candidate['rsi'], 1),
                    'bbLower' => round($candidate['bbLower'], 4),
                    'emaFast' => round($candidate['emaFast'], 4),
                    'score' => round($candidate['score'], 2),
                    'flags' => $candidate['flags'],
                    'oversoldTs' => $candidate['oversoldTs'],
                    'baseStartTs' => $candidate['baseStartTs'],
                    'barTs' => $candidate['barTs'],
                    'asOf' => $candidate['asOf'],
                ];
            });

        } catch (\Exception $e) {
            $results = collect();
            $error = $e->getMessage();
        }

        return Inertia::render('analysis/BottomDetect', [
            'title' => 'Bottom Detection Analysis',
            'bottom_data' => [
                'candidates' => $results ?? collect(),
                'metadata' => [
                    'scan_date' => now()->format('Y-m-d'),
                    'lookback_days' => $options['lookbackBars'],
                    'min_rsi_oversold' => $options['minRsi'],
                    'max_rsi_oversold' => 35, // Default max
                    'min_base_days' => $options['baseBars'],
                    'min_reclaim_volume_ratio' => $options['volContractRatio'],
                    'min_dollar_volume' => $options['minDollarVol'],
                    'error' => $error ?? null,
                ],
            ],
            'scan_date' => now()->format('Y-m-d'),
            'lookback_days' => $options['lookbackBars'],
            'min_rsi_oversold' => $options['minRsi'],
            'max_rsi_oversold' => 35,
            'min_base_days' => $options['baseBars'],
            'min_reclaim_volume_ratio' => $options['volContractRatio'],
            'min_dollar_volume' => $options['minDollarVol'],
            'max_gain_from_bottom_pct' => $options['maxGainFromBottomPct'],
            'asset_type' => $assetType,
            'timestamp' => now()->format('Y-m-d H:i:s T'),
        ]);
    }
}
