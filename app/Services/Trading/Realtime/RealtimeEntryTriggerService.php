<?php

namespace App\Services\Trading\Realtime;

use App\Models\RealtimeTradeCandidate;
use App\Services\TradingSettingService;
use Illuminate\Support\Facades\Log;

class RealtimeEntryTriggerService
{
    public function __construct(
        private readonly RealtimeMarketDataService $marketData,
    ) {}

    /** Warm up the internal market data cache with fresh quotes and bars. */
    public function warmUpCaches(array $symbols): void
    {
        $this->marketData->clearCache();
        $this->marketData->warmUpBars($symbols);
    }

    /**
     * Returns null if there is not a fresh entry yet.
     * Returns an entry array if this candidate should become a trade_alert.
     *
     * This is the primary finder — used by the watcher to trigger Pipeline R
     * (or whatever the configured entry_finder_class returns).
     */
    public function findEntry(RealtimeTradeCandidate $candidate): ?array
    {
        $custom = $this->findWithConfiguredEntryFinder($candidate);

        if ($custom !== null) {
            Log::info('[EntryTrigger] Configured finder returned entry', [
                'candidate_id' => $candidate->id,
                'symbol' => $candidate->symbol,
                'finder' => $custom['entry_finder'] ?? 'unknown',
            ]);

            return $custom;
        }

        return $this->findWithDefaultRealtimeTrigger($candidate);
    }

    /**
     * Run ALL configured entry finders (primary + additional) against a candidate.
     * Returns an array of entry arrays (one per finder that fired).
     *
     * The primary finder (entry_finder_class) is resolved via findEntry().
     * Additional finders (additional_entry_finders) are resolved here.
     *
     * Each entry carries its own pipeline_run, signal_type, and version
     * from the finder, so the factory can create alerts for different pipelines.
     *
     * @return array<int, array>
     */
    public function findAllEntries(RealtimeTradeCandidate $candidate): array
    {
        $entries = [];

        $additionalClasses = config('trading_realtime.additional_entry_finders', []);

        if (! is_array($additionalClasses)) {
            $additionalClasses = [$additionalClasses];
        }

        $activeFinders = array_values(array_filter(
            $additionalClasses,
            fn ($c) => is_string($c) && trim($c) !== '' && class_exists(trim($c))
        ));

        if ($activeFinders !== []) {
            Log::info('[EntryTrigger] Running additional entry finders', [
                'candidate_id' => $candidate->id,
                'symbol' => $candidate->symbol,
                'finders' => $activeFinders,
            ]);
        }

        // Primary entry
        $primary = $this->findEntry($candidate);
        if ($primary !== null) {
            $entries[] = $primary;
        }

        foreach ($activeFinders as $class) {
            $class = trim((string) $class);
            if ($class === '' || ! class_exists($class)) {
                continue;
            }

            try {
                $finder = app($class);

                $this->injectMarketDataIfNeeded($finder);

                if (! method_exists($finder, 'findBestLong')) {
                    continue;
                }

                $signalTsEst = $candidate->detected_ts_est->format('Y-m-d H:i:s');
                $asOfTsEst = now('America/New_York')->format('Y-m-d H:i:s');

                $entry = $finder->findBestLong(
                    $candidate->symbol,
                    $candidate->asset_type,
                    $signalTsEst,
                    $asOfTsEst
                );

                if (! $entry) {
                    continue;
                }

                if (is_object($entry)) {
                    $entry = (array) $entry;
                }

                if (! is_array($entry)) {
                    continue;
                }

                $entry['entry_finder'] = $class;

                $entries[] = $entry;

                Log::info('[EntryTrigger] Additional finder returned entry', [
                    'candidate_id' => $candidate->id,
                    'symbol' => $candidate->symbol,
                    'finder' => $class,
                    'pipeline_run' => $entry['pipeline_run'] ?? '?',
                ]);
            } catch (\Throwable $e) {
                Log::warning('[EntryTrigger] Additional finder failed', [
                    'class' => $class,
                    'candidate_id' => $candidate->id,
                    'symbol' => $candidate->symbol,
                    'message' => $e->getMessage(),
                ]);
            }
        }

        return $entries;
    }

