<?php

namespace App\Services\Trading\Realtime;

use App\Models\RealtimeTradeCandidate;
use App\Services\TradingSettingService;
use Illuminate\Support\Facades\Log;

class EarlyCandidateDetectorService
{
    /**
     * Skip-reason counters for the current detection loop.
     * Reset via resetSkipCounters() between loops.
     *
     * @var array<string, int>
     */
    private array $skipReasons = [];

    public function __construct(
        private readonly RealtimeMarketDataService $marketData,
    ) {}

    /** Reset skip-reason counters at the start of a new loop. */
    public function resetSkipCounters(): void
    {
        $this->skipReasons = [];
    }

    /** @return array<string, int> */
    public function getSkipReasons(): array
    {
        return $this->skipReasons;
    }

    private function bumpSkipReason(string $reason): void
    {
        $this->skipReasons[$reason] = ($this->skipReasons[$reason] ?? 0) + 1;
    }

    public function detectForSymbol(string $symbol, string $assetType = 'stock'): ?RealtimeTradeCandidate
    {
        $symbol = strtoupper($symbol);

        // No Redis lock — detection is idempotent (read-only lookups,
        // write is insert-or-ignore on existing watching candidates).
        // The per-symbol Cache::lock() was adding ~5000 Redis roundtrips
        // per loop (~5-10s latency). In-memory caching in MarketDataService
        // already prevents duplicate DB queries within a loop.
        return $this->detectForSymbolLocked($symbol, $assetType);
    }

    /** Warm up the internal market data service caches with fresh data from MySQL. */
    public function warmUpCaches(array $symbols): void
    {
        $this->marketData->clearCache();
        $this->marketData->warmUpBars($symbols);
    }

