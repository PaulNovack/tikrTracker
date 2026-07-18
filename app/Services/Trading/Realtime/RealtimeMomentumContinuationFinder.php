<?php

declare(strict_types=1);

namespace App\Services\Trading\Realtime;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;

/**
 * Realtime Momentum Continuation Finder
 *
 * Detects momentum continuation entries using ONLY 1-minute bar data from
 * RealtimeMarketDataService (Redis-backed, sub-ms reads). No 5-minute table
 * queries — entries happen on the same 1-minute bar the breakout occurs.
 *
 * Detection logic:
 *  1.  HH/HL structure — higher highs and higher lows over the last 5 bars
 *  2.  Consolidation — 2-3 tight bars (range ≤ 0.8%) after a directional move
 *  3.  Continuation breakout — current bar breaks above consolidation high
 *      with volume surge (vol ratio ≥ 1.30)
 *  4.  Multi-TF confirmation — 5-bar aggregate indicates uptrend (VWAP
 *      aligned, EMAs sloping up)
 *  5.  Stop — below consolidation low or entry bar low
 *
 * Compatible with both live trading:realtime-watch and
 * trading:realtime-backtest — both use RealtimeMarketDataService interface.
 */
class RealtimeMomentumContinuationFinder
{
    public function __construct(
        private RealtimeMarketDataService $marketData,
    ) {}

    private function consolidationBarCount(): int
    {
        return (int) config('trading_realtime.consolidation_bar_count', 3);
    }

    private function consolidationMaxRangePct(): float
    {
        return (float) config('trading_realtime.consolidation_max_range_pct', 0.8);
    }

    private function breakoutMinVolRatio(): float
    {
        return (float) config('trading_realtime.breakout_min_vol_ratio', 1.30);
    }

    private function structureLookback(): int
    {
        return (int) config('trading_realtime.structure_lookback_bars', 5);
    }

    private function maxVwapExtensionPct(): float
    {
        return (float) config('trading_realtime.max_vwap_extension_pct_finder', 1.75);
    }

    public function getVersion(): string
    {
        return 'rt-momentum-continuation-v1.0';
    }

