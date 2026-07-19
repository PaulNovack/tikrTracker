<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Inertia\Inertia;
use Inertia\Response;

class CandlestickScreenerController extends Controller
{
    private string $flaskBaseUrl = 'http://127.0.0.1:5000';

    /**
     * Display the TA-Lib candlestick pattern screener.
     */
    public function index(Request $request): Response
    {
        $pattern = $request->get('pattern', '');
        $limit = (int) $request->get('limit', 750);

        $patterns = [];
        $results = null;
        $error = null;

        // Fetch available patterns from Flask
        try {
            $patternsResponse = Http::timeout(5)->get("{$this->flaskBaseUrl}/api/patterns");

            if ($patternsResponse->successful()) {
                $patterns = $patternsResponse->json();
            }
        } catch (\Exception $e) {
            $error = 'Flask screener not running. Start it with: python candlestick-screener/app.py';
        }

        // If a pattern is selected, run the scan
        if ($pattern && empty($error)) {
            try {
                $scanResponse = Http::timeout(120)->get("{$this->flaskBaseUrl}/api/scan", [
                    'pattern' => $pattern,
                    'limit' => $limit,
                ]);

                if ($scanResponse->successful()) {
                    $results = $scanResponse->json();
                } else {
                    $error = $scanResponse->json()['error'] ?? 'Scan failed';
                }
            } catch (\Exception $e) {
                $error = 'Flask screener connection failed. Is it running?';
            }
        }

        return Inertia::render('ta-lib-analysis/index', [
            'patterns' => $patterns,
            'selectedPattern' => $pattern,
            'results' => $results,
            'limit' => $limit,
            'error' => $error,
        ]);
    }

    /**
     * Display the 5-minute intraday TA-Lib candlestick pattern screener.
     */
    public function fiveMinute(Request $request): Response
    {
        $pattern = $request->get('pattern', '');
        $limit = (int) $request->get('limit', 750);

        $patterns = [];
        $results = null;
        $error = null;

        try {
            $patternsResponse = Http::timeout(5)->get("{$this->flaskBaseUrl}/api/patterns");

            if ($patternsResponse->successful()) {
                $patterns = $patternsResponse->json();
            }
        } catch (\Exception $e) {
            $error = 'Flask screener not running. Start it with: python candlestick-screener/app.py';
        }

        if ($pattern && empty($error)) {
            try {
                $scanResponse = Http::timeout(120)->get("{$this->flaskBaseUrl}/api/scan-intraday", [
                    'pattern' => $pattern,
                    'limit' => $limit,
                ]);

                if ($scanResponse->successful()) {
                    $results = $scanResponse->json();
                } else {
                    $error = $scanResponse->json()['error'] ?? 'Scan failed';
                }
            } catch (\Exception $e) {
                $error = 'Flask screener connection failed. Is it running?';
            }
        }

        return Inertia::render('ta-lib-analysis/five-minute', [
            'patterns' => $patterns,
            'selectedPattern' => $pattern,
            'results' => $results,
            'limit' => $limit,
            'error' => $error,
        ]);
    }

    /**
     * Display the valid entry screener (5m engulfing + 1m confirmation).
     */
    public function validEntry(Request $request): Response
    {
        $limit = (int) $request->get('limit', 750);

        $results = null;
        $error = null;

        try {
            $scanResponse = Http::timeout(120)->get("{$this->flaskBaseUrl}/api/scan-valid-entry", [
                'limit' => $limit,
            ]);

            if ($scanResponse->successful()) {
                $results = $scanResponse->json();
            } else {
                $error = $scanResponse->json()['error'] ?? 'Scan failed';
            }
        } catch (\Exception $e) {
            $error = 'Flask screener connection failed. Is it running?';
        }

        return Inertia::render('ta-lib-analysis/valid-entry', [
            'results' => $results,
            'limit' => $limit,
            'error' => $error,
        ]);
    }
}
