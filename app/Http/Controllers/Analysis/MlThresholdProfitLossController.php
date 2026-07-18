<?php

namespace App\Http\Controllers\Analysis;

use App\Http\Controllers\Controller;
use App\Models\AlpacaOrder;
use App\Models\TradeAlert;
use App\Services\TradingSettingService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class MlThresholdProfitLossController extends Controller
{
    public function index(Request $request): Response
    {
        $allowedDays = range(1, 180);
        $days = (int) $request->input('days', 30);
        $allowedMinPercents = range(0, 100, 5);
        // -1 means "use per-pipeline .env thresholds" (handled client-side)
        $minPercent = (int) $request->input('min_percent', -1);

        if ($minPercent !== -1 && ! in_array($minPercent, $allowedMinPercents, true)) {
            $minPercent = 0;
        }

        if (! in_array($days, $allowedDays, true)) {
            $minPercent = 0;
        }

        $groupBy = $request->input('group_by', 'pipeline');

        if (! in_array($groupBy, ['combined', 'pipeline'], true)) {
            $groupBy = 'pipeline';
        }

        $mode = $request->input('mode', 'all');

        if (! in_array($mode, ['live', 'paper', 'all'], true)) {
            $mode = 'all';
        }

        $activeTimeSlotsOnly = $request->boolean('active_time_slots_only', false);

        $endDate = now()->format('Y-m-d');
        $startDate = Carbon::parse($endDate)->subDays($days - 1)->format('Y-m-d');

        $buyOrdersQuery = AlpacaOrder::query()
            ->where('side', 'buy')
            ->whereIn('status', ['filled', 'partially_filled'])
            ->where('filled_qty', '>', 0)
            ->whereNotNull('filled_at')
            ->whereBetween(DB::raw('DATE(CONVERT_TZ(filled_at, "+00:00", "-04:00"))'), [$startDate, $endDate])
            ->with('tradeAlert:id,ml_win_prob,pipeline_run,version,entry_ts_est')
            ->select([
                'id',
                'trade_alert_id',
                'alpaca_order_id',
                'parent_alpaca_order_id',
                'symbol',
                'side',
                'status',
                'filled_qty',
                'filled_avg_price',
                'filled_at',
                'notes',
                'is_paper',
            ])
            ->orderBy('filled_at');

        if ($mode === 'live') {
            $buyOrdersQuery->where('is_paper', false);
        } elseif ($mode === 'paper') {
            $buyOrdersQuery->where('is_paper', true);
        }

        $buyOrders = $buyOrdersQuery->get();

        $buySymbols = $buyOrders->pluck('symbol')->unique()->values()->all();

        $sellOrders = collect();

        if (! empty($buySymbols)) {
            $sellOrdersQuery = AlpacaOrder::query()
                ->whereIn('symbol', $buySymbols)
                ->where('side', 'sell')
                ->whereIn('status', ['filled', 'partially_filled'])
                ->where('filled_qty', '>', 0)
                ->whereNotNull('filled_at')
                ->whereRaw('DATE(CONVERT_TZ(filled_at, "+00:00", "-04:00")) >= ?', [$startDate])
                ->select([
                    'id',
                    'alpaca_order_id',
                    'parent_alpaca_order_id',
                    'symbol',
                    'side',
                    'status',
                    'filled_qty',
                    'filled_avg_price',
                    'filled_at',
                    'notes',
                    'is_paper',
                ])
                ->orderBy('filled_at');

            if ($mode === 'live') {
                $sellOrdersQuery->where('is_paper', false);
            } elseif ($mode === 'paper') {
                $sellOrdersQuery->where('is_paper', true);
            }

            $sellOrders = $sellOrdersQuery->get();
        }

        $directAlertIds = $buyOrders
            ->map(fn (AlpacaOrder $order) => $this->extractAlertId($order->notes))
            ->filter()
            ->unique()
            ->values();

        $directAlerts = collect();

        if ($directAlertIds->isNotEmpty()) {
            $directAlerts = TradeAlert::query()
                ->whereIn('id', $directAlertIds)
                ->get()
                ->keyBy('id');
        }

        $sellOrdersBySymbol = $sellOrders->groupBy('symbol');
        $usedSellIds = [];
        $trades = collect();

        foreach ($buyOrders as $buyOrder) {
            $buyFilledUtc = Carbon::parse($buyOrder->filled_at);
            $availableSells = $sellOrdersBySymbol->get($buyOrder->symbol, collect())
                ->reject(fn (AlpacaOrder $sellOrder) => in_array($sellOrder->alpaca_order_id, $usedSellIds, true))
                ->values();

            $matchedSell = $availableSells->first(function (AlpacaOrder $sellOrder) use ($buyOrder, $buyFilledUtc) {
                return $sellOrder->parent_alpaca_order_id === $buyOrder->alpaca_order_id
                    && Carbon::parse($sellOrder->filled_at)->greaterThan($buyFilledUtc);
            });

            if (! $matchedSell) {
                $matchedSell = $availableSells->first(function (AlpacaOrder $sellOrder) use ($buyFilledUtc) {
                    return Carbon::parse($sellOrder->filled_at)->greaterThan($buyFilledUtc);
                });
            }

            if (! $matchedSell) {
                continue;
            }

            $matchedAlert = $buyOrder->tradeAlert;

            if (! $matchedAlert) {
                $directAlertId = $this->extractAlertId($buyOrder->notes);
                $matchedAlert = $directAlertId ? $directAlerts->get($directAlertId) : null;
            }

            if (! $matchedAlert) {
                continue;
            }

            $usedSellIds[] = $matchedSell->alpaca_order_id;

            // Filter: only include trades whose entry_ts_est falls within active time slots
            if ($activeTimeSlotsOnly) {
                $entryTsEst = $matchedAlert->entry_ts_est;
                if (! $entryTsEst) {
                    continue;
                }
                $entryCarbon = Carbon::parse($entryTsEst, 'America/New_York');
                $slotMinute = (int) floor($entryCarbon->minute / 15) * 15;
                $slotKey = $entryCarbon->format('H').':'.str_pad((string) $slotMinute, 2, '0', STR_PAD_LEFT);

                if (! TradingSettingService::isTimeSlotEnabled($slotKey)) {
                    continue;
                }
            }

            $matchedQty = min((float) $buyOrder->filled_qty, (float) $matchedSell->filled_qty);
            $actualPnlDollar = round(((float) $matchedSell->filled_avg_price - (float) $buyOrder->filled_avg_price) * $matchedQty, 2);
            $actualPnlPercent = (float) $buyOrder->filled_avg_price > 0
                ? round((((float) $matchedSell->filled_avg_price - (float) $buyOrder->filled_avg_price) / (float) $buyOrder->filled_avg_price) * 100, 2)
                : 0.0;
            $pipelineRun = $matchedAlert->pipeline_run ?: 'Unknown';

            // Skip Manual pipeline trades
            if (strtoupper($pipelineRun) === 'MANUAL') {
                continue;
            }

            $mlWinProb = (float) $matchedAlert->ml_win_prob;
            $mlPct = round($mlWinProb * 100, 1);

            // Apply ML threshold filter
            if ($minPercent === -1) {
                // .env mode: use each trade's pipeline-specific threshold from settings
                $thresholds = TradingSettingService::getAllPipelineMlThresholds();
                $threshold = (float) ($thresholds[strtoupper($pipelineRun)] ?? $thresholds[strtolower($pipelineRun)] ?? 0.65);
                if ($mlWinProb < $threshold) {
                    continue;
                }
            } elseif ($mlPct < $minPercent) {
                continue;
            }

            $bucketStart = min(95, (int) floor($mlPct / 5) * 5);

            $trades->push([
                'trade_id' => $buyOrder->alpaca_order_id,
                'symbol' => $buyOrder->symbol,
                'pipeline_run' => $matchedAlert->pipeline_run ?: 'Unknown',
                'ml_win_prob' => $mlWinProb,
                'ml_pct' => $mlPct,
                'bucket_start' => $bucketStart,
                'bucket_label' => $this->formatBucketLabel($bucketStart),
                'is_paper' => (bool) $buyOrder->is_paper,
                'buy_filled_at' => Carbon::parse($buyOrder->filled_at)->format('Y-m-d H:i:s'),
                'sell_filled_at' => Carbon::parse($matchedSell->filled_at)->format('Y-m-d H:i:s'),
                'actual_qty' => $matchedQty,
                'trade_dollar_amount' => round($matchedQty * (float) $buyOrder->filled_avg_price, 2),
                'actual_fill' => round((float) $buyOrder->filled_avg_price, 2),
                'actual_exit_fill' => round((float) $matchedSell->filled_avg_price, 2),
                'actual_pnl_dollar' => $actualPnlDollar,
                'actual_pnl_percent' => $actualPnlPercent,
            ]);
        }

        $summary = $this->buildSummary($trades, $days, $startDate, $endDate);
        $combinedBreakdown = $this->buildBucketBreakdown($trades);
        $pipelineBreakdowns = $this->buildPipelineBreakdowns($trades);

        return Inertia::render('analysis/MlThresholdProfitLoss', [
            'summary' => $summary,
            'combinedBreakdown' => $combinedBreakdown,
            'pipelineBreakdowns' => $pipelineBreakdowns,
            'filters' => [
                'days' => $days,
                'group_by' => $groupBy,
                'mode' => $mode,
                'min_percent' => $minPercent,
                'start_date' => $startDate,
                'end_date' => $endDate,
                'active_time_slots_only' => $activeTimeSlotsOnly,
            ],
        ]);
    }

    private function buildSummary(Collection $trades, int $days, string $startDate, string $endDate): array
    {
        $totalTrades = $trades->count();
        $netPnl = round((float) $trades->sum('actual_pnl_dollar'), 2);
        $winningTrades = $trades->filter(fn (array $trade) => $trade['actual_pnl_dollar'] > 0)->count();
        $losingTrades = $trades->filter(fn (array $trade) => $trade['actual_pnl_dollar'] < 0)->count();

        return [
            'days' => $days,
            'start_date' => $startDate,
            'end_date' => $endDate,
            'total_trades' => $totalTrades,
            'winning_trades' => $winningTrades,
            'losing_trades' => $losingTrades,
            'win_rate' => $totalTrades > 0 ? round(($winningTrades / $totalTrades) * 100, 1) : 0.0,
            'net_pnl' => $netPnl,
            'avg_pnl' => $totalTrades > 0 ? round($netPnl / $totalTrades, 2) : 0.0,
        ];
    }

    private function buildBucketBreakdown(Collection $trades): array
    {
        return collect(range(0, 95, 5))
            ->map(function (int $bucketStart) use ($trades) {
                $bucketTrades = $trades->filter(fn (array $trade) => $trade['bucket_start'] === $bucketStart)->values();
                $bucketTotal = round((float) $bucketTrades->sum('actual_pnl_dollar'), 2);
                $bucketCount = $bucketTrades->count();
                $bucketWins = $bucketTrades->filter(fn (array $trade) => $trade['actual_pnl_dollar'] > 0)->count();
                $bucketLosses = $bucketTrades->filter(fn (array $trade) => $trade['actual_pnl_dollar'] < 0)->count();

                return [
                    'bucket_start' => $bucketStart,
                    'bucket_label' => $this->formatBucketLabel($bucketStart),
                    'trade_count' => $bucketCount,
                    'winning_trades' => $bucketWins,
                    'losing_trades' => $bucketLosses,
                    'win_rate' => $bucketCount > 0 ? round(($bucketWins / $bucketCount) * 100, 1) : 0.0,
                    'total_pnl' => $bucketTotal,
                    'avg_pnl' => $bucketCount > 0 ? round($bucketTotal / $bucketCount, 2) : 0.0,
                    'trades' => $bucketTrades->map(function (array $trade) {
                        return [
                            'trade_id' => $trade['trade_id'],
                            'symbol' => $trade['symbol'],
                            'pipeline_run' => $trade['pipeline_run'],
                            'ml_pct' => $trade['ml_pct'],
                            'buy_filled_at' => $trade['buy_filled_at'],
                            'sell_filled_at' => $trade['sell_filled_at'],
                            'actual_qty' => $trade['actual_qty'],
                            'trade_dollar_amount' => round((float) $trade['trade_dollar_amount'], 2),
                            'actual_fill' => round((float) $trade['actual_fill'], 2),
                            'actual_exit_fill' => round((float) $trade['actual_exit_fill'], 2),
                            'actual_pnl_dollar' => $trade['actual_pnl_dollar'],
                            'actual_pnl_percent' => round((float) $trade['actual_pnl_percent'], 2),
                            'mode' => $trade['is_paper'] ? 'paper' : 'live',
                        ];
                    })->values()->all(),
                ];
            })
            ->values()
            ->all();
    }

    private function buildPipelineBreakdowns(Collection $trades): array
    {
        return $trades
            ->groupBy('pipeline_run')
            ->map(function (Collection $pipelineTrades, string $pipelineRun) {
                $bucketBreakdown = $this->buildBucketBreakdown($pipelineTrades);
                $netPnl = round((float) $pipelineTrades->sum('actual_pnl_dollar'), 2);
                $tradeCount = $pipelineTrades->count();
                $winningTrades = $pipelineTrades->filter(fn (array $trade) => $trade['actual_pnl_dollar'] > 0)->count();

                return [
                    'pipeline_run' => $pipelineRun,
                    'trade_count' => $tradeCount,
                    'winning_trades' => $winningTrades,
                    'win_rate' => $tradeCount > 0 ? round(($winningTrades / $tradeCount) * 100, 1) : 0.0,
                    'net_pnl' => $netPnl,
                    'avg_pnl' => $tradeCount > 0 ? round($netPnl / $tradeCount, 2) : 0.0,
                    'buckets' => $bucketBreakdown,
                ];
            })
            ->sortByDesc('net_pnl')
            ->values()
            ->all();
    }

    private function extractAlertId(?string $notes): ?int
    {
        if (! $notes) {
            return null;
        }

        if (preg_match('/alert_id:(\d+)/', $notes, $matches)) {
            return (int) $matches[1];
        }

        return null;
    }

    private function formatBucketLabel(int $bucketStart): string
    {
        $bucketEnd = $bucketStart === 95 ? 100 : $bucketStart + 5;

        return sprintf('%d-%d%%', $bucketStart, $bucketEnd);
    }
}
