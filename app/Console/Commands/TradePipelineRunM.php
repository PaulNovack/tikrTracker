<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\TracesPipelineRun;
use App\Services\Trading\TradeAlertWriterV1;
use App\Services\TradingSettingService;
use Illuminate\Console\Command;

class TradePipelineRunM extends Command
{
    use TracesPipelineRun;

    protected $signature = 'trade:pipeline-m
        {assetType=stock : stock|crypto}
        {--asOf=now : EST timestamp "YYYY-mm-dd HH:MM:SS" or "now"}
        {--before=6 : Minutes before asOf to search for entries (live mode)}
        {--volLookback=20 : 1m volume baseline minutes}
        {--fill=next_open : Entry fill method: next_open|close}
        {--stale=5 : Live mode: ignore entries older than N minutes}
        {--backtest : Run backtest mode}
        {--rolling-window : Backtest mode with auto-calculated rolling window (5min ago to 6min future)}
        {--from= : Backtest start date (EST) YYYY-mm-dd}
        {--to= : Backtest end date (EST) YYYY-mm-dd}
        {--enforce-current-freshness : in backtest mode, compare slot/signal/entry age against current wall-clock and skip stale candidates early}
        {--step=5 : Backtest step minutes}
        {--timeFrom=09:40:00 : Backtest window start time (EST)}
        {--timeTo=15:30:00 : Backtest window end time (EST)}
        {--fulltable : use five_minute_prices_full and one_minute_prices_full tables}
    ';

    protected $description = 'Pipeline M (v1400.0 Tight Stops Clean Trend): Scan for smooth 2-hour trends with minimal drawdowns -> find tight-stop entries -> store alerts';

