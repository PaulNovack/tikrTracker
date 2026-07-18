<?php

namespace App\Services\Trading;

use Illuminate\Support\Facades\Log;

/**
 * ML Signal Scorer - Pre-filters 5m signals using trained ML model
 *
 * Scores signals BEFORE entry finding to skip low-probability setups.
 * Saves computation time and improves overall win rate.
 */
class MLSignalScorer
{
    private string $modelPath;

    private string $pythonScript;

    private float $minProbability;

    public function __construct(
        string $modelPath = 'python_ml/models/winner_model_xgb.joblib',
        float $minProbability = 0.40
    ) {
        $this->modelPath = base_path($modelPath);
        $this->pythonScript = base_path('python_ml/v2/score_5m_signals.py');
        $this->minProbability = $minProbability;
    }

    /**
     * Score 5m signals and filter by ML probability threshold
     *
     * @param  array  $signals  Array of 5m signal arrays
     * @return array Filtered signals with ml_win_prob added
     */
    public function scoreAndFilter(array $signals): array
    {
        if (empty($signals)) {
            return [];
        }

        try {
            // Prepare signals JSON
            $signalsJson = json_encode($signals);

            // Get Python executable (use venv if available)
            $pythonCmd = $this->getPythonCommand();

            // Build command
            $cmd = sprintf(
                '%s %s --signals-json %s --model %s 2>&1',
                escapeshellarg($pythonCmd),
                escapeshellarg($this->pythonScript),
                escapeshellarg($signalsJson),
                escapeshellarg($this->modelPath)
            );

            // Execute Python script
            exec($cmd, $output, $returnCode);
            $outputStr = implode("\n", $output);

            if ($returnCode !== 0) {
                Log::warning('ML Signal Scorer failed', [
                    'return_code' => $returnCode,
                    'output' => $outputStr,
                    'cmd' => $cmd,
                ]);

                // Return original signals on error (fail-open)
                return $signals;
            }

            // Parse ML probabilities
            $scores = json_decode($outputStr, true);

            if (json_last_error() !== JSON_ERROR_NONE || ! is_array($scores)) {
                Log::warning('ML Signal Scorer: Invalid JSON response', [
                    'output' => $outputStr,
                    'error' => json_last_error_msg(),
                ]);

                return $signals;
            }

            if (isset($scores['error'])) {
                Log::warning('ML Signal Scorer: Error from Python', [
                    'error' => $scores['error'],
                ]);

                return $signals;
            }

            // Add ML scores to signals and filter
            $filtered = [];
            $rejectedCount = 0;

            foreach ($signals as $signal) {
                $symbol = $signal['symbol'] ?? null;
                if ($symbol && isset($scores[$symbol])) {
                    $mlProb = (float) $scores[$symbol];
                    $signal['ml_win_prob'] = $mlProb;

                    if ($mlProb >= $this->minProbability) {
                        $filtered[] = $signal;
                    } else {
                        $rejectedCount++;
                    }
                } else {
                    // Keep signals without scores (fail-open)
                    $filtered[] = $signal;
                }
            }

            $acceptedCount = count($filtered);
            $totalCount = count($signals);

            Log::info('ML Signal Scorer: Pre-filtered signals', [
                'total' => $totalCount,
                'accepted' => $acceptedCount,
                'rejected' => $rejectedCount,
                'min_prob' => $this->minProbability,
            ]);

            return $filtered;

        } catch (\Exception $e) {
            Log::error('ML Signal Scorer: Exception', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            // Return original signals on exception (fail-open)
            return $signals;
        }
    }

    /**
     * Get Python command (prefer venv if available)
     */
    private function getPythonCommand(): string
    {
        $venvPython = base_path('python/venv/bin/python');

        if (file_exists($venvPython)) {
            return $venvPython;
        }

        return 'python3';
    }

    /**
     * Score a single signal (for testing)
     */
    public function scoreSignal(array $signal): ?float
    {
        $result = $this->scoreAndFilter([$signal]);

        return $result[0]['ml_win_prob'] ?? null;
    }
}