    private function detectForSymbolLocked(string $symbol, string $assetType): ?RealtimeTradeCandidate
    {
        $quote = $this->marketData->latestQuote($symbol);
        $partial = $this->marketData->latestPartialOneMinuteBar($symbol);

        if (! $quote || ! $partial) {
            $this->bumpSkipReason('no_quote_or_partial');

            return null;
        }

        $quoteAge = $this->marketData->quoteAgeSeconds($quote);

        // Use the looser candidate-detection threshold here — the strict
        // max_quote_age_seconds gate is enforced again at actual order entry.
        if ($quoteAge === null || $quoteAge > (int) config('trading_realtime.max_candidate_quote_age_seconds', 60)) {
            $this->bumpSkipReason('quote_stale');

            return null;
        }

        $bid = (float) $quote['bid'];
        $ask = (float) $quote['ask'];

        if ($bid <= 0 || $ask <= 0 || $ask < $bid) {
            $this->bumpSkipReason('bad_bid_ask');

            return null;
        }

        $mid = ($bid + $ask) / 2;
        $spreadPct = (($ask - $bid) / $mid) * 100;

        if ($spreadPct > (float) config('trading_realtime.max_spread_pct', 0.35)) {
            $this->bumpSkipReason('wide_spread');

            return null;
        }

        $close = (float) $partial['close'];
        $open = (float) $partial['open'];
        $high = (float) ($partial['high'] ?? $close);
        $low = (float) ($partial['low'] ?? $close);
        $volume = (int) ($partial['volume'] ?? 0);

        if ($close <= 0 || $open <= 0 || $volume <= 0) {
            $this->bumpSkipReason('invalid_partial_bar');

            return null;
        }

        // ── Minimum price gate — filter out penny stocks ──────────────────────
        $minPrice = (float) config('trading_realtime.min_price', 5.0);
        if ($close < $minPrice) {
            $this->bumpSkipReason('price_too_low');

            return null;
        }

        $dollarVolume1m = $close * $volume;

        // ── Pipeline H/K/A gate: minimum dollar liquidity ────────────────────
        $minDollarVol = TradingSettingService::getRealtimeMinDollarVolume1m();
        if ($dollarVolume1m < $minDollarVol) {
            $this->bumpSkipReason('low_dollar_volume');

            return null;
        }

        // ── Pull computed indicators from Redis bar (set by Python stream) ───
        $atrPct = isset($partial['atr_pct']) ? (float) $partial['atr_pct'] : null;
        $rvol = isset($partial['rvol']) ? (float) $partial['rvol'] : null;
        $move30mPct = isset($partial['move_30m_pct']) ? (float) $partial['move_30m_pct'] : null;
        $ema9AboveEma21 = isset($partial['ema9_above_ema21']) ? (int) $partial['ema9_above_ema21'] : null;
        $aboveVwap = isset($partial['above_vwap']) ? (int) $partial['above_vwap'] : null;

        $vwap = isset($partial['vwap']) && (float) $partial['vwap'] > 0
            ? (float) $partial['vwap']
            : null;

        $vwapDistPct = isset($partial['vwap_dist_pct'])
            ? (float) $partial['vwap_dist_pct']
            : ($vwap ? (($close - $vwap) / $vwap) * 100 : null);

        // ── Pipeline H gate: minimum volatility (ATR%) ───────────────────────
        $minAtrPct = TradingSettingService::getRealtimeMinAtrPct();
        if ($atrPct !== null && $atrPct < $minAtrPct) {
            $this->bumpSkipReason('low_atr_pct');

            return null;
        }

        // ── Pipeline H gate: minimum relative volume ─────────────────────────
        $minRvol = TradingSettingService::getRealtimeMinRvol();
        if ($rvol !== null && $rvol < $minRvol) {
            $this->bumpSkipReason('low_rvol');

            return null;
        }

        // ── Pipeline H gate: minimum 30m momentum ────────────────────────────
        $minMove30m = TradingSettingService::getRealtimeMinMove30mPct();
        if ($move30mPct !== null && $move30mPct < $minMove30m) {
            $this->bumpSkipReason('low_move_30m');

            return null;
        }

        // ── Pipeline K gate: not too extended above VWAP ─────────────────────
        if ($vwapDistPct !== null && $vwapDistPct > TradingSettingService::getRealtimeMaxVwapExtensionPct()) {
            $this->bumpSkipReason('too_far_above_vwap');

            return null;
        }

        // ── Price structure gate: HH/HL over last 5 bars ─────────────────────
        if (! $this->hasHHHLStructure($symbol)) {
            $this->bumpSkipReason('no_hh_hl_structure');

            return null;
        }

        // ── Compute fallback 1m/3m returns for scoring + logging ─────────────
        $return1mPct = (($close - $open) / $open) * 100;
        $recentBars = $this->marketData->recentOneMinuteBars($symbol, 5);
        $return3mPct = $this->calculateReturnAcrossBars($recentBars, $close);
        $volumeRatio = $rvol ?? $this->calculateVolumeRatio($recentBars, $volume);

        $bidQty = (int) ($quote['bid_qty'] ?? 0);
        $askQty = (int) ($quote['ask_qty'] ?? 0);
        $imbalance = null;

        if (($bidQty + $askQty) > 0) {
            $imbalance = ($bidQty - $askQty) / ($bidQty + $askQty);
        }

        // ── Score using Pipeline H/K/A quality metrics ───────────────────────
        $earlyScore = $this->score([
            'rvol' => $rvol ?? $volumeRatio,
            'move_30m_pct' => $move30mPct ?? $return3mPct,
            'atr_pct' => $atrPct,
            'above_vwap' => $aboveVwap,
            'ema9_above_ema21' => $ema9AboveEma21,
            'vwap_dist_pct' => $vwapDistPct,
            'spread_pct' => $spreadPct,
            'bid_ask_imbalance' => $imbalance,
        ]);

        if ($earlyScore < (float) config('trading_realtime.early_score_min', 55)) {
            $this->bumpSkipReason('score_too_low');

            if (($this->skipReasons['score_too_low'] ?? 0) % 50 === 1) {
                Log::info('[CandidateDetect] Score too low (sample)', [
                    'symbol' => $symbol,
                    'early_score' => $earlyScore,
                    'rvol' => $rvol,
                    'move_30m_pct' => $move30mPct,
                    'atr_pct' => $atrPct,
                ]);
            }

            return null;
        }

        $existing = RealtimeTradeCandidate::query()
            ->where('symbol', $symbol)
            ->where('status', 'watching')
            ->first();

        if ($existing) {
            return $existing;
        }

        // Cooldown: if a candidate for this symbol was already triggered (or rejected)
        // within the cooldown window, do not create another one. This prevents
        // the same signal from spawning a new candidate — and therefore a new alert —
        // every loop iteration after the first one fires.
        $cooldownSeconds = (int) config('trading_realtime.candidate_cooldown_seconds', 300);
        if ($cooldownSeconds > 0) {
            $cooldownCutoff = now('America/New_York')->subSeconds($cooldownSeconds)->format('Y-m-d H:i:s');
            $recentTriggered = RealtimeTradeCandidate::query()
                ->where('symbol', $symbol)
                ->whereIn('status', ['triggered', 'rejected'])
                ->where('detected_ts_est', '>=', $cooldownCutoff)
                ->exists();

            if ($recentTriggered) {
                $this->bumpSkipReason('candidate_cooldown');

                return null;
            }
        }

        $nowEst = method_exists($this->marketData, 'getSimulatedNow') && $this->marketData->getSimulatedNow()
            ? $this->marketData->getSimulatedNow()
            : now('America/New_York')->format('Y-m-d H:i:s');

        $candidate = RealtimeTradeCandidate::query()->create([
            'symbol' => $symbol,
            'asset_type' => $assetType,
            'detected_ts_est' => $nowEst,
            'stale_seconds' => 0,
            'detected_price' => $ask,

            'bid' => $bid,
            'ask' => $ask,
            'bid_qty' => $bidQty,
            'ask_qty' => $askQty,
            'spread_pct' => $spreadPct,

            'partial_open' => $open,
            'partial_high' => $high,
            'partial_low' => $low,
            'partial_close' => $close,
            'partial_volume' => $volume,

            'vwap' => $vwap,
            'vwap_dist_pct' => $vwapDistPct,

            'return_1m_pct' => $return1mPct,
            'return_3m_pct' => $return3mPct,
            'volume_ratio' => $volumeRatio,
            'dollar_volume_1m' => $dollarVolume1m,
            'bid_ask_imbalance' => $imbalance,

            'atr_pct' => $atrPct,
            'rvol' => $rvol,
            'move_30m_pct' => $move30mPct,
            'ema9_above_ema21' => $ema9AboveEma21,

            'early_score' => $earlyScore,
            'status' => 'watching',
        ]);

        Log::info('Realtime candidate created', [
            'candidate_id' => $candidate->id,
            'symbol' => $symbol,
            'early_score' => $earlyScore,
            'rvol' => $rvol,
            'move_30m_pct' => $move30mPct,
            'atr_pct' => $atrPct,
            'above_vwap' => $aboveVwap,
            'spread_pct' => $spreadPct,
            'vwap_dist_pct' => $vwapDistPct,
        ]);

        return $candidate;
    }

