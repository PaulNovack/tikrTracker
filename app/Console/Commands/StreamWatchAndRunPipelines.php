<?php

namespace App\Console\Commands;

use App\Services\TradingSettingService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;

class StreamWatchAndRunPipelines extends Command
{
    protected $signature = 'stream:watch-and-run-pipelines
        {--poll=2 : Seconds between Redis key checks}
        {--dry-run : Print pipeline commands without running them}
        {--startup-delay=120 : Seconds to wait after launch before firing any pipelines}
    ';

    protected $description = 'Watches stream:last_bar_ts in Redis and fires all trade pipelines the moment a new 1-minute bar lands.';

    /**
     * Pipeline commands to fire on each new bar, in the same order and with the
     * same flags as the per-minute cron entries in routes/console.php.
     *
     * @var array<string>
     */
    private const PIPELINES = [
        'trade:pipeline-a stock --top=50 --lookback=15 --stale=12 --before=6 --no-interaction',
        'trade:pipeline-b stock --top=50 --lookback=15 --stale=12 --before=6 --no-interaction',
        'trade:pipeline-c stock --top=50 --lookback=15 --stale=12 --before=6 --no-interaction',
        'trade:pipeline-d stock --top=50 --lookback=15 --stale=12 --before=6 --no-interaction',
        'trade:pipeline-e stock --top=50 --lookback=15 --stale=12 --before=6 --no-interaction',
        'trade:pipeline-f stock --top=25 --lookback=15 --before=8 --after=10 --stale=12 --no-interaction',
        'trade:pipeline-g stock --top=25 --lookback=15 --stale=12 --before=6 --no-interaction',
        'trade:pipeline-i stock --top=50 --lookback=15 --stale=12 --before=6 --no-interaction',
        'trade:pipeline-j stock --top=25 --lookback=60 --stale=12 --before=6 --no-interaction',
        'trade:pipeline-k stock --top=50 --stale=12 --fill=close --before=8 --no-interaction',
        'trade:pipeline-m stock --stale=12 --no-interaction',
        'trade:pipeline-n stock --stale=12 --before=6 --no-interaction',
        'trade:pipeline-q stock --top=60 --lookback=15 --stale=12 --before=6 --no-interaction',
        'trade:dispatch-ml-scoring --age=10 --limit=50 --no-interaction',
    ];

    /**
     * Pipelines that scan five_minute_prices — only meaningful immediately after a fresh 5m sync.
     * Fired separately after alpaca:sync-5m completes on 5-minute boundary bars.
     *
     * @var array<string>
     */
    private const PIPELINES_5M = [
        'trade:pipeline-e stock --top=50 --lookback=15 --stale=12 --before=6 --no-interaction',
        'trade:pipeline-h stock --top=50 --lookback=60 --minMove=0.4 --volMult=1.2 --stale=12 --before=6 --no-interaction',
        'trade:pipeline-o stock --stale=12 --before=8 --no-interaction',
    ];

    private const PIPELINE_L_5M_COMMAND = 'trade:pipeline-l stock --top=50 --lookback=120 --minMove=0.4 --volMult=1.2 --stale=12 --before=6 --no-interaction';

    /** Redis key written by stream_bars.py after each flush */
    private const SIGNAL_KEY = 'stream:last_bar_ts';

