<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\TracesPipelineRun;
use App\Services\Trading\TradeAlertWriterV1;
use App\Services\TradingSettingService;
use Illuminate\Console\Command;

class TradePipelineRunO extends Command
{
    use TracesPipelineRun;

    protected $signature = 'trade:pipeline-o
        {assetType=stock : stock|crypto}
        {--asOf=now : EST timestamp "YYYY-mm-dd HH:MM:SS" or "now"}
        {--before=6 : minutes before asOf to search for entries (live mode)}
        {--after=15 : minutes after signal for entry window}
        {--volLookback=20 : 1m volume baseline minutes}
        {--pivotLookback=15 : 1m pivot lookback minutes}
        {--fill=next_open : next_open|close}
        {--stale=3 : live mode: ignore entries older than N minutes}
        {--backtest : run backtest mode}
        {--rolling-window : backtest mode with auto-calculated rolling window (10min ago to 6min future)}
        {--from= : backtest start date (EST) YYYY-mm-dd}
        {--to= : backtest end date (EST) YYYY-mm-dd}
        {--step=5 : backtest step minutes}
        {--timeFrom=10:05:00 : backtest window start time (EST) - after 30min opening range}
        {--timeTo=15:30:00 : backtest window end time (EST)}
        {--fulltable : use five_minute_prices_full and one_minute_prices_full tables}
    ';

    protected $description = 'Run Pipeline O (v1500.0 Opening Range Breakout): Opening range breakout with volume confirmation -> 1m entry refine -> store alerts to DB (live or backtest).';

    public function handle(TradeAlertWriterV1 $writer): int
    {
        set_time_limit(0);

        // Only set innodb_lock_wait_timeout for MySQL connections
        if (config('database.default') === 'mysql') {
            \DB::statement('SET SESSION innodb_lock_wait_timeout = 600');
        }

        if (! $this->option('backtest') && ! $this->option('rolling-window') && ! TradingSettingService::isPipelineRunCronEnabled('o')) {
            $this->info('Pipeline O: Execution disabled (trading.pipeline_o.run_cron=0). Exiting.');

            return 0;
        }

        $version = config('app.trade_alert_o_version', 'v1500.0');

        // Convert version format: v1500.0 -> V1500_0
        $versionClean = 'V'.str_replace(['v', '.'], ['', '_'], $version);

        // Dynamically instantiate scanner and finder
        $scannerClass = "App\\Services\\Trading\\FiveMinuteSignalScanner{$versionClean}";
        $finderClass = "App\\Services\\Trading\\OneMinuteEntryFinder{$versionClean}";

        if (! class_exists($scannerClass) || ! class_exists($finderClass)) {
            $this->error("Pipeline O version {$version} not found (Scanner: {$scannerClass}, Finder: {$finderClass})");

            return 1;
        }

        $scanner = app($scannerClass);
        $finder = app($finderClass);
        $isFullTable = (bool) $this->option('fulltable')
            && ((bool) $this->option('backtest') || (bool) $this->option('rolling-window'));

        if ((bool) $this->option('fulltable') && ! $isFullTable) {
            $this->warn('Ignoring --fulltable in live mode; _full tables are restricted to backtest/offline runs.');
        }

        $scanner->setFullTable($isFullTable);
        $finder->setFullTable($isFullTable);
        $writer->setFullTable($isFullTable);

        $assetType = strtolower((string) $this->argument('assetType'));
        if (! in_array($assetType, ['stock', 'crypto'], true)) {
            $this->error('assetType must be stock or crypto');

            return 1;
        }

        if ($this->option('rolling-window')) {
            return $this->runRollingWindowBacktest($scanner, $finder, $writer, $assetType, $version);
        }

        if ($this->option('backtest')) {
            return $this->runBacktest($scanner, $finder, $writer, $assetType, $version);
        }

        // Live mode
        $asOfTsEst = $this->resolveAsOfTsEst((string) $this->option('asOf'));
        $tracer = $this->startTrace('O', $asOfTsEst);

        // Scan for opening range breakouts
        $signals = $scanner->scan($assetType, $asOfTsEst);
        $tracer?->checkpoint('SCANNER_DONE', ['signals_found' => count($signals ?? [])]);

        if (! $signals) {
            $this->line("Pipeline O ({$version}): No ORB signals at {$asOfTsEst}");

            $tracer?->finish(['alerts_written' => 0, 'signals' => 0]);

            return 0;
        }

        // Use .env configuration for max age (default 10 minutes)
        $staleMinutes = TradingSettingService::getPipelineMaxAgeMinutes('o');
        $actualNowEst = now('America/New_York')->format('Y-m-d H:i:s');
        $nowEpoch = strtotime($actualNowEst);

        $alertsWritten = 0;
        $ranked = [];

        foreach ($signals as $sig) {
            // Find optimal entry after opening range breakout
            $res = $finder->findBestLong(
                $sig['symbol'],
                $sig['asset_type'],
                $sig['signal_ts_est'],
                $asOfTsEst,
                (int) $this->option('before'),
                (int) $this->option('after'),
                (int) $this->option('volLookback'),
                (int) $this->option('pivotLookback'),
                (string) $this->option('fill')
            );

            if (empty($res['ok']) || empty($res['best_entry'])) {
                continue;
            }

            $entry = $res['best_entry'];
            $entryEpoch = strtotime($entry['entry_ts_est']);
            $signalEpoch = strtotime($sig['signal_ts_est']);

            // Live freshness check: reject if SIGNAL is too old
            // (entry might be recent but signal could be stale)
            if ($staleMinutes > 0 && ($nowEpoch - $signalEpoch) > ($staleMinutes * 60)) {
                continue;
            }

            // Write alert with pipeline_run = 'O'
            $alertId = $writer->upsertAlert($sig, $entry, $asOfTsEst, $scanner->getVersion(), 'O', true);
            if ($alertId) {
                $alertsWritten++;
                $tracer?->alertWritten($alertId, $sig['symbol'], $entry['entry_ts_est'], $sig['signal_ts_est']);
            }

            $ranked[] = [
                'symbol' => $sig['symbol'],
                'signal_ts_est' => $sig['signal_ts_est'],
                'entry_ts_est' => $entry['entry_ts_est'],
                'entry_price' => $entry['entry_price'],
                'stop_price' => $entry['stop_price'],
                'score' => $sig['score'] ?? 0,
            ];
        }

        usort($ranked, fn ($a, $b) => ($b['score'] ?? 0) <=> ($a['score'] ?? 0));

        $this->line(sprintf(
            "Pipeline O ({$version}): Wrote %d alerts from %d ORB signals at %s",
            $alertsWritten,
            count($signals),
            $asOfTsEst
        ));

        foreach ($ranked as $i => $r) {
            $this->line(sprintf(
                '  %2d) %6s | signal=%s | entry=%s @ $%.2f | stop=$%.2f | score=%.1f',
                $i + 1,
                $r['symbol'],
                $r['signal_ts_est'],
                $r['entry_ts_est'],
                $r['entry_price'],
                $r['stop_price'],
                $r['score']
            ));
        }

        $tracer?->finish(['alerts_written' => $alertsWritten, 'signals' => count($signals)]);

        return 0;
    }

