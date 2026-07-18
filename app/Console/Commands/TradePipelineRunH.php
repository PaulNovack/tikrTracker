<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\TracesPipelineRun;
use App\Services\Trading\EntryRefinerService;
use App\Services\Trading\TradeAlertWriterV1;
use App\Services\TradingSettingService;
use Illuminate\Console\Command;

class TradePipelineRunH extends Command
{
    use TracesPipelineRun;

    protected $signature = 'trade:pipeline-h
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

    protected $description = 'Run Pipeline H (uses TRADE_ALERT_H_VERSION): 5m scan -> 1m entry refine -> store alerts to DB (live or backtest).';

    public function handle(TradeAlertWriterV1 $writer): int
    {
        set_time_limit(0);

        // Only set innodb_lock_wait_timeout for MySQL connections
        if (config('database.default') === 'mysql') {
            \DB::statement('SET SESSION innodb_lock_wait_timeout = 600');
        }
        if (! $this->option('backtest') && ! $this->option('rolling-window') && ! TradingSettingService::isPipelineRunCronEnabled('h')) {
            $this->info('Pipeline H: Execution disabled (trading.pipeline_h.run_cron=0). Exiting.');

            return 0;
        }
        $version = config('app.trade_alert_h_version', 'v17.0');

        // Convert version format: v17.0 -> V17_0
        $versionClean = 'V'.str_replace(['v', '.'], ['', '_'], $version);

        // Dynamically instantiate scanner and finder
        $scannerClass = "App\\Services\\Trading\\FiveMinuteSignalScanner{$versionClean}";
        $finderClass = "App\\Services\\Trading\\OneMinuteEntryFinder{$versionClean}";

        if (! class_exists($scannerClass) || ! class_exists($finderClass)) {
            $this->error("Pipeline H version {$version} not found (Scanner: {$scannerClass}, Finder: {$finderClass})");

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
        $tracer = $this->startTrace('H', $asOfTsEst);

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
            $this->line("Pipeline H ({$version}): No 5m signals at {$asOfTsEst}");

            $tracer?->finish(['alerts_written' => 0, 'signals' => 0]);

            return 0;
        }

        // Live freshness gate must honor --stale from scheduler/watcher.
        // Fallback to Pipeline H max-age config only when option is omitted/invalid.
        $staleMinutes = (int) $this->option('stale');
        if ($staleMinutes <= 0) {
            $staleMinutes = TradingSettingService::getPipelineMaxAgeMinutes('h');
        }
        // Use actual current time for stale check, not scanning window time
        $actualNowEst = now('America/New_York')->format('Y-m-d H:i:s');
        $nowEpoch = strtotime($actualNowEst);

        $alertsWritten = 0;
        $ranked = [];

        // Get ignore types and no_filter_finder for Pipeline H from config
        $ignoreTypes = config('trading.pipelines.h.ignore_types', []);
        $noFilterFinder = config('trading.pipelines.h.no_filter_finder', false);

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
                (int) $this->option('stale'),
                $actualNowEst
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
                $signalAgeMinutes = round(($nowEpoch - $signalEpoch) / 60, 2);
                \Log::warning('[Pipeline H] Early stale signal detected in live path', [
                    'symbol' => $sig['symbol'] ?? null,
                    'signal_ts_est' => $sig['signal_ts_est'] ?? null,
                    'as_of_ts_est' => $asOfTsEst,
                    'signal_age_minutes' => $signalAgeMinutes,
                    'stale_limit_minutes' => $staleMinutes,
                    'mode' => 'live',
                ]);

                continue;
            }

            // Write alert with pipeline_run = 'H'
            $alertId = $writer->upsertAlert($sig, $entry, $asOfTsEst, $scanner->getVersion(), 'H', true);
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

        $this->info("Pipeline H ({$version}) | As-of (EST): {$asOfTsEst}");
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

    private function runBacktest($scanner, $finder, TradeAlertWriterV1 $writer, string $assetType, string $version, bool $enforceCurrentFreshness = false): int
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
        $totalStaleDetected = 0;
        $totalLagDetected = 0;
        $totalLagSkipped = 0;

        $staleMinutes = (int) $this->option('stale');
        if ($staleMinutes <= 0) {
            $staleMinutes = TradingSettingService::getPipelineMaxAgeMinutes('h');
        }

