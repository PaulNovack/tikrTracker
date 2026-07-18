<?php

namespace App\Jobs;

use App\Events\TradeAlertMLScored;
use App\Services\TradingSettingService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Process;

class ScoreTradeAlertWithMl implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 60;

    public function __construct(
        public int $alertId,
        public string $tableName = 'trade_alerts',
        public string $pipelineRun = 'A'
    ) {}

    public function handle(): void
    {
        $jobStart = microtime(true);

        $alert = DB::table($this->tableName)->where('id', $this->alertId)->first();

        if (! $alert) {
            Log::warning("ML Scoring: Alert {$this->alertId} not found in {$this->tableName}");

            return;
        }

        if ($alert->ml_scored_at !== null) {
            return;
        }

        // Pipeline K risk filter: skip ML scoring for risk_pct >= 2.0%
        if ($this->pipelineRun === 'K' && (float) ($alert->risk_pct ?? 0) >= 2.0) {
            Log::info("ML Scoring: Skipping Pipeline K alert {$this->alertId} ({$alert->symbol}) — risk_pct {$alert->risk_pct}% >= 2.0% execution filter");

            return;
        }

        // Resolve model path (pipeline-specific or default)
        $pipelineLetter = strtolower($this->pipelineRun);
        $pipelineConfigKey = "trading.ml_scoring.pipeline_{$pipelineLetter}_model_path";
        $modelPath = config($pipelineConfigKey)
            ?: config('trading.ml_scoring.model_path', 'python_ml/models/winner_model_xgb.joblib');

        try {
            // --- Attempt 1: persistent daemon via Unix socket (fast path, ~10ms) ---
            $prob = $this->scoreViaDaemon($this->alertId, $this->tableName, base_path($modelPath));

            if ($prob !== null) {
                $pythonMs = round((microtime(true) - $jobStart) * 1000);
                Log::channel('pipeline-timing')->info('[ML_SCORE] DONE (daemon)', [
                    'alert_id' => $this->alertId,
                    'pipeline' => $this->pipelineRun,
                    'symbol' => $alert->symbol ?? '?',
                    'python_ms' => $pythonMs,
                    'alert_to_job_sec' => $alert->created_at
                        ? round($jobStart - strtotime((string) $alert->created_at), 2)
                        : null,
                ]);
            } else {
                // --- Attempt 2: subprocess fallback (daemon not running) ---
                Log::info("ML Scoring: Daemon unavailable for alert {$this->alertId}, falling back to subprocess");

                $prob = $this->scoreViaSubprocess($this->alertId, $this->tableName, $modelPath, $jobStart, $alert);
            }

            if ($prob === null) {
                return;
            }

            Log::info("ML Scoring completed for alert {$this->alertId} in {$this->tableName}");

            $updatedAlert = DB::table($this->tableName)->where('id', $this->alertId)->first();

            if ($updatedAlert && $updatedAlert->ml_win_prob !== null) {
                // Reset passed_ml based on the actual threshold — Python always sets it to 1
                $mlThreshold = TradingSettingService::getPipelineMlThreshold($this->pipelineRun);
                $passedMl = (float) $updatedAlert->ml_win_prob >= $mlThreshold ? 1 : 0;
                DB::table($this->tableName)
                    ->where('id', $this->alertId)
                    ->update(['passed_ml' => $passedMl]);

                try {
                    broadcast(new TradeAlertMLScored(
                        alertId: $this->alertId,
                        symbol: $updatedAlert->symbol,
                        mlWinProb: (float) $updatedAlert->ml_win_prob,
                        mlModelVersion: $updatedAlert->ml_model_version ?? 'unknown',
                        tableName: $this->tableName,
                    ));
                    Log::info("Broadcasted ML score for alert {$this->alertId} ({$updatedAlert->symbol})");
                } catch (\Throwable $broadcastError) {
                    Log::warning("Failed to broadcast ML score for alert {$this->alertId}: ".$broadcastError->getMessage());
                }
            }
        } catch (\Throwable $e) {
            Log::error("ML Scoring error for alert {$this->alertId}: ".$e->getMessage());
            throw $e;
        }
    }

    /**
     * Send a scoring request to the persistent Python daemon over a Unix socket.
     * Returns the win probability (0–1) on success, or null if the daemon is
     * unavailable (socket missing, connection refused, timeout, malformed reply).
     */
    private function scoreViaDaemon(int $alertId, string $table, string $absoluteModelPath): ?float
    {
        $socketPath = config('trading.ml_scoring.daemon_socket', storage_path('ml-scoring.sock'));

        if (! file_exists($socketPath)) {
            return null;
        }

        $sock = @socket_create(AF_UNIX, SOCK_STREAM, 0);
        if (! $sock) {
            return null;
        }

        socket_set_option($sock, SOL_SOCKET, SO_RCVTIMEO, ['sec' => 5, 'usec' => 0]);
        socket_set_option($sock, SOL_SOCKET, SO_SNDTIMEO, ['sec' => 5, 'usec' => 0]);

        if (! @socket_connect($sock, $socketPath)) {
            socket_close($sock);

            return null;
        }

        $request = json_encode([
            'alert_id' => $alertId,
            'table' => $table,
            'model_path' => $absoluteModelPath,
        ])."\n";

        socket_send($sock, $request, strlen($request), 0);

        $raw = '';
        while (! str_contains($raw, "\n")) {
            $buf = '';
            $bytes = @socket_recv($sock, $buf, 4096, 0);
            if ($bytes === false || $bytes === 0) {
                break;
            }
            $raw .= $buf;
        }

        socket_close($sock);

        if ($raw === '') {
            return null;
        }

        $data = json_decode(trim($raw), true);

        if (! is_array($data) || ! ($data['ok'] ?? false)) {
            Log::warning("ML Daemon returned error for alert {$alertId}: ".($data['error'] ?? 'unknown'));

            return null;
        }

        return (float) $data['prob'];
    }

    /**
     * Fall back to spawning a fresh Python subprocess (original behaviour).
     * Returns win probability on success, null on failure.
     */
    private function scoreViaSubprocess(
        int $alertId,
        string $table,
        string $modelPath,
        float $jobStart,
        object $alert
    ): ?float {
        $cmd = [
            config('trading.ml_scoring.python_bin', 'python'),
            base_path($this->resolveScorerScript()),
            '--model-in', base_path($modelPath),
            '--alert-id', (string) $alertId,
            '--table', $table,
        ];

        $timeout = config('trading.ml_scoring.timeout_seconds', 60);
        $p = new Process($cmd, base_path());
        $p->setTimeout($timeout);
        $p->run();

        if (! $p->isSuccessful()) {
            $errorOutput = trim($p->getErrorOutput());

            if (
                str_contains($errorOutput, 'No data found for alert')
                || str_contains($errorOutput, 'entry_ts_est has no matching row in one_minute_prices')
                || (str_contains($errorOutput, 'Alert ') && str_contains($errorOutput, 'not found in trade_alerts'))
            ) {
                Log::warning("ML Scoring (subprocess) skipped for alert {$alertId}: {$errorOutput}");

                return null;
            }

            Log::error("ML Scoring (subprocess) failed for alert {$alertId}: {$errorOutput}");
            throw new \RuntimeException('ML scoring failed: '.$errorOutput);
        }

        $pythonMs = round((microtime(true) - $jobStart) * 1000);
        Log::channel('pipeline-timing')->info('[ML_SCORE] DONE (subprocess)', [
            'alert_id' => $alertId,
            'pipeline' => $this->pipelineRun,
            'symbol' => $alert->symbol ?? '?',
            'python_ms' => $pythonMs,
            'alert_to_job_sec' => $alert->created_at
                ? round($jobStart - strtotime((string) $alert->created_at), 2)
                : null,
        ]);

        // Return a non-null sentinel so callers know scoring ran; the actual
        // prob was written directly to the DB by the Python script.
        return -1.0;
    }

    /**
     * Resolve the scorer script for the current pipeline.
     * Falls back to score_single_alert_v2.py if no per-pipeline override is set.
     */
    private function resolveScorerScript(): string
    {
        $pipelineLetter = strtolower($this->pipelineRun);
        $configKey = "trading.ml_scoring.pipeline_{$pipelineLetter}_scorer_script";

        return (string) (config($configKey) ?: 'python_ml/v2/score_single_alert_v2.py');
    }
}
