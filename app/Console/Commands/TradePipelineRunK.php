<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\TracesPipelineRun;
use App\Services\Trading\TradeAlertWriterV1;
use App\Services\TradingSettingService;
use Illuminate\Console\Command;

class TradePipelineRunK extends Command
{
    use TracesPipelineRun;

    protected $signature = 'trade:pipeline-k
        {assetType=stock : stock|crypto}
        {--asOf=now : EST timestamp "YYYY-mm-dd HH:MM:SS" or "now"}
        {--top=25 : max 5m signals to refine}
        {--before=10 : minutes before asOf to search for entries (live mode)}
        {--after=30 : minutes after signal for entry window}
        {--volLookback=20 : 1m volume baseline minutes}
        {--pivotLookback=15 : 1m pivot lookback minutes}
        {--fill=next_open : next_open|close}
        {--stale=8 : live mode: ignore entries older than N minutes}
        {--signal-stale=15 : live mode: ignore signals (5m bars) older than N minutes}
        {--backtest : run backtest mode}
        {--rolling-window : backtest mode with auto-calculated rolling window (10min ago to 10min future)}
        {--from= : backtest start date (EST) YYYY-mm-dd}
        {--to= : backtest end date (EST) YYYY-mm-dd}
        {--step=5 : backtest step minutes}
        {--timeFrom=09:40:00 : backtest window start time (EST)}
        {--timeTo=15:30:00 : backtest window end time (EST)}
        {--fulltable : use five_minute_prices_full and one_minute_prices_full tables}
    ';

    protected $description = 'Run Pipeline K (v1100.0 Scarcity Leaders): 5m scan -> 1m entry refine -> store alerts to DB (live or backtest).';

