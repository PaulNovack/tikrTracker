<?php

namespace App\Console\Commands;

use App\Services\Trading\BarEventConsumer;
use App\Services\Trading\TradeAlertWriterV1;
use Illuminate\Console\Command;

class ConsumeBarEvents extends Command
{
    protected $signature = 'bar-events:consume
        {--pipeline=h : Pipeline letter (a-q), default h}
        {--group=scanner-h : Redis stream consumer group name}
        {--consumer=worker-1 : Consumer name (unique per process)}
        {--batch=100 : Max events per read}
    ';

    protected $description = 'Consume bar events from rt:events:bars stream and run per-symbol scanning.';

    public function handle(TradeAlertWriterV1 $writer): int
    {
        $group = $this->option('group');
        $consumerName = $this->option('consumer');
        $batchSize = max(1, (int) $this->option('batch'));
        $pipelineLetter = strtolower((string) $this->option('pipeline'));

        $version = config("app.trade_alert_{$pipelineLetter}_version");

        if (! $version) {
            $this->error("No version config for pipeline '{$pipelineLetter}'");

            return self::FAILURE;
        }

        $versionClean = 'V'.str_replace(['v', '.'], ['', '_'], $version);
        $scannerClass = "App\\Services\\Trading\\FiveMinuteSignalScanner{$versionClean}Redis";
        $finderClass = "App\\Services\\Trading\\OneMinuteEntryFinder{$versionClean}Redis";

        if (! class_exists($scannerClass)) {
            $this->error("Redis scanner not found: {$scannerClass} (pipeline {$pipelineLetter}, version {$version})");

            return self::FAILURE;
        }

        $scanner = app($scannerClass);
        $finder = app($finderClass);
        $barConsumer = new BarEventConsumer($scanner, $finder, $writer);

        $this->info("BarEventConsumer pipeline={$pipelineLetter} v={$version} group={$group}");

        $barConsumer->run($group, $consumerName, $batchSize);

        return self::SUCCESS;
    }
}