    /**
     * Score using Pipeline H/K/A quality metrics.
     *
     * Max 100 pts:
     *   30 — RVOL   (real volume surge, caps at 5x)
     *   25 — 30m move (real momentum, caps at 3%)
     *   20 — ATR%   (volatility = room to run, caps at 1.5%)
     *   15 — trend  (above VWAP + EMA9 > EMA21)
     *   10 — order book buy-side imbalance
     *  -15 — spread penalty (proportional to max allowed)
     *  -10 — VWAP over-extension penalty (>1% above VWAP)
     *
     * @param  array<string, float|int|null>  $x
     */

    /**
     * Check that the symbol shows a clear higher-high / higher-low structure
     * over the last 5 one-minute bars. Uses the RealtimeMarketDataService which
     * reads from Redis/MySQL so this works in both live and backtest modes.
     */
    private function hasHHHLStructure(string $symbol): bool
    {
        $recentBars = $this->marketData->recentOneMinuteBars($symbol, 5);

        if ($recentBars === null || count($recentBars) < 3) {
            return false;
        }

        $bars = array_values($recentBars);

        // Count bars where high is not lower than previous (HH candidate)
        // and low is not lower than previous (HL candidate).
        // Require at least half of pairs to show rising structure.
        $risingHighs = 0;
        $risingLows = 0;
        $pairs = count($bars) - 1;

        for ($i = 1; $i <= $pairs; $i++) {
            $prevHigh = (float) ($bars[$i - 1]['high'] ?? $bars[$i - 1]['close'] ?? 0);
            $currHigh = (float) ($bars[$i]['high'] ?? $bars[$i]['close'] ?? 0);
            $prevLow = (float) ($bars[$i - 1]['low'] ?? $bars[$i - 1]['close'] ?? 0);
            $currLow = (float) ($bars[$i]['low'] ?? $bars[$i]['close'] ?? 0);

            if ($currHigh > $prevHigh) {
                $risingHighs++;
            }
            if ($currLow > $prevLow) {
                $risingLows++;
            }
        }

        // Majorité des paires doivent montrer une tendance haussière
        // Sauf si --relaxed (backtest) où on accepte une seule paire haussière
        $isRelaxed = (bool) config('trading_realtime.relaxed_hh_hl', false);
        $minRising = $isRelaxed ? 1 : (int) ceil($pairs * 0.5);
        if ($risingHighs < $minRising || $risingLows < $minRising) {
            $this->bumpSkipReason('no_hh_hl_structure');

            return false;
        }

        return true;
    }

