<?php

namespace App\Http\Controllers\Analysis;

use App\Http\Controllers\Controller;
use App\Jobs\ScoreSingleSymbol;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class ScoreSymbolListController extends Controller
{
    public function index(Request $request): Response
    {
        return Inertia::render('analysis/ScoreSymbolList');
    }

    public function score(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'symbols' => 'required|array|min:1',
            'symbols.*' => 'required|string|max:10',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid symbols',
                'errors' => $validator->errors(),
            ], 422);
        }

        $symbols = array_map(fn ($s) => strtoupper(trim($s)), $request->input('symbols'));
        $assetType = 'stock';

        // Generate unique batch ID
        $batchId = Str::uuid()->toString();

        // Store batch metadata in cache
        Cache::put("symbol_score:{$batchId}:total", count($symbols), now()->addMinutes(5));
        Cache::put("symbol_score:{$batchId}:completed", 0, now()->addMinutes(5));
        Cache::put("symbol_score:{$batchId}:symbols", $symbols, now()->addMinutes(5));

        // Dispatch jobs to queue - will run in parallel across workers
        foreach ($symbols as $symbol) {
            ScoreSingleSymbol::dispatch($symbol, $assetType, $batchId);
        }

        return response()->json([
            'success' => true,
            'batch_id' => $batchId,
            'total' => count($symbols),
            'message' => 'Jobs dispatched to queue',
            'bell_threshold' => config('trading.ml_scoring.bell_threshold', 0.70),
        ]);
    }

    public function status(Request $request, string $batchId): JsonResponse
    {
        $total = Cache::get("symbol_score:{$batchId}:total", 0);
        $completed = Cache::get("symbol_score:{$batchId}:completed", 0);
        $symbols = Cache::get("symbol_score:{$batchId}:symbols", []);

        if ($total === 0) {
            return response()->json([
                'success' => false,
                'message' => 'Batch not found or expired',
            ], 404);
        }

        $results = [];
        foreach ($symbols as $symbol) {
            $cacheKey = "symbol_score:{$batchId}:{$symbol}";
            $result = Cache::get($cacheKey);
            if ($result) {
                $results[$symbol] = $result;
            }
        }

        $isComplete = $completed >= $total;

        return response()->json([
            'success' => true,
            'batch_id' => $batchId,
            'total' => $total,
            'completed' => $completed,
            'is_complete' => $isComplete,
            'results' => $results,
            'scored_at' => now()->toISOString(),
        ]);
    }

    public function topMovers(Request $request): JsonResponse
    {
        // Get the most recent trading day
        $latestDate = \DB::table('daily_prices')
            ->where('asset_type', 'stock')
            ->max('date');

        if (! $latestDate) {
            return response()->json([
                'success' => false,
                'message' => 'No daily price data found',
            ], 404);
        }

        // Get top 30 gainers
        $gainers = \DB::table('daily_prices')
            ->select('symbol')
            ->selectRaw('((price - open) / open * 100) as pct_change')
            ->where('date', $latestDate)
            ->where('asset_type', 'stock')
            ->where('open', '>', 0)
            ->orderBy('pct_change', 'desc')
            ->limit(30)
            ->pluck('symbol')
            ->toArray();

        // Get top 30 losers
        $losers = \DB::table('daily_prices')
            ->select('symbol')
            ->selectRaw('((price - open) / open * 100) as pct_change')
            ->where('date', $latestDate)
            ->where('asset_type', 'stock')
            ->where('open', '>', 0)
            ->orderBy('pct_change', 'asc')
            ->limit(30)
            ->pluck('symbol')
            ->toArray();

        // Combine and sort alphabetically
        $allSymbols = array_merge($gainers, $losers);
        sort($allSymbols);

        return response()->json([
            'success' => true,
            'symbols' => $allSymbols,
            'date' => $latestDate,
            'count' => count($allSymbols),
        ]);
    }
}
