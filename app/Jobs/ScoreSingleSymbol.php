<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class ScoreSingleSymbol implements ShouldQueue
{
    use Queueable;

    /**
     * The number of seconds the job can run before timing out.
     */
    public int $timeout = 120;

    /**
     * The number of times the job may be attempted.
     */
    public int $tries = 2;

    /**
     * The number of seconds to wait before retrying the job.
     */
    public int $backoff = 10;

    public function __construct(
        public string $symbol,
        public string $assetType,
        public string $batchId
    ) {}

    public function handle(): void
    {
        \Log::info("[ScoreSingleSymbol] Starting job for {$this->symbol}", [
            'batch_id' => $this->batchId,
            'symbol' => $this->symbol,
        ]);

        try {
            $result = $this->scoreSymbol($this->symbol, $this->assetType);

            // Store result in cache with 5 minute TTL
            $cacheKey = "symbol_score:{$this->batchId}:{$this->symbol}";
            Cache::put($cacheKey, $result, now()->addMinutes(5));

            // Atomically increment completed count with lock to prevent race conditions
            $lock = Cache::lock("symbol_score:{$this->batchId}:lock", 10);
            $lock->block(5, function () {
                Cache::increment("symbol_score:{$this->batchId}:completed");
            });

            \Log::info("[ScoreSingleSymbol] Completed job for {$this->symbol}", [
                'batch_id' => $this->batchId,
                'symbol' => $this->symbol,
                'success' => $result['success'],
            ]);
        } catch (\Exception $e) {
            \Log::error("[ScoreSingleSymbol] Exception for {$this->symbol}: {$e->getMessage()}", [
                'batch_id' => $this->batchId,
                'symbol' => $this->symbol,
                'exception' => $e->getMessage(),
            ]);

            $result = [
                'success' => false,
                'symbol' => $this->symbol,
                'message' => $e->getMessage(),
            ];

            $cacheKey = "symbol_score:{$this->batchId}:{$this->symbol}";
            Cache::put($cacheKey, $result, now()->addMinutes(5));

            // Atomically increment completed count even on failure with lock to prevent race conditions
            $lock = Cache::lock("symbol_score:{$this->batchId}:lock", 10);
            $lock->block(5, function () {
                Cache::increment("symbol_score:{$this->batchId}:completed");
            });
        }
    }

    /**
     * Handle job failure
     */
    public function failed(\Throwable $exception): void
    {
        // Store failure result in cache
        $result = [
            'success' => false,
            'symbol' => $this->symbol,
            'message' => 'Job failed: '.$exception->getMessage(),
        ];

        $cacheKey = "symbol_score:{$this->batchId}:{$this->symbol}";
        Cache::put($cacheKey, $result, now()->addMinutes(5));

        // Atomically increment completed count with lock to prevent race conditions
        $lock = Cache::lock("symbol_score:{$this->batchId}:lock", 10);
        $lock->block(5, function () {
            Cache::increment("symbol_score:{$this->batchId}:completed");
        });
    }

    private function scoreSymbol(string $symbol, string $assetType): array
    {
        // Get the most recent 1-minute price data
        $oneMinData = DB::table('one_minute_prices')
            ->where('symbol', $symbol)
            ->where('asset_type', $assetType)
            ->orderBy('ts_est', 'desc')
            ->first();

        if (! $oneMinData) {
            return [
                'success' => false,
                'symbol' => $symbol,
                'message' => 'No 1-minute price data found',
            ];
        }

        // Get the most recent 5-minute price data
        $fiveMinData = DB::table('five_minute_prices')
            ->where('symbol', $symbol)
            ->where('asset_type', $assetType)
            ->where('ts_est', '<=', $oneMinData->ts_est)
            ->orderBy('ts_est', 'desc')
            ->first();

        if (! $fiveMinData) {
            return [
                'success' => false,
                'symbol' => $symbol,
                'message' => 'No 5-minute price data found',
            ];
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
            'stop' => $oneMinData->price * 0.98,
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

        // Run ML scoring
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
            DB::table('trade_alerts_unfiltered')->where('id', $tempAlertId)->delete();
            throw new \Exception('ML scoring failed: '.$process->getErrorOutput());
        }

        // Fetch the scored result
        $scoredAlert = DB::table('trade_alerts_unfiltered')
            ->where('id', $tempAlertId)
            ->first();

        if (! $scoredAlert || $scoredAlert->ml_win_prob === null) {
            DB::table('trade_alerts_unfiltered')->where('id', $tempAlertId)->delete();
            throw new \Exception('ML scoring did not return a result');
        }

        // Determine if it's a buy
        $mlThreshold = config('trading.ml_scoring.buy_threshold', 0.55);
        $isBuy = $scoredAlert->ml_win_prob >= $mlThreshold;

        $result = [
            'success' => true,
            'symbol' => $symbol,
            'ml_win_prob' => round($scoredAlert->ml_win_prob, 4),
            'is_buy' => $isBuy,
            'threshold' => $mlThreshold,
            'entry_price' => (float) $scoredAlert->entry,
            'stop_price' => (float) $scoredAlert->stop,
            'atr_pct' => (float) $scoredAlert->atr_pct,
            'entry_ts_est' => $scoredAlert->entry_ts_est,
            'data_age_seconds' => now()->diffInSeconds($oneMinData->ts_est),
        ];

        // Clean up temp alert
        DB::table('trade_alerts_unfiltered')->where('id', $tempAlertId)->delete();

        return $result;
    }
}
