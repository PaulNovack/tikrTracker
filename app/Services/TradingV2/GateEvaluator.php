<?php

namespace App\Services\TradingV2;

use App\Services\TradingV2\Contracts\BarSourceInterface;
use App\Services\TradingV2\DTOs\BarGates;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Computes ALL 5m and 1m gates from bar data (Redis or MySQL).
 *
 * Uses BarSourceInterface — inject RedisBarSource for live,
 * MySqlBarSource for backtests.
 *
 * Called once per bar event per symbol. All gate values flow to
 * EvaluateBarJob for per-version threshold checking.
 *
 * Formulas sourced from COMPREHENSIVE_GATE_LIST.md and the existing
 * FiveMinuteSignalScanner / OneMinuteEntryFinder implementations.
 */
class GateEvaluator
{
    private const BENCHMARK_SYMBOL = 'QQQM';

    public function __construct(
        private readonly BarSourceInterface $bars,
    ) {}

    // ──────────────────────────────────────────────────
    // 5-MINUTE GATES
    // ──────────────────────────────────────────────────

    /**
     * Compute ALL 5m gates for one symbol. Returns a BarGates DTO.
     * Called once per 5m_bar event.
     */
    public function evaluate5m(string $symbol, string $asOfTsEst): BarGates
    {
        $rawBars = $this->bars->getBars('5m', $symbol, $asOfTsEst, 90, 200);

        if (count($rawBars) < 8) {
            return BarGates::empty('5m');
        }

        // Normalize to arrays for formula access
        $bars = array_map(fn ($b) => [
            'open' => $b->open, 'high' => $b->high, 'low' => $b->low,
            'close' => $b->close, 'volume' => $b->volume, 'vwap' => $b->vwap ?? $b->close,
            'ts' => $b->tsEst,
        ], $rawBars);

        $count = count($bars);
        $last = $bars[$count - 1];
        $close = $last['close'];
        $benchmarkMove = $this->getBenchmarkMove15m($asOfTsEst);

        return new BarGates('5m', [
            // ── Liquidity ──
            'notional' => round($close * $last['volume'], 2),
            'price' => round($close, 2),

            // ── Volatility (ATR-14) ──
            'atr' => round($this->computeAtr($bars, 14), 6),
            'atr_pct' => round($this->computeAtrPct($bars, 14), 4),

            // ── Activity (RVOL-20) ──
            'rvol_ratio' => round($this->computeRvol($bars, 20), 3),

            // ── Momentum ──
            'move_30m_pct' => round($this->computeMove($bars, 6), 4),
            'move_from_open_pct' => round($this->computeMoveFromOpen($bars), 4),
            'net_progress_pct' => round($this->computeNetProgress($bars), 4),
            'three_bar_gain_pct' => round($this->computeThreeBarGain($bars), 4),

            // ── EMA (9/21) ──
            'ema9_above_ema21' => (int) $this->computeEmaCrossover($bars, 9, 21),
            'ema9_slope_positive' => (int) $this->computeEmaSlopePositive($bars),
            'ema_spread_pct' => round($this->computeEmaSpread($bars), 4),

            // ── VWAP ──
            'above_vwap' => (int) ($close > ($last['vwap'] ?? $close)),
            'above_vwap_pct' => round((($close - ($last['vwap'] ?? $close)) / ($last['vwap'] ?: $close)) * 100, 4),
            'vwap_distance_min' => round((($close - ($last['vwap'] ?? $close)) / ($last['vwap'] ?: $close)) * 100, 4),
            'max_above_vwap_pct' => round((($close - ($last['vwap'] ?? $close)) / ($last['vwap'] ?: $close)) * 100, 4),
            'vwap_violation_count' => $this->countVwapViolations($bars),
            'distance_from_ema9_atr' => round($this->computeDistFromEma9Atr($bars), 4),

            // ── Candle / Bar Quality ──
            'green_close' => (int) ($close > $last['open']),
            'green_bar_pct' => round($this->computeGreenPct($bars), 2),

            // ── Pattern ──
            'directional_changes' => $this->countDirectionalChanges($bars),
            'higher_low_count' => $this->countHigherLows($bars, 5),
            'pullback_depth_pct' => round($this->computePullbackDepthPct($bars), 4),
            'range_contraction' => (int) $this->isRangeContracting($bars, 5),
            'closes_near_high_count' => $this->countClosesNearHigh($bars),
            'distance_from_high_atr' => round($this->computeDistFromHighAtr($bars), 4),
            'dist_to_hod_pct' => round($this->computeDistToHod($bars), 4),
            'opening_range_width_pct' => round($this->computeOpeningRangeWidth($bars), 4),
            'opening_range_bar_count' => $this->countOpeningRangeBars($bars),

            // ── RSI-14 ──
            'rsi' => round($this->computeRsi($bars, 14), 2),

            // ── Relative Strength vs Benchmark ──
            'rs_ratio' => round($this->computeRsRatio($bars, 6, $benchmarkMove), 4),

            // ── Market Context ──
            'benchmark_move_15m' => $benchmarkMove,
            'benchmark_below_vwap' => (int) ($benchmarkMove < 0),
            'market_weakness' => (int) ($benchmarkMove < -0.15),

            // ── Daily Data (needed by pipelines A, B, F, P, Q) ──
            ...$this->fetchDailyData($symbol, $asOfTsEst),

            // ── Time ──
            'signal_age_seconds' => 0,
            'min_bars' => $count,
        ]);
    }

