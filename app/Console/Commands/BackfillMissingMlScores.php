<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;

class BackfillMissingMlScores extends Command
{
    protected $signature = 'ml:backfill-missing
        {--start= : Restrict to trading_date_est >= this date (YYYY-MM-DD)}
        {--end= : Restrict to trading_date_est <= this date (YYYY-MM-DD)}
        {--pipeline= : Comma-separated pipelines (example: H,I,D)}
        {--limit-days=0 : Max pipeline/date groups to process (0 = all)}
        {--score-limit=200000 : --limit passed to python_ml/v2/score_trade_alerts.py}
        {--dry-run : Show work plan without running python scorer}';

    protected $description = 'Backfill missing ml_win_prob rows without clearing existing ML scores.';

    public function handle(): int
    {
        $start = $this->option('start');
        $end = $this->option('end');
        $dryRun = (bool) $this->option('dry-run');
        $limitDays = max(0, (int) $this->option('limit-days'));
        $scoreLimit = max(1, (int) $this->option('score-limit'));

        $python = config('trading.ml_scoring.python_bin', 'python');
        $script = base_path('python_ml/v2/score_trade_alerts.py');

        if (! file_exists($script)) {
            $this->error("Scoring script not found: {$script}");

            return self::FAILURE;
        }

        $query = DB::table('trade_alerts')
            ->select('pipeline_run', 'trading_date_est')
            ->whereNull('ml_win_prob')
            ->whereNotNull('pipeline_run')
            ->whereNotNull('trading_date_est');

        if ($start) {
            $query->where('trading_date_est', '>=', $start);
        }

        if ($end) {
            $query->where('trading_date_est', '<=', $end);
        }

        $requestedPipelines = $this->parsePipelines((string) $this->option('pipeline'));
        if ($requestedPipelines !== []) {
            $query->whereIn('pipeline_run', $requestedPipelines);
        }

        $work = $query
            ->distinct()
            ->orderBy('trading_date_est')
            ->orderBy('pipeline_run')
            ->get();

        if ($work->isEmpty()) {
            $this->info('No missing ml_win_prob rows found for the provided filters.');

            return self::SUCCESS;
        }

        if ($limitDays > 0) {
            $work = $work->take($limitDays)->values();
        }

        $this->info('Backfill plan: '.$work->count().' pipeline/date group(s).');
        $this->line('ML columns in trade_alerts: ml_win_prob, ml_scored_at, ml_model_version, ml_live_win_prob, ml_live_scored_at');

        $success = 0;
        $failed = 0;

        foreach ($work as $row) {
            $pipeline = (string) $row->pipeline_run;
            $date = (string) $row->trading_date_est;

            $modelPath = $this->resolveModelPath($pipeline);
            if ($modelPath === null) {
                $this->error("[{$pipeline} {$date}] Missing model config and default fallback is empty. Skipping.");
                $failed++;

                continue;
            }

            $absoluteModelPath = base_path($modelPath);
            if (! file_exists($absoluteModelPath)) {
                $this->error("[{$pipeline} {$date}] Model file not found: {$absoluteModelPath}");
                $failed++;

                continue;
            }

            $modelVersion = pathinfo($modelPath, PATHINFO_FILENAME);

            $this->line("[{$pipeline} {$date}] model={$modelPath}");

            if ($dryRun) {
                $success++;

                continue;
            }

            $command = [
                $python,
                $script,
                '--model-in', $absoluteModelPath,
                '--trading-date', $date,
                '--pipeline', $pipeline,
                '--model-version', $modelVersion,
                '--limit', (string) $scoreLimit,
            ];

            $process = new Process($command, base_path());
            $process->setTimeout(null);
            $process->run();

            if (! $process->isSuccessful()) {
                $this->error("[{$pipeline} {$date}] scorer failed");
                $this->line(trim($process->getErrorOutput()));
                $failed++;

                continue;
            }

            $output = trim($process->getOutput());
            if ($output !== '') {
                $lines = preg_split('/\r\n|\r|\n/', $output) ?: [];
                $tail = array_slice($lines, -3);
                foreach ($tail as $line) {
                    if ($line !== '') {
                        $this->line('  '.$line);
                    }
                }
            }

            $success++;
        }

        $remaining = DB::table('trade_alerts')->whereNull('ml_win_prob')
            ->when($start, fn ($q) => $q->where('trading_date_est', '>=', $start))
            ->when($end, fn ($q) => $q->where('trading_date_est', '<=', $end))
            ->when($requestedPipelines !== [], fn ($q) => $q->whereIn('pipeline_run', $requestedPipelines))
            ->count();

        $this->newLine();
        $this->info("Done. Success={$success} Failed={$failed} RemainingNulls={$remaining}");

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * @return array<int, string>
     */
    private function parsePipelines(string $value): array
    {
        $value = trim($value);
        if ($value === '') {
            return [];
        }

        $parts = array_map(
            static fn (string $p): string => strtoupper(trim($p)),
            explode(',', $value)
        );

        return array_values(array_filter(array_unique($parts), static fn (string $p): bool => $p !== ''));
    }

    private function resolveModelPath(string $pipeline): ?string
    {
        $pipeline = strtolower($pipeline);
        $pipelinePath = config("trading.ml_scoring.pipeline_{$pipeline}_model_path");

        if (is_string($pipelinePath) && $pipelinePath !== '') {
            return $pipelinePath;
        }

        $defaultPath = config('trading.ml_scoring.model_path');

        return is_string($defaultPath) && $defaultPath !== '' ? $defaultPath : null;
    }
}
