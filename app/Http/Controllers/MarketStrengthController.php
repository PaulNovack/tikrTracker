<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\StreamedResponse;

class MarketStrengthController extends Controller
{
    public function index(Request $request)
    {
        $days = $request->integer('days', 30);
        $days = min(365, max(1, $days)); // Limit between 1-365 days

        $startDate = now('America/New_York')->subDays($days)->format('Y-m-d');
        $endDate = now('America/New_York')->format('Y-m-d');

        $strengthData = $this->getMarketStrengthData($startDate, $endDate);
        $avgStrength = $strengthData->avg('strength');

        return Inertia::render('market-strength/index', [
            'data' => $strengthData,
            'days' => $days,
            'avgStrength' => round($avgStrength, 1),
            'startDate' => $startDate,
            'endDate' => $endDate,
        ]);
    }

    public function export(Request $request): StreamedResponse
    {
        $days = $request->integer('days', 30);
        $days = min(365, max(1, $days));

        $startDate = now('America/New_York')->subDays($days)->format('Y-m-d');
        $endDate = now('America/New_York')->format('Y-m-d');

        $strengthData = $this->getMarketStrengthData($startDate, $endDate);

        $filename = 'market-strength-'.$startDate.'-to-'.$endDate.'.csv';

        return response()->streamDownload(function () use ($strengthData) {
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
            ]);

            // Add data rows
            foreach ($strengthData as $row) {
                fputcsv($handle, [
                    $row['date'],
                    $row['strength'],
                    $row['label'],
                    $row['bars_4pct_plus'],
                    $row['bars_5pct_plus'],
                    $row['bars_10pct_plus'],
                    $row['max_gain'],
                ]);
            }

            fclose($handle);
        }, $filename, [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ]);
    }

    private function getMarketStrengthData(string $startDate, string $endDate)
    {
        // Query market strength data
        $results = DB::select('
            SELECT 
                trading_date_est,
                COUNT(CASE WHEN ((price - open) / open) * 100 >= 10 THEN 1 END) as bars_10pct_plus,
                COUNT(CASE WHEN ((price - open) / open) * 100 >= 5 THEN 1 END) as bars_5pct_plus,
                COUNT(CASE WHEN ((price - open) / open) * 100 >= 4 THEN 1 END) as bars_4pct_plus,
                ROUND(MAX(((price - open) / open) * 100), 2) as max_gain
            FROM five_minute_prices
            WHERE open > 0 AND trading_date_est >= ? AND trading_date_est <= ?
            GROUP BY trading_date_est
            ORDER BY trading_date_est DESC
        ', [$startDate, $endDate]);

        // Calculate strength scores (0-100 scale, 200 bars = 100%)
        $maxBars = 200;

        return collect($results)->map(function ($row) use ($maxBars) {
            $strength = min(100, round(($row->bars_4pct_plus / $maxBars) * 100));
            $label = $strength >= 70 ? 'STRONG' : ($strength >= 40 ? 'MODERATE' : 'WEAK');

            return [
                'date' => $row->trading_date_est,
                'bars_4pct_plus' => $row->bars_4pct_plus,
                'bars_5pct_plus' => $row->bars_5pct_plus,
                'bars_10pct_plus' => $row->bars_10pct_plus,
                'max_gain' => $row->max_gain,
                'strength' => $strength,
                'label' => $label,
            ];
        });
    }
}
