<?php

namespace App\Console\Commands;

use App\Services\Trading\Realtime\EarlyCandidateDetectorService;
use App\Services\Trading\Realtime\RealtimeEntryWatcherService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class TradingRealtimeWatchCommand extends Command
{
    protected $signature = 'trading:realtime-watch {--once : Run one loop and exit}';

    protected $description = 'Detect realtime trade candidates and watch them for fresh entries.';

    public function handle(
        EarlyCandidateDetectorService $candidateDetector,
        RealtimeEntryWatcherService $entryWatcher
    ): int {
        if (! (bool) config('trading_realtime.enabled', true)) {
            $this->warn('Realtime trading watcher disabled.');

            return self::SUCCESS;
        }

        $additionalFinders = array_values(array_filter(
            (array) config('trading_realtime.additional_entry_finders', []),
            fn ($c) => is_string($c) && trim($c) !== ''
        ));

        $this->info('Realtime trading watcher started.');
        Log::channel('realtime')->info('[RealtimeWatch] Watcher started', [
            'config' => [
                'early_score_min' => config('trading_realtime.early_score_min'),
                'max_quote_age_seconds' => config('trading_realtime.max_quote_age_seconds'),
                'candidate_ttl_seconds' => config('trading_realtime.candidate_ttl_seconds'),
                'loop_sleep_ms' => config('trading_realtime.loop_sleep_ms'),
                'watch_symbols_limit' => config('trading_realtime.watch_symbols_limit'),
                'entry_finder_class' => config('trading_realtime.entry_finder_class'),
                'additional_entry_finders' => $additionalFinders,
                'score_job_class' => config('trading_realtime.score_job_class'),
            ],
        ]);

        $loopCount = 0;

        while (true) {
            try {
                $symbols = $this->symbolsToWatch();
                $symbolCount = $symbols->count();

                $loopCount++;
                $symbolList = $symbols->pluck('symbol')->toArray();
                Log::channel('realtime')->info('[RealtimeWatch] Loop iteration', [
                    'loop' => $loopCount,
                    'symbol_count' => $symbolCount,
                    'first_symbols' => array_slice($symbolList, 0, 5),
                    'last_symbols' => array_slice($symbolList, -5),
                ]);

                $this->line("  [Loop {$loopCount}] Watching {$symbolCount} symbols...");
                $loopStartMs = round(microtime(true) * 1000);

                $candidateDetector->resetSkipCounters();

                // Bulk preload quotes + bars into the detector's internal market data service
                $symbolNames = $symbols->pluck('symbol')->map(fn ($s) => strtoupper((string) $s))->all();
                $candidateDetector->warmUpCaches($symbolNames);
                $entryWatcher->warmUpTriggerCache($symbolNames);

                $processed = 0;
                $candidatesFound = 0;
                $additionalFinders = array_values(array_filter(
                    (array) config('trading_realtime.additional_entry_finders', []),
                    fn ($c) => is_string($c) && trim($c) !== '' && class_exists(trim($c))
                ));

                if ($additionalFinders !== []) {
                    Log::channel('realtime')->info('[RealtimeWatch] Additional entry finders registered', [
                        'finders' => $additionalFinders,
                    ]);
                }

                foreach ($symbols as $row) {
                    $candidate = $candidateDetector->detectForSymbol(
                        (string) $row->symbol,
                        (string) ($row->asset_type ?? 'stock')
                    );
                    if ($candidate !== null) {
                        $candidatesFound++;

                        // Immediately evaluate this fresh candidate for entry — runs
                        // the primary finder (Pipeline R) AND additional finders
                        // (Pipeline S VWAP reversal) against each candidate.
                        try {
                            $entryWatcher->evaluateCandidate($candidate);
                        } catch (\Throwable $e) {
                            Log::channel('realtime')->warning('[RealtimeWatch] evaluateCandidate failed', [
                                'candidate_id' => $candidate->id,
                                'error' => $e->getMessage(),
                            ]);
                        }
                    }
                    $processed++;

                    // Log progress every 200 symbols
                    if ($processed % 200 === 0) {
                        $elapsed = round(microtime(true) * 1000) - $loopStartMs;
                        Log::channel('realtime')->debug('[RealtimeWatch] Scan progress', [
                            'processed' => $processed,
                            'candidates_so_far' => $candidatesFound,
                            'elapsed_ms' => $elapsed,
                        ]);
                    }
                }

                $loopDurationMs = round(microtime(true) * 1000) - $loopStartMs;
                $skipReasons = $candidateDetector->getSkipReasons();
                Log::channel('realtime')->info('[RealtimeWatch] Candidate detection complete', [
                    'loop' => $loopCount,
                    'symbols_processed' => $processed,
                    'candidates_found' => $candidatesFound,
                    'skip_reasons' => $skipReasons,
                    'duration_ms' => $loopDurationMs,
                ]);

                $this->line("  [Loop {$loopCount}] Done in {$loopDurationMs}ms — {$candidatesFound} candidates, {$processed} symbols");

                if (! empty($skipReasons)) {
                    $reasonSummary = collect($skipReasons)
                        ->sortDesc()
                        ->take(5)
                        ->map(fn ($count, $reason) => "{$reason}={$count}")
                        ->implode(', ');
                    Log::channel('realtime')->info('[RealtimeWatch] Skip reason summary', ['top_reasons' => $reasonSummary]);
                    $this->line("  [Loop {$loopCount}] Top skip reasons: {$reasonSummary}");
                }

                $this->line("  [Loop {$loopCount}] Running entry watcher...");
                $entryWatcher->watch();

                Log::channel('realtime')->info('[RealtimeWatch] Entry watcher complete', ['loop' => $loopCount]);
            } catch (\Throwable $e) {
                Log::channel('realtime')->error('Realtime watcher loop failed', [
                    'message' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]);

                $this->error($e->getMessage());
            }

            if ($this->option('once')) {
                break;
            }

            usleep((int) config('trading_realtime.loop_sleep_ms', 1000) * 1000);
        }

        return self::SUCCESS;
    }

    private function symbolsToWatch()
    {
        $table = config('trading_realtime.tables.asset_info', 'asset_info');
        $oneMinColumn = config('trading_realtime.asset_info_one_min_column', '1_min');

        return DB::table($table)
            ->select([
                'symbol',
                DB::raw("'stock' as asset_type"),
            ])
            ->where($oneMinColumn, 1)
            ->whereNotNull('symbol')
            ->orderBy('symbol')
            ->limit((int) config('trading_realtime.watch_symbols_limit', 500))
            ->get();
    }
}