    public function handle(TradeAlertWriterV1 $writer): int
    {
        set_time_limit(0);

        if (config('database.default') === 'mysql') {
            \DB::statement('SET SESSION innodb_lock_wait_timeout = 600');
        }

        if (! $this->option('backtest') && ! $this->option('rolling-window') && ! TradingSettingService::isPipelineRunCronEnabled('m')) {
            $this->info('Pipeline M: Execution disabled (trading.pipeline_m.run_cron=0). Exiting.');

            return 0;
        }

        $version = config('app.trade_alert_m_version', 'v57.0');

        // Convert version format: v57.0 -> V57_0
        $versionClean = 'V'.str_replace(['v', '.'], ['', '_'], $version);

        // Dynamically instantiate scanner and finder
        $scannerClass = "App\\Services\\Trading\\FiveMinuteSignalScanner{$versionClean}";
        $finderClass = "App\\Services\\Trading\\OneMinuteEntryFinder{$versionClean}";

        if (! class_exists($scannerClass) || ! class_exists($finderClass)) {
            $this->error("Pipeline M version {$version} not found (Scanner: {$scannerClass}, Finder: {$finderClass})");

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

        if ($this->option('backtest')) {
            return $this->runBacktest($scanner, $finder, $writer, $assetType, $version, (bool) $this->option('enforce-current-freshness'));
        }

        if ($this->option('rolling-window')) {
            return $this->runRollingWindowBacktest($scanner, $finder, $writer, $assetType, $version, (bool) $this->option('enforce-current-freshness'));
        }

        $asOfTsEst = $this->resolveAsOfTsEst((string) $this->option('asOf'));
        $tracer = $this->startTrace('M', $asOfTsEst);

        // Scan for clean 2-hour trends
        $signals = $scanner->scan($assetType, $asOfTsEst);
        $tracer?->checkpoint('SCANNER_DONE', ['signals_found' => count($signals ?? [])]);

        if (! $signals) {
            $this->line("Pipeline M ({$version}): No clean trends at {$asOfTsEst}");
            $tracer?->finish(['alerts_written' => 0, 'signals' => 0]);

            return 0;
        }

        // Prefer the --stale CLI option; fall back to config
        $staleMinutes = (int) $this->option('stale') ?: TradingSettingService::getPipelineMaxAgeMinutes('m');
        $actualNowEst = now('America/New_York')->format('Y-m-d H:i:s');
        $nowEpoch = strtotime($actualNowEst);

        $alertsWritten = 0;
        $ranked = [];
        $maxSignalAgeMinutes = 5;

        foreach ($signals as $sig) {
            $signalEpoch = strtotime((string) $sig['signal_ts_est']);
            $signalAgeSeconds = $signalEpoch !== false ? $nowEpoch - $signalEpoch : PHP_INT_MAX;
            $signalAgeMinutes = $signalAgeSeconds === PHP_INT_MAX ? null : round($signalAgeSeconds / 60, 1);

            if ($signalEpoch === false || ($maxSignalAgeMinutes > 0 && $signalAgeSeconds > ($maxSignalAgeMinutes * 60))) {
                $this->line("  Skipping {$sig['symbol']}: signal {$sig['signal_ts_est']} is too old ({$maxSignalAgeMinutes}m limit)");

                continue;
            }

            // Find clean continuation entry.
            // Use asOfTsEst as the entry search anchor so we only look at bars
            // close to NOW (controlled by --before), not all the way back to the
            // 2h trend anchor. This prevents picking entries that are already stale.
            $entrySearchFrom = date('Y-m-d H:i:s', strtotime($asOfTsEst) - ((int) $this->option('before') * 60));

            $res = $finder->findBestLong(
                $sig['symbol'],
                $sig['asset_type'],
                $entrySearchFrom,
                $asOfTsEst,
                (int) $this->option('before'),
                15, // afterMinutes - look 15min ahead for entries
                (int) $this->option('volLookback'),
                15, // pivotLookback
                (string) $this->option('fill')
            );

            if (empty($res['ok']) || empty($res['best_entry'])) {
                continue;
            }

            $entry = $res['best_entry'];
            $entryEpoch = strtotime($entry['entry_ts_est']);

            // Live freshness check: the ENTRY must be recent and the SIGNAL must
            // still be close to now.
            if ($staleMinutes > 0 && ($nowEpoch - $entryEpoch) > ($staleMinutes * 60)) {
                $this->line("  Skipping {$sig['symbol']}: entry {$entry['entry_ts_est']} is too old ({$staleMinutes}m limit)");

                continue;
            }

            if ($signalAgeMinutes !== null && $signalAgeMinutes > 5) {
                $this->line("  Skipping {$sig['symbol']}: signal {$sig['signal_ts_est']} is stale ({$signalAgeMinutes}m old)");

                continue;
            }

            // Write alert with pipeline_run = 'M'
            $alertId = $writer->upsertAlert($sig, $entry, $asOfTsEst, $scanner->getVersion(), 'M');
            if ($alertId) {
                $alertsWritten++;
                $tracer?->alertWritten($alertId, $sig['symbol'], $entry['entry_ts_est'], $sig['signal_ts_est']);
            }

            $trendPct = (float) ($sig['trend_pct'] ?? $sig['trendPct'] ?? 0.0);
            $maxDrawdownPct = (float) ($sig['max_drawdown_pct'] ?? $sig['maxDD'] ?? 0.0);
            $riskScore = (float) ($sig['risk_score'] ?? $sig['riskScore'] ?? 0.0);

            $ranked[] = [
                'symbol' => $sig['symbol'],
                'trendPct' => $trendPct,
                'maxDD' => $maxDrawdownPct,
                'riskScore' => $riskScore,
                'entryType' => $entry['type'],
                'entryTs' => $entry['entry_ts_est'],
                'entry' => $entry['entry'] ?? null,
                'stop' => $entry['stop'] ?? null,
                'riskPct' => $entry['risk_pct'] ?? null,
                'score' => $entry['score'] ?? null,
            ];
        }

        usort($ranked, function ($a, $b) {
            return ($b['riskScore'] ?? 0) <=> ($a['riskScore'] ?? 0);
        });

        $this->info("Pipeline M ({$version}) | As-of (EST): {$asOfTsEst}");
        $this->info('Clean Trends: '.count($signals).' | Tight-stop entries: '.count($ranked)." | Alerts written: {$alertsWritten}");

        $this->table(
            ['Symbol', 'Trend%', 'MaxDD%', 'RiskScore', 'EntryType', 'EntryTs', 'Entry', 'Stop', 'Risk%', 'Score'],
            array_slice(array_map(fn ($r) => [
                $r['symbol'],
                number_format($r['trendPct'], 2),
                number_format($r['maxDD'], 2),
                number_format($r['riskScore'], 2),
                $r['entryType'],
                $r['entryTs'],
                $r['entry'],
                $r['stop'],
                $r['riskPct'],
                $r['score'],
            ], $ranked), 0, 20)
        );

        $tracer?->finish(['alerts_written' => $alertsWritten, 'signals' => count($signals)]);

        return 0;
    }

    private function resolveAsOfTsEst(string $asOfOpt): string
    {
        if (strtolower(trim($asOfOpt)) === 'now') {
            $estTimezone = new \DateTimeZone('America/New_York');
            $now = new \DateTime('now', $estTimezone);

            return $now->format('Y-m-d H:i:s');
        }

        $timestamp = strtotime($asOfOpt);
        if ($timestamp !== false) {
            return date('Y-m-d H:i:s', $timestamp);
        }

        return $asOfOpt;
    }

    private function runBacktest($scanner, $finder, TradeAlertWriterV1 $writer, string $assetType, string $version, bool $enforceCurrentFreshness = false): int
    {
        $writer->setBacktestMode(true);
        $from = (string) $this->option('from');
        $to = (string) $this->option('to');
        $stepMinutes = (int) $this->option('step');
        $timeFrom = (string) $this->option('timeFrom');
        $timeTo = (string) $this->option('timeTo');

        if (! $from || ! $to) {
            $this->error('Backtest mode requires --from and --to dates');

            return 1;
        }

        $this->info("Pipeline M ({$version}) Backtest: {$from} to {$to}, step={$stepMinutes}min, window={$timeFrom}-{$timeTo}");

        $currentDate = $from;
        $totalAlerts = 0;
        $staleMinutes = (int) $this->option('stale');
        if ($staleMinutes <= 0) {
            $staleMinutes = TradingSettingService::getPipelineMaxAgeMinutes('m');
        }

        $earlyLeadMinutes = (int) config('trading.auto_alpaca_orders.stale_slot_early_lead_minutes', 11);
        $earlyLagThresholdMinutes = max(1, $staleMinutes - $earlyLeadMinutes);

        while ($currentDate <= $to) {
            // Start at timeFrom on this date
            $currentTs = "{$currentDate} {$timeFrom}";
            $endTs = "{$currentDate} {$timeTo}";

            $this->line("Processing {$currentDate}...");

            while ($currentTs <= $endTs) {
                if ($enforceCurrentFreshness) {
                    $nowEpoch = strtotime(now('America/New_York')->format('Y-m-d H:i:s'));
                    $asOfEpoch = strtotime($currentTs);
                    $slotLagMinutes = ($nowEpoch - $asOfEpoch) / 60;

                    if ($slotLagMinutes > $earlyLagThresholdMinutes) {
                        $this->line("  Skipping {$currentDate} {$currentTs}: slot is lagging by ".round($slotLagMinutes, 2).'m');
                    }

                    if ($slotLagMinutes > $staleMinutes) {
                        continue;
                    }
                }

                $signals = $scanner->scan($assetType, $currentTs);

                if ($signals) {
                    foreach ($signals as $sig) {
                        $res = $finder->findBestLong(
                            $sig['symbol'],
                            $sig['asset_type'],
                            $sig['signal_ts_est'],
                            $currentTs,
                            (int) $this->option('before'),
                            15, // afterMinutes
                            (int) $this->option('volLookback'),
                            15, // pivotLookback
                            (string) $this->option('fill')
                        );

                        if (! empty($res['ok']) && ! empty($res['best_entry'])) {
                            $entry = $res['best_entry'];

                            if ($enforceCurrentFreshness && $staleMinutes > 0) {
                                $currentNowEpoch = strtotime(now('America/New_York')->format('Y-m-d H:i:s'));
                                $signalEpoch = strtotime($sig['signal_ts_est']);
                                $entryEpoch = strtotime($entry['entry_ts_est']);
                                $signalAgeMinutes = ($currentNowEpoch - $signalEpoch) / 60;
                                $entryAgeMinutes = ($currentNowEpoch - $entryEpoch) / 60;

                                if ($entryAgeMinutes > $staleMinutes || $signalAgeMinutes > $staleMinutes) {
                                    \Log::channel('stale-alerts')->warning('[Pipeline M] Early stale alert candidate detected before write', [
                                        'symbol' => $sig['symbol'] ?? null,
                                        'signal_ts_est' => $sig['signal_ts_est'] ?? null,
                                        'entry_ts_est' => $entry['entry_ts_est'] ?? null,
                                        'as_of_ts_est' => $currentTs,
                                        'signal_age_minutes' => round($signalAgeMinutes, 2),
                                        'entry_age_minutes' => round($entryAgeMinutes, 2),
                                        'stale_limit_minutes' => $staleMinutes,
                                        'mode' => 'rolling-window-backtest',
                                    ]);

                                    continue;
                                }
                            }

                            if ($writer->upsertAlert($sig, $entry, $currentTs, $version, 'M')) {
                                $totalAlerts++;
                            }
                        }
                    }
                }
                // Advance by step
                $currentTs = date('Y-m-d H:i:s', strtotime($currentTs) + ($stepMinutes * 60));
            }

            // Next trading day
            $currentDate = date('Y-m-d', strtotime($currentDate.' +1 day'));
        }

        $this->info("Backtest complete! Total alerts written: {$totalAlerts}");

        return 0;
    }

    private function runRollingWindowBacktest($scanner, $finder, TradeAlertWriterV1 $writer, string $assetType, string $version, bool $enforceCurrentFreshness = false): int
    {
        $writer->setBacktestMode(true);
        $est = now('America/New_York');
        $fromDate = $est->copy()->subMinutes(5)->format('Y-m-d');
        $toDate = $est->copy()->addMinutes(6)->format('Y-m-d');
        $timeFrom = $est->copy()->subMinutes(5)->format('H:i:00');
        $timeTo = $est->copy()->addMinutes(6)->format('H:i:00');

        // Override options with calculated values
        $this->input->setOption('from', $fromDate);
        $this->input->setOption('to', $toDate);
        $this->input->setOption('timeFrom', $timeFrom);
        $this->input->setOption('timeTo', $timeTo);

        $this->info("Pipeline M ({$version}): Rolling window backtest");
        $this->info("From: {$fromDate} {$timeFrom} | To: {$toDate} {$timeTo}");

        return $this->runBacktest($scanner, $finder, $writer, $assetType, $version, $enforceCurrentFreshness);
    }
}
