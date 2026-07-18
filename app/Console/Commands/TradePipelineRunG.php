<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\TracesPipelineRun;
use App\Services\Trading\EntryRefinerService;
use App\Services\Trading\TradeAlertWriterV1;
use App\Services\TradingSettingService;
use Illuminate\Console\Command;

class TradePipelineRunG extends Command
{
    use TracesPipelineRun;

    protected $signature = 'trade:pipeline-g
        {assetType=stock : stock|crypto}
        {--asOf=now : EST timestamp "YYYY-mm-dd HH:MM:SS" or "now"}
        {--top=25 : max 5m signals to refine}
        {--lookback=60 : 5m scan lookback minutes}
        {--minMove=0.6 : 5m min move pct}
        {--volMult=1.5 : 5m volume multiple}
        {--before=6 : minutes before asOf to search for entries (live mode)}
        {--after=0 : minutes after signal (deprecated - now uses before from asOf)}
        {--volLookback=20 : 1m volume baseline minutes}
        {--pivotLookback=15 : 1m pivot lookback minutes}
        {--fill=next_open : next_open|close}
        {--stale=5 : live mode: ignore entries older than N minutes}
        {--backtest : run backtest mode}
        {--rolling-window : backtest mode with auto-calculated rolling window (10min ago to 6min future)}
        {--from= : backtest start date (EST) YYYY-mm-dd}
        {--to= : backtest end date (EST) YYYY-mm-dd}
        {--step=5 : backtest step minutes}
        {--timeFrom=09:40:00 : backtest window start time (EST)}
        {--timeTo=15:30:00 : backtest window end time (EST)}
        {--fulltable : use five_minute_prices_full and one_minute_prices_full tables}
    ';

    protected $description = 'Run Pipeline G (uses TRADE_ALERT_G_VERSION): 5m scan -> 1m entry refine -> store alerts to DB (live or backtest).';

