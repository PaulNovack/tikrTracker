<?php

declare(strict_types=1);

namespace App\Services\Trading\Realtime;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;

/**
 * Realtime VWAP Reversal Finder (Pipeline S)
 *
 * Detects stocks that have extended significantly from VWAP and show
 * reversal micro-structure. Complements RealtimeMomentumContinuationFinder
 * (Pipeline R) by catching entries going the other direction.
 *
 * Detection logic:
 *  1. Price > 2% above VWAP (overextended long) for multiple bars
 *  2. Micro double-top at the VWAP band (rejection wick pattern)
 *  3. Volume surge on the return bar toward VWAP
 *  4. Entry on return move + volume confirmation
 *  5. Stop beyond the double-top extreme
 *
 * Compatible with both live trading:realtime-watch and
 * trading:realtime-backtest — both use RealtimeMarketDataService interface.
 */
class RealtimeVwapReversalFinder
{
    public function __construct(
        private RealtimeMarketDataService $marketData,
    ) {}

    private function vwapExtensionPct(): float
    {
        return (float) config('trading_realtime.vwap_reversal.vwap_extension_pct', 2.0);
    }

    private function minExtensionBars(): int
    {
        return (int) config('trading_realtime.vwap_reversal.min_extension_bars', 3);
    }

    private function volumeRatioMin(): float
    {
        return (float) config('trading_realtime.vwap_reversal.volume_ratio_min', 1.5);
    }

    private function stopBufferAtr(): float
    {
        return (float) config('trading_realtime.vwap_reversal.stop_buffer_atr', 1.5);
    }

    private function lookbackBars(): int
    {
        return (int) config('trading_realtime.vwap_reversal.lookback_bars', 15);
    }

    public function getVersion(): string
    {
        return 'rt-vwap-reversal-v1.0';
    }

    /**
     * Find a VWAP reversal entry for the given symbol.
     *
     * @param  string  $symbol  Uppercase symbol.
     * @param  string  $assetType  Asset type (e.g. 'stock').
     * @param  string  $signalTsEst  Timestamp when the candidate was detected (EST).
     * @param  string  $asOfTsEst  Current timestamp (EST).
     * @return array{ok: int, best_entry: ?array, reason?: string, meta?: array}
     */
    public function findBestLong(
        string $symbol,
        string $assetType,
        string $signalTsEst,
        string $asOfTsEst,
        ...$rest
    ): array {
        try {
            return $this->findBestLongInternal($symbol, $assetType, $signalTsEst, $asOfTsEst);
        } catch (\Throwable $e) {
            Log::warning('[VwapReversal] findBestLong exception', [
                'symbol' => $symbol,
                'error' => $e->getMessage(),
            ]);

            return [
                'ok' => 0,
                'best_entry' => null,
                'reason' => 'internal_error',
            ];
        }
    }