    // ──────────────────────────────────────────────────
    // 1-MINUTE GATES
    // ──────────────────────────────────────────────────

    /**
     * Compute ALL 1m gates for one symbol. Returns a BarGates DTO.
     * Called once per 1m_bar event.
     */
    public function evaluate1m(string $symbol, string $asOfTsEst): BarGates
    {
        $rawBars = $this->bars->getBars('1m', $symbol, $asOfTsEst, 420, 420);

        if (count($rawBars) < 2) {
            return BarGates::empty('1m');
        }

        // Incremental VWAP + EMA (matches OneMinuteEntryFinderV25_2)
        $cumPV = 0.0;
        $cumV = 0.0;
        $ema9 = null;
        $ema21 = null;
        $k9 = 2.0 / 10;
        $k21 = 2.0 / 22;
        $hod = 0.0;
        $atrVals = [];

        $bars = [];
        foreach ($rawBars as $i => $r) {
            $o = (float) $r->open;
            $h = (float) $r->high;
            $l = (float) $r->low;
            $c = (float) $r->close;
            $v = (float) $r->volume;

            if ($h > $hod) {
                $hod = $h;
            }
            $typ = ($h + $l + $c) / 3.0;
            if ($v > 0) {
                $cumPV += $typ * $v;
                $cumV += $v;
            }
            $vwap = ($cumV > 0) ? ($cumPV / $cumV) : $c;
            $ema9 = ($ema9 === null) ? $c : (($c * $k9) + ($ema9 * (1 - $k9)));
            $ema21 = ($ema21 === null) ? $c : (($c * $k21) + ($ema21 * (1 - $k21)));

            if ($i > 0) {
                $prevC = (float) $rawBars[$i - 1]->close;
                $atrVals[] = max($h - $l, abs($h - $prevC), abs($l - $prevC));
            }

            $bars[] = ['open' => $o, 'high' => $h, 'low' => $l, 'close' => $c, 'volume' => $v, 'vwap' => $vwap, 'ema9' => $ema9, 'ema21' => $ema21];
        }

        $count = count($bars);
        $last = $bars[$count - 1];
        $close = $last['close'];
        $open = $last['open'];
        $high = $last['high'];
        $low = $last['low'];
        $range = max($high - $low, 0.01);

        // ATR from TR values (matches V1)
        $atr = 0.0;
        if (count($atrVals) >= 14) {
            $atr = array_sum(array_slice($atrVals, -14)) / 14;
        } elseif (count($atrVals) > 0) {
            $atr = array_sum($atrVals) / count($atrVals);
        }

        // Volume ratio: last vol / avg of prior bars (excludes last bar, matches V1)
        $priorVols = array_slice(array_column($bars, 'volume'), max(0, $count - 21), min(20, $count - 1));
        $avgVolOther = count($priorVols) > 1 ? (array_sum($priorVols) / count($priorVols)) : $last['volume'];
        $volRatio = $avgVolOther > 0 ? $last['volume'] / $avgVolOther : 1.0;

        return new BarGates('1m', [
            // ── Liquidity ──
            'notional_1m' => round($close * $last['volume'], 2),

            // ── Volume (V1 matching: excludes last bar) ──
            'vol_ratio_1m' => round($volRatio, 3),

            // ── Candle Quality ──
            'body_pct' => round(abs($close - $open) / $range, 4),
            'close_position' => round(($close - $low) / $range, 4),
            'upper_wick_fraction' => round(($high - max($close, $open)) / $range, 4),

            // ── VWAP (incremental, matches V1) ──
            'above_vwap_entry_pct' => $last['vwap'] != 0 ? round((($close - $last['vwap']) / $last['vwap']) * 100, 4) : 0,

            // ── Room to Run ──
            'room_to_hod_pct' => $close != 0 ? round((($hod - $close) / $close) * 100, 4) : 0,

            // ── EMA Alignment (incremental, matches V1) ──
            'ema9_above_ema21_1m' => (int) ($last['ema9'] > $last['ema21']),
            'ema9' => round($last['ema9'], 4),
            'ema21' => round($last['ema21'], 4),
            'ema_spread_pct' => $last['ema21'] != 0 ? round((($last['ema9'] - $last['ema21']) / $last['ema21']) * 100, 4) : 0,

            // ── RSI-14 (matches V1 formula) ──
            'rsi' => round($this->computeRsi($bars, 14), 2),

            // ── Session high ──
            'hod' => round($hod, 2),

            // ── Notional ──
            'notional' => round($close * $last['volume'], 2),

            // ── Time / Data Quality ──
            'min_bars' => $count,
            'extreme_drop' => (int) $this->hasExtremeDrop($bars),
            'time_blocked' => (int) $this->isLunchWindow($asOfTsEst),

            // ── ATR (matches V1 TR calculation) ──
            'price' => round($close, 2),
            'atr_1m' => round($atr, 6),
            'atr_pct' => $close > 0 ? round(($atr / $close) * 100, 4) : 0,
        ]);
    }

