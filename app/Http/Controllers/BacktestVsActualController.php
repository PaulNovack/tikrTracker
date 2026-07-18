<?php

namespace App\Http\Controllers;

use App\Services\AtrPerformanceService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class BacktestVsActualController extends Controller
{
    public function __construct(private readonly AtrPerformanceService $atrPerformanceService) {}

    /**
     * Try to load scanner name directly from the scanner class.
     * Converts version string (e.g., "v140.0") to class name (e.g., "FiveMinuteSignalScannerV140_0")
     */
    private function getScannerNameFromClass(string $version): ?string
    {
        // Remove 'v' prefix and replace dots with underscores
        $versionClean = str_replace(['.', '-'], '_', ltrim($version, 'v'));
        $className = "App\\Services\\Trading\\FiveMinuteSignalScannerV{$versionClean}";

        if (class_exists($className)) {
            try {
                $scanner = app($className);
                if (method_exists($scanner, 'getName')) {
                    return $scanner->getName();
                }
            } catch (\Throwable $e) {
                // Fall back to hardcoded array
            }
        }

        return null;
    }

    /**
     * Map of version string => human-readable scanner name.
     * Used as fallback for versions without scanner classes.
     *
     * @return array<string, string>
     */
    private function scannerNames(): array
    {
        return [
            'v1.0' => 'Biased Scanner',
            'v1.0-biased' => 'Biased Scanner',
            'v14.0' => 'Intraday Swing',
            'v16.0' => 'Base Pattern',
            'v17.0' => 'Base Pattern',
            'v18.0' => 'Earnings Momentum',
            'v20.0' => 'Alligator Wake-Up',
            'v21.0' => 'Alligator Wake-Up',
            'v22.0' => 'Alligator Wake-Up',
            'v25.0' => 'Institutional Fade Detection',
            'v25.1' => 'Institutional Fade Detection',
            'v25.2' => 'Quality-First',
            'v26.0' => 'Hybrid',
            'v26.1' => 'Institutional Fade Detection',
            'v30.0' => 'Momentum 5M',
            'v31.0' => 'Momentum 5M',
            'v40.0' => 'Runner Momentum',
            'v40.1' => 'Runner Momentum',
            'v50.0' => 'Entry Score Based',
            'v60.0' => 'Hybrid Breakout',
            'v60.1' => 'Hybrid Breakout',
            'v60.2' => 'Hybrid Breakout',
            'v60.3' => 'Hybrid Breakout',
            'v70.0' => 'RSI Momentum Divergence',
            'v80.1' => 'Multi-Timeframe Confirmation',
            'v90.0' => 'Momentum Continuation',
            'v90.1' => 'Momentum Continuation',
            'v100.0' => 'Bottom Detection',
            'v120.0' => 'Elite Multi-Day Momentum',
            'v130.0' => 'Elite Momentum Extended',
            'v140.0' => 'Institutional Follow-Through',
            'v200.0' => 'TPB Trend-Pullback-Breakout',
            'v210.0' => 'Oversold Bounce',
            'v300.0' => 'Reversal Reclaim',
            'v400.0' => 'Multi-Day Pattern Continuation',
            'v600.0' => 'Hybrid Big-Move Breakout',
            'v700.0' => 'Risk-Off Winners',
            'v800.0' => 'Mean Reversion / Fade',
            'v810.0' => 'Market Scan Daily Upswing',
            'v820.0' => 'Pattern-Based Fade Detection',
            'v830.0' => 'Intraday Breakout/Reversal',
            'v900.0' => 'Momentum Continuation',
            'v900.1' => 'Risk-Off Winners',
            'v1100.0' => 'Scarcity Leader (RS vs SPY)',
            'v1200.0' => 'Two-Bar Momentum',
            'v1400.0' => 'Tight Stops Clean Trend',
            'v1500.0' => 'Opening Range Breakout',
            'v1600.0' => 'Early Momentum Pre-Breakout',
            'v2000.0' => 'Market Movers Universe',
            'v2000.1' => 'Market Movers Universe',
            'v2100.0' => 'Forward-Looking 2-Hour Runner',
        ];
    }

    /** Resolve "Name (vX.X)" label for a given version string. */
    private function versionLabel(string $version, string $pipelineLetter = ''): string
    {
        // Try loading from scanner class first
        $name = $this->getScannerNameFromClass($version);

        // Fall back to hardcoded array
        if (! $name) {
            $name = $this->scannerNames()[$version] ?? '';
        }

        $prefix = $pipelineLetter ? "{$pipelineLetter}: " : '';

        return $name ? "{$prefix}{$name} ({$version})" : "{$prefix}{$version}";
    }

    public function index(Request $request): Response
    {
        $startDate = $request->input('start_date') ?? Carbon::today('America/New_York')->format('Y-m-d');
        $endDate = $request->input('end_date') ?? Carbon::today('America/New_York')->format('Y-m-d');
        $mlThreshold = (float) ($request->input('ml_threshold') ?? 0.0);
        $versionFilter = $request->input('version') ?? null;
        $maxSlippage = $request->input('max_slippage') !== null ? (float) $request->input('max_slippage') : null;
        $excludedPipelineRuns = ['X'];

        // Ground truth: start from actual Alpaca buy fills, then pair each one to the
        // corresponding sell fill by symbol and execution order.
        // Alpaca parent pointers are not always reliable for the exit leg, so we prefer
        // a direct parent match when available and otherwise fall back to the first sell
        // fill for the same symbol after the buy fill.
        $buyOrders = DB::table('alpaca_orders as bo')
            ->where('bo.side', 'buy')
            ->whereIn('bo.status', ['filled', 'partially_filled'])
            ->whereNotNull('bo.filled_at')
            ->whereBetween(DB::raw('DATE(CONVERT_TZ(bo.filled_at, "+00:00", "-04:00"))'), [$startDate, $endDate])
            ->select([
                'bo.alpaca_order_id',
                'bo.symbol',
                'bo.notes',
                DB::raw('DATE(CONVERT_TZ(bo.filled_at, "+00:00", "-04:00")) as trade_date'),
                'bo.created_at as order_placed_at',
                'bo.filled_at as buy_filled_at',
                'bo.filled_avg_price as actual_fill',
                'bo.filled_qty as actual_qty',
            ])
            ->orderBy('bo.filled_at')
            ->get();

        $buySymbols = $buyOrders->pluck('symbol')->unique()->values()->all();

        $sellOrders = DB::table('alpaca_orders as so')
            ->whereIn('so.symbol', $buySymbols)
            ->where('so.side', 'sell')
            ->whereIn('so.status', ['filled', 'partially_filled'])
            ->whereNotNull('so.filled_at')
            ->whereRaw('DATE(CONVERT_TZ(so.filled_at, "+00:00", "-04:00")) >= ?', [$startDate])
            ->select([
                'so.alpaca_order_id',
                'so.parent_alpaca_order_id',
                'so.symbol',
                'so.notes',
                'so.filled_at as sell_filled_at',
                'so.filled_avg_price as actual_exit_fill',
                'so.filled_qty as actual_exit_qty',
            ])
            ->orderBy('so.filled_at')
            ->get();

        $sellOrdersBySymbol = $sellOrders->groupBy('symbol');
        // Track remaining sell qty per sell order (sell can cover multiple buys)
        $sellRemainingQty = [];
        foreach ($sellOrders as $sellOrder) {
            $sellRemainingQty[$sellOrder->alpaca_order_id] = (float) $sellOrder->actual_exit_qty;
        }
        $alpacaTrades = collect();

        foreach ($buyOrders as $buyOrder) {
            $buyFilledUtc = Carbon::parse($buyOrder->buy_filled_at);
            $buyQty = (float) $buyOrder->actual_qty;

            $availableSells = $sellOrdersBySymbol->get($buyOrder->symbol, collect())
                ->filter(fn ($sellOrder) => ($sellRemainingQty[$sellOrder->alpaca_order_id] ?? 0) > 0)
                ->values();

            $matchedSell = $availableSells->first(function ($sellOrder) use ($buyOrder, $buyFilledUtc) {
                return $sellOrder->parent_alpaca_order_id === $buyOrder->alpaca_order_id
                    && Carbon::parse($sellOrder->sell_filled_at)->greaterThan($buyFilledUtc);
            });

            if (! $matchedSell) {
                $matchedSell = $availableSells->first(function ($sellOrder) use ($buyFilledUtc) {
                    return Carbon::parse($sellOrder->sell_filled_at)->greaterThan($buyFilledUtc);
                });
            }

            if (! $matchedSell) {
                continue;
            }

            // Consume from sell qty — allow partial consumption so one sell
            // can cover multiple buys (e.g. sell 70 covers two 35-share buys).
            $sellId = $matchedSell->alpaca_order_id;
            $matchedQty = min($buyQty, $sellRemainingQty[$sellId]);
            $sellRemainingQty[$sellId] -= $matchedQty;
            if ($sellRemainingQty[$sellId] <= 0) {
                unset($sellRemainingQty[$sellId]);
            }

            $alpacaTrades->push((object) [
                'alpaca_order_id' => $buyOrder->alpaca_order_id,
                'symbol' => $buyOrder->symbol,
                'notes' => $buyOrder->notes,
                'trade_date' => $buyOrder->trade_date,
                'order_placed_at' => $buyOrder->order_placed_at,
                'buy_filled_at' => $buyOrder->buy_filled_at,
                'actual_fill' => $buyOrder->actual_fill,
                'actual_qty' => $matchedQty,
                'actual_exit_fill' => $matchedSell->actual_exit_fill,
                'actual_exit_qty' => $matchedQty,
            ]);
        }

        // Pre-load all alert IDs referenced in buy order notes for direct matching.
        $directAlertIds = $alpacaTrades->map(function ($trade) {
            if (preg_match('/alert_id:(\d+)/', $trade->notes ?? '', $m)) {
                return (int) $m[1];
            }

            return null;
        })->filter()->unique()->values()->all();

        $directAlerts = collect();
        if (! empty($directAlertIds)) {
            $directAlerts = DB::table('trade_alerts')
                ->whereNotIn('pipeline_run', $excludedPipelineRuns)
                ->whereIn('id', $directAlertIds)
                ->select([
                    'id', 'symbol', 'version', 'entry', 'stop', 'exit_price',
                    'pnl_percent', 'pnl_dollar', 'calculated_position_size', 'ml_win_prob',
                    'entry_ts_est', 'signal_ts_est',
                    'analyzed', 'atr_pct', 'suggested_trailing_stop_pct', 'risk_pct',
                    'score', 'vol_ratio', 'entry_type', 'pipeline_run',
                    DB::raw('DATE(CONVERT_TZ(entry_ts_est, "+00:00", "-04:00")) as alert_date'),
                ])
                ->get()
                ->keyBy('id');
        }

        // Pre-load trade_alerts for the date range, grouped by symbol+date.
        // Used as fallback when no direct alert_id link exists.
        $alertQuery = DB::table('trade_alerts')
            ->whereBetween(DB::raw('DATE(CONVERT_TZ(entry_ts_est, "+00:00", "-04:00"))'), [$startDate, $endDate])
            ->whereNotIn('pipeline_run', $excludedPipelineRuns)
            ->select([
                'id', 'symbol', 'version', 'entry', 'stop', 'exit_price',
                'pnl_percent', 'pnl_dollar', 'calculated_position_size', 'ml_win_prob',
                'entry_ts_est', 'signal_ts_est',
                'analyzed', 'atr_pct', 'suggested_trailing_stop_pct', 'risk_pct',
                'score', 'vol_ratio', 'entry_type', 'pipeline_run',
                DB::raw('DATE(CONVERT_TZ(entry_ts_est, "+00:00", "-04:00")) as alert_date'),
            ]);

        if ($versionFilter) {
            $alertQuery->where('version', $versionFilter);
        }

        $alertsBySymbolDate = $alertQuery->get()->groupBy(fn ($a) => $a->symbol.'|'.$a->alert_date);

        $trades = $alpacaTrades->map(function ($trade) use ($alertsBySymbolDate, $mlThreshold, $versionFilter, $directAlerts) {
            // First: try to match directly via alert_id stored in the buy order notes.
            $directAlertId = null;
            if (preg_match('/alert_id:(\d+)/', $trade->notes ?? '', $m)) {
                $directAlertId = (int) $m[1];
            }

            $matchedAlert = $directAlertId ? ($directAlerts->get($directAlertId) ?? null) : null;

            // Legacy fallback: only when notes do not include alert_id.
            // If alert_id is present, require direct matching to avoid accidental mismatches.
            if (! $matchedAlert && $directAlertId === null) {
                $key = $trade->symbol.'|'.$trade->trade_date;
                $candidates = $alertsBySymbolDate->get($key, collect());

                $buyFilledUtc = \Carbon\Carbon::parse($trade->buy_filled_at);
                $sortByProximity = fn ($subset) => $subset->sortBy(function ($a) use ($buyFilledUtc) {
                    $signalUtc = \Carbon\Carbon::createFromFormat('Y-m-d H:i:s', $a->entry_ts_est, 'America/New_York');

                    return abs($buyFilledUtc->diffInSeconds($signalUtc));
                })->first();

                $thresholdCandidates = $mlThreshold > 0
                    ? $candidates->filter(fn ($a) => (float) $a->ml_win_prob >= $mlThreshold)
                    : $candidates;

                $matchedAlert = $thresholdCandidates->isNotEmpty()
                    ? $sortByProximity($thresholdCandidates)
                    : $sortByProximity($candidates);
            }

            $buyFilledUtc = \Carbon\Carbon::parse($trade->buy_filled_at);

            $matchedQty = (float) $trade->actual_qty;
            $actualPnlDollar = round(((float) $trade->actual_exit_fill - (float) $trade->actual_fill) * $matchedQty, 2);
            $actualPnlPct = (float) $trade->actual_fill > 0
                ? round((((float) $trade->actual_exit_fill - (float) $trade->actual_fill) / (float) $trade->actual_fill) * 100, 2)
                : null;

            $signalPositionDollars = $matchedAlert ? (float) ($matchedAlert->calculated_position_size ?? 0) : 0;

            $computedPnl = $matchedAlert ? $this->atrPerformanceService->computePnlForAlert($matchedAlert) : null;
            $signalPnlPct = $computedPnl ? $computedPnl['pnl_percent'] : ($matchedAlert ? (float) $matchedAlert->pnl_percent : null);
            $signalExitPrice = $computedPnl ? $computedPnl['exit_price'] : ($matchedAlert ? (float) $matchedAlert->exit_price : null);
            // Use the price the simulation actually entered from (signal_ts_est bar price when it
            // differs from entry_ts_est) so Signal Entry, BT P&L%, and Signal Exit are all consistent.
            $signalEntryPrice = $computedPnl ? $computedPnl['entry_price'] : ($matchedAlert ? (float) $matchedAlert->entry : null);

            // Slippage: how far the actual fill was from the price the BT simulation used as entry.
            $entrySlippagePct = ($matchedAlert && $signalEntryPrice > 0)
                ? round((((float) $trade->actual_fill - $signalEntryPrice) / $signalEntryPrice) * 100, 2)
                : null;

            $backtestPnlDollar = ($matchedAlert && $signalPositionDollars > 0 && $signalPnlPct !== null)
                ? round($signalPositionDollars * ($signalPnlPct / 100), 2)
                : null;

            $mlPct = $matchedAlert ? round((float) $matchedAlert->ml_win_prob * 100, 1) : null;

            // Skip trades with no matching signal ONLY when a version filter is
            // not active. With no version filter, unmatched trades are noise.
            // With a version filter, real trades should always show even if the
            // matching alert was created by a different pipeline version.
            if (! $matchedAlert && ! $versionFilter) {
                return null;
            }

            // Apply ML threshold filter post-match (affects direct-matched alerts too).
            if ($mlThreshold > 0 && (float) $matchedAlert->ml_win_prob < $mlThreshold) {
                return null;
            }

            // Apply version filter post-match: only filter when there IS a match.
            // Unmatched trades are allowed through so real trades always display.
            if ($versionFilter && $matchedAlert && $matchedAlert->version !== $versionFilter) {
                return null;
            }

            $signalTimeEt = $matchedAlert
                ? \Carbon\Carbon::createFromFormat('Y-m-d H:i:s', $matchedAlert->entry_ts_est, 'America/New_York')->format('H:i')
                : null;
            $fillTimeEt = \Carbon\Carbon::parse($trade->buy_filled_at)->setTimezone('America/New_York')->format('H:i');
            $signalCarbonForStale = $matchedAlert
                ? \Carbon\Carbon::createFromFormat('Y-m-d H:i:s', $matchedAlert->entry_ts_est, 'America/New_York')
                : null;
            // Positive = fill came after signal (stale), negative = fill came before signal.
            $minutesStale = $signalCarbonForStale
                ? (int) round($signalCarbonForStale->diffInSeconds($buyFilledUtc, false) / 60)
                : null;

            // Signal age at order placement time (when PHP placed the Alpaca order, before broker fill).
            $orderPlacedUtc = \Carbon\Carbon::parse($trade->order_placed_at);
            $signalAgeAtOrder = $signalCarbonForStale
                ? (int) round($signalCarbonForStale->diffInSeconds($orderPlacedUtc, false) / 60)
                : null;

            return [
                'signal_date' => $trade->trade_date,
                'signal_time' => $signalTimeEt,
                'fill_time' => $fillTimeEt,
                'minutes_stale' => $minutesStale,
                'signal_age_at_order' => $signalAgeAtOrder,
                'symbol' => $trade->symbol,
                'version' => $matchedAlert?->version,
                'ml_pct' => $mlPct,
                'signal_entry' => $signalEntryPrice,
                'signal_exit' => $signalExitPrice,
                'signal_pnl_pct' => $signalPnlPct,
                'signal_position_size' => $signalPositionDollars ?: null,
                'actual_fill' => (float) $trade->actual_fill,
                'actual_exit_fill' => (float) $trade->actual_exit_fill,
                'actual_qty' => $matchedQty,
                'actual_position_size' => round((float) $trade->actual_fill * $matchedQty, 2),
                'entry_slippage_pct' => $entrySlippagePct,
                'actual_pnl_pct' => $actualPnlPct,
                'backtest_pnl_dollar' => $backtestPnlDollar,
                'actual_pnl_dollar' => $actualPnlDollar,
                'has_signal' => $matchedAlert !== null,
            ];
        })->filter()->values();

        // Apply entry slippage cap filter
        if ($maxSlippage !== null) {
            $trades = $trades->filter(fn ($t) => $t['entry_slippage_pct'] !== null && $t['entry_slippage_pct'] <= $maxSlippage)->values();
        }

        $summary = [
            'total_trades' => $trades->count(),
            'avg_entry_slippage_pct' => $trades->count() > 0
                ? round($trades->avg('entry_slippage_pct'), 2)
                : null,
            'backtest_total_dollar' => round($trades->sum('backtest_pnl_dollar'), 2),
            'actual_total_dollar' => round($trades->sum('actual_pnl_dollar'), 2),
        ];

        $page = (int) $request->input('page', 1);
        $perPage = 50;
        $paginated = new LengthAwarePaginator(
            $trades->forPage($page, $perPage)->values(),
            $trades->count(),
            $perPage,
            $page,
            ['path' => $request->url(), 'query' => $request->except('page')]
        );

        // Available versions for filter
        $versionToPipeline = DB::table('trade_alerts')
            ->whereNotNull('exit_price')
            ->whereNotIn('pipeline_run', $excludedPipelineRuns)
            ->whereNotNull('pipeline_run')
            ->select('version', 'pipeline_run')
            ->distinct()
            ->get()
            ->mapWithKeys(fn ($row) => [$row->version => $row->pipeline_run]);

        $versions = DB::table('trade_alerts')
            ->whereNotNull('exit_price')
            ->whereNotIn('pipeline_run', $excludedPipelineRuns)
            ->select('version')
            ->distinct()
            ->pluck('version')
            ->map(fn ($v) => [
                'value' => $v,
                'label' => isset($versionToPipeline[$v])
                    ? $this->versionLabel($v, $versionToPipeline[$v])
                    : $this->versionLabel($v),
                'sort' => $versionToPipeline[$v] ?? 'ZZ',
            ])
            ->push([
                'value' => config('app.trade_alert_j_version', 'v2000.0'),
                'label' => $this->versionLabel(config('app.trade_alert_j_version', 'v2000.0'), 'J'),
                'sort' => 'J',
            ])
            ->push([
                'value' => config('app.trade_alert_p_version', 'v140.0'),
                'label' => $this->versionLabel(config('app.trade_alert_p_version', 'v140.0'), 'P'),
                'sort' => 'P',
            ])
            ->push([
                'value' => config('app.trade_alert_q_version', 'v27.0'),
                'label' => $this->versionLabel(config('app.trade_alert_q_version', 'v27.0'), 'Q'),
                'sort' => 'Q',
            ])
            ->push([
                'value' => config('app.trade_alert_q_version', 'v27.0'),
                'label' => $this->versionLabel(config('app.trade_alert_q_version', 'v27.0'), 'Q'),
                'sort' => 'Q',
            ])
            ->unique('value')
            ->sortBy('sort')
            ->map(fn ($v) => ['value' => $v['value'], 'label' => $v['label']])
            ->values();

        return Inertia::render('backtest-vs-actual/index', [
            'trades' => $paginated,
            'summary' => $summary,
            'versions' => $versions,
            'filters' => [
                'start_date' => $startDate,
                'end_date' => $endDate,
                'ml_threshold' => $mlThreshold,
                'version' => $versionFilter,
                'max_slippage' => $maxSlippage,
            ],
        ]);
    }
}