    private function findWithConfiguredEntryFinder(RealtimeTradeCandidate $candidate): ?array
    {
        $class = config('trading_realtime.entry_finder_class');

        if (! $class || ! is_string($class) || ! class_exists($class)) {
            return null;
        }

        try {
            $finder = app($class);

            $this->injectMarketDataIfNeeded($finder);

            if (! method_exists($finder, 'findBestLong')) {
                Log::warning('Configured realtime entry finder has no findBestLong method', [
                    'class' => $class,
                ]);

                return null;
            }

            $signalTsEst = $candidate->detected_ts_est->format('Y-m-d H:i:s');
            $asOfTsEst = now('America/New_York')->format('Y-m-d H:i:s');

            $entry = $finder->findBestLong(
                $candidate->symbol,
                $candidate->asset_type,
                $signalTsEst,
                $asOfTsEst
            );

            if (! $entry) {
                return null;
            }

            if (is_object($entry)) {
                $entry = (array) $entry;
            }

            if (! is_array($entry)) {
                return null;
            }

            $entry['entry_finder'] = $class;

            return $entry;
        } catch (\Throwable $e) {
            Log::warning('Configured realtime entry finder failed', [
                'class' => $class,
                'candidate_id' => $candidate->id,
                'symbol' => $candidate->symbol,
                'message' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Default realtime trigger built around the realtime_trade_candidates row.
     *
     * All gate thresholds are DB-backed via TradingSettingService (with config
     * fallbacks), so they can be adjusted live from the /trading-settings UI.
     */
    private function findWithDefaultRealtimeTrigger(RealtimeTradeCandidate $candidate): ?array
    {
        if ((string) $candidate->status !== 'watching') {
            return $this->gateFail($candidate, 'candidate_not_watching', [
                'status' => $candidate->status,
            ]);
        }

        $quote = $this->marketData->latestQuote($candidate->symbol);
        $partial = $this->marketData->latestPartialOneMinuteBar($candidate->symbol);

        // ── Gate 0: skip-first-minutes (Pipeline R only) ────────────────
        $skipFirstMinutes = TradingSettingService::getRealtimeSkipFirstMinutes();
        if ($skipFirstMinutes > 0) {
            $nowEst = now('America/New_York');
            $marketOpen = $nowEst->copy()->setTime(9, 30, 0);
            $secondsSinceOpen = $marketOpen->diffInSeconds($nowEst, true);
            if ($secondsSinceOpen < ($skipFirstMinutes * 60)) {
                return $this->gateFail($candidate, 'skip_first_minutes', [
                    'skip_first_minutes' => $skipFirstMinutes,
                    'seconds_since_open' => $secondsSinceOpen,
                ]);
            }

            // Also reject candidates whose signal (detected_ts_est) was recorded during
            // the skip window. Without this, a candidate detected at e.g. 09:31 can
            // fire at 09:34+ and produce an alert with signal_ts_est=09:31 — which is
            // exactly what the skip-first-minutes setting is meant to exclude.
            $detectedTsEst = \Carbon\Carbon::parse(
                $candidate->getRawOriginal('detected_ts_est'),
                'America/New_York'
            );
            $detectedMarketOpen = $detectedTsEst->copy()->setTime(9, 30, 0);
            $detectedSecondsSinceOpen = $detectedMarketOpen->diffInSeconds($detectedTsEst, true);
            if ($detectedSecondsSinceOpen < ($skipFirstMinutes * 60)) {
                // Permanently reject — this condition will never change, no need to retry.
                $candidate->update([
                    'status' => 'rejected',
                    'rejection_reason' => 'signal_in_skip_window',
                ]);

                return $this->gateFail($candidate, 'signal_in_skip_window', [
                    'skip_first_minutes' => $skipFirstMinutes,
                    'detected_ts_est' => $detectedTsEst->format('Y-m-d H:i:s'),
                    'detected_seconds_since_open' => $detectedSecondsSinceOpen,
                ]);
            }
        }

        // ── Gate 1: candidate freshness ──────────────────────────────────
        $candidateAgeSeconds = $this->candidateAgeSeconds($candidate);
        $maxCandidateAgeSeconds = TradingSettingService::getRealtimeEntryCandidateMaxAgeSeconds();

        if ($candidateAgeSeconds === null || $candidateAgeSeconds > $maxCandidateAgeSeconds) {
            return $this->gateFail($candidate, 'candidate_too_old', [
                'candidate_age_seconds' => $candidateAgeSeconds,
                'max_allowed' => $maxCandidateAgeSeconds,
            ]);
        }

        // ── Gate 2: quote freshness ──────────────────────────────────────
        $quoteAge = $quote ? $this->marketData->quoteAgeSeconds($quote) : null;
        $staleSeconds = $this->num($candidate->stale_seconds);

        if ($quoteAge === null && $staleSeconds !== null) {
            $quoteAge = (int) $staleSeconds;
        }

        $maxQuoteAgeSeconds = TradingSettingService::getMaxQuoteAgeSeconds();

        if ($quoteAge === null || $quoteAge > $maxQuoteAgeSeconds) {
            return $this->gateFail($candidate, 'quote_too_old', [
                'quote_age_seconds' => $quoteAge,
                'stale_seconds' => $candidate->stale_seconds,
                'max_allowed' => $maxQuoteAgeSeconds,
            ]);
        }

        // ── Gate 3: bid/ask validity ─────────────────────────────────────
        $ask = $this->numFromArray($quote, ['ask', 'ap']) ?? $this->num($candidate->ask);
        $bid = $this->numFromArray($quote, ['bid', 'bp']) ?? $this->num($candidate->bid);

        if ($ask === null || $bid === null || $ask <= 0 || $bid <= 0 || $ask < $bid) {
            return $this->gateFail($candidate, 'bad_bid_ask', [
                'ask' => $ask,
                'bid' => $bid,
            ]);
        }

        $entryPrice = $ask;
        $mid = ($ask + $bid) / 2;

        // ── Gate 4: spread ───────────────────────────────────────────────
        $spreadPct = $this->num($candidate->spread_pct);

        if ($spreadPct === null && $mid > 0) {
            $spreadPct = (($ask - $bid) / $mid) * 100;
        }

        $maxSpreadPct = TradingSettingService::getMaxSpreadPct();

        if ($spreadPct === null || $spreadPct > $maxSpreadPct) {
            return $this->gateFail($candidate, 'spread_too_wide', [
                'spread_pct' => $spreadPct !== null ? round($spreadPct, 4) : null,
                'max_allowed' => $maxSpreadPct,
            ]);
        }

        // ── Gate 5: price range ──────────────────────────────────────────
        $minPrice = TradingSettingService::getRealtimeEntryMinPrice();
        $maxPrice = TradingSettingService::getRealtimeEntryMaxPrice();

        if ($entryPrice < $minPrice || $entryPrice > $maxPrice) {
            return $this->gateFail($candidate, 'price_out_of_range', [
                'entry_price' => round($entryPrice, 4),
                'min_price' => $minPrice,
                'max_price' => $maxPrice,
            ]);
        }

        // ── Gate 6: valid partial bar ────────────────────────────────────
        $open = $this->numFromArray($partial, ['open', 'o']) ?? $this->num($candidate->partial_open);
        $high = $this->numFromArray($partial, ['high', 'h']) ?? $this->num($candidate->partial_high);
        $low = $this->numFromArray($partial, ['low', 'l']) ?? $this->num($candidate->partial_low);
        $close = $this->numFromArray($partial, ['close', 'c']) ?? $this->num($candidate->partial_close) ?? $entryPrice;
        $partialVolume = $this->numFromArray($partial, ['volume', 'v']) ?? $this->num($candidate->partial_volume);
        $vwap = $this->numFromArray($partial, ['vwap', 'vw']) ?? $this->num($candidate->vwap);

        if ($open === null || $high === null || $low === null || $close === null || $open <= 0 || $high <= 0 || $low <= 0 || $close <= 0) {
            return $this->gateFail($candidate, 'invalid_partial_bar', [
                'open' => $open,
                'high' => $high,
                'low' => $low,
                'close' => $close,
            ]);
        }

        $return1mPct = (($close - $open) / $open) * 100;
        $candidateReturn1m = $this->num($candidate->return_1m_pct);
        if ($candidateReturn1m !== null) {
            $return1mPct = max($return1mPct, $candidateReturn1m);
        }

        $return3mPct = $this->num($candidate->return_3m_pct) ?? 0.0;
        $volumeRatio = $this->num($candidate->volume_ratio) ?? $this->num($candidate->rvol) ?? 0.0;
        $dollarVolume1m = $this->num($candidate->dollar_volume_1m);

        if ($dollarVolume1m === null && $partialVolume !== null && $close > 0) {
            $dollarVolume1m = $partialVolume * $close;
        }

        $bidQty = $this->numFromArray($quote, ['bid_qty', 'bid_size', 'bs']) ?? $this->num($candidate->bid_qty);
        $askQty = $this->numFromArray($quote, ['ask_qty', 'ask_size', 'as']) ?? $this->num($candidate->ask_qty);
        $bidAskImbalance = $this->num($candidate->bid_ask_imbalance);

        if ($bidAskImbalance === null && $bidQty !== null && $askQty !== null && ($bidQty + $askQty) > 0) {
            $bidAskImbalance = ($bidQty - $askQty) / ($bidQty + $askQty);
        }

        $vwapDistPct = $this->num($candidate->vwap_dist_pct);

        if ($vwap !== null && $vwap > 0) {
            $vwapDistPct = (($entryPrice - $vwap) / $vwap) * 100;
        }

        $moveSinceCandidatePct = null;
        $detectedPrice = $this->num($candidate->detected_price);
        if ($detectedPrice !== null && $detectedPrice > 0) {
            $moveSinceCandidatePct = (($entryPrice - $detectedPrice) / $detectedPrice) * 100;
        }

        $range = $high - $low;
        $closePosition = $range > 0 ? ($close - $low) / $range : 0.0;
        $upperWickRatio = $range > 0 ? ($high - $close) / $range : 0.0;

        // ── Gate 7: VWAP requirement ─────────────────────────────────────
        $requireVwap = TradingSettingService::isRealtimeEntryRequireVwap();

        if ($requireVwap && ($vwap === null || $vwap <= 0 || $vwapDistPct === null)) {
            return $this->gateFail($candidate, 'missing_vwap', [
                'vwap' => $vwap,
                'vwap_dist_pct' => $vwapDistPct,
            ]);
        }

        if ($vwap !== null && $vwap > 0 && $entryPrice < $vwap) {
            return $this->gateFail($candidate, 'price_below_vwap', [
                'entry_price' => round($entryPrice, 4),
                'vwap' => round($vwap, 4),
                'vwap_dist_pct' => round((float) $vwapDistPct, 4),
            ]);
        }

        // ── Gate 8: VWAP extension ───────────────────────────────────────
        $maxVwapExtensionPct = TradingSettingService::getRealtimeMaxVwapExtensionPct();

        if ($vwapDistPct !== null && $vwapDistPct > $maxVwapExtensionPct) {
            return $this->gateFail($candidate, 'too_far_above_vwap', [
                'vwap_dist_pct' => round($vwapDistPct, 4),
                'max_allowed' => $maxVwapExtensionPct,
            ]);
        }

        // ── Gate 9: 1m return ────────────────────────────────────────────
        $minReturn1mPct = TradingSettingService::getRealtimeEntryReturn1mMinPct();

        if ($return1mPct < $minReturn1mPct) {
            return $this->gateFail($candidate, 'return_1m_too_low', [
                'return_1m_pct' => round($return1mPct, 4),
                'min_required' => $minReturn1mPct,
            ]);
        }

        // ── Gate 10: 3m return ───────────────────────────────────────────
        $minReturn3mPct = TradingSettingService::getRealtimeEntryReturn3mMinPct();

        if ($return3mPct < $minReturn3mPct) {
            return $this->gateFail($candidate, 'return_3m_too_low', [
                'return_3m_pct' => round($return3mPct, 4),
                'min_required' => $minReturn3mPct,
            ]);
        }

        // ── Gate 11: volume ratio ────────────────────────────────────────
        $minVolumeRatio = TradingSettingService::getRealtimeEntryVolumeRatioMin();

        if ($volumeRatio < $minVolumeRatio) {
            return $this->gateFail($candidate, 'volume_ratio_too_low', [
                'volume_ratio' => round($volumeRatio, 4),
                'min_required' => $minVolumeRatio,
            ]);
        }

        // ── Gate 12: dollar volume ───────────────────────────────────────
        $minDollarVolume1m = TradingSettingService::getRealtimeEntryMinDollarVolume1m();

        if ($dollarVolume1m === null || $dollarVolume1m < $minDollarVolume1m) {
            return $this->gateFail($candidate, 'dollar_volume_1m_too_low', [
                'dollar_volume_1m' => $dollarVolume1m !== null ? round($dollarVolume1m, 2) : null,
                'min_required' => $minDollarVolume1m,
            ]);
        }

        // ── Gate 13: move since candidate (upper bound) ──────────────────
        $maxMoveSinceCandidatePct = TradingSettingService::getRealtimeMaxMoveSinceCandidatePct();

        if ($moveSinceCandidatePct !== null && $moveSinceCandidatePct > $maxMoveSinceCandidatePct) {
            return $this->gateFail($candidate, 'moved_too_far_since_candidate', [
                'move_since_candidate_pct' => round($moveSinceCandidatePct, 4),
                'max_allowed' => $maxMoveSinceCandidatePct,
            ]);
        }

        // ── Gate 14: move since candidate (lower bound) ──────────────────
        $minMoveSinceCandidatePct = TradingSettingService::getRealtimeEntryAboveCandidateMinPct();

        if ($moveSinceCandidatePct !== null && $moveSinceCandidatePct < $minMoveSinceCandidatePct) {
            return $this->gateFail($candidate, 'price_dropped_below_candidate', [
                'move_since_candidate_pct' => round($moveSinceCandidatePct, 4),
                'min_allowed' => $minMoveSinceCandidatePct,
            ]);
        }

        // ── Gate 15: candle close position ───────────────────────────────
        $minClosePosition = TradingSettingService::getRealtimeEntryClosePositionMin();

        if ($closePosition < $minClosePosition) {
            return $this->gateFail($candidate, 'weak_candle_close_position', [
                'close_position' => round($closePosition, 4),
                'min_required' => $minClosePosition,
            ]);
        }

        // ── Gate 16: upper wick ──────────────────────────────────────────
        $maxUpperWickRatio = TradingSettingService::getRealtimeEntryUpperWickMax();

        if ($upperWickRatio > $maxUpperWickRatio) {
            return $this->gateFail($candidate, 'upper_wick_too_large', [
                'upper_wick_ratio' => round($upperWickRatio, 4),
                'max_allowed' => $maxUpperWickRatio,
            ]);
        }

        // ── Gate 17: bid/ask imbalance ───────────────────────────────────
        $minBidAskImbalance = TradingSettingService::getRealtimeEntryBidAskImbalanceMin();

        if ($bidAskImbalance !== null && $bidAskImbalance < $minBidAskImbalance) {
            return $this->gateFail($candidate, 'bid_ask_imbalance_too_weak', [
                'bid_ask_imbalance' => round($bidAskImbalance, 4),
                'min_required' => $minBidAskImbalance,
            ]);
        }

        // ── Gate 18: EMA trend ───────────────────────────────────────────
        $requireEmaTrend = TradingSettingService::isRealtimeEntryRequireEma9AboveEma21();

        if ($requireEmaTrend && $candidate->ema9_above_ema21 !== null && (int) $candidate->ema9_above_ema21 !== 1) {
            return $this->gateFail($candidate, 'ema9_not_above_ema21', [
                'ema9_above_ema21' => $candidate->ema9_above_ema21,
            ]);
        }

        // ── Final score ──────────────────────────────────────────────────
        $finalScore = $this->scoreFreshOneMinuteEntry(
            return1mPct: $return1mPct,
            return3mPct: $return3mPct,
            volumeRatio: $volumeRatio,
            vwapDistPct: $vwapDistPct,
            closePosition: $closePosition,
            upperWickRatio: $upperWickRatio,
            spreadPct: $spreadPct,
            maxSpreadPct: $maxSpreadPct,
            bidAskImbalance: $bidAskImbalance,
            ema9AboveEma21: $candidate->ema9_above_ema21 !== null ? (int) $candidate->ema9_above_ema21 : null,
            earlyScore: $this->num($candidate->early_score),
            moveSinceCandidatePct: $moveSinceCandidatePct
        );

        $minFinalScore = TradingSettingService::getRealtimeEntryFinalScoreMin();

        if ($finalScore < $minFinalScore) {
            return $this->gateFail($candidate, 'final_score_too_low', [
                'final_score' => round($finalScore, 2),
                'min_required' => $minFinalScore,
                'return_1m_pct' => round($return1mPct, 4),
                'return_3m_pct' => round($return3mPct, 4),
                'volume_ratio' => round($volumeRatio, 4),
                'vwap_dist_pct' => $vwapDistPct !== null ? round($vwapDistPct, 4) : null,
                'spread_pct' => round($spreadPct, 4),
            ]);
        }

        $setup = $this->classifySetup(
            return1mPct: $return1mPct,
            return3mPct: $return3mPct,
            volumeRatio: $volumeRatio,
            vwapDistPct: $vwapDistPct,
            closePosition: $closePosition,
            bidAskImbalance: $bidAskImbalance
        );

        $candidate->forceFill([
            'last_gate_fail_reason' => null,
        ])->saveQuietly();

        Log::info('[EntryTrigger] ✅ FRESH ONE-MINUTE ENTRY PASSED', [
            'candidate_id' => $candidate->id,
            'symbol' => $candidate->symbol,
            'entry_price' => round($entryPrice, 4),
            'final_score' => round($finalScore, 2),
            'setup' => $setup,
            'return_1m_pct' => round($return1mPct, 4),
            'return_3m_pct' => round($return3mPct, 4),
            'volume_ratio' => round($volumeRatio, 4),
            'vwap_dist_pct' => $vwapDistPct !== null ? round($vwapDistPct, 4) : null,
            'spread_pct' => round($spreadPct, 4),
            'candidate_age_seconds' => $candidateAgeSeconds,
            'quote_age_seconds' => $quoteAge,
        ]);

        return [
            'entry_ts_est' => now('America/New_York')->format('Y-m-d H:i:s'),
            'entry_price' => $entryPrice,
            'score' => round($finalScore, 2),
            'reason' => 'fresh_one_minute_realtime_trigger',
            'entry_finder' => static::class,
            'setup' => $setup,

            // Core features for trade_alerts/meta/ML.
            'return_1m_pct' => $return1mPct,
            'return_3m_pct' => $return3mPct,
            'volume_ratio' => $volumeRatio,
            'rvol' => $this->num($candidate->rvol),
            'move_30m_pct' => $this->num($candidate->move_30m_pct),
            'move_since_candidate_pct' => $moveSinceCandidatePct,
            'vwap_dist_pct' => $vwapDistPct,
            'atr_pct' => $this->num($candidate->atr_pct),
            'spread_pct' => $spreadPct,
            'bid_ask_imbalance' => $bidAskImbalance,
            'dollar_volume_1m' => $dollarVolume1m,
            'close_position' => $closePosition,
            'upper_wick_ratio' => $upperWickRatio,
            'quote_age_seconds' => $quoteAge,
            'candidate_age_seconds' => $candidateAgeSeconds,
            'partial_open' => $open,
            'partial_high' => $high,
            'partial_low' => $low,
            'partial_close' => $close,
            'partial_volume' => $partialVolume,
            'vwap' => $vwap,
            'early_score' => $this->num($candidate->early_score),
        ];
    }

    private function scoreFreshOneMinuteEntry(
        float $return1mPct,
        float $return3mPct,
        float $volumeRatio,
        ?float $vwapDistPct,
        float $closePosition,
        float $upperWickRatio,
        float $spreadPct,
        float $maxSpreadPct,
        ?float $bidAskImbalance,
        ?int $ema9AboveEma21,
        ?float $earlyScore,
        ?float $moveSinceCandidatePct
    ): float {
        $score = 0.0;

        $score += 25.0 * $this->cap01($return1mPct / 0.35);
        $score += 20.0 * $this->cap01($return3mPct / 0.75);
        $score += 20.0 * $this->cap01($volumeRatio / 3.0);

        if ($vwapDistPct !== null && $vwapDistPct >= 0) {
            $score += 15.0;

            if ($vwapDistPct > 1.25) {
                $score -= 10.0 * $this->cap01(($vwapDistPct - 1.25) / 0.50);
            }
        }

        $score += 10.0 * $this->cap01(($closePosition - 0.50) / 0.50);

        if ($upperWickRatio > 0.35) {
            $score -= 10.0 * $this->cap01(($upperWickRatio - 0.35) / 0.25);
        }

        if ($bidAskImbalance !== null && $bidAskImbalance > 0) {
            $score += 5.0 * $this->cap01($bidAskImbalance / 0.50);
        }

        if ($ema9AboveEma21 === 1) {
            $score += 5.0;
        }

        if ($earlyScore !== null) {
            $score += 5.0 * $this->cap01($earlyScore / 100.0);
        }

        if ($moveSinceCandidatePct !== null && $moveSinceCandidatePct > 0.50) {
            $score -= 10.0 * $this->cap01(($moveSinceCandidatePct - 0.50) / 0.50);
        }

        if ($maxSpreadPct > 0) {
            $score -= 10.0 * $this->cap01($spreadPct / $maxSpreadPct);
        }

        return min(100.0, max(0.0, $score));
    }

    private function classifySetup(
        float $return1mPct,
        float $return3mPct,
        float $volumeRatio,
        ?float $vwapDistPct,
        float $closePosition,
        ?float $bidAskImbalance
    ): string {
        if ($vwapDistPct !== null && $vwapDistPct <= 0.80 && $volumeRatio >= 3.0 && $closePosition >= 0.70) {
            return 'fresh_micro_breakout';
        }

        if ($return1mPct >= 0.20 && $return3mPct >= 0.40 && ($vwapDistPct ?? 99) <= 1.20) {
            return 'early_momentum_continuation';
        }

        if (($vwapDistPct ?? 99) <= 0.50 && $volumeRatio >= 2.5) {
            return 'vwap_hold_breakout';
        }

        if ($bidAskImbalance !== null && $bidAskImbalance >= 0.30) {
            return 'quote_supported_momentum';
        }

        return 'one_minute_momentum';
    }

    private function gateFail(RealtimeTradeCandidate $candidate, string $reason, array $context = []): ?array
    {
        Log::info('[EntryTrigger] Gate fail: '.$reason, array_merge([
            'candidate_id' => $candidate->id,
            'symbol' => $candidate->symbol,
        ], $context));

        if ((string) $candidate->last_gate_fail_reason !== $reason) {
            $candidate->forceFill([
                'last_gate_fail_reason' => $reason,
            ])->saveQuietly();
        }

        return null;
    }

    private function candidateAgeSeconds(RealtimeTradeCandidate $candidate): ?int
    {
        $detected = $candidate->detected_ts_est;

        if ($detected instanceof \DateTimeInterface) {
            $detectedString = $detected->format('Y-m-d H:i:s');
        } elseif (is_string($detected) && trim($detected) !== '') {
            $detectedString = trim($detected);
        } else {
            return null;
        }

        $detectedTs = strtotime($detectedString);
        $nowTs = strtotime(now('America/New_York')->format('Y-m-d H:i:s'));

        if ($detectedTs === false || $nowTs === false) {
            return null;
        }

        return max(0, $nowTs - $detectedTs);
    }

    private function num(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (! is_numeric($value)) {
            return null;
        }

        return (float) $value;
    }

    private function numFromArray(?array $values, array $keys): ?float
    {
        if (! $values) {
            return null;
        }

        foreach ($keys as $key) {
            if (array_key_exists($key, $values) && is_numeric($values[$key])) {
                return (float) $values[$key];
            }
        }

        return null;
    }

    private function cap01(float $value): float
    {
        return min(1.0, max(0.0, $value));
    }

    /**
     * Inject the shared market data service into finders that need it.
     */
    private function injectMarketDataIfNeeded(object $finder): void
    {
        if (property_exists($finder, 'marketData')) {
            $ref = new \ReflectionProperty($finder, 'marketData');
            $ref->setAccessible(true);
            $ref->setValue($finder, $this->marketData);
        }
    }
}
