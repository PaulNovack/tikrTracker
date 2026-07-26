<?php

namespace App\Console\Commands;

use App\Services\Trading\BarEventConsumer;
use App\Services\Trading\FiveMinuteSignalScannerV25_2Redis;
use App\Services\Trading\OneMinuteEntryFinderV25_2Redis;
use Illuminate\Console\Command;

class ConsumeBarEvents extends Command
{
    protected $signature = 'bar-events:consume
        {--group=scanner-v25 : Redis stream consumer group name}
        {--consumer=worker-1 : Consumer name (unique per process)}
        {--batch=100 : Max events per read}
    ';

    protected $description = 'Consume bar events from rt:events:bars stream and run per-symbol scanning.';

    public function handle(): int
    {
        $group = $this->option('group');
        $consumer = $this->option('consumer');
        $batchSize = max(1, (int) $this->option('batch'));

        $scanner = new FiveMinuteSignalScannerV25_2Redis(
            app(\App\Services\Market\BestPerformers5mService::class),
            app(\App\Services\GainersLosersAnalysisService::class),
        );
        $finder = new OneMinuteEntryFinderV25_2Redis;

        $consumer = new BarEventConsumer($scanner, $finder);

        $this->info("Starting BarEventConsumer (group={$group}, batch={$batchSize})...");

        $consumer->run($group, $consumer->name, $batchSize);

        return self::SUCCESS;
    }
}