    public function handle(): int
    {
        if (! $this->isStreamEnabled()) {
            $this->warn('ALPACA_STREAM_ENABLED is not true — watcher has nothing to watch. Exiting.');

            return self::SUCCESS;
        }

        $pollSeconds = max(1, (int) $this->option('poll'));
        $dryRun = (bool) $this->option('dry-run');
        $startupDelay = max(0, (int) $this->option('startup-delay'));

        $this->info(sprintf(
            'stream:watch-and-run-pipelines started (poll every %ds, dry-run: %s, startup-delay: %ds)',
            $pollSeconds,
            $dryRun ? 'yes' : 'no',
            $startupDelay,
        ));

        if ($startupDelay > 0) {
            $this->info("Startup grace period: waiting {$startupDelay}s before firing any pipelines…");
            sleep($startupDelay);
            $this->info('Startup grace period complete — watching for new bars.');
        }

        $lastSeenTs = $this->readSignalKey();
        $this->info('Initial signal key value: '.($lastSeenTs ?? '(empty)'));

        // Track the last minute we fired on to prevent multiple fires per minute
        $lastFiredMinute = $lastSeenTs ? substr($lastSeenTs, 0, 16) : null;

        while (true) {
            sleep($pollSeconds);

            $currentTs = $this->readSignalKey();

            if ($currentTs === null || $currentTs === $lastSeenTs) {
                continue;
            }

            $lastSeenTs = $currentTs;

            // Only fire once per unique minute (bar_ts format: "2026-05-12 17:35:00")
            $currentMinute = substr($currentTs, 0, 16);

            if ($currentMinute === $lastFiredMinute) {
                continue;
            }

            $lastFiredMinute = $currentMinute;

            $this->info("New bar detected @ {$currentTs} — firing pipelines…");
            Log::info('[StreamWatcher] New bar signal received', ['bar_ts' => $currentTs]);

            // On 5-minute boundary bars (e.g. 10:30, 10:35…), sync five_minute_prices FIRST
            // so that pipeline-h/e/o see fresh bar data instead of data up to 5 min stale.
            // This moves detection from ~:01 cron (60+ s late) to ~2-3 s after bar close.
            $barMinute = (int) date('i', strtotime($currentTs));
            $isFiveMinBoundary = ($barMinute % 5 === 0);

            if ($isFiveMinBoundary) {
                $this->info('5m boundary bar — syncing five_minute_prices synchronously…');
                Log::info('[StreamWatcher] 5m boundary: starting alpaca:sync-5m', ['bar_ts' => $currentTs]);
                $syncStart = microtime(true);
                $this->call('alpaca:sync-5m', ['--hours' => 1, '--chunk' => 200, '--feed' => 'iex', '--no-interaction' => true]);
                $syncMs = round((microtime(true) - $syncStart) * 1000);
                $this->info("5m sync complete in {$syncMs}ms — firing 5m pipelines…");
                Log::info('[StreamWatcher] 5m sync complete, firing 5m pipelines', ['duration_ms' => $syncMs]);
                $pipelines5m = self::PIPELINES_5M;
                if ($this->isPipelineEnabledBySettings('L')) {
                    $pipelines5m[] = self::PIPELINE_L_5M_COMMAND;
                }
                $this->firePipelines($dryRun, $pipelines5m);
            }

            $this->firePipelines($dryRun);
        }
    }

    private function readSignalKey(): ?string
    {
        try {
            return Redis::get(self::SIGNAL_KEY) ?: null;
        } catch (\Throwable $e) {
            $this->warn('Redis read failed: '.$e->getMessage());

            return null;
        }
    }

    /**
     * @param  array<string>|null  $pipelines  Defaults to self::PIPELINES when null.
     */
    private function firePipelines(bool $dryRun, ?array $pipelines = null): void
    {
        foreach ($pipelines ?? self::PIPELINES as $commandString) {
            if (! $this->shouldFireCommand($commandString)) {
                continue;
            }

            if ($dryRun) {
                $this->line("  [dry-run] php artisan {$commandString}");

                continue;
            }

            // Each pipeline runs in its own process so they don't block each other
            // and match exactly the behaviour of the per-minute cron entries.
            $artisan = base_path('artisan');
            $phpBin = PHP_BINARY;
            $cmd = "{$phpBin} {$artisan} {$commandString} > /dev/null 2>&1 &";
            exec($cmd);
        }
    }

    private function isStreamEnabled(): bool
    {
        return in_array(
            strtolower((string) env('ALPACA_STREAM_ENABLED', 'false')),
            ['true', '1', 'yes'],
            true,
        );
    }

    private function shouldFireCommand(string $commandString): bool
    {
        if (! preg_match('/trade:pipeline-([a-z])\b/i', $commandString, $matches)) {
            return true;
        }

        $pipelineRun = strtoupper($matches[1]);

        return $this->isPipelineEnabledBySettings($pipelineRun);
    }

    private function isPipelineEnabledBySettings(string $pipelineRun): bool
    {
        return TradingSettingService::isPipelineRunCronEnabled($pipelineRun);
    }
}
