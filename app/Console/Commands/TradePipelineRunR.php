<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\TracesPipelineRun;
use App\Services\TradingSettingService;
use Illuminate\Console\Command;

class TradePipelineRunR extends Command
{
    use TracesPipelineRun;

    protected $signature = 'trade:pipeline-r
        {--from= : Backtest start date (EST) YYYY-MM-DD}
        {--to= : Backtest end date (EST) YYYY-MM-DD}
        {--days= : Alternative: number of trading days back from --to}
        {--interval=15 : Minutes between simulated loops}
        {--full-table : Use one_minute_prices_full table}
        {--relaxed : Relax detection thresholds}
        {--dispatch-jobs : Dispatch symbol batches as queued jobs}
        {--dry-run : Scan but do not create candidates or alerts}
        {--no-entry-watcher : Skip the entry watcher step}
        {--skip-liquidity-filter : Skip the slow liquidity pre-filter}
        {--limit= : Max symbols to scan per loop}
        {--max-yield : Ultra-aggressive detection for ML training}
        {--live : Run live (not backtest) — detects and watches candidates}';

    protected $description = 'Run Pipeline R (Momentum Continuation) — backtest or live realtime detection.';

    public function handle(): int
    {
        set_time_limit(0);

        if (config('database.default') === 'mysql') {
            \DB::statement('SET SESSION innodb_lock_wait_timeout = 600');
        }

        if ($this->option('live')) {
            if (! TradingSettingService::isPipelineRunCronEnabled('r')) {
                $this->info('Pipeline R: Execution disabled (trading.pipeline_r.run_cron=0). Exiting.');

                return 0;
            }

            return $this->call('trading:realtime-watch');
        }

        $from = $this->option('from');
        $to = $this->option('to');

        if (! $from || ! $to) {
            $this->error('Both --from and --to are required for backtest mode.');

            return self::FAILURE;
        }

        $args = [
            '--pipeline' => 'R',
            '--from' => $from,
            '--to' => $to,
        ];

        foreach (['days', 'interval', 'full-table', 'relaxed', 'dispatch-jobs',
            'dry-run', 'no-entry-watcher', 'skip-liquidity-filter', 'limit', 'max-yield'] as $opt) {
            $val = $this->option($opt);
            if ($val !== null && $val !== false) {
                $args['--'.$opt] = $val;
            }
        }

        return $this->call('trading:realtime-backtest', $args);
    }
}
