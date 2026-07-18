<?php

namespace App\Http\Controllers\Analysis;

use App\Http\Controllers\Controller;
use App\Services\TradingSettingService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class VwapStatusController extends Controller
{
    /**
     * Show 5-minute VWAP status bars for a selectable trading date.
     * Each bar shows the benchmark symbol's price relative to its intraday VWAP.
     */
    public function index(Request $request): Response
    {
        $date = $request->input('date', Carbon::today('America/New_York')->toDateString());

        // Validate date format
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            $date = Carbon::today('America/New_York')->toDateString();
        }

        $benchmarkSymbol = TradingSettingService::getBenchmarkSymbol();
        $gateEnabled = TradingSettingService::isBenchmarkVwapGateEnabled();
        $maxPctBelowHigh = TradingSettingService::getBenchmarkMaxPctBelowHigh();
        $pipelineOverrides = TradingSettingService::getAllPipelineBenchmarkVwapGateOverrides();

        // Fetch all 5-min bars for the selected date
        $bars = DB::connection('mysql')
            ->table('five_minute_prices')
            ->where('symbol', $benchmarkSymbol)
            ->where('asset_type', 'stock')
            ->where('trading_date_est', $date)
            ->whereNotNull('vwap')
            ->orderBy('ts_est')
            ->get(['ts_est', 'price', 'vwap', 'above_vwap', 'vwap_dist_pct']);

        // Compute intraday high for the secondary check
        $intradayHigh = $bars->max('price') ?? 0;

        // Enrich each bar with gate pass/fail status
        $enrichedBars = $bars->map(function ($bar) use ($maxPctBelowHigh, $intradayHigh) {
            $dist = (float) $bar->vwap_dist_pct;
            $belowVwap = $dist < 0;
            $barPrice = (float) $bar->price;

            $belowHighPct = null;
            $failsBelowHigh = false;
            if ($maxPctBelowHigh !== null && $intradayHigh > 0) {
                $belowHighPct = round((($intradayHigh - $barPrice) / $intradayHigh) * 100, 2);
                $failsBelowHigh = $belowHighPct >= $maxPctBelowHigh;
            }

            // Always compute whether the bar WOULD be blocked (regardless of gate toggle).
            // The actual order placement enforcement in PlaceAlpacaOrderForHighScoreAlerts.php
            // still gates on $gateEnabled.
            $wouldBlock = $belowVwap || $failsBelowHigh;
            $blockReason = null;
            if ($belowVwap) {
                $blockReason = 'Below VWAP';
            } elseif ($failsBelowHigh) {
                $blockReason = "{$belowHighPct}% below intraday high";
            }

            return [
                'ts_est' => $bar->ts_est,
                'time' => substr($bar->ts_est, 11, 5),
                'price' => $barPrice,
                'vwap' => (float) $bar->vwap,
                'above_vwap' => (bool) $bar->above_vwap,
                'vwap_dist_pct' => $dist,
                'below_high_pct' => $belowHighPct,
                'gate_would_block' => $wouldBlock,
                'block_reason' => $blockReason,
            ];
        });

        // Count would-block bars
        $blockedCount = $enrichedBars->filter(fn ($b) => $b['gate_would_block'])->count();
        $passedCount = $enrichedBars->count() - $blockedCount;

        return Inertia::render('analysis/vwap-status/index', [
            'date' => $date,
            'benchmarkSymbol' => $benchmarkSymbol,
            'gateEnabled' => $gateEnabled,
            'maxPctBelowHigh' => $maxPctBelowHigh,
            'pipelineOverrides' => $pipelineOverrides,
            'bars' => $enrichedBars->values(),
            'intradayHigh' => $intradayHigh,
            'blockedCount' => $blockedCount,
            'passedCount' => $passedCount,
            'totalBars' => $enrichedBars->count(),
        ]);
    }
}
