<?php

namespace App\Http\Controllers;

use App\Models\MarketMover;
use App\Services\MarketMoversService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\StreamedResponse;

class MarketMoversController extends Controller
{
    public function __construct(private MarketMoversService $service) {}

    public function index(Request $request)
    {
        $days = $request->integer('days', 30);
        $days = min(365, max(1, $days)); // Limit between 1-365 days

        $startDate = now('America/New_York')->subDays($days)->format('Y-m-d');
        $endDate = now('America/New_York')->format('Y-m-d');

        $moversData = MarketMover::whereBetween('trading_date', [$startDate, $endDate])
            ->orderBy('trading_date', 'desc')
            ->get()
            ->map(function ($record) {
                return [
                    'date' => $record->trading_date->format('Y-m-d'),
                    'bars_4pct_plus' => $record->bars_4pct_plus,
                    'bars_5pct_plus' => $record->bars_5pct_plus,
                    'bars_10pct_plus' => $record->bars_10pct_plus,
                    'max_gain' => $record->max_gain,
                    'strength' => $record->strength,
                    'label' => $record->label,
                    'top_movers' => $record->movers,
                ];
            });

        if ($moversData->isEmpty()) {
            // Fall back to live calculation if the stored tables are unavailable for the range.
            $calculatedData = $this->service->calculateForDateRange($startDate, $endDate);
            $moversData = collect($calculatedData)->map(function ($data) {
                return [
                    'date' => $data['date'],
                    'bars_4pct_plus' => $data['bars_4pct_plus'],
                    'bars_5pct_plus' => $data['bars_5pct_plus'],
                    'bars_10pct_plus' => $data['bars_10pct_plus'],
                    'max_gain' => $data['max_gain'],
                    'strength' => $data['strength'],
                    'label' => $data['label'],
                    'top_movers' => $data['movers'],
                ];
            });
        }

        $avgStrength = $moversData->avg('strength');

        // Collect all unique symbols across all days to build asset ID map
        $allSymbols = $moversData->flatMap(fn ($row) => collect($row['top_movers'])->pluck('symbol'))->unique()->values()->toArray();

        $assetIds = [];
        if (! empty($allSymbols)) {
            $assets = DB::table('asset_info')
                ->select('symbol', 'id')
                ->whereIn('symbol', $allSymbols)
                ->where('asset_type', 'stock')
                ->get();

            foreach ($assets as $asset) {
                $assetIds[$asset->symbol] = $asset->id;
            }
        }

        return Inertia::render('market-movers/index', [
            'data' => $moversData,
            'days' => $days,
            'avgStrength' => round($avgStrength, 1),
            'startDate' => $startDate,
            'endDate' => $endDate,
            'assetIds' => $assetIds,
        ]);
    }

    public function export(Request $request): StreamedResponse
    {
        $days = $request->integer('days', 30);
        $days = min(365, max(1, $days));

        $startDate = now('America/New_York')->subDays($days)->format('Y-m-d');
        $endDate = now('America/New_York')->format('Y-m-d');

        // Try to get data from database first
        $moversData = MarketMover::whereBetween('trading_date', [$startDate, $endDate])
            ->orderBy('trading_date', 'desc')
            ->get()
            ->map(function ($record) {
                return [
                    'date' => $record->trading_date->format('Y-m-d'),
                    'bars_4pct_plus' => $record->bars_4pct_plus,
                    'bars_5pct_plus' => $record->bars_5pct_plus,
                    'bars_10pct_plus' => $record->bars_10pct_plus,
                    'max_gain' => $record->max_gain,
                    'strength' => $record->strength,
                    'label' => $record->label,
                    'top_movers' => $record->movers,
                ];
            });

        // If no data in database, calculate on the fly
        if ($moversData->isEmpty()) {
            $calculatedData = $this->service->calculateForDateRange($startDate, $endDate);
            $moversData = collect($calculatedData)->map(function ($data) {
                return [
                    'date' => $data['date'],
                    'bars_4pct_plus' => $data['bars_4pct_plus'],
                    'bars_5pct_plus' => $data['bars_5pct_plus'],
                    'bars_10pct_plus' => $data['bars_10pct_plus'],
                    'max_gain' => $data['max_gain'],
                    'strength' => $data['strength'],
                    'label' => $data['label'],
                    'top_movers' => $data['movers'],
                ];
            });
        }

        $filename = 'market-movers-'.$startDate.'-to-'.$endDate.'.csv';

        return response()->streamDownload(function () use ($moversData) {
            $handle = fopen('php://output', 'w');

            // Add CSV headers
            fputcsv($handle, [
                'Date',
                'Strength Score',
                'Status',
                '4%+ Bars',
                '5%+ Bars',
                '10%+ Bars',
                'Max Gain %',
                'All Movers (Symbol: %)',
            ]);

            // Add data rows
            foreach ($moversData as $row) {
                $moversString = collect($row['top_movers'])->map(function ($mover) {
                    return $mover['symbol'].': '.$mover['gain_pct'].'%';
                })->implode(', ');

                fputcsv($handle, [
                    $row['date'],
                    $row['strength'],
                    $row['label'],
                    $row['bars_4pct_plus'],
                    $row['bars_5pct_plus'],
                    $row['bars_10pct_plus'],
                    $row['max_gain'],
                    $moversString,
                ]);
            }

            fclose($handle);
        }, $filename, [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ]);
    }
}
