<?php

namespace App\Http\Controllers\Analysis;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class PipelineCountsController extends Controller
{
    public function index(Request $request): Response
    {
        $fromDate = $request->input('date');

        $query = DB::table('trade_alerts')
            ->select(
                'pipeline_run',
                DB::raw('COUNT(*) as total_alerts'),
                DB::raw('MIN(trading_date_est) as first_date'),
                DB::raw('MAX(trading_date_est) as last_date'),
                DB::raw('COUNT(DISTINCT trading_date_est) as trading_days'),
                DB::raw('COUNT(DISTINCT symbol) as unique_symbols')
            );

        if ($fromDate && preg_match('/^\d{4}-\d{2}-\d{2}$/', $fromDate)) {
            $query->where('trading_date_est', '>=', $fromDate);
        }

        $counts = $query
            ->groupBy('pipeline_run')
            ->orderBy('pipeline_run')
            ->get();

        return Inertia::render('analysis/PipelineCounts', [
            'counts' => $counts,
            'filters' => [
                'date' => $fromDate,
            ],
        ]);
    }
}
