<?php

namespace App\Http\Controllers\Analysis;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Inertia\Inertia;
use Inertia\Response;

class ScoreSymbolController extends Controller
{
    public function index(Request $request): Response
    {
        return Inertia::render('analysis/ScoreSymbol');
    }

    public function score(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'symbol' => 'required|string|max:10',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid symbol',
                'errors' => $validator->errors(),
            ], 422);
        }

        $symbol = strtoupper(trim($request->input('symbol')));
        $assetType = 'stock';

        try {
            // Get the most recent 1-minute price data
            $oneMinData = DB::table('one_minute_prices')
                ->where('symbol', $symbol)
                ->where('asset_type', $assetType)
                ->orderBy('ts_est', 'desc')
                ->first();

            if (! $oneMinData) {
                return response()->json([
                    'success' => false,
                    'message' => "No 1-minute price data found for {$symbol}",
                ], 404);
            }

            // Get the most recent 5-minute price data at or before the 1m timestamp
            $fiveMinData = DB::table('five_minute_prices')
                ->where('symbol', $symbol)
                ->where('asset_type', $assetType)
                ->where('ts_est', '<=', $oneMinData->ts_est)
                ->orderBy('ts_est', 'desc')
                ->first();

            if (! $fiveMinData) {
                return response()->json([
                    'success' => false,
                    'message' => "No 5-minute price data found for {$symbol}",
                ], 404);
            }

            // Create a temporary alert record for ML scoring
            $tempAlertId = DB::table('trade_alerts_unfiltered')->insertGetId([
                'symbol' => $symbol,
                'asset_type' => $assetType,
                'trading_date_est' => $oneMinData->trading_date_est,
                'as_of_ts_est' => now(),
                'signal_type' => 'temp_score',
                'signal_ts_est' => $fiveMinData->ts_est,
                'time_of_day' => $oneMinData->trading_time_est,
                'entry_type' => 'live_score',
                'entry_ts_est' => $oneMinData->ts_est,
                'entry' => $oneMinData->price,
                'stop' => $oneMinData->price * 0.98, // Simple 2% stop
                'risk_pct' => 2.00,
                'risk_per_share' => $oneMinData->price * 0.02,
                'score' => 5.0,
                'vol_ratio' => 1.0,
                'five_min_directional_changes' => 0,
                'five_min_green_bar_pct' => 50.0,
                'five_min_net_progress' => 0.0,
                'consolidation_bars' => 0,
                'breakout_volume_ratio' => 1.0,
                'atr' => $oneMinData->atr ?? 0,
                'atr_pct' => $oneMinData->atr_pct ?? 0,
                'rsi_14_1m' => 50.0,
                'suggested_trailing_stop' => $oneMinData->price * 0.97,
                'suggested_trailing_stop_pct' => 3.00,
                'targets' => json_encode(['1R' => $oneMinData->price * 1.02]),
                'analyzed' => 0,
                'version' => 'TEMP_SCORE',
                'pipeline_run' => 'A',
                'dedupe_key' => 'temp_score_'.uniqid(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            // Run ML scoring via Python script
            $modelPath = config('trading.ml_scoring.model_path', 'python_ml/models/winner_model_xgb.joblib');
            $pythonPath = config('trading.ml_scoring.python_path', '/var/www/html/laravel-invest/.venv/bin/python3');
            $cmd = [
                $pythonPath,
                base_path('python_ml/v2/score_single_alert_v2.py'),
                '--model-in', base_path($modelPath),
                '--alert-id', (string) $tempAlertId,
                '--table', 'trade_alerts_unfiltered',
            ];

            $process = new \Symfony\Component\Process\Process($cmd, base_path());
            $process->setTimeout(config('trading.ml_scoring.timeout_seconds', 60));
            $process->run();

            if (! $process->isSuccessful()) {
                // Clean up temp alert
                DB::table('trade_alerts_unfiltered')->where('id', $tempAlertId)->delete();

                return response()->json([
                    'success' => false,
                    'message' => 'ML scoring failed: '.$process->getErrorOutput(),
                ], 500);
            }

            // Fetch the scored result
            $scoredAlert = DB::table('trade_alerts_unfiltered')
                ->where('id', $tempAlertId)
                ->first();

            if (! $scoredAlert || $scoredAlert->ml_win_prob === null) {
                // Clean up temp alert
                DB::table('trade_alerts_unfiltered')->where('id', $tempAlertId)->delete();

                return response()->json([
                    'success' => false,
                    'message' => 'ML scoring did not return a result',
                ], 500);
            }

            // Determine if it's a buy based on ml_win_prob threshold
            $mlThreshold = config('trading.ml_scoring.buy_threshold', 0.55);
            $isBuy = $scoredAlert->ml_win_prob >= $mlThreshold;

            $result = [
                'success' => true,
                'symbol' => $symbol,
                'ml_win_prob' => round($scoredAlert->ml_win_prob, 4),
                'is_buy' => $isBuy,
                'threshold' => $mlThreshold,
                'alert_id' => $scoredAlert->id,
                'entry_price' => (float) $scoredAlert->entry,
                'stop_price' => (float) $scoredAlert->stop,
                'score' => (float) $scoredAlert->score,
                'vol_ratio' => (float) $scoredAlert->vol_ratio,
                'atr_pct' => (float) $scoredAlert->atr_pct,
                'rsi_14_1m' => (float) $scoredAlert->rsi_14_1m,
                'scored_at' => $scoredAlert->ml_scored_at,
                'model_version' => $scoredAlert->ml_model_version,
                'entry_ts_est' => $scoredAlert->entry_ts_est,
                'data_age_seconds' => now()->diffInSeconds($oneMinData->ts_est),
            ];

            // Clean up temp alert
            DB::table('trade_alerts_unfiltered')->where('id', $tempAlertId)->delete();

            return response()->json($result);
        } catch (\Exception $e) {
            \Log::error("Error scoring symbol {$symbol}: ".$e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'An error occurred while scoring the symbol: '.$e->getMessage(),
            ], 500);
        }
    }
}
