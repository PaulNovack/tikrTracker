<?php

namespace App\Jobs;

use App\Services\Trading\Realtime\BacktestRealtimeMarketDataService;
use App\Services\Trading\Realtime\EarlyCandidateDetectorService;
use App\Services\Trading\Realtime\RealtimeIntegrationDispatcherService;
use App\Services\Trading\Realtime\RealtimeTradeAlertFactoryService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class RealtimeBacktestSymbolBatchJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 0;

    public $maxExceptions = 10;

    public function __construct(
        public array $symbols,
        public string $asOfTsEst,
        public string $oneMinTable,
        public bool $dryRun = false,
        public bool $noEntryWatcher = false,
        public bool $relaxed = false,
        public bool $maxYield = false,
        public string $pipelineRun = 'R',
    ) {
        // Jobs go to the default queue where the R pipeline workers listen
    }

    public function handle(): void
    {
        $startAll = microtime(true);

        // Apply relaxed thresholds if the dispatching command requested them
        if ($this->maxYield) {
            config(['trading_realtime.min_price' => 0.1]);
            config(['trading_realtime.min_dollar_volume_1m' => 0]);
            config(['trading_realtime.min_rvol' => 0.0]);
            config(['trading_realtime.min_move_30m_pct' => -99.0]);
            config(['trading_realtime.early_score_min' => 0]);
            config(['trading_realtime.relaxed_hh_hl' => true]);
            config(['trading_realtime.min_bars' => 1]);
            config(['trading_realtime.skip_price_filter' => true]);
        } elseif ($this->relaxed) {
            config(['trading_realtime.min_price' => 1.0]);
            config(['trading_realtime.min_dollar_volume_1m' => 1000]);
            config(['trading_realtime.min_rvol' => 0.8]);
            config(['trading_realtime.min_move_30m_pct' => 0.0]);
            config(['trading_realtime.early_score_min' => 30]);
            config(['trading_realtime.relaxed_hh_hl' => true]);
        }

        Log::info('[BacktestJob] START', ['symbols' => count($this->symbols), 'as_of' => $this->asOfTsEst, 'table' => $this->oneMinTable, 'pipeline' => $this->pipelineRun, 'relaxed' => $this->relaxed]);

        $marketData = new BacktestRealtimeMarketDataService($this->oneMinTable);
        $marketData->setSimulatedNow($this->asOfTsEst);
        $marketData->clearCache();

        $candidateDetector = new EarlyCandidateDetectorService($marketData);
        $entryTrigger = new \App\Services\Trading\Realtime\RealtimeEntryTriggerService($marketData);
        $alertFactory = new RealtimeTradeAlertFactoryService;
        $alertFactory->isRealtime = false;
        $integrationDispatcher = new RealtimeIntegrationDispatcherService;

        // Reset caches for this time point batch
        RealtimeTradeAlertFactoryService::resetCaches();

        $upper = array_map('strtoupper', $this->symbols);

        $t0 = microtime(true);
        // Batch-load all bars for this batch in 3 fast queries instead of
        // 3*N per-symbol queries on the 392M-row _full table.
        $marketData->warmUpBars($upper);
        Log::info('[BacktestJob] warmUpBars', ['symbols' => count($upper), 'duration_ms' => round((microtime(true) - $t0) * 1000)]);

        $detected = 0;
        $reasons = [];
        $loopCount = 0;

        foreach ($upper as $sym) {
            $loopCount++;
            try {
                Log::info('[BacktestJob] processing symbol', ['sym' => $sym, 'idx' => $loopCount, 'total' => count($upper)]);

                if ($this->pipelineRun === 'S') {
                    // Pipeline S: run VWAP Reversal Finder directly.
                    $vwapFinder = app(\App\Services\Trading\Realtime\RealtimeVwapReversalFinder::class);
                    $result = $vwapFinder->findBestLong($sym, 'stock', $this->asOfTsEst, $this->asOfTsEst);

                    if (($result['ok'] ?? 0) === 1 && ! empty($result['best_entry']) && ! $this->dryRun) {
                        $best = $result['best_entry'];
                        $quote = $marketData->latestQuote($sym);
                        if ($quote) {
                            $alert = $alertFactory->createFromCandidate(
                                new \App\Models\RealtimeTradeCandidate([
                                    'symbol' => $sym,
                                    'asset_type' => 'stock',
                                    'detected_ts_est' => $this->asOfTsEst,
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
                                0,
                                0,
                                $this->asOfTsEst
                            );

                            $detected++;

                            Log::info('[BacktestJob] VWAP REVERSAL ALERT CREATED', [
                                'trade_alert_id' => $alert->id,
                                'symbol' => $sym,
                                'pipeline_run' => $this->pipelineRun,
                                'score' => $best['score'] ?? 0,
                            ]);
                        }
                    }
                } else {
                    // Pipeline R: use candidate detector + forceEntry
                    $candidate = $candidateDetector->detectForSymbol($sym);

                    if ($candidate !== null && ! $this->dryRun) {
                        $detected++;

                        $quote = $marketData->latestQuote($candidate->symbol);
                        if (! $quote) {
                            continue;
                        }

                        $ask = (float) $quote['ask'];
                        $entryPrice = $ask;
                        $entry = [
                            'entry_ts_est' => $candidate->getRawOriginal('detected_ts_est'),
                            'entry_price' => $entryPrice,
                            'score' => $candidate->early_score + 5,
                            'reason' => 'backtest_force_entry',
                            'entry_finder' => 'BacktestForceEntry',
                            'pipeline_run' => $this->pipelineRun,
                            'signal_type' => 'REALTIME_MOMENTUM',
                            'version' => 'rt-momentum-continuation-v1.0',
                            'spread_pct' => 0,
                        ];

                        $alert = $alertFactory->createFromCandidate(
                            $candidate,
                            $entry,
                            $quote,
                            0,
                            0,
                            $this->asOfTsEst
                        );

                        $candidate->update([
                            'status' => 'triggered',
                            'trade_alert_id' => $alert->id,
                        ]);

                        Log::info('[BacktestJob] TRADE ALERT CREATED', [
                            'candidate_id' => $candidate->id,
                            'trade_alert_id' => $alert->id,
                            'symbol' => $candidate->symbol,
                            'pipeline_run' => $this->pipelineRun,
                        ]);
                    }
                }
            } catch (\Throwable $e) {
                Log::warning('[BacktestJob] symbol batch failed for '.$sym, ['error' => $e->getMessage()]);
            }
        }

        $t1 = microtime(true);
        Log::info('[BacktestJob] detection loop complete', [
            'symbols' => count($upper),
            'candidates_detected' => $detected,
            'skip_reasons' => $reasons,
            'duration_ms' => round(($t1 - $t0) * 1000),
        ]);

        // Flush all pending trade_alert inserts for this batch (50 symbols)
        $flushed = $alertFactory->flushPendingInserts();
        if ($flushed > 0) {
            Log::info('[BacktestJob] Flushed '.$flushed.' pending trade alerts to database');
        }
    }
}
