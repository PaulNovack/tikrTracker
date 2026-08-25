<?php

namespace App\Http\Controllers\Analysis;

use App\Http\Controllers\Controller;
use App\Models\TradeAlert;
use App\Services\TradingSettingService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

class TradesPerDayController extends Controller
{
    private const DEFAULT_LOOKBACK_DAYS = 30;

    private const NOTIONAL_PER_TRADE = 10000.0;

    public function index(Request $request): Response
    {
        $defaultStartDate = now()->subDays(self::DEFAULT_LOOKBACK_DAYS - 1)->toDateString();
        $startDate = $this->normalizeDate($request->input('start_date'), $defaultStartDate);
        $endDate = $this->normalizeDate($request->input('end_date'), now()->toDateString());

        if (Carbon::parse($endDate)->lt(Carbon::parse($startDate))) {
            $endDate = $startDate;
        }

        $pipelineThresholds = TradingSettingService::getAllPipelineMlThresholds();
        $globalThreshold = TradingSettingService::getGlobalMlThreshold();

        $alerts = TradeAlert::query()
            ->select(['symbol', 'trading_date_est', 'pipeline_run', 'ml_win_prob', 'pnl_percent', 'signal_type', 'entry_type', 'entry_ts_est', 'exit_ts_est'])
            ->whereNotNull('trading_date_est')
            ->whereNotNull('ml_win_prob')
            ->whereBetween('trading_date_est', [$startDate, $endDate])
            ->orderBy('trading_date_est')
            ->orderBy('entry_ts_est')
            ->orderBy('symbol')
            ->get();

        $filteredAlerts = $alerts->filter(function (TradeAlert $alert) use ($pipelineThresholds, $globalThreshold): bool {
            $pipelineRun = strtoupper((string) ($alert->pipeline_run ?? ''));
            $threshold = $pipelineThresholds[$pipelineRun] ?? $globalThreshold;

            return (float) $alert->ml_win_prob >= $threshold;
        });

        $dedupedAlerts = $filteredAlerts
            ->groupBy(fn (TradeAlert $alert) => $alert->trading_date_est?->toDateString() ?? '')
            ->filter(fn (Collection $group, string $date) => $date !== '')
            ->flatMap(function (Collection $group): Collection {
                return $group
                    ->sortBy(fn (TradeAlert $alert): string => $alert->entry_ts_est?->format('Y-m-d H:i:s') ?? '')
                    ->unique(fn (TradeAlert $alert): string => (string) $alert->symbol)
                    ->values();
            })
            ->values();

        $dailyBreakdowns = $dedupedAlerts
            ->groupBy(fn (TradeAlert $alert) => $alert->trading_date_est?->toDateString() ?? '')
            ->filter(fn (Collection $group, string $date) => $date !== '')
            ->map(function (Collection $group, string $date) use ($pipelineThresholds, $globalThreshold): array {
                $trades = $group->map(function (TradeAlert $alert) use ($pipelineThresholds, $globalThreshold): array {
                    $pipelineRun = strtoupper((string) ($alert->pipeline_run ?? ''));
                    $threshold = $pipelineThresholds[$pipelineRun] ?? $globalThreshold;

                    return [
                        'symbol' => $alert->symbol,
                        'pipeline_run' => $alert->pipeline_run ?: 'Unknown',
                        'entry_type' => $alert->entry_type,
                        'signal_type' => $alert->signal_type,
                        'entry_ts_est' => $alert->entry_ts_est?->format('Y-m-d H:i:s'),
                        'exit_ts_est' => $alert->exit_ts_est?->format('Y-m-d H:i:s'),
                        'ml_win_prob' => round((float) $alert->ml_win_prob, 4),
                        'pnl_percent' => $alert->pnl_percent !== null ? round((float) $alert->pnl_percent, 2) : null,
                        'profit_10k' => $alert->pnl_percent !== null ? round(((float) $alert->pnl_percent) * 100.0, 2) : null,
                        'ml_threshold' => round($threshold, 4),
                    ];
                })->values();

                return [
                    'date' => $date,
                    'trade_count' => $trades->count(),
                    'winning_trades' => $trades->filter(fn (array $trade): bool => ($trade['pnl_percent'] ?? null) !== null && (float) $trade['pnl_percent'] >= 0)->count(),
                    'losing_trades' => $trades->filter(fn (array $trade): bool => ($trade['pnl_percent'] ?? null) !== null && (float) $trade['pnl_percent'] < 0)->count(),
                    'invested_10k' => round($trades->count() * self::NOTIONAL_PER_TRADE, 2),
                    'total_profit_10k' => round($trades->sum(fn (array $trade): float => (float) ($trade['profit_10k'] ?? 0)), 2),
                    'pnl_percent' => $trades->count() > 0 ? round(($trades->sum(fn (array $trade): float => (float) ($trade['profit_10k'] ?? 0)) / ($trades->count() * self::NOTIONAL_PER_TRADE)) * 100, 2) : 0.0,
                    'trades' => $trades->all(),
                ];
            })
            ->sortKeys()
            ->values()
            ->all();

        $dailyCounts = array_map(static fn (array $day): array => [
            'date' => $day['date'],
            'trade_count' => $day['trade_count'],
        ], $dailyBreakdowns);

        $totalTrades = $dedupedAlerts->count();
        $activeDays = count($dailyBreakdowns);
        $peakDay = collect($dailyBreakdowns)->sortByDesc('trade_count')->first();
        $totalWins = collect($dailyBreakdowns)->sum('winning_trades');
        $totalLosses = collect($dailyBreakdowns)->sum('losing_trades');
        $totalInvested10k = round($totalTrades * self::NOTIONAL_PER_TRADE, 2);
        $totalProfit10k = round(collect($dailyBreakdowns)->sum('total_profit_10k'), 2);
        $avgProfitPercent = $dedupedAlerts->count() > 0
            ? round(
                $dedupedAlerts->sum(fn (TradeAlert $alert): float => $alert->pnl_percent !== null ? (float) $alert->pnl_percent : 0.0) / $dedupedAlerts->count(),
                2,
            )
            : 0.0;

        return Inertia::render('analysis/TradesPerDay', [
            'summary' => [
                'start_date' => $startDate,
                'end_date' => $endDate,
                'total_trades' => $totalTrades,
                'total_invested_10k' => $totalInvested10k,
                'total_profit_10k' => $totalProfit10k,
                'avg_profit_percent' => $avgProfitPercent,
                'total_wins' => $totalWins,
                'total_losses' => $totalLosses,
                'win_rate' => ($totalWins + $totalLosses) > 0 ? round(($totalWins / ($totalWins + $totalLosses)) * 100, 2) : 0.0,
                'active_days' => $activeDays,
                'avg_trades_per_day' => $activeDays > 0 ? round($totalTrades / $activeDays, 2) : 0.0,
                'peak_day' => $peakDay['date'] ?? null,
                'peak_day_trades' => (int) ($peakDay['trade_count'] ?? 0),
            ],
            'dailyBreakdowns' => $dailyBreakdowns,
            'dailyCounts' => $dailyCounts,
            'pipelineThresholds' => $pipelineThresholds,
            'filters' => [
                'start_date' => $startDate,
                'end_date' => $endDate,
            ],
        ]);
    }

    private function normalizeDate(mixed $value, string $fallback): string
    {
        if (! is_string($value) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return $fallback;
        }

        return $value;
    }
}
