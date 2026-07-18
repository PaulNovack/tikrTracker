<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\TracesPipelineRun;
use App\Services\Trading\EntryRefinerService;
use App\Services\Trading\TradeAlertWriterV1;
use App\Services\TradingSettingService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class TradePipelineRunE extends Command
{
    use TracesPipelineRun;

    protected $signature = 'trade:pipeline-e
        {assetType=stock : stock|crypto}
        {--asOf=now : EST timestamp "YYYY-mm-dd HH:MM:SS" or "now"}
        {--top=50 : max 5m signals to refine}
        {--lookback=60 : 5m scan lookback minutes}
        {--minMove=0.5 : 5m min move pct}
        {--volMult=1.3 : 5m volume multiple}
        {--before=6 : minutes before asOf to search for entries (live mode)}
        {--after=0 : minutes after signal (deprecated - now uses before from asOf)}
        {--volLookback=20 : 1m volume baseline minutes}
        {--pivotLookback=15 : 1m pivot lookback minutes}
        {--fill=next_open : next_open|close}
        {--stale=5 : live mode: ignore entries older than N minutes}
        {--signal-stale=5 : live mode: ignore signals (5m bars) older than N minutes}
        {--backtest : run backtest mode}
        {--rolling-window : backtest mode with auto-calculated rolling window (10min ago to 6min future)}
        {--from= : backtest start date (EST) YYYY-mm-dd}
        {--to= : backtest end date (EST) YYYY-mm-dd}
        {--step=5 : backtest step minutes}
        {--timeFrom=09:40:00 : backtest window start time (EST)}
        {--timeTo=15:30:00 : backtest window end time (EST)}
        {--fulltable : use five_minute_prices_full and one_minute_prices_full tables}
    ';

    protected $description = 'Run Pipeline E (uses TRADE_ALERT_E_VERSION): 5m scan -> 1m entry refine -> store alerts to DB (live or backtest).';

    public function handle(TradeAlertWriterV1 $writer): int
    {
        // Increase execution time for backtests (unlimited)
        set_time_limit(0);

        // Only set innodb_lock_wait_timeout for MySQL connections
        if (config('database.default') === 'mysql') {
            \DB::statement('SET SESSION innodb_lock_wait_timeout = 600');
        }

        if (! $this->option('backtest') && ! $this->option('rolling-window') && ! TradingSettingService::isPipelineRunCronEnabled('e')) {
            $this->info('Pipeline E: Execution disabled (trading.pipeline_e.run_cron=0). Exiting.');

            return 0;
        }

        $version = config('app.trade_alert_e_version', 'v40.1');

        // Convert version format: v19.0 -> V19_0
        $versionClean = 'V'.str_replace(['v', '.'], ['', '_'], $version);

        // Dynamically instantiate scanner and finder
        $scannerClass = "App\\Services\\Trading\\FiveMinuteSignalScanner{$versionClean}";
        $finderClass = "App\\Services\\Trading\\OneMinuteEntryFinder{$versionClean}";

        if (! class_exists($scannerClass) || ! class_exists($finderClass)) {
            $this->error("Pipeline E version {$version} not found (Scanner: {$scannerClass}, Finder: {$finderClass})");

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
        $tracer = $this->startTrace('E', $asOfTsEst);

        // V21.0 uses simpler scan API (just assetType and timestamp)
        if ($version === 'v21.0') {
            $signals = $scanner->scan($assetType, $asOfTsEst);
        } else {
            $signals = $scanner->scan(
                $assetType,
                $asOfTsEst,
                (int) $this->option('lookback'),
                (float) $this->option('minMove'),
                (float) $this->option('volMult'),
                (int) $this->option('top')
            );
        }

        if (! $signals) {
            $this->line("Pipeline E ({$version}): No 5m signals at {$asOfTsEst}");

            $tracer?->finish(['alerts_written' => 0, 'signals' => 0]);

            return 0;
        }

        // ML PRE-FILTERING: Score and filter signals before entry finding
        $mlPrefilterEnabled = config('trading.pipelines.e.ml_prefilter', false);
        if ($mlPrefilterEnabled && $version === 'v200.0') {
            $mlModelPath = config('trading.v200.ml_model_path', 'python_ml/models/winner_model_xgb.joblib');
            $mlMinProb = config('trading.v200.ml_prefilter_min_prob', 0.40);

            $this->line("Pipeline E ({$version}): ML pre-filtering enabled (min_prob: {$mlMinProb})");

            $mlScorer = new \App\Services\Trading\MLSignalScorer($mlModelPath, $mlMinProb);
            $signalsBeforeML = count($signals);
            $signals = $mlScorer->scoreAndFilter($signals);
            $signalsAfterML = count($signals);

            $this->line("Pipeline E ({$version}): ML filtered {$signalsBeforeML} → {$signalsAfterML} signals");
        }

        // Use .env configuration for max age (default 10 minutes)
        $staleMinutes = TradingSettingService::getPipelineMaxAgeMinutes('e');
        $signalStaleMinutes = (int) $this->option('signal-stale');
        if ($version === 'v400.0') {
            $signalStaleMinutes = min($signalStaleMinutes > 0 ? $signalStaleMinutes : 5, 5);
        }
        // Use actual current time for stale check, not scanning window time
        $actualNowEst = now('America/New_York')->format('Y-m-d H:i:s');
        $nowEpoch = strtotime($actualNowEst);

        $alertsWritten = 0;
        $ranked = [];

        // Get ignore types for Pipeline E from config
        $ignoreTypes = config('trading.pipelines.e.ignore_types', []);

        // V21.0 uses batch findEntries, other versions use per-signal findBestLong
        if ($version === 'v21.0') {
            $entries = $finder->findEntries($signals, $assetType, $asOfTsEst);

            foreach ($entries as $entry) {
                // Skip if entry type is in ignore list
                if (! empty($ignoreTypes) && in_array($entry['entry_type'] ?? '', $ignoreTypes, true)) {
                    continue;
                }

                // Check signal-level staleness
                if ($signalStaleMinutes > 0) {
                    $signalEpoch = strtotime($entry['signal_ts_est'] ?? $asOfTsEst);
                    if (($nowEpoch - $signalEpoch) / 60 > $signalStaleMinutes) {
                        continue;
                    }
                }

                $entryEpoch = strtotime($entry['entry_ts_est'] ?? $entry['signal_ts_est'] ?? $asOfTsEst);

                // Live freshness: ignore if too old
                if ($staleMinutes > 0 && ($nowEpoch - $entryEpoch) > ($staleMinutes * 60)) {
                    continue;
                }

                // Write alert with pipeline_run = 'E'
                // For V21.0, signal and entry are merged in $entry
                $sig = [
                    'symbol' => $entry['symbol'],
                    'asset_type' => $entry['asset_type'],
                    'signal_type' => $entry['signal_type'],
                    'signal_ts_est' => $entry['signal_ts_est'] ?? $asOfTsEst,
                    'score' => $entry['score'] ?? null,
                ];

                $alertId = $writer->upsertAlert($sig, $entry, $asOfTsEst, $scanner->getVersion(), 'E', true);
                if ($alertId) {
                    $alertsWritten++;
                    $tracer?->alertWritten($alertId, $sig['symbol'], $entry['entry_ts_est'], $sig['signal_ts_est']);
                }

                $ranked[] = [
                    'symbol' => $entry['symbol'],
                    'sigScore' => $entry['score'] ?? null,
                    'entryType' => $entry['entry_type'],
                    'entryTs' => $asOfTsEst,
                    'entry' => $entry['entry'] ?? null,
                    'stop' => $entry['stop'] ?? null,
                    'riskPct' => $entry['risk_pct'] ?? null,
                    'score' => $entry['score'] ?? null,
                ];
            }
        } else {
            // Get no_filter_finder for Pipeline E from config
            $noFilterFinder = config('trading.pipelines.e.no_filter_finder', false);

            foreach ($signals as $sig) {
                // Check signal-level staleness before running finder
                if ($signalStaleMinutes > 0) {
                    $signalEpoch = strtotime($sig['signal_ts_est'] ?? $asOfTsEst);
                    if (($nowEpoch - $signalEpoch) / 60 > $signalStaleMinutes) {
                        continue;
                    }
                }

                // Always call finder to get entry type classification
                // v200.0: Use options-based signature with signalMeta
                if ($version === 'v200.0') {
                    $res = $finder->findBestLong(
                        $sig['symbol'],
                        $sig['asset_type'],
                        $sig['signal_ts_est'],
                        $asOfTsEst,
                        [
                            'beforeMinutes' => (int) $this->option('before'),
                            'afterMinutes' => 0,  // Live mode: don't look ahead
                            'signalMeta' => $sig['meta'] ?? [],
                            'fillModel' => (string) $this->option('fill'),
                            'volLookback' => (int) $this->option('volLookback'),
                            'pivotLookback' => (int) $this->option('pivotLookback'),
                        ]
                    );
                } else {
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
                }

                if (empty($res['ok']) || empty($res['best_entry'])) {
                    if ($noFilterFinder) {
                        $currentPrice = $sig['price'] ?? $sig['meta']['current_price'] ?? null;
                        // 1% initial stop (not 8%)
                        $stopPrice = $currentPrice ? round($currentPrice * 0.99, 2) : null;
                        $riskPerShare = $currentPrice && $stopPrice ? round($currentPrice - $stopPrice, 4) : null;
                        $riskPct = $currentPrice && $riskPerShare ? round(($riskPerShare / $currentPrice) * 100, 2) : null;

                        $entryType = 'FILTERED_OUT';
                        if (! empty($res['filtered_best']['type'])) {
                            $entryType = 'FILTERED_'.$res['filtered_best']['type'];
                        }

                        // Calculate ATR-based trailing stops for filtered entries (needed for analysis)
                        // ATR is stored in signal metadata, not top-level signal array
                        $atr = $sig['meta']['atr'] ?? $sig['atr'] ?? null;
                        $atrPct = $sig['meta']['atr_pct'] ?? $sig['atr_pct'] ?? null;

                        // Debug: Check first signal only
                        static $debugged = false;
                        if (! $debugged) {
                            \Log::info('NO_FILTER_FINDER DEBUG', [
                                'symbol' => $sig['symbol'],
                                'price' => $currentPrice,
                                'atr_from_meta' => $sig['meta']['atr'] ?? 'NOT_SET',
                                'atr_from_sig' => $sig['atr'] ?? 'NOT_SET',
                                'atr_pct_from_meta' => $sig['meta']['atr_pct'] ?? 'NOT_SET',
                                'final_atr' => $atr,
                                'final_atr_pct' => $atrPct,
                            ]);
                            $debugged = true;
                        }

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
                        continue;
                    }
                } else {
                    $entry = $res['best_entry'];
                    // Slide to latest bar for live mode
                    $entry = EntryRefinerService::slideToLatest($entry, $actualNowEst);
                }

                // Skip if entry type is in ignore list
                if (! empty($ignoreTypes) && in_array($entry['type'] ?? '', $ignoreTypes, true)) {
                    continue;
                }

                $entryEpoch = strtotime($entry['entry_ts_est']);
                $ageMinutes = ($nowEpoch - $entryEpoch) / 60;

                // Live freshness: ignore if too old
                if ($staleMinutes > 0 && $ageMinutes > $staleMinutes) {
                    Log::info('[Pipeline E] Filtered stale entry', [
                        'symbol' => $sig['symbol'],
                        'entry_ts_est' => $entry['entry_ts_est'],
                        'actual_now_est' => $actualNowEst,
                        'age_minutes' => round($ageMinutes, 2),
                        'stale_limit' => $staleMinutes,
                    ]);

                    continue;
                }

                // Write alert with pipeline_run = 'E'
                if ($writer->upsertAlert($sig, $entry, $asOfTsEst, $scanner->getVersion(), 'E', true)) {
                    $alertsWritten++;
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

        $this->info("Pipeline E ({$version}) | As-of (EST): {$asOfTsEst}");
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

        // Get ignore types and no_filter_finder for Pipeline E from config
        $ignoreTypes = config('trading.pipelines.e.ignore_types', []);
        $noFilterFinder = config('trading.pipelines.e.no_filter_finder', false);

        for ($d = $startDate; $d <= $endDate; $d += 86400) {
            $day = date('Y-m-d', $d);

            $tStart = strtotime($day.' '.$timeFrom);
            $tEnd = strtotime($day.' '.$timeTo);

            for ($t = $tStart; $t <= $tEnd; $t += ($step * 60)) {
                $asOfTsEst = date('Y-m-d H:i:s', $t);

                // V21.0 uses simpler scan API
                if ($version === 'v21.0') {
                    $signals = $scanner->scan($assetType, $asOfTsEst);
                } else {
                    $signals = $scanner->scan(
                        $assetType,
                        $asOfTsEst,
                        (int) $this->option('lookback'),
                        (float) $this->option('minMove'),
                        (float) $this->option('volMult'),
                        (int) $this->option('top')
                    );
                }

                $totalSignals += count($signals);

                // ML PRE-FILTERING in backtest mode
                $mlPrefilterEnabled = config('trading.pipelines.e.ml_prefilter', false);
                if ($mlPrefilterEnabled && $version === 'v200.0' && ! empty($signals)) {
                    $mlModelPath = config('trading.v200.ml_model_path', 'python_ml/models/winner_model_xgb.joblib');
                    $mlMinProb = config('trading.v200.ml_prefilter_min_prob', 0.40);

                    $mlScorer = new \App\Services\Trading\MLSignalScorer($mlModelPath, $mlMinProb);
                    $signals = $mlScorer->scoreAndFilter($signals);
                }

                // V21.0 uses batch findEntries
                if ($version === 'v21.0') {
                    $entries = $finder->findEntries($signals, $assetType, $asOfTsEst);

                    foreach ($entries as $entry) {
                        // Skip if entry type is in ignore list
                        if (! empty($ignoreTypes) && in_array($entry['entry_type'] ?? '', $ignoreTypes, true)) {
                            continue;
                        }

                        $totalEntries++;

                        // Write alert with pipeline_run = 'E'
                        $sig = [
                            'symbol' => $entry['symbol'],
                            'asset_type' => $entry['asset_type'],
                            'signal_type' => $entry['signal_type'],
                            'signal_ts_est' => $entry['signal_ts_est'] ?? $asOfTsEst,
                            'score' => $entry['score'] ?? null,
                        ];

                        if ($writer->upsertAlert($sig, $entry, $asOfTsEst, $scanner->getVersion(), 'E', false)) {
                            $totalAlerts++;
                        }
                    }
                } else {
                    foreach ($signals as $sig) {
                        // Always call finder to get entry type classification
                        // v200.0 & v400.0: Use options-based signature with afterMinutes and signalMeta
                        if ($version === 'v200.0' || $version === 'v400.0') {
                            $res = $finder->findBestLong(
                                $sig['symbol'],
                                $sig['asset_type'],
                                $sig['signal_ts_est'],
                                $asOfTsEst,
                                [
                                    'beforeMinutes' => $version === 'v200.0' ? 25 : 20, // v400.0 needs 20min for continuation patterns
                                    'afterMinutes' => $version === 'v200.0' ? 15 : (int) $this->option('after'),
                                    'signalMeta' => $sig['meta'] ?? [],
                                    'fillModel' => (string) $this->option('fill'),
                                    'volLookback' => (int) $this->option('volLookback'),
                                    'pivotLookback' => (int) $this->option('pivotLookback'),
                                    'freshnessMinutes' => (int) $this->option('stale'),
                                ]
                            );
                        } else {
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
                        }

                        if (empty($res['ok']) || empty($res['best_entry'])) {
                            if ($noFilterFinder) {
                                $currentPrice = $sig['price'] ?? $sig['meta']['current_price'] ?? null;
                                // 1% initial stop (not 8%)
                                $stopPrice = $currentPrice ? round($currentPrice * 0.99, 2) : null;
                                $riskPerShare = $currentPrice && $stopPrice ? round($currentPrice - $stopPrice, 4) : null;
                                $riskPct = $currentPrice && $riskPerShare ? round(($riskPerShare / $currentPrice) * 100, 2) : null;

                                $entryType = 'FILTERED_OUT';
                                if (! empty($res['filtered_best']['type'])) {
                                    $entryType = 'FILTERED_'.$res['filtered_best']['type'];
                                }

                                // Calculate ATR-based trailing stops for filtered entries (needed for analysis)
                                // ATR is stored in signal metadata, not top-level signal array
                                $atr = $sig['meta']['atr'] ?? $sig['atr'] ?? null;
                                $atrPct = $sig['meta']['atr_pct'] ?? $sig['atr_pct'] ?? null;
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

                        // Write alert with pipeline_run = 'E'
                        if ($writer->upsertAlert($sig, $entry, $asOfTsEst, $scanner->getVersion(), 'E', false)) {
                            $totalAlerts++;
                        }
                    }
                }
            }

            $this->line("Pipeline E ({$version}): Backtest day {$day} done.");
        }

        $this->info("Pipeline E ({$version}): Backtest complete.");
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

        $this->info("Pipeline E ({$version}): Rolling window backtest");
        $this->info("From: {$fromDate} {$timeFrom} | To: {$toDate} {$timeTo}");

        return $this->runBacktest($scanner, $finder, $writer, $assetType, $version);
    }
}