    public function handle(TradeAlertWriterV1 $writer): int
    {
        set_time_limit(0);

        // Only set innodb_lock_wait_timeout for MySQL connections
        if (config('database.default') === 'mysql') {
            \DB::statement('SET SESSION innodb_lock_wait_timeout = 600');
        }
        if (! TradingSettingService::isPipelineRunCronEnabled('g')) {
            $this->info('Pipeline G: Execution disabled (trading.pipeline_g.run_cron=0). Exiting.');

            return 0;
        }
        $version = config('app.trade_alert_g_version', 'v17.0');

        // Convert version format: v17.0 -> V17_0
        $versionClean = 'V'.str_replace(['v', '.'], ['', '_'], $version);

        // Dynamically instantiate scanner and finder
        $scannerClass = "App\\Services\\Trading\\FiveMinuteSignalScanner{$versionClean}";
        $finderClass = "App\\Services\\Trading\\OneMinuteEntryFinder{$versionClean}";

        if (! class_exists($scannerClass) || ! class_exists($finderClass)) {
            $this->error("Pipeline G version {$version} not found (Scanner: {$scannerClass}, Finder: {$finderClass})");

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

        $asOfTsEst = $this->resolveAsOfTsEst((string) $this->option('asOf'));
        $tracer = $this->startTrace('G', $asOfTsEst);

        $signals = $scanner->scan(
            $assetType,
            $asOfTsEst,
            (int) $this->option('lookback'),
            (float) $this->option('minMove'),
            (float) $this->option('volMult'),
            (int) $this->option('top')
        );
        $tracer?->checkpoint('SCANNER_DONE', ['signals_found' => count($signals ?? [])]);

        if (! $signals) {
            $this->line("Pipeline G ({$version}): No 5m signals at {$asOfTsEst}");

            $tracer?->finish(['alerts_written' => 0, 'signals' => 0]);

            return 0;
        }

        // Use .env configuration for max age (default 10 minutes)
        $staleMinutes = TradingSettingService::getPipelineMaxAgeMinutes('g');
        // Use actual current time for stale check, not scanning window time
        $actualNowEst = now('America/New_York')->format('Y-m-d H:i:s');
        $nowEpoch = strtotime($actualNowEst);

        $alertsWritten = 0;
        $ranked = [];

        // Get ignore types and no_filter_finder for Pipeline G from config
        $ignoreTypes = config('trading.pipelines.g.ignore_types', []);
        $noFilterFinder = config('trading.pipelines.g.no_filter_finder', false);

        foreach ($signals as $sig) {
            // Always call finder to get entry type classification
            $res = $finder->findBestLong(
                $sig['symbol'],
                $sig['asset_type'],
                $sig['signal_ts_est'],
                $asOfTsEst,
                (int) $this->option('before'),
                (int) $this->option('after'),
                (int) $this->option('volLookback'),
                (int) $this->option('pivotLookback'),
                (string) $this->option('fill'),
                (int) $this->option('stale')
            );

            if (empty($res['ok']) || empty($res['best_entry'])) {
                if ($noFilterFinder) {
                    // Finder filtered it out, but we want to keep it for unfiltered comparison
                    // Use scanner's current price as entry
                    $currentPrice = $sig['meta']['current_price'] ?? $sig['price'] ?? null;
                    $stopPrice = $currentPrice ? round($currentPrice * 0.92, 2) : null;
                    $riskPerShare = $currentPrice && $stopPrice ? round($currentPrice - $stopPrice, 4) : null;
                    $riskPct = $currentPrice && $riskPerShare ? round(($riskPerShare / $currentPrice) * 100, 2) : null;

                    // Use filtered_best type if available to show what type it would have been
                    $entryType = 'FILTERED_OUT';
                    if (! empty($res['filtered_best']['type'])) {
                        $entryType = 'FILTERED_'.$res['filtered_best']['type'];
                    }

                    // Calculate ATR-based trailing stops for filtered entries (needed for analysis)
                    $atr = $sig['atr'] ?? null;
                    $atrPct = $sig['atr_pct'] ?? null;
                    $suggestedTrailingStop = ($atr && $currentPrice) ? round((float) $atr * 2.5, 6) : null;
                    $suggestedTrailingStopPct = ($suggestedTrailingStop && $currentPrice) ? round(($suggestedTrailingStop / (float) $currentPrice) * 100, 6) : null;

                    $entry = [
                        'type' => $entryType,
                        'entry_ts_est' => $sig['signal_ts_est'],
                        'entry' => $currentPrice,
                        'stop' => $stopPrice,
                        'risk_pct' => $riskPct,
                        'risk_per_share' => $riskPerShare,
                        'score' => $sig['score'] ?? null,
                        'vol_ratio' => $sig['vol_ratio'] ?? null,
                        'atr' => $atr,
                        'atr_pct' => $atrPct,
                        'suggested_trailing_stop' => $suggestedTrailingStop,
                        'suggested_trailing_stop_pct' => $suggestedTrailingStopPct,
                    ];
                } else {
                    // Filtered out and we're respecting filters
                    continue;
                }
            } else {
                // Use finder's entry
                $entry = $res['best_entry'];
                // Slide to latest bar for live mode
                $entry = EntryRefinerService::slideToLatest($entry, $actualNowEst);
            }

            // Skip if entry type is in ignore list
            if (! empty($ignoreTypes) && in_array($entry['type'] ?? '', $ignoreTypes, true)) {
                continue;
            }

            $entryEpoch = strtotime($entry['entry_ts_est']);
            $signalEpoch = strtotime($sig['signal_ts_est']);

            // Live freshness: reject if SIGNAL is too old
            if ($staleMinutes > 0 && ($nowEpoch - $signalEpoch) > ($staleMinutes * 60)) {
                continue;
            }

            // Write alert with pipeline_run = 'G'
            $alertId = $writer->upsertAlert($sig, $entry, $asOfTsEst, $scanner->getVersion(), 'G', true);
            if ($alertId) {
                $alertsWritten++;
                $tracer?->alertWritten($alertId, $sig['symbol'], $entry['entry_ts_est'], $sig['signal_ts_est']);
            }

            $ranked[] = [
                'symbol' => $sig['symbol'],
                'sigScore' => $sig['score'] ?? null,
                'entryType' => $entry['type'],
                'entryTs' => $entry['entry_ts_est'],
                'entry' => $entry['entry'] ?? null,
                'stop' => $entry['stop'] ?? null,
                'riskPct' => $entry['risk_pct'] ?? null,
                'score' => $entry['score'] ?? null,
            ];
        }

        usort($ranked, function ($a, $b) {
            $ra = $a['riskPct'] ?? 999;
            $rb = $b['riskPct'] ?? 999;
            if ($ra === $rb) {
                return ($b['score'] ?? 0) <=> ($a['score'] ?? 0);
            }

            return $ra <=> $rb;
        });

        $tracer?->finish(['alerts_written' => $alertsWritten, 'signals' => count($signals)]);

        $this->info("Pipeline G ({$version}) | As-of (EST): {$asOfTsEst}");
        $this->info('Signals: '.count($signals).' | Actionable entries: '.count($ranked)." | Alerts written: {$alertsWritten}");

        $this->table(
            ['Symbol', 'EntryType', 'EntryTs', 'Entry', 'Stop', 'Risk%', 'EntryScore', 'SigScore'],
            array_slice(array_map(fn ($r) => [
                $r['symbol'], $r['entryType'], $r['entryTs'],
                $r['entry'], $r['stop'], $r['riskPct'], $r['score'], $r['sigScore'],
            ], $ranked), 0, 20)
        );

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

    private function runBacktest($scanner, $finder, TradeAlertWriterV1 $writer, string $assetType, string $version): int
    {
        $writer->setBacktestMode(true);
        $from = (string) $this->option('from');
        $to = (string) $this->option('to');
        if (! $from || ! $to) {
            $this->error('--from and --to are required in --backtest mode (YYYY-mm-dd)');

            return 1;
        }

        $step = max(1, (int) $this->option('step'));
        $timeFrom = (string) $this->option('timeFrom');
        $timeTo = (string) $this->option('timeTo');

        $startDate = strtotime($from.' 00:00:00');
        $endDate = strtotime($to.' 00:00:00');

        $totalSignals = 0;
        $totalEntries = 0;
        $totalAlerts = 0;

        // Get ignore types and no_filter_finder for Pipeline G from config
        $ignoreTypes = config('trading.pipelines.g.ignore_types', []);
        $noFilterFinder = config('trading.pipelines.g.no_filter_finder', false);

        for ($d = $startDate; $d <= $endDate; $d += 86400) {
            $day = date('Y-m-d', $d);

            $tStart = strtotime($day.' '.$timeFrom);
            $tEnd = strtotime($day.' '.$timeTo);

            for ($t = $tStart; $t <= $tEnd; $t += ($step * 60)) {
                $asOfTsEst = date('Y-m-d H:i:s', $t);

                $signals = $scanner->scan(
                    $assetType,
                    $asOfTsEst,
                    (int) $this->option('lookback'),
                    (float) $this->option('minMove'),
                    (float) $this->option('volMult'),
                    (int) $this->option('top')
                );

                $totalSignals += count($signals);

                foreach ($signals as $sig) {
                    // Always call finder to get entry type classification
                    $res = $finder->findBestLong(
                        $sig['symbol'],
                        $sig['asset_type'],
                        $sig['signal_ts_est'],
                        $asOfTsEst,
                        (int) $this->option('before'),
                        (int) $this->option('after'),
                        (int) $this->option('volLookback'),
                        (int) $this->option('pivotLookback'),
                        (string) $this->option('fill'),
                        $sig, // Pass signal metadata as array
                        (int) $this->option('stale')
                    );

                    if (empty($res['ok']) || empty($res['best_entry'])) {
                        if ($noFilterFinder) {
                            // Finder filtered it out, but we want to keep it for unfiltered comparison
                            // Use scanner's current price as entry
                            $currentPrice = $sig['meta']['current_price'] ?? $sig['price'] ?? null;
                            $stopPrice = $currentPrice ? round($currentPrice * 0.92, 2) : null;
                            $riskPerShare = $currentPrice && $stopPrice ? round($currentPrice - $stopPrice, 4) : null;
                            $riskPct = $currentPrice && $riskPerShare ? round(($riskPerShare / $currentPrice) * 100, 2) : null;

                            // Use filtered_best type if available to show what type it would have been
                            $entryType = 'FILTERED_OUT';
                            if (! empty($res['filtered_best']['type'])) {
                                $entryType = 'FILTERED_'.$res['filtered_best']['type'];
                            }

                            // Calculate ATR-based trailing stops for filtered entries (needed for analysis)
                            $atr = $sig['atr'] ?? null;
                            $atrPct = $sig['atr_pct'] ?? null;
                            $suggestedTrailingStop = ($atr && $currentPrice) ? round((float) $atr * 2.5, 6) : null;
                            $suggestedTrailingStopPct = ($suggestedTrailingStop && $currentPrice) ? round(($suggestedTrailingStop / (float) $currentPrice) * 100, 6) : null;

                            $entry = [
                                'type' => $entryType,
                                'entry_ts_est' => $sig['signal_ts_est'],
                                'entry' => $currentPrice,
                                'stop' => $stopPrice,
                                'risk_pct' => $riskPct,
                                'risk_per_share' => $riskPerShare,
                                'score' => $sig['score'] ?? null,
                                'vol_ratio' => $sig['vol_ratio'] ?? null,
                                'atr' => $atr,
                                'atr_pct' => $atrPct,
                                'suggested_trailing_stop' => $suggestedTrailingStop,
                                'suggested_trailing_stop_pct' => $suggestedTrailingStopPct,
                            ];
                        } else {
                            // Filtered out and we're respecting filters
                            continue;
                        }
                    } else {
                        // Use finder's entry
                        $entry = $res['best_entry'];
                    }

                    // Skip if entry type is in ignore list
                    if (! empty($ignoreTypes) && in_array($entry['type'] ?? '', $ignoreTypes, true)) {
                        continue;
                    }

                    $totalEntries++;

                    // Write alert with pipeline_run = 'G'
                    if ($writer->upsertAlert($sig, $entry, $asOfTsEst, $scanner->getVersion(), 'G', false)) {
                        $totalAlerts++;
                    }
                }
            }

            $this->line("Pipeline G ({$version}): Backtest day {$day} done.");
        }

        $this->info("Pipeline G ({$version}): Backtest complete.");
        $this->info("Total signals: {$totalSignals}");
        $this->info("Total actionable entries: {$totalEntries}");
        $this->info("Total alerts inserted (deduped): {$totalAlerts}");

        return 0;
    }

    private function runRollingWindowBacktest($scanner, $finder, TradeAlertWriterV1 $writer, string $assetType, string $version): int
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

        $this->info("Pipeline G ({$version}): Rolling window backtest");
        $this->info("From: {$fromDate} {$timeFrom} | To: {$toDate} {$timeTo}");

        return $this->runBacktest($scanner, $finder, $writer, $assetType, $version);
    }
}