    // ══════════════════════════════════════════════════
    // FORMULA IMPLEMENTATIONS
    // ══════════════════════════════════════════════════

    /**
     * ATR (Average True Range) over $period bars.
     * True Range = max(high−low, |high−prevClose|, |low−prevClose|)
     *
     * @param  list<array{high: float, low: float, close: float}>  $bars
     */
    public function computeAtr(array $bars, int $period = 14): float
    {
        $trs = [];
        for ($i = 1, $n = count($bars); $i < $n; $i++) {
            $prevC = $bars[$i - 1]['close'];
            $h = $bars[$i]['high'];
            $l = $bars[$i]['low'];
            $trs[] = max($h - $l, abs($h - $prevC), abs($l - $prevC));
        }
        $slice = array_slice($trs, max(0, count($trs) - $period));

        return count($slice) > 0 ? array_sum($slice) / count($slice) : 0.0;
    }

    /**
     * ATR as percentage of current price.
     */
    public function computeAtrPct(array $bars, int $period = 14): float
    {
        $atr = $this->computeAtr($bars, $period);
        $close = end($bars)['close'] ?? 0;

        return $close > 0 ? ($atr / $close) * 100 : 0.0;
    }

    /**
     * RVOL: latest volume / average volume over $lookback bars.
     */
    public function computeRvol(array $bars, int $lookback = 20): float
    {
        $count = count($bars);
        $slice = array_slice($bars, max(0, $count - $lookback - 1), $lookback);
        if (count($slice) === 0) {
            return 1.0;
        }
        $avgVol = array_sum(array_column($slice, 'volume')) / count($slice);

        return $avgVol > 0 ? ($bars[$count - 1]['volume'] ?? 0) / $avgVol : 1.0;
    }

