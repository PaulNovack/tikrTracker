<?php

namespace App\Console\Commands;

use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Process;

class BacktestDailyParallel extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'trading:v2-backtest-days
        {from : Start date EST (YYYY-MM-DD)}
        {to : End date EST (YYYY-MM-DD)}
        {--workers=4 : Number of parallel backtest processes}
        {--pipeline= : Optional single pipeline letter filter}
        {--symbol= : Optional single symbol filter}
        {--step=5 : Step interval in minutes}
        {--no-write : Do not write alerts to trade_alerts table}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Run the TradingV2 backtest once per trading day (weekdays only, 09:30-16:00 EST) across a date range, in parallel.';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $from = Carbon::parse($this->argument('from'), 'America/New_York')->startOfDay();
        $to = Carbon::parse($this->argument('to'), 'America/New_York')->startOfDay();
        $workers = max(1, (int) $this->option('workers'));
        $step = (int) $this->option('step');

        if ($to->lt($from)) {
            $this->error('End date must be on or after start date.');

            return self::FAILURE;
        }

        // Build the list of trading days (weekdays only).
        $days = [];
        for ($d = $from->copy(); $d->lte($to); $d->addDay()) {
            if ($d->isWeekday()) {
                $days[] = $d->copy();
            }
        }

        if (empty($days)) {
            $this->error('No weekdays found in the given range.');

            return self::FAILURE;
        }

        $this->info('Found '.count($days)." weekday(s) between {$from->format('Y-m-d')} and {$to->format('Y-m-d')}.");
        $this->info("Workers: {$workers} | Step: {$step}min | Write: ".(! $this->option('no-write') ? 'YES' : 'no'));

        $artisan = base_path('artisan');
        $php = PHP_BINARY;

        $jobs = [];
        $failed = 0;
        $completed = 0;
        $running = [];

        foreach ($days as $day) {
            $date = $day->format('Y-m-d');

            $cmd = $this->buildCommand($date, $step);

            // Spawn a process, but keep the running pool capped at $workers.
            $process = Process::start($cmd);
            $running[$date] = $process;
            $this->line("  [{$date}] started");

            if (count($running) >= $workers) {
                $this->reapOne($running, $completed, $failed);
            }
        }

        // Drain any remaining processes.
        $this->info('Waiting for all backtest processes to finish...');
        while (count($running) > 0) {
            $this->reapOne($running, $completed, $failed);
            usleep(500_000);
        }

        $this->newLine();
        $this->info("Done. Completed: {$completed} | Failed: {$failed}");

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }

    private function buildCommand(string $date, int $step): string
    {
        $args = [
            PHP_BINARY,
            base_path('artisan'),
            'trading:v2-backtest',
            "--from={$date} 09:30",
            "--to={$date} 16:00",
            "--step={$step}",
            '--fulltable',
        ];

        if (! $this->option('no-write')) {
            $args[] = '--write';
        }
        if ($p = $this->option('pipeline')) {
            $args[] = "--pipeline={$p}";
        }
        if ($s = $this->option('symbol')) {
            $args[] = "--symbol={$s}";
        }

        return implode(' ', array_map('escapeshellarg', $args));
    }

    /**
     * Wait for at least one running process to finish, reap it, and record result.
     *
     * @param  array<string, \Illuminate\Contracts\Process\ProcessResult>  $running
     */
    private function reapOne(array &$running, int &$completed, int &$failed): void
    {
        while (count($running) > 0) {
            // Check each process; wait for one that has completed.
            foreach ($running as $date => $process) {
                if (! $process->running()) {
                    $exitCode = $process->wait()->exitCode();
                    unset($running[$date]);

                    if ($exitCode === 0) {
                        $completed++;
                        $this->line("  [{$date}] ✅ completed");
                    } else {
                        $failed++;
                        $this->error("  [{$date}] ❌ failed (exit {$exitCode})");
                    }

                    return;
                }
            }

            usleep(500_000);
        }
    }
}
