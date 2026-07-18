<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Process;

class MlScoringDaemon extends Command
{
    protected $signature = 'ml:scoring-daemon
        {--socket= : Unix socket path (defaults to storage/ml-scoring.sock)}
        {--python= : Python executable (defaults to trading.ml_scoring.python_bin config)}
        {--restart-delay=3 : Seconds to wait before restarting on crash}
    ';

    protected $description = 'Run the persistent ML scoring daemon that eliminates Python cold-start overhead (~1.5–2.5s per alert saved).';

    public function handle(): int
    {
        $basePath = config('trading.auto_alpaca_orders.base_path') ?: base_path();
        $socketPath = $this->option('socket') ?: (config('trading.auto_alpaca_orders.daemon_socket_display') ?: config('trading.ml_scoring.daemon_socket', storage_path('ml-scoring.sock')));
        $python = $this->option('python') ?: config('trading.ml_scoring.python_bin', 'python');
        $restartDelay = max(1, (int) $this->option('restart-delay'));
        $daemonScript = $basePath.'/python_ml/v2/scoring_daemon.py';

        // Build pre-warm list from every configured pipeline model (de-duped)
        $preWarmArg = implode(',', array_unique($this->resolveAllModelPaths()));

        $this->info('ML Scoring Daemon starting');
        $this->info("  Socket : {$socketPath}");
        $this->info('  Models : '.count(array_unique($this->resolveAllModelPaths())).' unique models to pre-warm');
        $this->info("  Python : {$python}");

        $cmd = array_values(array_filter([
            $python,
            $daemonScript,
            '--socket', $socketPath,
            $preWarmArg !== '' ? '--models' : null,
            $preWarmArg !== '' ? $preWarmArg : null,
        ]));

        while (true) {
            $this->info('Starting Python scoring daemon process…');

            $process = new Process($cmd, base_path());
            $process->setTimeout(null);
            $process->setIdleTimeout(null);

            $process->start(function (string $type, string $buffer) {
                foreach (explode("\n", rtrim($buffer, "\n")) as $line) {
                    if ($line !== '') {
                        $this->line($line);
                    }
                }
            });

            $pid = $process->getPid();
            $this->info("Daemon PID: {$pid}");
            Log::info('[ML Daemon] Started', ['pid' => $pid, 'socket' => $socketPath]);

            $process->wait();

            $exitCode = $process->getExitCode();
            Log::warning('[ML Daemon] Process exited', ['exit_code' => $exitCode, 'pid' => $pid]);

            if ($exitCode === 0) {
                $this->info('Daemon exited cleanly (code 0). Stopping.');

                return self::SUCCESS;
            }

            $this->error("Daemon crashed (exit code: {$exitCode}). Restarting in {$restartDelay}s…");

            // Remove stale socket so next daemon can bind cleanly
            if (file_exists($socketPath)) {
                @unlink($socketPath);
            }

            sleep($restartDelay);
        }
    }

    /**
     * Collect every model path configured across all pipelines, resolved to absolute paths.
     *
     * @return array<string>
     */
    private function resolveAllModelPaths(): array
    {
        $paths = [];
        $basePath = config('trading.auto_alpaca_orders.base_path') ?: base_path();

        $default = config('trading.ml_scoring.model_path');
        if ($default) {
            $paths[] = $basePath.'/'.$default;
        }

        foreach (['a', 'b', 'c', 'd', 'e', 'f', 'g', 'h', 'i', 'j', 'k', 'l', 'm', 'n', 'o', 'p', 'q', 'r', 's', 'external'] as $letter) {
            $path = config("trading.ml_scoring.pipeline_{$letter}_model_path");
            if ($path) {
                $paths[] = $basePath.'/'.$path;
            }
        }

        return array_values(array_filter($paths));
    }
}