    /**
     * Find a momentum continuation entry for the given symbol.
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
            Log::warning('[MomentumContinuation] findBestLong exception', [
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
     * Internal implementation — extracted for error boundary clarity.
     *
     * @return array{ok: int, best_entry: ?array, reason?: string, meta?: array}
     */
    private function findBestLongInternal(
        string $symbol,
        string $assetType,
        string $signalTsEst,
        string $asOfTsEst,
    ): array {
        // ── Step 1: Load recent bars from RealtimeMarketDataService ───────────
        $lookback = $this->structureLookback();
        $recentBars = $this->marketData->recentOneMinuteBars($symbol, $lookback + 3);

        if ($recentBars === null || count($recentBars) < $lookback) {
            return [
                'ok' => 0,
                'best_entry' => null,
                'reason' => 'insufficient_bars',
                'meta' => ['bar_count' => $recentBars !== null ? count($recentBars) : 0],
            ];
        }

        $bars = array_values($recentBars);

        // ── Step 2: Extract OHLCV arrays ─────────────────────────────────────
        $highs = array_map(fn ($b) => (float) ($b['high'] ?? $b['close'] ?? 0), $bars);
        $lows = array_map(fn ($b) => (float) ($b['low'] ?? $b['close'] ?? 0), $bars);
        $closes = array_map(fn ($b) => (float) ($b['close'] ?? $b['price'] ?? 0), $bars);
        $volumes = array_map(fn ($b) => (int) ($b['volume'] ?? 0), $bars);
        $opens = array_map(fn ($b) => (float) ($b['open'] ?? 0), $bars);

        if (min($closes) <= 0 || min($highs) <= 0) {
            return [
                'ok' => 0,
                'best_entry' => null,
                'reason' => 'invalid_bar_data',
            ];
        }

        // ── Step 3: HH/HL structure check ────────────────────────────────────
        $currentClose = $closes[0];
        $currentHigh = $highs[0];
        $currentLow = $lows[0];
        $currentOpen = $opens[0];
        $currentVolume = $volumes[0];

        // Look for structure in bars [1..N-1] (excluding current partial bar)
        $lookback = $this->structureLookback();
        $structureHighs = array_slice($highs, 1, $lookback);
        $structureLows = array_slice($lows, 1, $lookback);

        if (count($structureHighs) < 3) {
            return [
                'ok' => 0,
                'best_entry' => null,
                'reason' => 'insufficient_structure_bars',
            ];
        }

        $structureValid = true;
        $prevHigh = $structureHighs[0];
        $prevLow = $structureLows[0];

        foreach (array_slice($structureHighs, 1) as $i => $h) {
            if ($h <= $prevHigh) {
                $structureValid = false;
                break;
            }
            $prevHigh = $h;
        }

        if ($structureValid) {
            foreach (array_slice($structureLows, 1) as $i => $l) {
                if ($l <= $prevLow) {
                    $structureValid = false;
                    break;
                }
                $prevLow = $l;
            }
        }

        if (! $structureValid) {
            return [
                'ok' => 0,
                'best_entry' => null,
                'reason' => 'no_hh_hl_structure',
            ];
        }

        // ── Step 4: Consolidation detection ──────────────────────────────────
        // Look for 2-3 tight-range bars immediately before the current bar
        $preBreakoutBars = array_slice($bars, 1, $this->consolidationBarCount());

        if (count($preBreakoutBars) < 2) {
            return [
                'ok' => 0,
                'best_entry' => null,
                'reason' => 'insufficient_pre_bars',
            ];
        }

        $consolidationHigh = 0.0;
        $consolidationLow = PHP_FLOAT_MAX;
        $isRelaxed = (bool) config('trading_realtime.relaxed_hh_hl', false);
        $maxRangePct = $isRelaxed ? $this->consolidationMaxRangePct() * 2.0 : $this->consolidationMaxRangePct();

        foreach ($preBreakoutBars as $pb) {
            $pbHigh = (float) ($pb['high'] ?? $pb['close'] ?? 0);
            $pbLow = (float) ($pb['low'] ?? $pb['close'] ?? 0);
            $pbRangePct = $pbLow > 0 ? (($pbHigh - $pbLow) / $pbLow) * 100 : 0;

            // Each consolidation bar must be tight (double allowance when --relaxed)
            if ($pbRangePct > $maxRangePct) {
                return [
                    'ok' => 0,
                    'best_entry' => null,
                    'reason' => 'consolidation_bar_too_wide',
                    'meta' => ['range_pct' => round($pbRangePct, 4)],
                ];
            }

            if ($pbHigh > $consolidationHigh) {
                $consolidationHigh = $pbHigh;
            }
            if ($pbLow < $consolidationLow) {
                $consolidationLow = $pbLow;
            }
        }

        // ── Step 5: Continuation breakout check ──────────────────────────────
        $greenBar = $currentClose > $currentOpen;

        if (! $greenBar) {
            return [
                'ok' => 0,
                'best_entry' => null,
                'reason' => 'breakout_bar_not_green',
            ];
        }

        // Breakout: current bar must break above consolidation high
        if ($currentHigh <= $consolidationHigh) {
            return [
                'ok' => 0,
                'best_entry' => null,
                'reason' => 'no_breakout_above_consolidation',
                'meta' => [
                    'current_high' => round($currentHigh, 4),
                    'consolidation_high' => round($consolidationHigh, 4),
                ],
            ];
        }

        // ── Step 6: Volume surge check ───────────────────────────────────────
        $priorVolumes = array_slice($volumes, 1, 10);
        $priorVolumes = array_filter($priorVolumes, fn ($v) => $v > 0);

        if (count($priorVolumes) < 3) {
            // Not enough volume data — fall back to a generous baseline
            $avgVolume = $currentVolume * 0.5;
        } else {
            $avgVolume = array_sum($priorVolumes) / count($priorVolumes);
        }

        $volumeRatio = $avgVolume > 0
            ? $currentVolume / $avgVolume
            : 0;

        $minVolRatio = $this->breakoutMinVolRatio();
        if ($volumeRatio < $minVolRatio) {
            return [
                'ok' => 0,
                'best_entry' => null,
                'reason' => 'breakout_volume_too_low',
                'meta' => [
                    'volume_ratio' => round($volumeRatio, 3),
                    'min_required' => $minVolRatio,
                ],
            ];
        }

        // ── Step 7: Multi-TF confirmation via 1-min aggregate ────────────────
        // Check VWAP alignment on the current bar and the 5-bar aggregate
        $vwap = isset($bars[0]['vwap']) && (float) $bars[0]['vwap'] > 0
            ? (float) $bars[0]['vwap']
            : null;

        $vwapDistPct = null;

        if ($vwap !== null && $vwap > 0) {
            // Current bar must be above VWAP
            if ($currentClose < $vwap) {
                return [
                    'ok' => 0,
                    'best_entry' => null,
                    'reason' => 'below_vwap',
                    'meta' => [
                        'close' => round($currentClose, 4),
                        'vwap' => round($vwap, 4),
                    ],
                ];
            }

            $vwapDistPct = (($currentClose - $vwap) / $vwap) * 100;

            // Not too extended (configurable, default 1.75% for continuation entries)
            if ($vwapDistPct > $this->maxVwapExtensionPct()) {
                return [
                    'ok' => 0,
                    'best_entry' => null,
                    'reason' => 'too_extended_above_vwap',
                    'meta' => ['vwap_dist_pct' => round($vwapDistPct, 4)],
                ];
            }
        }

        // Check shorter-term 3-bar EMA trend (computed from 1-min closes)
        $trendCloses = array_slice($closes, 0, 5);
        if (count($trendCloses) >= 3) {
            $ema3 = $this->ema($trendCloses, 3);
            $lastEma3 = $this->ema(array_slice($trendCloses, 1), 3);

            // EMA should be sloping up (current close above ema, and ema rising)
            $closeAboveEma = $currentClose > $ema3;
            $emaRising = $ema3 > $lastEma3;

            if (! $closeAboveEma || ! $emaRising) {
                return [
                    'ok' => 0,
                    'best_entry' => null,
                    'reason' => 'no_trend_alignment',
                    'meta' => [
                        'close_above_ema3' => $closeAboveEma,
                        'ema3_rising' => $emaRising,
                        'ema3' => round($ema3, 4),
                        'last_ema3' => round($lastEma3, 4),
                    ],
                ];
            }
        }

        // ── Step 8: Calculate entry price and stop ───────────────────────────
        $entryPrice = $currentClose;
        $stopPrice = max(0.01, min($consolidationLow, $currentLow) * 0.997);  // 0.3% buffer below support
        $riskPerShare = round($entryPrice - $stopPrice, 4);
        $riskPct = $entryPrice > 0 ? round(($riskPerShare / $entryPrice) * 100, 4) : 0;

        // Enforce max risk
        if ($riskPct > 1.90) {
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

        // ── Step 9: Compute score ────────────────────────────────────────────
        $score = $this->scoreEntry(
            volumeRatio: $volumeRatio,
            vwapDistPct: $vwapDistPct,
            riskPct: $riskPct,
            consolidationBars: count($preBreakoutBars),
        );

        $nowEst = CarbonImmutable::parse($asOfTsEst, 'America/New_York')
            ->format('Y-m-d H:i:s');

        $return1mPct = $currentOpen > 0
            ? (($currentClose - $currentOpen) / $currentOpen) * 100
            : 0;

        Log::info('[MomentumContinuation] ✅ Entry found', [
            'symbol' => $symbol,
            'entry_price' => round($entryPrice, 4),
            'stop' => $stopPrice,
            'risk_pct' => $riskPct,
            'score' => $score,
            'volume_ratio' => round($volumeRatio, 3),
            'vwap_dist_pct' => $vwapDistPct !== null ? round($vwapDistPct, 4) : null,
            'consolidation_bars' => count($preBreakoutBars),
        ]);

        return [
            'entry_ts_est' => $nowEst,
            'entry_price' => $entryPrice,
            'score' => $score,
            'reason' => 'momentum_continuation_breakout',
            'return_1m_pct' => $return1mPct,
            'move_since_candidate_pct' => 0,
            'vwap_dist_pct' => $vwapDistPct,
            'spread_pct' => 0,  // spread is checked before findEntry is called
        ];
    }

    /**
     * Simple EMA calculation using a single smoothing factor.
     *
     * @param  array<int, float>  $values  Most recent first.
     * @param  int  $period  EMA period.
     */
    private function ema(array $values, int $period): float
    {
        $values = array_reverse(array_values($values));
        $k = 2 / ($period + 1);
        $ema = $values[0];

        for ($i = 1; $i < count($values); $i++) {
            $ema = ($values[$i] * $k) + ($ema * (1 - $k));
        }

        return $ema;
    }

    /**
     * Score the entry quality (0-100).
     */
    private function scoreEntry(
        float $volumeRatio,
        ?float $vwapDistPct,
        float $riskPct,
        int $consolidationBars,
    ): float {
        $score = 60.0;  // Base: passed all structural gates

        // Volume bonus: +0-20 pts (scales 1.3x → 3.0x)
        $score += 20.0 * min(max(($volumeRatio - 1.3) / 1.7, 0), 1);

        // VWAP proximity bonus: +0-10 pts (closer to VWAP = better)
        if ($vwapDistPct !== null && $vwapDistPct > 0) {
            $score += 10.0 * max(0, 1 - ($vwapDistPct / 1.75));
        }

        // Risk efficiency bonus: +0-10 pts (tighter stops = higher confidence)
        $score += 10.0 * max(0, 1 - ($riskPct / 1.5));

        // Consolidation bonus: +0-5 pts (more consolidation bars = stronger base)
        $score += min(5, ($consolidationBars - 1) * 2.5);

        return round(min(100, max(0, $score)), 1);
    }
}
