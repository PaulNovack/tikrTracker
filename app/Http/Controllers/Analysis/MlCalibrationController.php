<?php

namespace App\Http\Controllers\Analysis;

use App\Http\Controllers\Controller;
use App\Models\MlProbabilityCalibration;
use App\Services\TradingSettingService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class MlCalibrationController extends Controller
{
    public function index(Request $request): Response
    {
        $selectedPipeline = $request->input('pipeline', '');

        $pipelines = MlProbabilityCalibration::query()
            ->select('pipeline', 'version')
            ->distinct()
            ->orderBy('pipeline')
            ->get()
            ->map(fn ($row) => [
                'pipeline' => $row->pipeline,
                'version' => $row->version,
            ]);

        // Build the pipeline list with labels like "A (v17.0)"
        $pipelineOptions = $pipelines->map(fn ($p) => [
            'value' => $p['pipeline'],
            'label' => "{$p['pipeline']} ({$p['version']})",
        ]);

        $buckets = collect();
        $recommendation = null;

        if ($selectedPipeline !== '' && $selectedPipeline !== '0') {
            $buckets = MlProbabilityCalibration::query()
                ->where('pipeline', $selectedPipeline)
                ->orderBy('bucket_min')
                ->get();

            // Compute threshold recommendation: find the lowest bucket
            // with positive avg_pnl and win_rate >= some floor (e.g., 20%)
            $recommendation = $this->computeRecommendation($buckets);
        }

        return Inertia::render('analysis/MlCalibration', [
            'pipelineOptions' => $pipelineOptions,
            'selectedPipeline' => $selectedPipeline,
            'buckets' => $buckets,
            'recommendation' => $recommendation,
        ]);
    }

    /**
     * @param  \Illuminate\Support\Collection  $buckets
     * @return array{threshold: float|null, bucket_label: string|null, win_rate: float|null, avg_pnl: float|null, rationale: string, should_disable: bool}
     */
    private function computeRecommendation($buckets): array
    {
        if ($buckets->isEmpty()) {
            return [
                'threshold' => null,
                'bucket_label' => null,
                'win_rate' => null,
                'avg_pnl' => null,
                'rationale' => 'No calibration data available for this pipeline.',
                'should_disable' => true,
            ];
        }

        // Find buckets with avg_pnl >= threshold, win_rate >= 15%, and enough samples
        $threshold = TradingSettingService::getMinAvgPnl();
        $strong = $buckets->filter(function ($b) use ($threshold) {
            return (float) $b->avg_pnl >= $threshold
                && (float) $b->win_rate >= 0.15
                && $b->rows >= 3;
        });

        if ($strong->isNotEmpty()) {
            $best = $strong->sortBy('bucket_min')->first();
            $threshold = (float) $best->bucket_min;
            $winRatePct = round((float) $best->win_rate * 100, 1);
            $avgPnl = round((float) $best->avg_pnl, 2);

            $rationale = "Set the ML threshold to {$threshold} (enter ".(int) ($threshold * 100).' in Trade Settings). '
                ."At this level, trades in the \"{$best->bucket_label}\" bucket have a {$winRatePct}% win rate "
                .'and an average P&L of +'.$avgPnl.'%. '
                .'Raising the threshold further may improve win rate but reduce trade frequency.';

            return [
                'threshold' => $threshold,
                'bucket_label' => $best->bucket_label,
                'win_rate' => $winRatePct,
                'avg_pnl' => $avgPnl,
                'rationale' => $rationale,
                'should_disable' => false,
            ];
        }

        // No bucket meets the 1.7% avg_pnl target — recommend disabling
        $bestAvailable = $buckets
            ->filter(fn ($b) => $b->rows >= 3)
            ->sortByDesc('avg_pnl')
            ->first();

        $bestAvgPnl = $bestAvailable ? round((float) $bestAvailable->avg_pnl, 2) : 0;
        $threshold = TradingSettingService::getMinAvgPnl();

        $rationale = "No probability bucket achieves the target average P&L of +{$threshold}% for this pipeline. "
            .($bestAvailable
                ? "The best bucket (\"{$bestAvailable->bucket_label}\") only reaches +{$bestAvgPnl}% avg P&L. "
                : 'Not enough data is available. ')
            .'This pipeline should be <strong>disabled</strong> until the model is retrained with more data or the strategy is adjusted.';

        return [
            'threshold' => null,
            'bucket_label' => $bestAvailable?->bucket_label,
            'win_rate' => $bestAvailable ? round((float) $bestAvailable->win_rate * 100, 1) : null,
            'avg_pnl' => $bestAvgPnl,
            'rationale' => $rationale,
            'should_disable' => true,
        ];
    }
}