    /**
     * Internal implementation.
     *
     * @return array{ok: int, best_entry: ?array, reason?: string, meta?: array}
     */
    private function findBestLongInternal(
        string $symbol,
        string $assetType,
        string $signalTsEst,
        string $asOfTsEst,
    ): array {
        Log::info('[VwapReversal] Checking symbol', [
            'symbol' => $symbol,
            'signal_ts' => $signalTsEst,
            'as_of_ts' => $asOfTsEst,
        ]);

        // ── Step 1: Load recent bars ─────────────────────────────────────────
        $lookback = $this->lookbackBars();
        $recentBars = $this->marketData->recentOneMinuteBars($symbol, $lookback);

        if ($recentBars === null || count($recentBars) < 5) {
            return [
                'ok' => 0,
                'best_entry' => null,
                'reason' => 'insufficient_bars',
                'meta' => ['bar_count' => $recentBars !== null ? count($recentBars) : 0],
            ];
        }

        $bars = array_values($recentBars);

        $highs = array_map(fn ($b) => (float) ($b['high'] ?? $b['close'] ?? 0), $bars);
        $lows = array_map(fn ($b) => (float) ($b['low'] ?? $b['close'] ?? 0), $bars);
        $closes = array_map(fn ($b) => (float) ($b['close'] ?? $b['price'] ?? 0), $bars);
        $opens = array_map(fn ($b) => (float) ($b['open'] ?? 0), $bars);
        $volumes = array_map(fn ($b) => (int) ($b['volume'] ?? 0), $bars);

        if (min($closes) <= 0 || min($highs) <= 0) {
            return [
                'ok' => 0,
                'best_entry' => null,
                'reason' => 'invalid_bar_data',
            ];
        }

        $currentClose = $closes[0];
        $currentHigh = $highs[0];
        $currentLow = $lows[0];
        $currentOpen = $opens[0];
        $currentVolume = $volumes[0];

        // ── Step 2: Check VWAP extension ─────────────────────────────────────
        $vwap = isset($bars[0]['vwap']) && (float) $bars[0]['vwap'] > 0
            ? (float) $bars[0]['vwap']
            : null;

        if ($vwap === null || $vwap <= 0) {
            return [
                'ok' => 0,
                'best_entry' => null,
                'reason' => 'no_vwap',
            ];
        }

        // Current VWAP distance
        $currentVwapDistPct = (($currentClose - $vwap) / $vwap) * 100;
        $minExtensionPct = $this->vwapExtensionPct();

        // We're looking for stocks that are extended ABOVE VWAP (overbought reversal).
        if ($currentVwapDistPct < $minExtensionPct * 0.5) {
            return [
                'ok' => 0,
                'best_entry' => null,
                'reason' => 'not_extended_enough',
                'meta' => ['vwap_dist_pct' => round($currentVwapDistPct, 4)],
            ];
        }

        // Check that at least minExtensionBars of the recent bars show extension
        $minBars = $this->minExtensionBars();
        $extendedCount = 0;
        foreach ($bars as $bar) {
            $barVwap = isset($bar['vwap']) && (float) $bar['vwap'] > 0 ? (float) $bar['vwap'] : null;
            if ($barVwap === null) {
                continue;
            }
            $barClose = (float) ($bar['close'] ?? $bar['price'] ?? 0);
            $barDistPct = (($barClose - $barVwap) / $barVwap) * 100;
            if ($barDistPct >= $minExtensionPct) {
                $extendedCount++;
            }
        }

        if ($extendedCount < $minBars) {
            return [
                'ok' => 0,
                'best_entry' => null,
                'reason' => 'not_enough_extended_bars',
                'meta' => [
                    'extended_count' => $extendedCount,
                    'min_required' => $minBars,
                ],
            ];
        }

        // ── Step 3: Double-top / rejection pattern detection ──────────────────
        // Look for 2 similar highs within 0.3% of each other in the last N bars,
        // with a dip in between, indicating resistance at the VWAP band.
        $lookbackForTop = min(10, count($highs));
        $recentHighs = array_slice($highs, 0, $lookbackForTop);
        $microDoubleTop = false;
        $doubleTopLevel = 0.0;

        for ($i = 0; $i < count($recentHighs) - 2; $i++) {
            for ($j = $i + 2; $j < count($recentHighs); $j++) {
                $top1 = $recentHighs[$i];
                $top2 = $recentHighs[$j];
                $diffPct = abs(($top2 - $top1) / max($top1, 0.01)) * 100;

                // Two highs within 0.3% of each other
                if ($diffPct <= 0.3 && $diffPct >= 0.01) {
                    // Check there's a dip between them (at least one bar low < min(top1, top2))
                    $minBarLow = min(array_slice($lows, min($i, $j) + 1, abs($j - $i)));
                    $lowerTop = min($top1, $top2);
                    if ($minBarLow < $lowerTop) {
                        // Check the current bar is showing a rejection (closing in the lower half or red)
                        $bodyPct = $currentOpen > 0
                            ? abs($currentClose - $currentOpen) / max($currentOpen, 0.01) * 100
                            : 0;
                        $isRedBar = $currentClose < $currentOpen;
                        $rejectionWick = ($currentHigh - max($currentClose, $currentOpen)) > ($currentHigh - $currentLow) * 0.5;

                        if ($isRedBar || $rejectionWick) {
                            $microDoubleTop = true;
                            $doubleTopLevel = max($top1, $top2);
                            break;
                        }
                    }
                }
            }
            if ($microDoubleTop) {
                break;
            }
        }

        if (! $microDoubleTop) {
            // Fallback: check for a simple rejection (long upper wick on recent bar)
            // If the prior bar has a wick > 50% of its total range, that's a rejection
            if (count($bars) >= 3) {
                $priorBar = $bars[1];
                $priorHigh = (float) ($priorBar['high'] ?? $priorBar['close'] ?? 0);
                $priorLow = (float) ($priorBar['low'] ?? $priorBar['close'] ?? 0);
                $priorClose = (float) ($priorBar['close'] ?? $priorBar['price'] ?? 0);
                $priorRange = $priorHigh - $priorLow;

                if ($priorRange > 0) {
                    $upperWick = $priorHigh - max($priorClose, (float) ($priorBar['open'] ?? $priorClose));
                    $wickPct = $upperWick / $priorRange;

                    if ($wickPct >= 0.5) {
                        // Prior bar shows a rejection wick — good enough
                        $doubleTopLevel = $priorHigh;
                    } else {
                        return [
                            'ok' => 0,
                            'best_entry' => null,
                            'reason' => 'no_rejection_pattern',
                        ];
                    }
                } else {
                    return [
                        'ok' => 0,
                        'best_entry' => null,
                        'reason' => 'no_rejection_pattern',
                    ];
                }
            } else {
                return [
                    'ok' => 0,
                    'best_entry' => null,
                    'reason' => 'no_rejection_pattern',
                ];
            }
        }

        // ── Step 4: Volume surge on return toward VWAP ────────────────────────
        $priorVolumes = array_slice($volumes, 1, 10);
        $priorVolumes = array_filter($priorVolumes, fn ($v) => $v > 0);

        if (count($priorVolumes) < 3) {
            $avgVolume = $currentVolume * 0.5;
        } else {
            $avgVolume = array_sum($priorVolumes) / count($priorVolumes);
        }

        $volumeRatio = $avgVolume > 0
            ? $currentVolume / $avgVolume
            : 0;

        // Is the current bar closing toward VWAP? (reversal in progress)
        // We want to see the close moving back toward VWAP compared to prior bars
        $priorClose = $closes[1] ?? $currentClose;
        $priorVwapDistPct = (($priorClose - $vwap) / $vwap) * 100;

        // The current bar should be closer to VWAP than the prior bar (moving toward it)
        $movingTowardVwap = abs($currentVwapDistPct) < abs($priorVwapDistPct);

        if (! $movingTowardVwap) {
            return [
                'ok' => 0,
                'best_entry' => null,
                'reason' => 'not_moving_toward_vwap',
                'meta' => [
                    'current_vwap_dist' => round($currentVwapDistPct, 4),
                    'prior_vwap_dist' => round($priorVwapDistPct, 4),
                ],
            ];
        }

        if ($volumeRatio < $this->volumeRatioMin()) {
            return [
                'ok' => 0,
                'best_entry' => null,
                'reason' => 'return_volume_too_low',
                'meta' => [
                    'volume_ratio' => round($volumeRatio, 3),
                    'min_required' => $this->volumeRatioMin(),
                ],
            ];
        }

        // ── Step 5: Entry price and stop ──────────────────────────────────────
        $entryPrice = $currentClose;

        // Stop beyond the double-top extreme + 1 ATR buffer
        $atrPct = isset($bars[0]['atr_pct']) && (float) $bars[0]['atr_pct'] > 0
            ? (float) $bars[0]['atr_pct']
            : 1.0;

        $atrDollars = $entryPrice * ($atrPct / 100);
        $stopPrice = $doubleTopLevel > 0
            ? $doubleTopLevel + ($atrDollars * $this->stopBufferAtr())
            : $entryPrice * 1.02; // fallback: 2% above entry

        $riskPerShare = round($stopPrice - $entryPrice, 4);
        $riskPct = $entryPrice > 0 ? round(($riskPerShare / $entryPrice) * 100, 4) : 0;

        // Enforce max risk — VWAP reversals should be tight
        if ($riskPct > 2.0) {
            return [
                'ok' => 0,
                'best_entry' => null,
                'reason' => 'risk_too_wide',
                'meta' => [
                    'risk_pct' => $riskPct,
                    'entry' => round($entryPrice, 4),
                    'stop' => $stopPrice,
                ],
            ];
        }

        // ── Step 6: Score ─────────────────────────────────────────────────────
        $score = $this->scoreEntry(
            vwapDistPct: $currentVwapDistPct,
            volumeRatio: $volumeRatio,
            riskPct: $riskPct,
            hasDoubleTop: $microDoubleTop,
        );

        $nowEst = CarbonImmutable::parse($asOfTsEst, 'America/New_York')
            ->format('Y-m-d H:i:s');

        $return1mPct = $currentOpen > 0
            ? (($currentClose - $currentOpen) / $currentOpen) * 100
            : 0;

        Log::info('[VwapReversal] ✅ Entry found', [
            'symbol' => $symbol,
            'entry_price' => round($entryPrice, 4),
            'stop' => round($stopPrice, 4),
            'risk_pct' => $riskPct,
            'score' => $score,
            'vwap_dist_pct' => round($currentVwapDistPct, 4),
            'volume_ratio' => round($volumeRatio, 3),
            'double_top' => $microDoubleTop,
            'pipeline_run' => 'S',
        ]);

        return [
            'entry_ts_est' => $nowEst,
            'entry_price' => $entryPrice,
            'score' => $score,
            'reason' => 'vwap_reversal',
            'return_1m_pct' => $return1mPct,
            'move_since_candidate_pct' => 0,
            'vwap_dist_pct' => $currentVwapDistPct,
            'spread_pct' => 0,
            'pipeline_run' => 'S',
            'signal_type' => 'VWAP_REVERSAL',
            'version' => $this->getVersion(),
        ];
    }

    /**
     * Score the entry quality (0-100).
     */
    private function scoreEntry(
        float $vwapDistPct,
        float $volumeRatio,
        float $riskPct,
        bool $hasDoubleTop,
    ): float {
        $score = 60.0; // Base: passed all structural gates

        // VWAP extension quality: more extension = stronger reversal potential
        // Score +0-15 pts for extension beyond 2% up to 5%
        $extensionScore = min(max(($vwapDistPct - 2.0) / 3.0, 0), 1) * 15;
        $score += $extensionScore;

        // Volume bonus: +0-15 pts (scales 1.5x → 4.0x)
        $score += 15.0 * min(max(($volumeRatio - 1.5) / 2.5, 0), 1);

        // Double-top pattern bonus: +10 pts
        if ($hasDoubleTop) {
            $score += 10;
        }

        // Risk penalty: -0-10 pts (bigger risk = lower score)
        $riskPenalty = min(max(($riskPct / 2.0), 0), 1) * 10;
        $score -= $riskPenalty;

        return round(min(max($score, 0), 100), 1);
    }
}
