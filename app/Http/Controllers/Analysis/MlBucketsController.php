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
    private const NOTIONAL_PER_TRADE = 10000.0;

    public function index(Request $request): Response
    {
        $startDate = $request->input('start_date');

        if (! is_string($startDate) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $startDate)) {
            $startDate = null;
        }

        $bucketRows = DB::table('trade_alerts')
            ->selectRaw("COALESCE(pipeline_run, 'Unknown') as pipeline_run")
            ->selectRaw("GROUP_CONCAT(DISTINCT version ORDER BY version SEPARATOR ', ') as version")
            ->selectRaw('LEAST(95, FLOOR((ml_win_prob * 100) / 5) * 5) as bucket_start')
            ->selectRaw('COUNT(*) as trade_count')
            ->selectRaw('SUM(CASE WHEN pnl_percent > 0 THEN 1 ELSE 0 END) as winning_trades')
            ->selectRaw('SUM(CASE WHEN pnl_percent < 0 THEN 1 ELSE 0 END) as losing_trades')
            ->selectRaw(sprintf('ROUND(SUM(pnl_percent) * %.2f / 100, 2) as total_profit_10k', self::NOTIONAL_PER_TRADE))
            ->selectRaw(sprintf('ROUND(AVG(pnl_percent) * %.2f / 100, 2) as avg_profit_10k', self::NOTIONAL_PER_TRADE))
            ->whereNotNull('ml_win_prob')
            ->whereNotNull('pnl_percent')
            ->when($startDate, fn ($query) => $query->where('trading_date_est', '>=', $startDate))
            ->groupByRaw("COALESCE(pipeline_run, 'Unknown'), LEAST(95, FLOOR((ml_win_prob * 100) / 5) * 5)")
            ->orderByRaw("COALESCE(pipeline_run, 'Unknown')")
            ->orderByRaw('LEAST(95, FLOOR((ml_win_prob * 100) / 5) * 5)')
            ->get();

        $tradeRows = DB::table('trade_alerts')
            ->selectRaw("COALESCE(pipeline_run, 'Unknown') as pipeline_run")
            ->selectRaw('LEAST(95, FLOOR((ml_win_prob * 100) / 5) * 5) as bucket_start')
            ->addSelect([
                'id',
                'symbol',
                'trading_date_est',
                'entry_ts_est',
                'ml_win_prob',
                'pnl_percent',
            ])
            ->selectRaw(sprintf('ROUND(pnl_percent * %.2f / 100, 2) as profit_10k', self::NOTIONAL_PER_TRADE))
            ->whereNotNull('ml_win_prob')
            ->whereNotNull('pnl_percent')
            ->when($startDate, fn ($query) => $query->where('trading_date_est', '>=', $startDate))
            ->orderByRaw("COALESCE(pipeline_run, 'Unknown')")
            ->orderByRaw('LEAST(95, FLOOR((ml_win_prob * 100) / 5) * 5)')
            ->orderByRaw('ml_win_prob DESC')
            ->get();

        $dateRange = DB::table('trade_alerts')
            ->selectRaw('MIN(trading_date_est) as first_date')
            ->selectRaw('MAX(trading_date_est) as last_date')
            ->selectRaw("COUNT(DISTINCT COALESCE(pipeline_run, 'Unknown')) as pipeline_count")
            ->whereNotNull('ml_win_prob')
            ->whereNotNull('pnl_percent')
            ->when($startDate, fn ($query) => $query->where('trading_date_est', '>=', $startDate))
            ->first();

        $pipelineBreakdowns = $this->buildPipelineBreakdowns($bucketRows, $tradeRows);
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
        $netProfit10k = round((float) $bucketRows->sum('total_profit_10k'), 2);

        return [
            'start_date' => $startDate,
            'first_date' => $dateRange?->first_date,
            'last_date' => $dateRange?->last_date,
            'total_alerts' => $totalAlerts,
            'total_pipelines' => (int) ($dateRange?->pipeline_count ?? 0),
            'winning_trades' => $winningTrades,
            'win_rate' => $totalAlerts > 0 ? round(($winningTrades / $totalAlerts) * 100, 1) : 0.0,
            'net_profit_10k' => $netProfit10k,
            'avg_profit_10k' => $totalAlerts > 0 ? round($netProfit10k / $totalAlerts, 2) : 0.0,
        ];
    }

    /**
     * @param  \Illuminate\Support\Collection<int, object>  $bucketRows
     * @param  \Illuminate\Support\Collection<int, object>  $tradeRows
     * @return array<int, array<string, mixed>>
     */
    private function buildPipelineBreakdowns(Collection $bucketRows, Collection $tradeRows): array
    {
        $tradeLookup = [];
        foreach ($tradeRows as $trade) {
            $pipelineRun = (string) $trade->pipeline_run;
            $bucketStart = (int) $trade->bucket_start;

            $tradeLookup[$pipelineRun][$bucketStart][] = [
                'id' => (int) $trade->id,
                'symbol' => $trade->symbol,
                'trading_date_est' => $trade->trading_date_est,
                'entry_ts_est' => $trade->entry_ts_est,
                'ml_win_prob' => round((float) $trade->ml_win_prob, 4),
                'pnl_percent' => round((float) $trade->pnl_percent, 2),
                'profit_10k' => round((float) $trade->profit_10k, 2),
            ];
        }

        return $bucketRows
            ->groupBy('pipeline_run')
            ->map(function (Collection $pipelineRows, string $pipelineRun) use ($tradeLookup): array {
                $bucketMap = $pipelineRows->keyBy(fn (object $row) => (int) $row->bucket_start);
                $pipelineTradeBuckets = $tradeLookup[$pipelineRun] ?? [];
                $tradeCount = (int) $pipelineRows->sum('trade_count');
                $winningTrades = (int) $pipelineRows->sum('winning_trades');
                $netProfit10k = round((float) $pipelineRows->sum('total_profit_10k'), 2);

                return [
                    'pipeline_run' => $pipelineRun,
                    'version' => (string) ($pipelineRows->first()->version ?? ''),
                    'trade_count' => $tradeCount,
                    'winning_trades' => $winningTrades,
                    'win_rate' => $tradeCount > 0 ? round(($winningTrades / $tradeCount) * 100, 1) : 0.0,
                    'net_profit_10k' => $netProfit10k,
                    'avg_profit_10k' => $tradeCount > 0 ? round($netProfit10k / $tradeCount, 2) : 0.0,
                    'buckets' => collect(range(0, 95, 5))
                        ->map(function (int $bucketStart) use ($bucketMap, $pipelineTradeBuckets): array {
                            $row = $bucketMap->get($bucketStart);
                            $trades = $pipelineTradeBuckets[$bucketStart] ?? [];
                            $bucketTotal = $row ? round((float) $row->total_profit_10k, 2) : 0.0;
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
                                'total_profit_10k' => $bucketTotal,
                                'avg_profit_10k' => $row ? round((float) $row->avg_profit_10k, 2) : 0.0,
                                'trades' => $trades,
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
