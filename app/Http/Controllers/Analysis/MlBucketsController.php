<?php

namespace App\Http\Controllers\Analysis;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class MlBucketsController extends Controller
{
    public function index(Request $request): Response
    {
        $startDate = $request->input('start_date');

        if (! is_string($startDate) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $startDate)) {
            $startDate = null;
        }

        $bucketRows = DB::table('trade_alerts')
            ->selectRaw("COALESCE(pipeline_run, 'Unknown') as pipeline_run")
            ->selectRaw('LEAST(95, FLOOR((ml_win_prob * 100) / 5) * 5) as bucket_start')
            ->selectRaw('COUNT(*) as trade_count')
            ->selectRaw('SUM(CASE WHEN pnl_percent > 0 THEN 1 ELSE 0 END) as winning_trades')
            ->selectRaw('SUM(CASE WHEN pnl_percent < 0 THEN 1 ELSE 0 END) as losing_trades')
            ->selectRaw('ROUND(SUM(pnl_percent), 2) as total_pnl')
            ->selectRaw('ROUND(AVG(pnl_percent), 2) as avg_pnl')
            ->whereNotNull('ml_win_prob')
            ->whereNotNull('pnl_percent')
            ->when($startDate, fn ($query) => $query->where('trading_date_est', '>=', $startDate))
            ->groupByRaw("COALESCE(pipeline_run, 'Unknown'), LEAST(95, FLOOR((ml_win_prob * 100) / 5) * 5)")
            ->orderByRaw("COALESCE(pipeline_run, 'Unknown')")
            ->orderByRaw('LEAST(95, FLOOR((ml_win_prob * 100) / 5) * 5)')
            ->get();

        $dateRange = DB::table('trade_alerts')
            ->selectRaw('MIN(trading_date_est) as first_date')
            ->selectRaw('MAX(trading_date_est) as last_date')
            ->selectRaw("COUNT(DISTINCT COALESCE(pipeline_run, 'Unknown')) as pipeline_count")
            ->whereNotNull('ml_win_prob')
            ->whereNotNull('pnl_percent')
            ->when($startDate, fn ($query) => $query->where('trading_date_est', '>=', $startDate))
            ->first();

        $pipelineBreakdowns = $this->buildPipelineBreakdowns($bucketRows);
        $summary = $this->buildSummary($bucketRows, $dateRange, $startDate);

        return Inertia::render('analysis/MlBuckets', [
            'summary' => $summary,
            'pipelineBreakdowns' => $pipelineBreakdowns,
            'filters' => [
                'start_date' => $startDate,
            ],
        ]);
    }

    /**
     * @param  \Illuminate\Support\Collection<int, object>  $bucketRows
     * @return array<string, mixed>
     */
    private function buildSummary(Collection $bucketRows, ?object $dateRange, ?string $startDate): array
    {
        $totalAlerts = (int) $bucketRows->sum('trade_count');
        $winningTrades = (int) $bucketRows->sum('winning_trades');
        $netPnl = round((float) $bucketRows->sum('total_pnl'), 2);

        return [
            'start_date' => $startDate,
            'first_date' => $dateRange?->first_date,
            'last_date' => $dateRange?->last_date,
            'total_alerts' => $totalAlerts,
            'total_pipelines' => (int) ($dateRange?->pipeline_count ?? 0),
            'winning_trades' => $winningTrades,
            'win_rate' => $totalAlerts > 0 ? round(($winningTrades / $totalAlerts) * 100, 1) : 0.0,
            'net_pnl' => $netPnl,
            'avg_pnl' => $totalAlerts > 0 ? round($netPnl / $totalAlerts, 2) : 0.0,
        ];
    }

    /**
     * @param  \Illuminate\Support\Collection<int, object>  $bucketRows
     * @return array<int, array<string, mixed>>
     */
    private function buildPipelineBreakdowns(Collection $bucketRows): array
    {
        return $bucketRows
            ->groupBy('pipeline_run')
            ->map(function (Collection $pipelineRows, string $pipelineRun): array {
                $bucketMap = $pipelineRows->keyBy(fn (object $row) => (int) $row->bucket_start);
                $tradeCount = (int) $pipelineRows->sum('trade_count');
                $winningTrades = (int) $pipelineRows->sum('winning_trades');
                $netPnl = round((float) $pipelineRows->sum('total_pnl'), 2);

                return [
                    'pipeline_run' => $pipelineRun,
                    'trade_count' => $tradeCount,
                    'winning_trades' => $winningTrades,
                    'win_rate' => $tradeCount > 0 ? round(($winningTrades / $tradeCount) * 100, 1) : 0.0,
                    'net_pnl' => $netPnl,
                    'avg_pnl' => $tradeCount > 0 ? round($netPnl / $tradeCount, 2) : 0.0,
                    'buckets' => collect(range(0, 95, 5))
                        ->map(function (int $bucketStart) use ($bucketMap): array {
                            $row = $bucketMap->get($bucketStart);
                            $bucketTotal = $row ? round((float) $row->total_pnl, 2) : 0.0;
                            $bucketCount = $row ? (int) $row->trade_count : 0;
                            $bucketWins = $row ? (int) $row->winning_trades : 0;
                            $bucketLosses = $row ? (int) $row->losing_trades : 0;

                            return [
                                'bucket_start' => $bucketStart,
                                'bucket_label' => $this->formatBucketLabel($bucketStart),
                                'trade_count' => $bucketCount,
                                'winning_trades' => $bucketWins,
                                'losing_trades' => $bucketLosses,
                                'win_rate' => $bucketCount > 0 ? round(($bucketWins / $bucketCount) * 100, 1) : 0.0,
                                'total_pnl' => $bucketTotal,
                                'avg_pnl' => $row ? round((float) $row->avg_pnl, 2) : 0.0,
                            ];
                        })
                        ->all(),
                ];
            })
            ->sortBy('pipeline_run')
            ->values()
            ->all();
    }

    private function formatBucketLabel(int $bucketStart): string
    {
        $bucketEnd = $bucketStart === 95 ? 100 : $bucketStart + 5;

        return sprintf('%d-%d%%', $bucketStart, $bucketEnd);
    }
}