    private function score(array $x): float
    {
        $score = 0.0;

        // RVOL: 30 pts, scales 1x→5x
        $rvol = (float) ($x['rvol'] ?? 1.0);
        $score += 30.0 * min(max(($rvol - 1.0) / 4.0, 0), 1);

        // 30m move: 25 pts, scales 0.5%→3%
        $move30m = (float) ($x['move_30m_pct'] ?? 0);
        $score += 25.0 * min(max(($move30m - 0.5) / 2.5, 0), 1);

        // ATR%: 20 pts, scales 0.25%→1.5%
        $atrPct = (float) ($x['atr_pct'] ?? 0);
        $score += 20.0 * min(max(($atrPct - 0.25) / 1.25, 0), 1);

        // Trend confirmation: 15 pts
        $aboveVwap = (int) ($x['above_vwap'] ?? 0);
        $ema9Above = (int) ($x['ema9_above_ema21'] ?? 0);
        $score += 8.0 * $aboveVwap;
        $score += 7.0 * $ema9Above;

        // Order book imbalance: 10 pts (buy-side pressure)
        $imbalance = $x['bid_ask_imbalance'] ?? null;
        if ($imbalance !== null) {
            $score += 10.0 * min(max(((float) $imbalance) / 0.50, 0), 1);
        }

        // Spread penalty: -15 pts (proportional)
        $spreadPct = (float) ($x['spread_pct'] ?? 0);
        $maxSpread = (float) config('trading_realtime.max_spread_pct', 0.35);
        if ($spreadPct > 0 && $maxSpread > 0) {
            $score -= 15.0 * min(max($spreadPct / $maxSpread, 0), 1);
        }

        // VWAP over-extension penalty: -10 pts (only if >1% above VWAP)
        $vwapDistPct = $x['vwap_dist_pct'] ?? null;
        if ($vwapDistPct !== null && (float) $vwapDistPct > 1.0) {
            $score -= 10.0 * min(max(((float) $vwapDistPct - 1.0) / 1.5, 0), 1);
        }

        return round(max(0, min(100, $score)), 4);
    }

    private function calculateVolumeRatio(array $recentBars, int $currentVolume): float
    {
        if (count($recentBars) < 3) {
            return 1.0;
        }

        $volumes = array_map(static fn ($bar) => (float) ($bar['volume'] ?? 0), $recentBars);
        $volumes = array_filter($volumes, static fn ($v) => $v > 0);

        if (count($volumes) === 0) {
            return 1.0;
        }

        $avg = array_sum($volumes) / count($volumes);

        return $avg > 0 ? $currentVolume / $avg : 1.0;
    }

    private function calculateReturnAcrossBars(array $recentBars, float $currentClose): float
    {
        if (count($recentBars) < 3) {
            return 0.0;
        }

        $first = $recentBars[0]['open']
            ?? $recentBars[0]['price']
            ?? $recentBars[0]['close']
            ?? null;

        if (! $first || (float) $first <= 0) {
            return 0.0;
        }

        return (($currentClose - (float) $first) / (float) $first) * 100;
    }
}