    public function handle(TradeAlertWriterV1 $writer): int
    {
        set_time_limit(0);

        // Only set innodb_lock_wait_timeout for MySQL connections
        if (config('database.default') === 'mysql') {
            \DB::statement('SET SESSION innodb_lock_wait_timeout = 600');
        }

        if (! $this->option('backtest') && ! $this->option('rolling-window') && ! TradingSettingService::isPipelineRunCronEnabled('k')) {
            $this->info('Pipeline K: Execution disabled (trading.pipeline_k.run_cron=0). Exiting.');

            return 0;
        }

        $version = config('app.trade_alert_k_version', 'v1100.0');

        // Convert version format: v1100.0 -> V1100_0
        $versionClean = 'V'.str_replace(['v', '.'], ['', '_'], $version);

        // Dynamically instantiate scanner and finder
        $scannerClass = "App\\Services\\Trading\\FiveMinuteSignalScanner{$versionClean}";
        $finderClass = "App\\Services\\Trading\\OneMinuteEntryFinder{$versionClean}";

        if (! class_exists($scannerClass) || ! class_exists($finderClass)) {
            $this->error("Pipeline K version {$version} not found (Scanner: {$scannerClass}, Finder: {$finderClass})");

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
        $actualNowEst = now('America/New_York')->format('Y-m-d H:i:s');
        $nowEpoch = strtotime($actualNowEst);
        $tracer = $this->startTrace('K', $asOfTsEst);

        $signals = $scanner->scan(
            $asOfTsEst,
            (int) $this->option('top')
        );
        $tracer?->checkpoint('SCANNER_DONE', ['signals_found' => count($signals ?? [])]);

        if (! $signals) {
            $this->line("Pipeline K ({$version}): No 5m signals at {$asOfTsEst}");

            $tracer?->finish(['alerts_written' => 0, 'signals' => 0]);

            return 0;
        }

        $this->info("Pipeline K ({$version}): Found ".count($signals).' 5m signal(s) at '.$asOfTsEst);

        $staleMinutes = (int) $this->option('stale');
        $signalStaleMinutes = (int) $this->option('signal-stale');
        $entriesFound = 0;
        $entriesWritten = 0;

        foreach ($signals as $signal) {
            $symbol = $signal->symbol;
            $assetType = $signal->asset_type;
            $signalTs = $signal->ts_est;

            // Check signal-level staleness before running the entry finder
            if ($signalStaleMinutes > 0) {
                $signalTime = new \DateTime($signalTs, new \DateTimeZone('America/New_York'));
                $asOfTime = new \DateTime($asOfTsEst, new \DateTimeZone('America/New_York'));
                $signalAgeMinutes = ($asOfTime->getTimestamp() - $signalTime->getTimestamp()) / 60;

                if ($signalAgeMinutes > $signalStaleMinutes) {
                    $this->warn("  {$symbol}: Signal at {$signalTs} is {$signalAgeMinutes}m old (signal-stale > {$signalStaleMinutes}m), skipping");

                    continue;
                }
            }

            $entry = $finder->findBestLong(
                $symbol,
                $assetType,
                $signalTs,
                $asOfTsEst,
                (int) $this->option('before'),
                (int) $this->option('after'),
                (int) $this->option('volLookback'),
                (int) $this->option('pivotLookback'),
                (string) $this->option('fill')
            );

            if (! $entry) {
                continue;
            }

            $entriesFound++;

            // Check staleness for live mode
            $entryTs = $entry['ts_est'] ?? null;
            if ($entryTs) {
                $entryTime = new \DateTime($entryTs, new \DateTimeZone('America/New_York'));
                $asOfTime = new \DateTime($asOfTsEst, new \DateTimeZone('America/New_York'));
                $ageMinutes = ($asOfTime->getTimestamp() - $entryTime->getTimestamp()) / 60;

                if ($ageMinutes > $staleMinutes) {
                    $this->warn("  {$symbol}: Entry at {$entryTs} is {$ageMinutes} min old (stale > {$staleMinutes}m)");

                    continue;
                }
            }

            $alertId = $writer->upsertAlert($this->normalizeSignal($signal), $this->normalizeEntry($entry), $asOfTsEst, $version, 'K', true);
            if ($alertId) {
                $entriesWritten++;
                $tracer?->alertWritten($alertId, $symbol, $entry['ts_est'] ?? $asOfTsEst, $asOfTsEst);
                $this->info("  {$symbol}: {$entry['entry_type']} entry at {$entry['ts_est']} (score: {$entry['entry_score']})");
            }
        }

        $tracer?->finish(['alerts_written' => $entriesWritten, 'signals' => count($signals)]);
        $this->info("Pipeline K ({$version}): {$entriesFound} entries found, {$entriesWritten} written");

        return 0;
    }

    /**
     * Run backtest mode
     */
    protected function runBacktest($scanner, $finder, $writer, string $assetType, string $version): int
    {
        $writer->setBacktestMode(true);
        $from = $this->option('from');
        $to = $this->option('to');

        if (! $from || ! $to) {
            $this->error('Backtest mode requires --from and --to dates (YYYY-mm-dd)');

            return 1;
        }

        $timeFrom = (string) $this->option('timeFrom');
        $timeTo = (string) $this->option('timeTo');
        $step = (int) $this->option('step');

        $current = new \DateTime($from.' '.$timeFrom, new \DateTimeZone('America/New_York'));
        $end = new \DateTime($to.' '.$timeTo, new \DateTimeZone('America/New_York'));

        $totalSignals = 0;
        $totalEntries = 0;

        while ($current <= $end) {
            // Skip weekends
            if (in_array($current->format('N'), [6, 7])) {
                $current->modify('+1 day')->setTime(
                    (int) substr($timeFrom, 0, 2),
                    (int) substr($timeFrom, 3, 2),
                    (int) substr($timeFrom, 6, 2)
                );

                continue;
            }

            // Check time window
            $timeStr = $current->format('H:i:s');
            if ($timeStr < $timeFrom || $timeStr > $timeTo) {
                $current->modify('+1 day')->setTime(
                    (int) substr($timeFrom, 0, 2),
                    (int) substr($timeFrom, 3, 2),
                    (int) substr($timeFrom, 6, 2)
                );

                continue;
            }

            $asOfTsEst = $current->format('Y-m-d H:i:s');

            $signals = $scanner->scan(
                $asOfTsEst,
                (int) $this->option('top')
            );

            if ($signals) {
                $totalSignals += count($signals);
                $this->info("[{$asOfTsEst}] Found ".count($signals).' signal(s)');

                foreach ($signals as $signal) {
                    $entry = $finder->findBestLong(
                        $signal->symbol,
                        $signal->asset_type,
                        $signal->ts_est,
                        $asOfTsEst,
                        (int) $this->option('before'),
                        (int) $this->option('after'),
                        (int) $this->option('volLookback'),
                        (int) $this->option('pivotLookback'),
                        (string) $this->option('fill')
                    );

                    if ($entry) {
                        if ($writer->upsertAlert($this->normalizeSignal($signal), $this->normalizeEntry($entry), $asOfTsEst, $version, 'K', false)) {
                            $totalEntries++;
                        }
                    }
                }
            }

            $current->modify("+{$step} minutes");
        }

        $this->info("Backtest complete: {$totalSignals} signals, {$totalEntries} entries");

        return 0;
    }

    /**
     * Run rolling window backtest
     */
    protected function runRollingWindowBacktest($scanner, $finder, $writer, string $assetType, string $version): int
    {
        $writer->setBacktestMode(true);
        // Rolling window: scan "now", look back --before minutes for entries, forward --after minutes
        $asOfTsEst = $this->resolveAsOfTsEst('now');

        $signals = $scanner->scan(
            $asOfTsEst,
            (int) $this->option('top')
        );
        $tracer?->checkpoint('SCANNER_DONE', ['signals_found' => count($signals ?? [])]);

        if (! $signals) {
            return 0;
        }

        $staleMinutes = (int) $this->option('stale');
        $signalStaleMinutes = (int) $this->option('signal-stale');
        $entriesWritten = 0;

        foreach ($signals as $signal) {
            // Check signal-level staleness
            if ($signalStaleMinutes > 0) {
                $signalTime = new \DateTime($signal->ts_est, new \DateTimeZone('America/New_York'));
                $asOfTime = new \DateTime($asOfTsEst, new \DateTimeZone('America/New_York'));
                $signalAgeMinutes = ($asOfTime->getTimestamp() - $signalTime->getTimestamp()) / 60;

                if ($signalAgeMinutes > $signalStaleMinutes) {
                    continue;
                }
            }

            $entry = $finder->findBestLong(
                $signal->symbol,
                $signal->asset_type,
                $signal->ts_est,
                $asOfTsEst,
                (int) $this->option('before'),
                (int) $this->option('after'),
                (int) $this->option('volLookback'),
                (int) $this->option('pivotLookback'),
                (string) $this->option('fill')
            );

            if (! $entry) {
                continue;
            }

            // Check staleness
            $entryTs = $entry['ts_est'] ?? null;
            if ($entryTs) {
                $entryTime = new \DateTime($entryTs, new \DateTimeZone('America/New_York'));
                $asOfTime = new \DateTime($asOfTsEst, new \DateTimeZone('America/New_York'));
                $ageMinutes = ($asOfTime->getTimestamp() - $entryTime->getTimestamp()) / 60;

                if ($ageMinutes > $staleMinutes) {
                    continue;
                }
            }

            if ($writer->upsertAlert($this->normalizeSignal($signal), $this->normalizeEntry($entry), $asOfTsEst, $version, 'K', false)) {
                $entriesWritten++;
            }
        }

        return 0;
    }

    /**
     * Normalize a scanner signal object into the shape TradeAlertWriterV1::upsertAlert() expects.
     */
    protected function normalizeSignal(object|array $signal): array
    {
        $s = is_array($signal) ? (object) $signal : $signal;

        return [
            'symbol' => $s->symbol,
            'asset_type' => $s->asset_type,
            'signal_type' => 'SCARCITY_LEADER',
            'signal_ts_est' => $s->ts_est,
            'meta' => null,
        ];
    }

    /**
     * Normalize an entry-finder result array into the shape TradeAlertWriterV1::upsertAlert() expects.
     */
    protected function normalizeEntry(array $entry): array
    {
        $entryPrice = (float) ($entry['suggested_entry'] ?? $entry['price'] ?? 0);
        $stopLossPct = $this->calculateStopLoss($entry);
        $stopPrice = $entryPrice > 0 ? round($entryPrice * (1 - $stopLossPct / 100), 4) : 0;

        $atrMultiplier = (float) config('trading.auto_alpaca_orders.stop_loss_atr_multiplier', 4.0);
        $rawTrailingStopPct = isset($entry['atr_pct']) ? (float) $entry['atr_pct'] * $atrMultiplier : null;

        return array_merge($entry, [
            'type' => $entry['entry_type'] ?? 'VWAP_PULLBACK',
            'entry_ts_est' => $entry['ts_est'],
            'entry' => $entryPrice,
            'stop' => $stopPrice,
            'score' => $entry['entry_score'] ?? 0,
            'risk_pct' => $stopLossPct,
            'risk_per_share' => $entryPrice > 0 ? round($entryPrice * $stopLossPct / 100, 4) : null,
            'suggested_trailing_stop_pct' => $rawTrailingStopPct,
        ]);
    }

    /**
     * Calculate stop loss percentage
     */
    protected function calculateStopLoss(array $entry): float
    {
        $atr = $entry['fmp_atr'] ?? $entry['atr'] ?? 0;
        $price = $entry['price'] ?? 1;

        if ($atr > 0 && $price > 0) {
            // 2.0x ATR stop loss
            return min(2.0, max(0.75, ($atr * 2.0) / $price * 100));
        }

        return 1.5; // default 1.5%
    }

    /**
     * Calculate target profit percentage
     */
    protected function calculateTarget(array $entry): float
    {
        $stopLoss = $this->calculateStopLoss($entry);

        // 2R target
        return $stopLoss * 2.0;
    }

    /**
     * Build tags for alert
     */
    protected function buildTags($signal, array $entry): array
    {
        $tags = [];

        // SPY context
        if (isset($signal->spy_above_vwap) && $signal->spy_above_vwap == 0) {
            $tags[] = 'spy_below_vwap';
        }
        if (isset($signal->spy_move_15m_pct) && $signal->spy_move_15m_pct < 0) {
            $tags[] = 'spy_declining';
        }

        // Relative strength
        if (isset($signal->rel_strength_ratio) && $signal->rel_strength_ratio >= 1.5) {
            $tags[] = 'high_rs';
        }

        // Distance from high
        if (isset($signal->distance_from_high_atr) && $signal->distance_from_high_atr <= 0.5) {
            $tags[] = 'near_high';
        }

        // Entry type
        if (isset($entry['entry_type'])) {
            $tags[] = strtolower($entry['entry_type']);
        }

        return $tags;
    }

    /**
     * Resolve asOf timestamp
     */
    protected function resolveAsOfTsEst(string $asOf): string
    {
        if ($asOf === 'now') {
            return (new \DateTime('now', new \DateTimeZone('America/New_York')))->format('Y-m-d H:i:s');
        }

        return $asOf;
    }
}
