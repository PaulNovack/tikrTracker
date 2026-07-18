<?php

namespace App\Services\Trading\Realtime;

use App\Models\RealtimeTradeCandidate;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class RealtimeEntryWatcherService
{
    private int $loopsSinceSummary = 0;

    /** @var array<string, int> */
    private array $gateFailReasons = [];

    public function __construct(
        private readonly RealtimeMarketDataService $marketData,
        private readonly RealtimeEntryTriggerService $entryTrigger,
        private readonly RealtimeTradeAlertFactoryService $alertFactory,
        private readonly RealtimeIntegrationDispatcherService $integrationDispatcher,
    ) {}

    /** Warm up both the watcher's and the trigger's internal market data caches. */
    public function warmUpTriggerCache(array $symbols): void
    {
        $this->marketData->clearCache();
        $this->marketData->warmUpBars($symbols);
        $this->entryTrigger->warmUpCaches($symbols);
    }

    /**
     * Evaluate a single candidate immediately (called inline during scan loop).
     * Uses the same cache lock as watch() to prevent double-trigger races.
     */
    public function evaluateCandidate(RealtimeTradeCandidate $candidate): void
    {
        Cache::lock('rt_candidate_watch:'.$candidate->id, 5)->get(function () use ($candidate): void {
            $fresh = $candidate->fresh();

            if (! $fresh || $fresh->status !== 'watching') {
                return;
            }

            $gateFails = [];
            $reason = $this->tryTrigger($fresh, $gateFails);

            if ($reason === null) {
                Log::info('[EntryWatcher] Candidate triggered inline', [
                    'candidate_id' => $fresh->id,
                    'symbol' => $fresh->symbol,
                ]);
            } else {
                $fresh->update(['last_gate_fail_reason' => $reason]);
                Log::info('[EntryWatcher] Candidate gate fail (inline)', [
                    'candidate_id' => $fresh->id,
                    'symbol' => $fresh->symbol,
                    'reason' => $reason,
                ]);
            }
        });
    }

    public function watch(): void
    {
        $ttl = (int) config('trading_realtime.candidate_ttl_seconds', 180);

        $candidates = RealtimeTradeCandidate::query()
            ->where('status', 'watching')
            ->orderBy('detected_ts_est')
            ->limit(200)
            ->get();

        $total = $candidates->count();

        if ($total === 0) {
            return;
        }

        $expired = 0;
        $triggered = 0;
        $loopGateFails = [];

        $candidates->each(function (RealtimeTradeCandidate $candidate) use ($ttl, &$expired, &$triggered, &$loopGateFails): void {
            $detectedAt = \Carbon\Carbon::parse(
                $candidate->getRawOriginal('detected_ts_est'),
                'America/New_York'
            );
            $age = $detectedAt->diffInSeconds(now('America/New_York'), true);

            if ($age > $ttl) {
                $candidate->update([
                    'status' => 'expired',
                    'rejection_reason' => 'candidate_ttl_expired',
                ]);

                Log::info('[EntryWatcher] Candidate expired', [
                    'candidate_id' => $candidate->id,
                    'symbol' => $candidate->symbol,
                    'age_seconds' => $age,
                    'ttl_seconds' => $ttl,
                ]);

                $expired++;

                return;
            }

            Cache::lock('rt_candidate_watch:'.$candidate->id, 5)->get(function () use ($candidate, &$triggered, &$loopGateFails): void {
                $fresh = $candidate->fresh();

                if (! $fresh || $fresh->status !== 'watching') {
                    return;
                }

                $failReason = $this->tryTrigger($fresh, $loopGateFails);

                if ($failReason === null) {
                    $triggered++;
                } else {
                    // Record the gate failure reason for visibility
                    $fresh->update(['last_gate_fail_reason' => $failReason]);
                }
            });
        });

        // Aggregate loop gate fails into persistent counters
        foreach ($loopGateFails as $reason => $count) {
            $this->gateFailReasons[$reason] = ($this->gateFailReasons[$reason] ?? 0) + $count;
        }
        $this->loopsSinceSummary++;

        // Log summary every 10 loops (roughly every 5 seconds)
        if ($this->loopsSinceSummary >= 10 && ! empty($this->gateFailReasons)) {
            $totalFails = array_sum($this->gateFailReasons);
            $topReasons = collect($this->gateFailReasons)
                ->sortDesc()
                ->take(5)
                ->map(fn ($count, $reason) => "{$reason}={$count}")
                ->implode(', ');

            Log::info('[EntryWatcher] Trigger gate fail summary (last ~5s)', [
                'watching_now' => $total,
                'triggered' => $triggered,
                'expired' => $expired,
                'total_gate_fails' => $totalFails,
                'top_reasons' => $topReasons,
            ]);

            $this->gateFailReasons = [];
            $this->loopsSinceSummary = 0;
        }
    }

    /**
     * Try to trigger a trade entry for the candidate.
     * Returns null on success (triggered), or a short reason string on failure.
     *
     * @param  array<string, int>  $gateFails
     */
    private function tryTrigger(RealtimeTradeCandidate $candidate, array &$gateFails = []): ?string
    {
        $quote = $this->marketData->latestQuote($candidate->symbol);

        // Record stale_seconds for visibility on the Realtime Alerts page
        $quoteAge = $this->marketData->quoteAgeSeconds($quote);
        if ($quoteAge !== null) {
            $candidate->update(['stale_seconds' => $quoteAge]);
        }

        if (! $quote) {
            $gateFails['no_quote'] = ($gateFails['no_quote'] ?? 0) + 1;

            return 'no_quote';
        }

        $ask = (float) $quote['ask'];

        if ($ask <= 0 || $candidate->detected_price <= 0) {
            $gateFails['invalid_price'] = ($gateFails['invalid_price'] ?? 0) + 1;

            return 'invalid_price';
        }

        // ── Always attempt additional finders (Pipeline S VWAP reversal) ────
        // These run independently of the primary finder on this candidate,
        // regardless of whether the primary finder fires.
        $this->runAdditionalFinders($candidate, $quote, $ask);

        $moveSinceCandidatePct = (($ask - $candidate->detected_price) / $candidate->detected_price) * 100;

        if ($moveSinceCandidatePct > (float) config('trading_realtime.max_move_since_candidate_pct', 0.75)) {
            $candidate->update([
                'status' => 'rejected',
                'rejection_reason' => 'moved_too_far_since_candidate',
            ]);
            $gateFails['moved_too_far'] = ($gateFails['moved_too_far'] ?? 0) + 1;

            return 'moved_too_far';
        }

        $entry = $this->entryTrigger->findEntry($candidate);

        if (! $entry) {
            $gateFails['find_entry_null'] = ($gateFails['find_entry_null'] ?? 0) + 1;

            return 'find_entry_null';
        }

        $entryPrice = (float) ($entry['entry_price'] ?? $ask);

        if ($entryPrice <= 0) {
            Log::info('[EntryTrigger] Invalid entry price', [
                'candidate_id' => $candidate->id,
                'symbol' => $candidate->symbol,
                'entry_price' => $entryPrice,
            ]);
            $gateFails['entry_price_unavailable'] = ($gateFails['entry_price_unavailable'] ?? 0) + 1;

            return 'entry_price_unavailable';
        }

        $moveSinceEntryPct = (($ask - $entryPrice) / $entryPrice) * 100;

        if ($moveSinceEntryPct > (float) config('trading_realtime.max_move_since_entry_pct', 0.35)) {
            $candidate->update([
                'status' => 'rejected',
                'rejection_reason' => 'moved_too_far_since_entry',
            ]);
            $gateFails['moved_since_entry'] = ($gateFails['moved_since_entry'] ?? 0) + 1;

            return 'moved_since_entry';
        }

        $alert = $this->alertFactory->createFromCandidateAndFlush(
            $candidate,
            $entry,
            $quote,
            $moveSinceCandidatePct,
            $moveSinceEntryPct
        );

        $candidate->update([
            'status' => 'triggered',
            'trade_alert_id' => $alert->id,
        ]);

        Log::info('[EntryTrigger] ✅ TRADE ALERT CREATED', [
            'candidate_id' => $candidate->id,
            'trade_alert_id' => $alert->id,
            'symbol' => $candidate->symbol,
            'entry_price' => $entryPrice,
            'pipeline_run' => $entry['pipeline_run'] ?? 'R',
            'score' => $candidate->early_score,
        ]);

        $this->integrationDispatcher->dispatchAfterAlertCreated($alert);

        return null; // success
    }

    /**
     * Run additional entry finders (e.g. Pipeline S VWAP reversal) on a candidate.
     *
     * @return null|string null on success, or a short reason string on failure.
     */
    private function runAdditionalFinders(RealtimeTradeCandidate $candidate, array $quote, float $ask): void
    {
        try {
            $additionalEntries = $this->entryTrigger->findAllEntries($candidate);

            if ($additionalEntries === []) {
                Log::info('[EntryTrigger] Additional finders completed — no entries found', [
                    'candidate_id' => $candidate->id,
                    'symbol' => $candidate->symbol,
                ]);
            }

            foreach ($additionalEntries as $additionalEntry) {
                // Skip the entry that was already handled as the primary
                // (the primary is tracked via findEntry, not findAllEntries, so this
                // check is a safety net in case findAllEntries includes the primary.)
                $additionalFinder = $additionalEntry['entry_finder'] ?? '';
                $additionalPipelineRun = $additionalEntry['pipeline_run'] ?? '?';

                Log::info('[EntryTrigger] Additional finder evaluating', [
                    'candidate_id' => $candidate->id,
                    'symbol' => $candidate->symbol,
                    'finder' => $additionalFinder,
                    'pipeline_run' => $additionalPipelineRun,
                ]);

                $additionalPrice = (float) ($additionalEntry['entry_price'] ?? $ask);
                if ($additionalPrice <= 0) {
                    continue;
                }

                $additionalPrice = (float) ($additionalEntry['entry_price'] ?? $ask);
                if ($additionalPrice <= 0) {
                    continue;
                }

                $additionalMoveSinceCandidatePct = (($ask - $candidate->detected_price) / $candidate->detected_price) * 100;
                $additionalMoveSinceEntryPct = (($ask - $additionalPrice) / $additionalPrice) * 100;

                if ($additionalMoveSinceEntryPct > (float) config('trading_realtime.max_move_since_entry_pct', 0.35)) {
                    continue;
                }

                $additionalAlert = $this->alertFactory->createFromCandidateAndFlush(
                    $candidate,
                    $additionalEntry,
                    $quote,
                    $additionalMoveSinceCandidatePct,
                    $additionalMoveSinceEntryPct
                );

                Log::info('[EntryTrigger] ✅ ADDITIONAL TRADE ALERT CREATED', [
                    'candidate_id' => $candidate->id,
                    'trade_alert_id' => $additionalAlert->id,
                    'symbol' => $candidate->symbol,
                    'entry_price' => $additionalPrice,
                    'pipeline_run' => $additionalEntry['pipeline_run'] ?? '?',
                    'finder' => $additionalEntry['entry_finder'] ?? 'unknown',
                ]);

                $this->integrationDispatcher->dispatchAfterAlertCreated($additionalAlert);
            }
        } catch (\Throwable $e) {
            Log::warning('[EntryTrigger] Additional entry finder failure', [
                'candidate_id' => $candidate->id,
                'symbol' => $candidate->symbol,
                'message' => $e->getMessage(),
            ]);
        }

    }
}
