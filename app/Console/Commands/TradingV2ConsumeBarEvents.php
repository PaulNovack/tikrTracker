<?php

namespace App\Console\Commands;

use App\Services\TradingV2\BarEventConsumer;
use App\Services\TradingV2\GateEvaluator;
use App\Services\TradingV2\Repositories\AlertVersionRepository;
use Illuminate\Console\Command;

/**
 * Single consumer for all alert versions — replaces 18 per-pipeline
 * bar-events:consume commands.
 *
 * Usage: php artisan trading:consume-bars
 */
class TradingV2ConsumeBarEvents extends Command
{
    protected $signature = 'trading:consume-bars
        {--group=scanner : Redis stream consumer group}
        {--consumer=worker : Consumer name}
        {--batch=200 : Max events per read}';

    protected $description = 'Consume bar events from Redis stream and dispatch gate-check jobs for all alert versions.';

    public function handle(GateEvaluator $evaluator, AlertVersionRepository $versionRepo): int
    {
        $consumer = new BarEventConsumer($evaluator, $versionRepo);

        $this->info('TradingV2 consumer starting...');
        $this->info('Stream: rt:events:bars');
        $this->info('Group: '.$this->option('group'));
        $this->info('Batch: '.$this->option('batch'));
        $this->info('Queue: gate-check');
        $this->info('Active versions: '.count($versionRepo->getActive()));

        $consumer->run(
            $this->option('group'),
            $this->option('consumer'),
            (int) $this->option('batch'),
        );

        return self::SUCCESS;
    }
}
