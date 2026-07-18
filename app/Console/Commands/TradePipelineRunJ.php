<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\TracesPipelineRun;
use App\Services\Trading\EntryRefinerService;
use App\Services\Trading\TradeAlertWriterV1;
use App\Services\TradingSettingService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

class TradePipelineRunJ extends Command
{
    use TracesPipelineRun;

    protected $signature = 'trade:pipeline-j
        {assetType=stock : stock|crypto}
        {--asOf=now : EST timestamp "YYYY-mm-dd HH:MM:SS" or "now"}
        {--top=10000 : max 5m signals to alert}
        {--lookback=60 : 5m scan lookback minutes}
        {--minMove=0.6 : 5m min move pct}
        {--volMult=1.5 : 5m volume multiple}
        {--before=6 : minutes before asOf to search for entries (live mode)}
        {--after=15 : minutes after signal for entry window}
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

    protected $description = 'Run Pipeline J (v2000.0 Recent 4 Percent Plus Movers): 5m scan -> 1m entry refine -> store alerts to DB (live or backtest).';

    public function handle(TradeAlertWriterV1 $writer): int
    {
        set_time_limit(0);

        // Only set innodb_lock_wait_timeout for MySQL connections
        if (config('database.default') === 'mysql') {
            \DB::statement('SET SESSION innodb_lock_wait_timeout = 600');
        }

        if (! $this->option('backtest') && ! $this->option('rolling-window') && ! TradingSettingService::isPipelineRunCronEnabled('j')) {
            $this->info('Pipeline J: Execution disabled (trading.pipeline_j.run_cron=0). Exiting.');

            return 0;
        }

        $version = config('app.trade_alert_j_version', 'v2000.0');

        // Convert version format: v2000.0 -> V2000_0
        $versionClean = 'V'.str_replace(['v', '.'], ['', '_'], $version);

        // Dynamically instantiate scanner and finder
        $scannerClass = "App\\Services\\Trading\\FiveMinuteSignalScanner{$versionClean}";
        $finderClass = "App\\Services\\Trading\\OneMinuteEntryFinder{$versionClean}";

        if (! class_exists($scannerClass) || ! class_exists($finderClass)) {
            $this->error("Pipeline J version {$version} not found (Scanner: {$scannerClass}, Finder: {$finderClass})");

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
        $tracer = $this->startTrace('J', $asOfTsEst);

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
            $this->line("Pipeline J - Recent 4 Percent Plus Movers ({$version}): No 5m signals at {$asOfTsEst}");

            $tracer?->finish(['alerts_written' => 0, 'signals' => 0]);

            return 0;
        }

        // Use Pipeline J specific .env configuration (15 minutes for delayed pattern recognition)
        $staleMinutes = TradingSettingService::getPipelineMaxAgeMinutes('j');
        $maxAlertsPerMinute = max(0, (int) config('trading.auto_alpaca_orders.max_alerts_per_minute_pipeline_j', 0));
        // Captured once for display/logging only — actual stale checks use a
        // fresh `now()` per-signal so signals don't go stale while the finder
        // processes a long symbol list.
        $actualNowEst = now('America/New_York')->format('Y-m-d H:i:s');

        $alertsWritten = 0;
        $ranked = [];

        // Get ignore types and no_filter_finder for Pipeline J from config
        $ignoreTypes = config('trading.pipelines.j.ignore_types', []);
        $noFilterFinder = config('trading.pipelines.j.no_filter_finder', false);

        foreach ($signals as $sig) {
            // Pre-filter stale signals BEFORE running the finder to avoid wasting
            // processing time on signals that will be rejected at write time.
            // Uses a fresh Carbon now() with proper EST→UTC conversion.
            if ($staleMinutes > 0) {
                $signalTsEst = (string) ($sig['signal_ts_est'] ?? '');
                if ($signalTsEst !== '') {
                    $signalAtUtc = \Carbon\Carbon::parse($signalTsEst, 'America/New_York')->setTimezone('UTC');
                    $signalAgeFromNow = $signalAtUtc->diffInMinutes(now('UTC'), true);
                    if ($signalAgeFromNow > $staleMinutes) {
                        \Log::channel('stale-alerts')->debug('[Pipeline J] Pre-finder skipped stale signal', [
                            'symbol' => $sig['symbol'] ?? null,
                            'signal_ts_est' => $signalTsEst,
                            'signal_age_minutes' => round($signalAgeFromNow, 1),
                            'stale_limit_minutes' => $staleMinutes,
                        ]);

                        continue;
                    }
                }
            }

            // Call finder to get entry
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
                if ($noFilterFinder) {
                    // Finder filtered it out, but we want to keep it for unfiltered comparison
                    $currentPrice = $sig['meta']['setup_price'] ?? null;
                    $stopPrice = $currentPrice ? round($currentPrice * 0.99, 2) : null;
                    $riskPerShare = $currentPrice && $stopPrice ? round($currentPrice - $stopPrice, 4) : null;
                    $riskPct = $currentPrice && $riskPerShare ? round(($riskPerShare / $currentPrice) * 100, 2) : null;

                    $atr = $sig['atr'] ?? null;
                    $atrPct = $sig['atr_pct'] ?? null;
                    $suggestedTrailingStop = ($atr && $currentPrice) ? round((float) $atr * 2.0, 6) : null;
                    $suggestedTrailingStopPct = ($suggestedTrailingStop && $currentPrice) ? round(($suggestedTrailingStop / (float) $currentPrice) * 100, 6) : null;

                    $entry = [
                        'type' => 'FILTERED_OUT',
                        'entry_ts_est' => $sig['signal_ts_est'],
                        'entry' => $currentPrice,
                        'stop' => $stopPrice,
                        'risk_pct' => $riskPct,
                        'risk_per_share' => $riskPerShare,
                        'score' => $sig['score'] ?? null,
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

            if ($maxAlertsPerMinute > 0 && ! $this->reserveAlertMinuteSlot('j', $actualNowEst, $maxAlertsPerMinute)) {
                break;
            }

            // Write alert with pipeline_run = 'J'
            $alertId = $writer->upsertAlert($sig, $entry, $asOfTsEst, $scanner->getVersion(), 'J');
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

        $this->info("Pipeline J - Recent 4 Percent Plus Movers ({$version}) | As-of (EST): {$asOfTsEst}");
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
        $maxAlertsPerMinute = max(0, (int) config('trading.auto_alpaca_orders.max_alerts_per_minute_pipeline_j', 0));

        // Get ignore types and no_filter_finder for Pipeline J from config
        $ignoreTypes = config('trading.pipelines.j.ignore_types', []);
        $noFilterFinder = config('trading.pipelines.j.no_filter_finder', false);

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
                        if ($noFilterFinder) {
                            $currentPrice = $sig['meta']['setup_price'] ?? null;
                            $stopPrice = $currentPrice ? round($currentPrice * 0.99, 2) : null;
                            $riskPerShare = $currentPrice && $stopPrice ? round($currentPrice - $stopPrice, 4) : null;
                            $riskPct = $currentPrice && $riskPerShare ? round(($riskPerShare / $currentPrice) * 100, 2) : null;

                            $atr = $sig['atr'] ?? null;
                            $atrPct = $sig['atr_pct'] ?? null;
                            $suggestedTrailingStop = ($atr && $currentPrice) ? round((float) $atr * 2.0, 6) : null;
                            $suggestedTrailingStopPct = ($suggestedTrailingStop && $currentPrice) ? round(($suggestedTrailingStop / (float) $currentPrice) * 100, 6) : null;

                            $entry = [
                                'type' => 'FILTERED_OUT',
                                'entry_ts_est' => $sig['signal_ts_est'],
                                'entry' => $currentPrice,
                                'stop' => $stopPrice,
                                'risk_pct' => $riskPct,
                                'risk_per_share' => $riskPerShare,
                                'score' => $sig['score'] ?? null,
                                'atr' => $atr,
                                'atr_pct' => $atrPct,
                                'suggested_trailing_stop' => $suggestedTrailingStop,
                                'suggested_trailing_stop_pct' => $suggestedTrailingStopPct,
                            ];
                        } else {
                            continue;
                        }
                    } else {
                        $entry = $res['best_entry'];
                    }

                    // Skip if entry type is in ignore list
                    if (! empty($ignoreTypes) && in_array($entry['type'] ?? '', $ignoreTypes, true)) {
                        continue;
                    }

                    $totalEntries++;

                    $actualNowEst = now('America/New_York')->format('Y-m-d H:i:s');

                    if ($maxAlertsPerMinute > 0 && ! $this->reserveAlertMinuteSlot('j', $actualNowEst, $maxAlertsPerMinute)) {
                        break;
                    }

                    // Write alert with pipeline_run = 'J'
                    if ($writer->upsertAlert($sig, $entry, $asOfTsEst, $scanner->getVersion(), 'J')) {
                        $totalAlerts++;
                    }
                }
            }

            $this->line("Pipeline J - Recent 4 Percent Plus Movers ({$version}): Backtest day {$day} done.");
        }

        $this->info("Pipeline J - Recent 4 Percent Plus Movers ({$version}): Backtest complete.");
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

        $this->info("Pipeline J - Recent 4 Percent Plus Movers ({$version}): Rolling window backtest");
        $this->info("From: {$fromDate} {$timeFrom} | To: {$toDate} {$timeTo}");

        return $this->runBacktest($scanner, $finder, $writer, $assetType, $version);
    }

    private function reserveAlertMinuteSlot(string $pipelineRun, string $timestampEst, int $maxAlertsPerMinute): bool
    {
        $pipelineKey = strtolower(trim($pipelineRun));
        $bucketKey = 'trading:pipeline:'.$pipelineKey.':alerts-per-minute:'.substr(str_replace([' ', ':'], '-', $timestampEst), 0, 16);

        Cache::add($bucketKey, 0, now()->addMinutes(2));

        $currentCount = (int) Cache::increment($bucketKey);

        return $currentCount <= $maxAlertsPerMinute;
    }
}