    protected function resolveAsOfTsEst(string $input): string
    {
        if ($input === 'now') {
            return now('America/New_York')->format('Y-m-d H:i:s');
        }

        return $input;
    }

    protected function runBacktest($scanner, $finder, TradeAlertWriterV1 $writer, string $assetType, string $version): int
    {
        $writer->setBacktestMode(true);
        $fromDate = (string) $this->option('from');
        $toDate = (string) $this->option('to');
        $step = (int) $this->option('step');
        $timeFrom = (string) $this->option('timeFrom');
        $timeTo = (string) $this->option('timeTo');

        if (! $fromDate || ! $toDate) {
            $this->error('Backtest mode requires --from and --to dates (EST).');

            return 1;
        }

        $this->line("Pipeline O ({$version}): Backtest from {$fromDate} to {$toDate}, step {$step}min, {$timeFrom} to {$timeTo}");

        $current = \Carbon\Carbon::parse("{$fromDate} {$timeFrom}", 'America/New_York');
        $end = \Carbon\Carbon::parse("{$toDate} {$timeTo}", 'America/New_York');

        $totalSignals = 0;
        $totalAlerts = 0;

        while ($current <= $end) {
            $asOfTsEst = $current->format('Y-m-d H:i:s');

            // Skip weekends
            if (in_array($current->dayOfWeek, [0, 6], true)) {
                $current->addMinutes($step);

                continue;
            }

            // Scan for signals
            $signals = $scanner->scan($assetType, $asOfTsEst);

            if ($signals) {
                $totalSignals += count($signals);

                foreach ($signals as $sig) {
                    $res = $finder->findBestLong(
                        $sig['symbol'],
                        $sig['asset_type'],
                        $sig['signal_ts_est'],
                        $asOfTsEst,
                        (int) $this->option('before'),
                        (int) $this->option('after'),
                        (int) $this->option('volLookback'),
                        (int) $this->option('pivotLookback'),
                        (string) $this->option('fill')
                    );

                    if (! empty($res['ok']) && ! empty($res['best_entry'])) {
                        $entry = $res['best_entry'];
                        if ($writer->upsertAlert($sig, $entry, $asOfTsEst, $scanner->getVersion(), 'O', false)) {
                            $totalAlerts++;
                        }
                    }
                }
            }

            $current->addMinutes($step);
        }

        $this->info("Pipeline O ({$version}): Backtest complete. Signals: {$totalSignals}, Alerts: {$totalAlerts}");

        return 0;
    }

    protected function runRollingWindowBacktest($scanner, $finder, TradeAlertWriterV1 $writer, string $assetType, string $version): int
    {
        $writer->setBacktestMode(true);
        // Rolling window: scan 10 minutes ago, look forward 6 minutes for entry
        $nowEst = now('America/New_York');
        $asOfTsEst = $nowEst->copy()->subMinutes(10)->format('Y-m-d H:i:s');

        $this->line("Pipeline O ({$version}): Rolling window backtest asOf={$asOfTsEst}");

        $signals = $scanner->scan($assetType, $asOfTsEst);
        $tracer?->checkpoint('SCANNER_DONE', ['signals_found' => count($signals ?? [])]);

        if (! $signals) {
            $this->line("Pipeline O ({$version}): No ORB signals in rolling window at {$asOfTsEst}");
            $tracer?->finish(['alerts_written' => 0, 'signals' => 0]);

            return 0;
        }

        $alertsWritten = 0;

        foreach ($signals as $sig) {
            $res = $finder->findBestLong(
                $sig['symbol'],
                $sig['asset_type'],
                $sig['signal_ts_est'],
                $asOfTsEst,
                (int) $this->option('before'),
                (int) $this->option('after'),
                (int) $this->option('volLookback'),
                (int) $this->option('pivotLookback'),
                (string) $this->option('fill')
            );

            if (! empty($res['ok']) && ! empty($res['best_entry'])) {
                $entry = $res['best_entry'];
                if ($writer->upsertAlert($sig, $entry, $asOfTsEst, $scanner->getVersion(), 'O', false)) {
                    $alertsWritten++;
                }
            }
        }

        $this->line("Pipeline O ({$version}): Rolling window wrote {$alertsWritten} alerts from ".count($signals).' signals');

        return 0;
    }
}