    /**
     * N-bar percentage move: (close − close[N−1]) / close[N−1] × 100.
     * Default N=6 gives ~30-minute move on 5m bars.
     */
    public function computeMove(array $bars, int $nBars = 6): float
    {
        $count = count($bars);
        $idx = $count - 1 - $nBars;
        if ($idx < 0 || ! isset($bars[$idx]['close']) || $bars[$idx]['close'] <= 0) {
            return 0.0;
        }

        return (($bars[$count - 1]['close'] - $bars[$idx]['close']) / $bars[$idx]['close']) * 100;
    }

    /**
     * EMA crossover: EMA(fast) > EMA(slow) on the last bar.
     */
    public function computeEmaCrossover(array $bars, int $fast = 9, int $slow = 21): bool
    {
        $emaFast = $this->computeEma($bars, $fast);
        $emaSlow = $this->computeEma($bars, $slow);

        return $emaFast > $emaSlow;
    }

    /**
     * EMA slope: is the fast EMA rising over the last $lookback bars?
     */
    public function computeEmaSlopePositive(array $bars, int $lookback = 5): bool
    {
        $count = count($bars);
        if ($count < $lookback + 9) {
            return false;
        }
        $ema9Recent = $this->computeEma(array_slice($bars, -$lookback), 9);
        $ema9Older = $this->computeEma(array_slice($bars, -$lookback - 5, $lookback), 9);

        return $ema9Recent > $ema9Older;
    }

    /**
     * EMA spread: (EMA9 − EMA21) / EMA21 × 100.
     */
    public function computeEmaSpread(array $bars): float
    {
        $ema9 = $this->computeEma($bars, 9);
        $ema21 = $this->computeEma($bars, 21);

        return $ema21 > 0 ? (($ema9 - $ema21) / $ema21) * 100 : 0.0;
    }

    /**
     * Exponential Moving Average.
     */
    public function computeEma(array $bars, int $period): float
    {
        $k = 2.0 / ($period + 1);
        $ema = null;
        foreach ($bars as $bar) {
            $c = $bar['close'];
            $ema = ($ema === null) ? $c : ($c * $k) + ($ema * (1 - $k));
        }

        return $ema ?? 0.0;
    }

    /**
     * RSI-14 (Wilder's smoothing).
     */
    public function computeRsi(array $bars, int $period = 14): float
    {
        $count = count($bars);
        if ($count < $period + 1) {
            return 50.0;
        }
        $gains = $losses = 0.0;
        for ($i = 1; $i <= $period; $i++) {
            $diff = $bars[$count - $period + $i - 1]['close'] - $bars[$count - $period + $i - 2]['close'];
            $gains += max(0, $diff);
            $losses += max(0, -$diff);
        }
        $avgGain = $gains / $period;
        $avgLoss = $losses / $period;

        return $avgLoss > 0 ? 100 - (100 / (1 + ($avgGain / $avgLoss))) : 100.0;
    }

    /**
     * % of bars that are green (close > open).
     */
    public function computeGreenPct(array $bars): float
    {
        if (count($bars) === 0) {
            return 0.0;
        }
        $green = count(array_filter($bars, fn ($b) => ($b['close'] ?? 0) > ($b['open'] ?? 0)));

        return ($green / count($bars)) * 100;
    }

    /**
     * Count direction changes in the price series (choppiness proxy).
     */
    public function countDirectionalChanges(array $bars): int
    {
        $changes = 0;
        $prevDir = null;
        for ($i = 1, $n = count($bars); $i < $n; $i++) {
            $dir = $bars[$i]['close'] <=> $bars[$i - 1]['close'];
            if ($prevDir !== null && $dir !== 0 && $dir !== $prevDir) {
                $changes++;
            }
            if ($dir !== 0) {
                $prevDir = $dir;
            }
        }

        return $changes;
    }

    /**
     * Count consecutive higher lows in the most recent N bars.
     */
    public function countHigherLows(array $bars, int $lookback = 5): int
    {
        $count = count($bars);
        $slice = array_slice($bars, max(0, $count - $lookback));
        $higherLowCount = 0;
        for ($i = 1, $n = count($slice); $i < $n; $i++) {
            if (($slice[$i]['low'] ?? 0) > ($slice[$i - 1]['low'] ?? 0)) {
                $higherLowCount++;
            }
        }

        return $higherLowCount;
    }