        $earlyLeadMinutes = (int) config('trading.auto_alpaca_orders.stale_slot_early_lead_minutes', 11);
        $earlyLagThresholdMinutes = max(1, $staleMinutes - $earlyLeadMinutes);

        // Get ignore types and no_filter_finder for Pipeline H from config
        $ignoreTypes = config('trading.pipelines.h.ignore_types', []);
        $noFilterFinder = config('trading.pipelines.h.no_filter_finder', false);

        for ($d = $startDate; $d <= $endDate; $d += 86400) {
            $day = date('Y-m-d', $d);

            $tStart = strtotime($day.' '.$timeFrom);
            $tEnd = strtotime($day.' '.$timeTo);

            for ($t = $tStart; $t <= $tEnd; $t += ($step * 60)) {
                $asOfTsEst = date('Y-m-d H:i:s', $t);

                if ($enforceCurrentFreshness) {
                    $nowEpoch = strtotime(now('America/New_York')->format('Y-m-d H:i:s'));
                    $asOfEpoch = strtotime($asOfTsEst);
                    $slotLagMinutes = ($nowEpoch - $asOfEpoch) / 60;

                    if ($slotLagMinutes > $earlyLagThresholdMinutes) {
                        $totalLagDetected++;
                    }

                    if ($slotLagMinutes > $staleMinutes) {
                        $totalLagSkipped++;

                        continue;
                    }
                }

                $signals = $scanner->scan(
                    $assetType,
                    $asOfTsEst,
                    (int) $this->option('lookback'),
                    (float) $this->option('minMove'),
                    (float) $this->option('volMult'),
                    (int) $this->option('top'),
                    skipCache: true
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
                        (int) $this->option('stale'),
                        $enforceCurrentFreshness ? now('America/New_York')->format('Y-m-d H:i:s') : null
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

                    if ($enforceCurrentFreshness && $staleMinutes > 0) {
                        $nowEpoch = strtotime(now('America/New_York')->format('Y-m-d H:i:s'));
                        $signalEpoch = strtotime($sig['signal_ts_est']);
                        $entryEpoch = strtotime($entry['entry_ts_est']);

                        $signalAgeMinutes = ($nowEpoch - $signalEpoch) / 60;
                        $entryAgeMinutes = ($nowEpoch - $entryEpoch) / 60;

                        if ($entryAgeMinutes > $staleMinutes) {
                            $totalStaleDetected++;

                            \Log::channel('stale-alerts')->warning('[Pipeline H] Early stale alert candidate detected before write', [
                                'symbol' => $sig['symbol'] ?? null,
                                'signal_ts_est' => $sig['signal_ts_est'] ?? null,
                                'entry_ts_est' => $entry['entry_ts_est'] ?? null,
                                'as_of_ts_est' => $asOfTsEst,
                                'signal_age_minutes' => round($signalAgeMinutes, 2),
                                'entry_age_minutes' => round($entryAgeMinutes, 2),
                                'stale_limit_minutes' => $staleMinutes,
                                'mode' => 'rolling-window-backtest',
                            ]);

                            continue;
                        }
                    }

                    $totalEntries++;

                    // Write alert with pipeline_run = 'A'
                    if ($writer->upsertAlert($sig, $entry, $asOfTsEst, $scanner->getVersion(), 'H', false)) {
                        $totalAlerts++;
                    }
                }
            }

            $this->line("Pipeline H ({$version}): Backtest day {$day} done.");
        }

        $this->info("Pipeline H ({$version}): Backtest complete.");
        $this->info("Total signals: {$totalSignals}");
        $this->info("Total actionable entries: {$totalEntries}");
        $this->info("Total alerts inserted (deduped): {$totalAlerts}");
        if ($enforceCurrentFreshness) {
            $this->info("Total stale candidates detected early: {$totalStaleDetected}");
            $this->info("Total lagging slots detected early: {$totalLagDetected}");
            $this->info("Total lagging slots skipped before symbol processing: {$totalLagSkipped}");
        }

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

        $this->info("Pipeline H ({$version}): Rolling window backtest");
        $this->info("From: {$fromDate} {$timeFrom} | To: {$toDate} {$timeTo}");

        return $this->runBacktest($scanner, $finder, $writer, $assetType, $version, true);
    }
}
