<?php

namespace App\Console\Commands;

use App\Models\RealtimeTradeCandidate;
use App\Models\TradeAlert;
use App\Services\Trading\Realtime\BacktestRealtimeMarketDataService;
use App\Services\Trading\Realtime\EarlyCandidateDetectorService;
use App\Services\Trading\Realtime\RealtimeEntryTriggerService;
use App\Services\Trading\Realtime\RealtimeEntryWatcherService;
use App\Services\Trading\Realtime\RealtimeIntegrationDispatcherService;
use App\Services\Trading\Realtime\RealtimeTradeAlertFactoryService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class TradingRealtimeBacktestCommand extends Command
{
    public $timeout = 0;

    protected $signature = 'trading:realtime-backtest
        {--from= : Start date YYYY-MM-DD (required)}
        {--to= : End date YYYY-MM-DD (required)}
        {--days= : Alternative: number of trading days back from --to (overrides --from)}
        {--interval=1 : Minutes between simulated loops (1 = every minute)}
        {--start-time=09:30 : Start time HH:MM in EST}
        {--end-time=16:00 : End time HH:MM in EST}
        {--full-table : Use one_minute_prices_full table (for extensive backfills)}
        {--relaxed : Relax detection thresholds to generate more candidates}
        {--dispatch-jobs : Dispatch symbol batches as queued jobs (requires queue workers)}
        {--dry-run : Scan but do not create candidates or alerts}
        {--no-entry-watcher : Skip the entry watcher step (only detection)}
        {--skip-liquidity-filter : Skip the slow liquidity pre-filter (recommended for backtests)}
        {--limit= : Max symbols to scan per loop (default: all 3,828)}
        {--max-yield : Ultra-aggressive detection for ML training — generate maximum trade_alert records}
        {--pipeline=R : Pipeline to run (R=MomentumContinuation, S=VwapReversal)}';

    protected $description = 'Backtest the realtime-watch pipeline over historical data (Pipeline R or S).';

    private string $pipelineRun = 'R';

    private int $totalLoops = 0;

    private int $totalCandidates = 0;

    private int $totalAlerts = 0;

    private array $skipSummary = [];

    /** @var array<int, array> */
    private array $alertLog = [];

    public function handle(): int
    {
        set_time_limit(0);

        $from = $this->option('from');
        $to = $this->option('to');
        $days = $this->option('days');

        if ($days !== null && $to !== null) {
            $from = CarbonImmutable::parse($to, 'America/New_York')
                ->subDays((int) $days)
                ->format('Y-m-d');
        }

        if (! $from || ! $to) {
            $this->error('Both --from and --to are required (or --to and --days).');

            return self::FAILURE;
        }

        $pipeline = strtoupper((string) $this->option('pipeline'));
        if (! in_array($pipeline, ['R', 'S'], true)) {
            $this->error('--pipeline must be R or S');

            return self::FAILURE;
        }
        $this->pipelineRun = $pipeline;

        $interval = (int) $this->option('interval');
        $startTime = $this->option('start-time');
        $endTime = $this->option('end-time');
        $fullTable = $this->option('full-table');
        $dryRun = $this->option('dry-run');
        $noEntryWatcher = $this->option('no-entry-watcher');
        $symbolLimit = $this->option('limit') ? (int) $this->option('limit') : null;

        $oneMinTable = $fullTable ? 'one_minute_prices_full' : 'one_minute_prices';

        $this->info("Realtime Backtest [Pipeline {$pipeline}]: {$from} → {$to}");
        $this->info("Time window: {$startTime} – {$endTime} EST, scanning every {$interval} min");
        $this->info("Table: {$oneMinTable}");
        if ($dryRun) {
            $this->warn('DRY RUN — no data will be written.');
        }

        // Optionally relax detection thresholds for backtesting
        if ($this->option('max-yield')) {
            config(['trading_realtime.min_price' => 0.1]);
            config(['trading_realtime.min_dollar_volume_1m' => 0]);
            config(['trading_realtime.min_rvol' => 0.0]);
            config(['trading_realtime.min_move_30m_pct' => -99.0]);
            config(['trading_realtime.early_score_min' => 0]);
            config(['trading_realtime.relaxed_hh_hl' => true]);
            config(['trading_realtime.min_bars' => 1]);
            config(['trading_realtime.skip_price_filter' => true]);
            $this->warn('MAX YIELD mode — ultra-aggressive detection for ML training');
        } elseif ($this->option('relaxed')) {
            config(['trading_realtime.min_price' => 1.0]);
            config(['trading_realtime.min_dollar_volume_1m' => 1000]);
            config(['trading_realtime.min_rvol' => 0.8]);
            config(['trading_realtime.min_move_30m_pct' => 0.0]);
            config(['trading_realtime.early_score_min' => 30]);
            // Relax also the HH/HL gate: require fewer rising pairs
            config(['trading_realtime.relaxed_hh_hl' => true]);
            $this->warn('RELAXED detection thresholds enabled for backtest.');
        }

        // Build service stack with backtest market data service.
        // When running Pipeline S (VWAP Reversal), temporarily override the
        // primary entry_finder_class and clear additional_entry_finders so
        // only S entries are produced.
        if ($pipeline === 'S') {
            config(['trading_realtime.entry_finder_class' => \App\Services\Trading\Realtime\RealtimeVwapReversalFinder::class]);
            config(['trading_realtime.additional_entry_finders' => []]);
            $this->info('Pipeline S: VWAP Reversal finder active (sole finder).');
        } else {
            config(['trading_realtime.entry_finder_class' => \App\Services\Trading\Realtime\RealtimeMomentumContinuationFinder::class]);
            config(['trading_realtime.additional_entry_finders' => []]);
            $this->info('Pipeline R: Momentum Continuation finder active (sole finder).');
        }

        $marketData = new BacktestRealtimeMarketDataService($oneMinTable);
        $candidateDetector = new EarlyCandidateDetectorService($marketData);
        $entryTrigger = new RealtimeEntryTriggerService($marketData);
        $alertFactory = new RealtimeTradeAlertFactoryService;
        $alertFactory->isRealtime = false;
        $integrationDispatcher = new RealtimeIntegrationDispatcherService;
        $entryWatcher = new RealtimeEntryWatcherService(
            $marketData, $entryTrigger, $alertFactory, $integrationDispatcher
        );

        // Get all symbols with 1-min data
        $symbolsQuery = DB::table('asset_info')
            ->select(['symbol', DB::raw("'stock' as asset_type")])
            ->where('1_min', 1)
            ->whereNotNull('symbol')
            ->orderBy('symbol');

        // Pre-filter universe by static liquidity: only include symbols whose
        // average dollar volume for the target day meets the min threshold.
        // Skip for backtests where the symbol universe is already known.
        if ($this->option('skip-liquidity-filter')) {
            $this->info('Liquidity pre-filter SKIPPED (--skip-liquidity-filter)');
        } else {
            $minDollarVol = (float) config('trading_realtime.min_dollar_volume_1m', 50000);
            try {
                $eligible = DB::table($oneMinTable)
                    ->selectRaw('symbol, AVG(volume * price) as avg_dollar_vol')
                    ->where('trading_date_est', $to)
                    ->whereBetween('trading_time_est', ['09:30:00', '16:00:00'])
                    ->groupBy('symbol')
                    ->havingRaw('AVG(volume * price) >= ?', [$minDollarVol])
                    ->pluck('symbol')
                    ->map(fn ($s) => strtoupper($s))
                    ->all();

                if (! empty($eligible)) {
                    $this->info('Filtered universe by liquidity: '.count($eligible).' eligible symbols');
                    $symbolsQuery->whereIn('symbol', $eligible);
                } else {
                    $this->warn('No eligible symbols found by liquidity filter; falling back to full universe');
                }
            } catch (\Throwable $e) {
                $this->warn('Liquidity pre-filter failed, continuing with full universe: '.$e->getMessage());
            }
        }

        if ($symbolLimit !== null) {
            $symbolsQuery->limit($symbolLimit);
        } else {
            $symbolsQuery->limit((int) config('trading_realtime.watch_symbols_limit', 500));
        }

        $allSymbols = $symbolsQuery->get();

        $symbolCount = $allSymbols->count();
        $this->info("Universe: {$symbolCount} symbols");

        // Iterate trading days
        $date = CarbonImmutable::parse($from, 'America/New_York')->startOfDay();
        $endDate = CarbonImmutable::parse($to, 'America/New_York')->startOfDay();

        while ($date->lte($endDate)) {
            $dateStr = $date->format('Y-m-d');

            // Skip weekends — checked before market schedule query
            if ($date->isWeekend()) {
                $date = $date->addDay();

                continue;
            }

            // Check market schedule (status = 'open' or 'half_day')
            $schedule = DB::table('market_schedules')
                ->where('date', $dateStr)
                ->where('market_type', 'stock')
                ->whereIn('status', ['open', 'half_day'])
                ->first();

            if (! $schedule) {
                $this->line("  [{$dateStr}] Non-trading day — skipping");
                $date = $date->addDay();

                continue;
            }

            // Determine end time for this day (respect early closes)
            $dayEndTime = $endTime;
            if ($schedule->closes_at && $schedule->closes_at !== '16:00:00') {
                $dayEndTime = substr($schedule->closes_at, 0, 5);
                $this->line("  [{$dateStr}] Early close at {$dayEndTime}");
            }

            $this->info("── {$dateStr} ──");

            // Generate time points for this day (respects early close)
            $timePoints = $this->generateTimePoints($dateStr, $startTime, $dayEndTime, $interval);
            $loopsToday = count($timePoints);
            $this->line("  {$loopsToday} time points ({$interval} min intervals)");

            // Load ALL bars for this entire day into memory (zero DB queries per time point)
            $symbolNames = $allSymbols->pluck('symbol')->all();
            $marketData->loadAllBarsForDay($dateStr, $symbolNames);
            $entryWatcher->warmUpTriggerCache($symbolNames);

            $dayLoops = 0;
            $dayCandidates = 0;
            $dayAlerts = 0;

            foreach ($timePoints as $asOfTsEst) {
                $dayLoops++;
                $this->totalLoops++;

                try {
                    // Set the simulated "now" for all data queries
                    $marketData->setSimulatedNow($asOfTsEst);
                    $marketData->clearCache();
                    $candidateDetector->resetSkipCounters();

                    // Reset factory caches to avoid stale data from previous loop
                    RealtimeTradeAlertFactoryService::resetCaches();

                    $dispatchMode = $this->option('dispatch-jobs');

                    if ($dispatchMode) {
                        // Dispatch mode: use Job queue
                        $batchSize = (int) config('trading_realtime.backtest_batch_size', 50);
                        $batches = array_chunk($symbolNames, $batchSize);

                        foreach ($batches as $batch) {
                            \App\Jobs\RealtimeBacktestSymbolBatchJob::dispatch(
                                $batch, $asOfTsEst, $oneMinTable, $dryRun, $noEntryWatcher, $this->option('relaxed'), $this->option('max-yield'), $this->pipelineRun
                            );
                        }
                    } else {
                        // Sync mode: process inline with shared services

                        $alertFactory = new RealtimeTradeAlertFactoryService;
                        $alertFactory->isRealtime = false;
                        $candidatesThisLoop = 0;

                        if ($this->pipelineRun === 'S') {
                            // Pipeline S: run VWAP Reversal Finder directly on all symbols.
                            // The generic candidate detector uses momentum-based gates
                            // (HH/HL structure, RVOL, ATR%) that don't apply to VWAP reversals.
                            $vwapFinder = app(\App\Services\Trading\Realtime\RealtimeVwapReversalFinder::class);
                            $dateStrLocal = $dateStr;

                            foreach ($symbolNames as $symbol) {
                                try {
                                    $result = $vwapFinder->findBestLong(
                                        $symbol, 'stock', $asOfTsEst, $asOfTsEst
                                    );

                                    if (($result['ok'] ?? 0) === 1 && ! empty($result['best_entry']) && ! $dryRun) {
                                        $best = $result['best_entry'];
                                        $quote = $marketData->latestQuote($symbol);
                                        if ($quote) {
                                            $alertFactory->createFromCandidateAndFlush(
                                                new \App\Models\RealtimeTradeCandidate([
                                                    'symbol' => $symbol,
                                                    'asset_type' => 'stock',
                                                    'detected_ts_est' => $asOfTsEst,
                                                    'detected_price' => (float) $quote['ask'],
                                                    'early_score' => $best['score'] ?? 60,
                                                    'return_1m_pct' => $best['return_1m_pct'] ?? 0,
                                                    'volume_ratio' => $result['meta']['volume_ratio'] ?? 0,
                                                    'vwap_dist_pct' => $result['meta']['vwap_dist_pct'] ?? 0,
                                                    'dollar_volume_1m' => 0,
                                                    'spread_pct' => 0,
                                                    'bid' => (float) ($quote['bid'] ?? 0),
                                                    'ask' => (float) ($quote['ask'] ?? 0),
                                                    'bid_qty' => 0,
                                                    'ask_qty' => 0,
                                                    'partial_open' => 0,
                                                    'partial_high' => 0,
                                                    'partial_low' => 0,
                                                    'partial_close' => 0,
                                                    'partial_volume' => 0,
                                                ]),
                                                $best,
                                                $quote,
                                                0,  // moveSinceCandidatePct
                                                0,  // moveSinceEntryPct
                                                $asOfTsEst
                                            );

                                            $candidatesThisLoop++;
                                        }
                                    }
                                } catch (\Throwable $e) {
                                    // Continue silently
                                }
                            }

                            // Flush all pending alerts for this time point
                            $alertFactory->flushPendingInserts();
                        } else {
                            // Pipeline R: use candidate detector + forceEntry (existing behavior)
                            foreach ($symbolNames as $symbol) {
                                try {
                                    $candidate = $candidateDetector->detectForSymbol($symbol);

                                    if ($candidate !== null && ! $dryRun) {
                                        $this->forceEntryFromCandidate(
                                            $candidate,
                                            $alertFactory,
                                            $integrationDispatcher,
                                            $marketData,
                                            $asOfTsEst
                                        );

                                        $candidatesThisLoop++;
                                    }
                                } catch (\Throwable $e) {
                                    Log::warning('[Backtest] Symbol detection failed: '.$symbol, ['error' => $e->getMessage()]);
                                }
                            }

                            // Flush all pending alerts for this time point
                            $alertFactory->flushPendingInserts();
                        }
                    }

                    $skipReasons = $candidateDetector->getSkipReasons();
                    foreach ($skipReasons as $reason => $count) {
                        $this->skipSummary[$reason] = ($this->skipSummary[$reason] ?? 0) + $count;
                    }

                    // Run entry watcher if not disabled
                    if (! $dryRun && ! $noEntryWatcher) {
                        $entryWatcher->watch();
                    }

                    // Count alerts created through this time point
                    $totalAlertsToDate = TradeAlert::query()
                        ->where('pipeline_run', $this->pipelineRun)
                        ->whereRaw('DATE(as_of_ts_est) = ?', [$dateStr])
                        ->where('entry_type', 'RealTime')
                        ->count();

                    // Progress indicator every 5 loops or first loop
                    if ($dayLoops % 5 === 0 || $dayLoops === 1) {
                        $this->line("    [{$asOfTsEst}] Loop {$dayLoops}/{$loopsToday} — candidates detected, {$totalAlertsToDate} total alerts today");
                    }

                    $dayAlerts = $totalAlertsToDate;
                } catch (\Throwable $e) {
                    Log::error('[Backtest] Time point loop failed: '.$asOfTsEst, ['error' => $e->getMessage()]);
                    $this->error("    ✗ Loop {$dayLoops} failed: {$e->getMessage()}");
                }
            }

            // Count candidates for this day
            $dayCandidateCount = RealtimeTradeCandidate::query()
                ->whereRaw('DATE(detected_ts_est) = ?', [$dateStr])
                ->count();

            $this->totalCandidates += $dayCandidateCount;
            $this->totalAlerts += $dayAlerts;

            $this->info("  ✅ {$dateStr} complete: {$dayLoops} loops, {$dayCandidateCount} candidates, {$dayAlerts} alerts");

            $date = $date->addDay();
        }

        // ── Summary Report ──
        $this->newLine();
        $this->line(str_repeat('─', 60));
        $this->info('BACKTEST COMPLETE');
        $this->line(str_repeat('─', 60));
        $this->line("  Total loops:       {$this->totalLoops}");
        $this->line("  Total candidates:  {$this->totalCandidates}");
        $this->line("  Total alerts:      {$this->totalAlerts}");
        $this->line('  Avg candidates/loop: '.round($this->totalCandidates / max(1, $this->totalLoops), 1));
        $this->newLine();

        if (! empty($this->skipSummary)) {
            $this->info('Top Skip Reasons:');
            $sorted = $this->skipSummary;
            arsort($sorted);
            $rank = 1;
            foreach (array_slice($sorted, 0, 10) as $reason => $count) {
                $pct = $this->totalLoops > 0 ? round($count / max(1, $this->totalLoops * $symbolCount) * 100, 1) : 0;
                $this->line("    {$rank}. {$reason}: {$count} ({$pct}%)");
                $rank++;
            }
        }

        return self::SUCCESS;
    }

    /**
     * Bypass the entry trigger and directly create a trade_alert from a candidate.
     * Synthesizes a minimal entry array using the candidate's own data and the
     * market data service's current quote.
     */
    private function forceEntryFromCandidate(
        RealtimeTradeCandidate $candidate,
        RealtimeTradeAlertFactoryService $alertFactory,
        RealtimeIntegrationDispatcherService $dispatcher,
        BacktestRealtimeMarketDataService $marketData,
        ?string $asOfTsEst = null
    ): void {
        $quote = $marketData->latestQuote($candidate->symbol);
        if (! $quote) {
            return;
        }

        $ask = (float) $quote['ask'];
        $bid = $quote['bid'] ?? 0;

        // Backtest: skip the no-chase check — simulated quotes reflect the full bar,
        // which naturally differs from the detected price. Direct entry at ask.
        $moveSinceCandidatePct = 0;
        $moveSinceEntryPct = 0;
        $entryPrice = $ask;
        $entryScore = $candidate->early_score + 5;

        $entry = [
            'entry_ts_est' => $candidate->getRawOriginal('detected_ts_est'),
            'entry_price' => $entryPrice,
            'score' => $entryScore,
            'reason' => 'backtest_forced_entry',
            'entry_finder' => 'BacktestForceEntry',
            'pipeline_run' => $this->pipelineRun,
            'signal_type' => $this->pipelineRun === 'S' ? 'VWAP_REVERSAL' : 'REALTIME_MOMENTUM',
            'version' => $this->pipelineRun === 'S'
                ? 'rt-vwap-reversal-v1.0'
                : 'rt-momentum-continuation-v1.0',
            'return_1m_pct' => $candidate->return_1m_pct,
            'move_since_candidate_pct' => $moveSinceCandidatePct,
            'vwap_dist_pct' => $candidate->vwap_dist_pct,
            'spread_pct' => $bid > 0 ? (($ask - $bid) / (($ask + $bid) / 2)) * 100 : 0,
            'quote_age_seconds' => 0,
        ];

        $alert = $alertFactory->createFromCandidate(
            $candidate,
            $entry,
            $quote,
            $moveSinceCandidatePct,
            $moveSinceEntryPct,
            $asOfTsEst
        );

        $candidate->update([
            'status' => 'triggered',
            'trade_alert_id' => $alert->id,
        ]);

        Log::info('[Backtest] ✅ TRADE ALERT CREATED (forced entry)', [
            'candidate_id' => $candidate->id,
            'symbol' => $candidate->symbol,
            'pipeline_run' => $this->pipelineRun,
            'entry_price' => $entryPrice,
            'score' => $entryScore,
        ]);

        // Skip ML scoring dispatch during backtest — it requires a real DB ID
        // which batch-inserted alerts don't have yet.
    }

    /**
     * @return string[]
     */
    private function generateTimePoints(string $dateStr, string $startTime, string $endTime, int $intervalMinutes): array
    {
        $points = [];
        $startTs = CarbonImmutable::parse("{$dateStr} {$startTime}:00", 'America/New_York')->timestamp;
        $endTs = CarbonImmutable::parse("{$dateStr} {$endTime}:00", 'America/New_York')->timestamp;

        $currentTs = $startTs;
        while ($currentTs <= $endTs) {
            $points[] = CarbonImmutable::createFromTimestamp($currentTs, 'America/New_York')
                ->setTimezone('America/New_York')
                ->format('Y-m-d H:i:s');
            $currentTs += ($intervalMinutes * 60);
        }

        return $points;
    }
}