    /**
     * Count bars closing near their highs (close ≥ high − 30% of range).
     */
    public function countClosesNearHigh(array $bars): int
    {
        $count = 0;
        foreach ($bars as $b) {
            $range = ($b['high'] ?? 0) - ($b['low'] ?? 0);
            if ($range > 0 && ($b['close'] ?? 0) >= ($b['high'] ?? 0) - ($range * 0.3)) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * Count bars where close fell below VWAP.
     */
    public function countVwapViolations(array $bars): int
    {
        $count = 0;
        foreach ($bars as $b) {
            if (($b['close'] ?? 0) < ($b['vwap'] ?? $b['close'])) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * Distance from close to EMA9 in ATR multiples.
     */
    public function computeDistFromEma9Atr(array $bars): float
    {
        $ema9 = $this->computeEma($bars, 9);
        $close = end($bars)['close'] ?? 0;
        $atr = $this->computeAtr($bars);

        return $atr > 0 ? abs($close - $ema9) / $atr : 0.0;
    }

    /**
     * Distance from close to session high in ATR multiples.
     */
    public function computeDistFromHighAtr(array $bars): float
    {
        $count = count($bars);
        $sessionHigh = 0.0;
        for ($i = max(0, $count - 20); $i < $count; $i++) {
            $sessionHigh = max($sessionHigh, $bars[$i]['high'] ?? 0);
        }
        $close = end($bars)['close'] ?? 0;
        $atr = $this->computeAtr($bars);

        return $atr > 0 ? ($sessionHigh - $close) / $atr : 999.0;
    }

    /**
     * Distance to session high as percentage.
     */
    public function computeDistToHod(array $bars): float
    {
        $count = count($bars);
        $hod = 0.0;
        for ($i = max(0, $count - 20); $i < $count; $i++) {
            $hod = max($hod, $bars[$i]['high'] ?? 0);
        }
        $close = end($bars)['close'] ?? 0;

        return $hod > 0 ? (($hod - $close) / $hod) * 100 : 0.0;
    }

    /**
     * Net progress: (latest close − earliest close) / earliest close × 100
     * over the window of bars provided.
     */
    public function computeNetProgress(array $bars): float
    {
        if (count($bars) < 2) {
            return 0.0;
        }
        $first = $bars[0]['close'] ?? 0;
        $last = end($bars)['close'] ?? 0;

        return $first > 0 ? (($last - $first) / $first) * 100 : 0.0;
    }

    /**
     * Pullback depth: largest % decline from a peak in the recent window.
     */
    public function computePullbackDepthPct(array $bars): float
    {
        $count = count($bars);
        if ($count < 2) {
            return 0.0;
        }
        $peak = 0.0;
        $maxDrawdown = 0.0;
        foreach ($bars as $b) {
            $c = $b['close'] ?? 0;
            $peak = max($peak, $c);
            if ($peak > 0) {
                $drawdown = ($peak - $c) / $peak * 100;
                $maxDrawdown = max($maxDrawdown, $drawdown);
            }
        }

        return $maxDrawdown;
    }

    /**
     * Is the range tightening over the last N bars?
     */
    public function isRangeContracting(array $bars, int $lookback = 5): bool
    {
        $count = count($bars);
        if ($count < $lookback * 2) {
            return false;
        }
        $recent = array_slice($bars, -$lookback);
        $prior = array_slice($bars, -$lookback * 2, $lookback);

        $recentRange = $this->avgRange($recent);
        $priorRange = $this->avgRange($prior);

        return $priorRange > 0 && $recentRange < $priorRange;
    }

    private function avgRange(array $bars): float
    {
        if (count($bars) === 0) {
            return 0.0;
        }
        $sum = 0.0;
        foreach ($bars as $b) {
            $sum += ($b['high'] ?? 0) - ($b['low'] ?? 0);
        }

        return $sum / count($bars);
    }

    /**
     * RS ratio: symbol's move / benchmark's move.
     */
    public function computeRsRatio(array $bars, int $nBars, float $benchmarkMove): float
    {
        $symbolMove = $this->computeMove($bars, $nBars);

        return abs($benchmarkMove) > 0.01 ? $symbolMove / $benchmarkMove : 0.0;
    }

    /**
     * Move from the first bar's open to current close, as a percentage.
     */
    public function computeMoveFromOpen(array $bars): float
    {
        if (count($bars) < 2) {
            return 0.0;
        }
        $firstOpen = $bars[0]['open'] ?? $bars[0]['close'] ?? 0;
        $lastClose = $bars[count($bars) - 1]['close'] ?? 0;

        return $firstOpen > 0 ? (($lastClose - $firstOpen) / $firstOpen) * 100 : 0.0;
    }

    /**
     * Three-bar gain: (close[0] - open[2]) / open[2] * 100.
     * Used by pipeline N (TWO_BAR_MOMENTUM).
     */
    public function computeThreeBarGain(array $bars): float
    {
        $count = count($bars);
        if ($count < 3) {
            return 0.0;
        }
        $open3 = $bars[$count - 3]['open'] ?? 0;
        $close0 = $bars[$count - 1]['close'] ?? 0;

        return $open3 > 0 ? (($close0 - $open3) / $open3) * 100 : 0.0;
    }

    /**
     * Opening range width: (OR high - OR low) / OR low * 100.
     * Uses first N bars of the day (default 5).
     */
    public function computeOpeningRangeWidth(array $bars, int $orBars = 5): float
    {
        if (count($bars) < $orBars) {
            return 0.0;
        }
        $orBars = array_slice($bars, 0, $orBars);
        $orHigh = max(array_column($orBars, 'high'));
        $orLow = min(array_column($orBars, 'low'));

        return $orLow > 0 ? (($orHigh - $orLow) / $orLow) * 100 : 0.0;
    }

    /**
     * Number of bars in the opening range window.
     * Always returns $orBars if enough bars exist.
     */
    public function countOpeningRangeBars(array $bars, int $orBars = 5): int
    {
        return count($bars) >= $orBars ? $orBars : count($bars);
    }

    /**
     * Fetch daily-price-derived gates for universe pre-filtering.
     *
     * Computes yesterday_move_pct, yesterday_vol_mult, and multi_day_green_count
     * from the daily_prices table — matching V1 scanner universe filters.
     *
     * Results are cached per symbol+date for the lifetime of the request.
     *
     * @return array<string, float|int>
     */
    /**
     * Redis-cached daily-price-derived gates for universe pre-filtering.
     *
     * Uses a bulk preload pattern: on first call, loads ALL symbols' daily data
     * for the given trade date into a Redis hash, then reads from the hash.
     *
     * Redis key: rt:daily:gates:{tradeDate}
     * TTL: 1 hour (long enough for a backtest run, short enough to not waste memory)
     *
     * @return array<string, float|int>
     */
    private function fetchDailyData(string $symbol, string $asOfTsEst): array
    {
        $tradeDate = substr($asOfTsEst, 0, 10);
        $redisKey = "rt:daily:gates:{$tradeDate}";

        // Try Redis hash read first
        try {
            $cached = Cache::store('redis')->get($redisKey);
            if (is_array($cached) && isset($cached[$symbol])) {
                return $cached[$symbol];
            }
        } catch (\Throwable) {
            // Redis unavailable — fall through to direct DB
        }

        // Bulk preload ALL symbols' daily data into Redis
        try {
            $allData = $this->bulkLoadDailyData($tradeDate);
            try {
                Cache::store('redis')->put($redisKey, $allData, 3600);
            } catch (\Throwable) {
                // Redis write failed but we still have the data
            }

            return $allData[$symbol] ?? [
                'yesterday_move_pct' => 0.0,
                'yesterday_vol_mult' => 0.0,
                'multi_day_green_count' => 0,
                'require_vol_increase' => 0,
            ];
        } catch (\Throwable) {
            return [
                'yesterday_move_pct' => 0.0,
                'yesterday_vol_mult' => 0.0,
                'multi_day_green_count' => 0,
                'require_vol_increase' => 0,
            ];
        }
    }

    /**
     * Bulk load daily data for ALL symbols with data on the given trade date.
     *
     * Three queries total (not per-symbol):
     * 1. Yesterday's price/volume/move data
     * 2. 5-day average volume per symbol
     * 3. Multi-day green close count (past 10 days)
     *
     * @return array<string, array{yesterday_move_pct: float, yesterday_vol_mult: float, multi_day_green_count: int, require_vol_increase: int}>
     */
    public function bulkLoadDailyData(string $tradeDate): array
    {
        // ── Query 1: yesterday's data per symbol ──
        $yestRows = DB::select('
            SELECT d1.symbol,
                   d1.open,
                   d1.price AS close,
                   d1.volume,
                   ROUND((d1.price - d1.open) / d1.open * 100, 2) AS move_pct
            FROM daily_prices d1
            INNER JOIN (
                SELECT symbol, MAX(date) AS prev_date
                FROM daily_prices
                WHERE date < ?
                GROUP BY symbol
            ) d2 ON d1.symbol = d2.symbol AND d1.date = d2.prev_date
        ', [$tradeDate]);

        // ── Query 2: 5-day average volume per symbol ──
        $avgVols = DB::select('
            SELECT symbol, AVG(volume) AS avg_vol
            FROM (
                SELECT symbol, volume,
                       ROW_NUMBER() OVER (PARTITION BY symbol ORDER BY date DESC) AS rn
                FROM daily_prices
                WHERE date < ?
            ) sub
            WHERE rn <= 5
            GROUP BY symbol
        ', [$tradeDate]);

        $avgVolMap = [];
        foreach ($avgVols as $row) {
            $avgVolMap[$row->symbol] = (float) $row->avg_vol;
        }

        // ── Query 3: multi-day green count (past 10 days) ──
        $greenCounts = DB::select('
            SELECT symbol, COUNT(*) AS green_days
            FROM (
                SELECT symbol, price, open,
                       ROW_NUMBER() OVER (PARTITION BY symbol ORDER BY date DESC) AS rn
                FROM daily_prices
                WHERE date < ?
            ) sub
            WHERE rn <= 10 AND price > open
            GROUP BY symbol
        ', [$tradeDate]);

        $greenMap = [];
        foreach ($greenCounts as $row) {
            $greenMap[$row->symbol] = (int) $row->green_days;
        }

        // ── Assemble per-symbol result ──
        $result = [];
        foreach ($yestRows as $row) {
            $avgVol = $avgVolMap[$row->symbol] ?? 0.0;
            $volMult = ($avgVol > 0 && $row->volume > 0)
                ? round($row->volume / $avgVol, 2)
                : 0.0;

            $result[$row->symbol] = [
                'yesterday_move_pct' => (float) $row->move_pct,
                'yesterday_vol_mult' => $volMult,
                'multi_day_green_count' => $greenMap[$row->symbol] ?? 0,
                'require_vol_increase' => $volMult >= 1.3 ? 1 : 0,
            ];
        }

        return $result;
    }

    /**
     * Has an extreme drop (>50%) occurred between any two consecutive bars?
     */
    public function hasExtremeDrop(array $bars): bool
    {
        for ($i = 1, $n = count($bars); $i < $n; $i++) {
            $prevClose = $bars[$i - 1]['close'] ?? 0;
            $currentOpen = $bars[$i]['open'] ?? 0;
            if ($prevClose > 0) {
                $dropPct = (($currentOpen - $prevClose) / $prevClose) * 100;
                if ($dropPct < -50.0) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Is the current time in the lunch chop window (11:30–13:30 ET)?
     */
    public function isLunchWindow(string $tsEst): bool
    {
        $ts = strtotime($tsEst.' America/New_York');
        if ($ts === false) {
            return false;
        }
        $minutes = (int) date('H', $ts) * 60 + (int) date('i', $ts);

        return $minutes >= 690 && $minutes <= 810;
    }

    /**
     * Get benchmark's 15-minute move from the bar source.
     */
    private function getBenchmarkMove15m(string $asOfTsEst): float
    {
        $bars = $this->bars->getBars('5m', self::BENCHMARK_SYMBOL, $asOfTsEst, 60, 20);

        if (count($bars) < 4) {
            return 0.0;
        }

        $normalized = array_map(fn ($b) => ['close' => $b->close], $bars);

        return $this->computeMove($normalized, 3); // 3 × 5m = 15m
    }
}
