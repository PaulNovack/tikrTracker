<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

final class ScanChartPatterns extends Command
{
    protected $signature = 'market:scan-chart-patterns
        {asset_type=stock : stock|crypto}
        {--asOf=now : EST datetime "Y-m-d H:i:s" or "now"}
        {--lookback=900 : Minutes of 5m history to load per symbol}
        {--maxSymbols=0 : 0=all, else limit}
        {--minScore=6.0 : Minimum score to write signal}
        {--confirm1m : Use 1m confirmation to mark triggered}
        {--debugSymbol= : Only scan one symbol (e.g. AAPL)}
    ';

    protected $description = 'Scan 5m bars for bullish chart patterns (double bottom, inverted H&S, falling wedge, flag/pennant) and write signals to trade_signals';

    // -------------------- Tunables --------------------
    private const PIVOT_LEFT = 2;

    private const PIVOT_RIGHT = 2;

    // IMPORTANT: morning scans will otherwise skip almost everything.
    private const MIN_BARS_5M = 30;

    private const MIN_PIVOTS = 6;

    // tolerances (fractional)
    private const DBL_BOTTOM_TOL = 0.015;       // 1.5% low1 ~ low2

    private const IHS_SHOULDER_TOL = 0.030;     // 3% shoulder similarity

    private const IHS_HEAD_DEPTH_MIN = 0.010;   // head at least 1% lower than shoulders

    // Wedge / flag/pennant fitting constraints
    private const LINE_TOUCH_TOL = 0.010; // 1% distance to count a "touch"

    private const MIN_TOUCHES = 3;

    // Pole requirement for flag/pennant
    private const FLAG_MIN_POLE_PCT = 0.020;      // 2%

    private const FLAG_MAX_CONSOL_PCT = 0.020;    // 2% depth in consolidation

    public function handle(): int
    {
        $assetType = strtolower((string) $this->argument('asset_type'));
        if (! in_array($assetType, ['stock', 'crypto'], true)) {
            $this->error('asset_type must be stock|crypto');

            return 1;
        }

        $asOfInput = (string) $this->option('asOf');
        $asOfEst = $this->parseAsOfEst($asOfInput);

        $lookbackMinutes = max(120, (int) $this->option('lookback'));
        $minScore = (float) $this->option('minScore');
        $confirm1m = (bool) $this->option('confirm1m');
        $maxSymbols = (int) $this->option('maxSymbols');
        $debugSymbol = $this->option('debugSymbol') ? strtoupper(trim((string) $this->option('debugSymbol'))) : null;

        $this->info("Scanning patterns: asset_type={$assetType}, asOfEST={$asOfEst}, lookback={$lookbackMinutes}m, confirm1m=".($confirm1m ? 'yes' : 'no'));

        $symbols = $this->fetchSymbols($assetType, $maxSymbols, $debugSymbol);
        $this->info('Symbols: '.count($symbols));

        $written = 0;
        $triggered = 0;

        // instrumentation (so you can see why you get 0 hits)
        $processed = 0;
        $skippedNotEnoughBars = 0;
        $skippedNotEnoughPivots = 0;

        foreach ($symbols as $symbol) {
            $bars5m = $this->fetchBars5m($symbol, $assetType, $asOfEst, $lookbackMinutes);

            // FIX: don't require 80 bars (morning will never hit it)
            if (count($bars5m) < self::MIN_BARS_5M) {
                $skippedNotEnoughBars++;

                continue;
            }

            // FIX: pivot tie-safe logic (below) + allow fewer pivots
            $pivots = $this->pivots($bars5m, self::PIVOT_LEFT, self::PIVOT_RIGHT);
            if (count($pivots) < self::MIN_PIVOTS) {
                $skippedNotEnoughPivots++;

                continue;
            }

            $processed++;

            $setups = [];

            // Double Bottom
            if ($s = $this->detectDoubleBottom($bars5m, $pivots)) {
                $setups[] = $s;
            }

            // Inverted H&S
            if ($s = $this->detectInvertedHS($bars5m, $pivots)) {
                $setups[] = $s;
            }

            // Falling Wedge
            if ($s = $this->detectFallingWedge($bars5m, $pivots)) {
                $setups[] = $s;
            }

            // Flag / Pennant (returns either FLAG or PENNANT)
            $fp = $this->detectFlagOrPennant($bars5m, $pivots);
            foreach ($fp as $s) {
                $setups[] = $s;
            }

            if (! $setups) {
                continue;
            }

            foreach ($setups as $setup) {
                if ($setup['score'] < $minScore) {
                    continue;
                }

                $didTrigger = false;
                $triggerTs = null;

                if ($confirm1m && isset($setup['trigger']) && is_array($setup['trigger'])) {
                    [$didTrigger, $triggerTs] = $this->confirmOn1m($symbol, $assetType, $asOfEst, $setup['trigger']);
                }

                $ok = $this->writeSignal(
                    $symbol,
                    $assetType,
                    $asOfEst,
                    $setup['pattern'],
                    $setup['levels'],
                    $setup['score'],
                    $didTrigger,
                    $triggerTs,
                    $setup['notes'] ?? null
                );

                if ($ok) {
                    $written++;
                    if ($didTrigger) {
                        $triggered++;
                    }

                    if ($debugSymbol) {
                        $this->line(json_encode([
                            'symbol' => $symbol,
                            'pattern' => $setup['pattern'],
                            'score' => $setup['score'],
                            'triggered' => $didTrigger,
                            'levels' => $setup['levels'],
                            'notes' => $setup['notes'] ?? null,
                        ], JSON_PRETTY_PRINT));
                    }
                }
            }
        }

        $this->info("Processed={$processed} / ".count($symbols));
        $this->info("Skipped: notEnoughBars={$skippedNotEnoughBars}, notEnoughPivots={$skippedNotEnoughPivots}");
        $this->info("Done. Signals written={$written}, triggered={$triggered}");

        return 0;
    }

    // -------------------- Symbol universe --------------------

    private function fetchSymbols(string $assetType, int $maxSymbols, ?string $debugSymbol): array
    {
        if ($debugSymbol) {
            return [$debugSymbol];
        }

        // Prefer asset_info if present
        try {
            $q = DB::table('asset_info')
                ->select('symbol')
                ->where('asset_type', $assetType)
                ->whereNull('deleted_at')
                ->orderBy('symbol');

            if ($maxSymbols > 0) {
                $q->limit($maxSymbols);
            }

            $rows = $q->get();
            if ($rows->count() > 0) {
                return $rows->pluck('symbol')->map(fn ($s) => strtoupper((string) $s))->all();
            }
        } catch (\Throwable $e) {
            // ignore, fall back
        }

        // fallback: distinct symbols from five_minute_prices
        $q2 = DB::table('five_minute_prices')
            ->select('symbol')
            ->where('asset_type', $assetType)
            ->groupBy('symbol')
            ->orderBy('symbol');

        if ($maxSymbols > 0) {
            $q2->limit($maxSymbols);
        }

        return $q2->get()->pluck('symbol')->map(fn ($s) => strtoupper((string) $s))->all();
    }

    // -------------------- Data fetch --------------------

    private function fetchBars5m(string $symbol, string $assetType, string $asOfEst, int $lookbackMinutes): array
    {
        $start = $this->estMinusMinutes($asOfEst, $lookbackMinutes);

        // FIX: COALESCE OHLC, so NULLs don't wreck pivots
        $rows = DB::table('five_minute_prices')
            ->select([
                'ts_est',
                DB::raw('COALESCE(open, price) as open'),
                DB::raw('COALESCE(high, price) as high'),
                DB::raw('COALESCE(low,  price) as low'),
                DB::raw('price as close'),
                'volume',
            ])
            ->where('asset_type', $assetType)
            ->where('symbol', $symbol)
            ->whereBetween('ts_est', [$start, $asOfEst])
            ->orderBy('ts_est')
            ->get();

        return $rows->map(fn ($r) => [
            'ts_est' => (string) $r->ts_est,
            'open' => (float) $r->open,
            'high' => (float) $r->high,
            'low' => (float) $r->low,
            'close' => (float) $r->close,
            'volume' => (float) ($r->volume ?? 0),
        ])->all();
    }

    private function fetchBars1m(string $symbol, string $assetType, string $startEst, string $endEst): array
    {
        // FIX: COALESCE OHLC here too
        $rows = DB::table('one_minute_prices')
            ->select([
                'ts_est',
                DB::raw('COALESCE(open, price) as open'),
                DB::raw('COALESCE(high, price) as high'),
                DB::raw('COALESCE(low,  price) as low'),
                DB::raw('price as close'),
                'volume',
            ])
            ->where('asset_type', $assetType)
            ->where('symbol', $symbol)
            ->whereBetween('ts_est', [$startEst, $endEst])
            ->orderBy('ts_est')
            ->get();

        return $rows->map(fn ($r) => [
            'ts_est' => (string) $r->ts_est,
            'open' => (float) $r->open,
            'high' => (float) $r->high,
            'low' => (float) $r->low,
            'close' => (float) $r->close,
            'volume' => (float) ($r->volume ?? 0),
        ])->all();
    }

    // -------------------- Pivots --------------------

    private function pivots(array $bars, int $left, int $right): array
    {
        $n = count($bars);
        $p = [];

        for ($i = $left; $i < $n - $right; $i++) {
            $hi = (float) $bars[$i]['high'];
            $lo = (float) $bars[$i]['low'];

            $isHigh = true;
            $isLow = true;

            // FIX: allow ties, but require at least one strict win
            $strictHighWin = false;
            $strictLowWin = false;

            for ($j = $i - $left; $j <= $i + $right; $j++) {
                if ($j === $i) {
                    continue;
                }

                $hj = (float) $bars[$j]['high'];
                $lj = (float) $bars[$j]['low'];

                // High pivot: disqualify only if someone is strictly higher
                if ($hj > $hi) {
                    $isHigh = false;
                }
                if ($hj < $hi) {
                    $strictHighWin = true;
                }

                // Low pivot: disqualify only if someone is strictly lower
                if ($lj < $lo) {
                    $isLow = false;
                }
                if ($lj > $lo) {
                    $strictLowWin = true;
                }

                if (! $isHigh && ! $isLow) {
                    break;
                }
            }

            if ($isHigh && $strictHighWin) {
                $p[] = ['i' => $i, 'type' => 'H', 'price' => $hi, 'ts' => $bars[$i]['ts_est']];
            }
            if ($isLow && $strictLowWin) {
                $p[] = ['i' => $i, 'type' => 'L', 'price' => $lo, 'ts' => $bars[$i]['ts_est']];
            }
        }

        usort($p, fn ($a, $b) => $a['i'] <=> $b['i']);

        return $p;
    }

    // -------------------- Pattern: Double Bottom --------------------

    private function detectDoubleBottom(array $bars, array $pivots): ?array
    {
        // Scan last L-H-L from the tail (simple starter)
        for ($k = count($pivots) - 1; $k >= 2; $k--) {
            $p3 = $pivots[$k];
            $p2 = $pivots[$k - 1];
            $p1 = $pivots[$k - 2];

            if ($p1['type'] !== 'L' || $p2['type'] !== 'H' || $p3['type'] !== 'L') {
                continue;
            }

            if ($this->pctDiff($p1['price'], $p3['price']) > self::DBL_BOTTOM_TOL) {
                continue;
            }

            $neckline = (float) $p2['price'];
            $lastClose = (float) $bars[array_key_last($bars)]['close'];
            $bottom = min((float) $p1['price'], (float) $p3['price']);
            $depthPct = ($neckline - $bottom) / max(1e-9, $neckline);

            $score = 0.0;
            $score += min(4.0, 100.0 * $depthPct);
            $score += max(0.0, 3.0 - 200.0 * $this->pctDiff($p1['price'], $p3['price']));
            if ($lastClose > $neckline) {
                $score += 2.0;
            }

            return [
                'pattern' => 'BULL_DOUBLE_BOTTOM',
                'score' => round($score, 3),
                'levels' => [
                    'low1' => (float) $p1['price'],
                    'low2' => (float) $p3['price'],
                    'neckline' => $neckline,
                    'low1_ts' => $p1['ts'],
                    'low2_ts' => $p3['ts'],
                    'neckline_ts' => $p2['ts'],
                    'depth_pct' => $depthPct,
                ],
                'trigger' => [
                    'type' => 'close_above_level',
                    'level' => $neckline,
                ],
                'notes' => 'L-H-L pivot structure; neckline breakout confirms',
            ];
        }

        return null;
    }

    // -------------------- Pattern: Inverted Head & Shoulders --------------------

    private function detectInvertedHS(array $bars, array $pivots): ?array
    {
        // Find last L-H-L-H-L (LS, N1, Head, N2, RS)
        for ($k = count($pivots) - 1; $k >= 4; $k--) {
            $rs = $pivots[$k];
            $n2 = $pivots[$k - 1];
            $hd = $pivots[$k - 2];
            $n1 = $pivots[$k - 3];
            $ls = $pivots[$k - 4];

            if ($ls['type'] !== 'L' || $n1['type'] !== 'H' || $hd['type'] !== 'L' || $n2['type'] !== 'H' || $rs['type'] !== 'L') {
                continue;
            }

            $headDepthOk =
                $hd['price'] <= $ls['price'] * (1.0 - self::IHS_HEAD_DEPTH_MIN) &&
                $hd['price'] <= $rs['price'] * (1.0 - self::IHS_HEAD_DEPTH_MIN);

            if (! $headDepthOk) {
                continue;
            }

            if ($this->pctDiff((float) $ls['price'], (float) $rs['price']) > self::IHS_SHOULDER_TOL) {
                continue;
            }

            $neck = $this->lineFrom2Points((int) $n1['i'], (float) $n1['price'], (int) $n2['i'], (float) $n2['price']);
            if (! $neck) {
                continue;
            }

            $lastIdx = array_key_last($bars);
            $neckAtLast = $neck['m'] * $lastIdx + $neck['b'];
            $lastClose = (float) $bars[$lastIdx]['close'];

            $score = 0.0;
            $shoulderAvg = ((float) $ls['price'] + (float) $rs['price']) / 2.0;
            $depthPct = ($shoulderAvg - (float) $hd['price']) / max(1e-9, $shoulderAvg);
            $score += min(5.0, 120.0 * $depthPct);
            $score += max(0.0, 3.0 - 150.0 * $this->pctDiff((float) $ls['price'], (float) $rs['price']));
            if ($lastClose > $neckAtLast) {
                $score += 2.0;
            }

            return [
                'pattern' => 'BULL_INVERTED_HEAD_SHOULDERS',
                'score' => round($score, 3),
                'levels' => [
                    'ls' => (float) $ls['price'],
                    'head' => (float) $hd['price'],
                    'rs' => (float) $rs['price'],
                    'n1' => (float) $n1['price'],
                    'n2' => (float) $n2['price'],
                    'neckline_m' => $neck['m'],
                    'neckline_b' => $neck['b'],
                    'neckline_at_asof' => $neckAtLast,
                    'ls_ts' => $ls['ts'],
                    'head_ts' => $hd['ts'],
                    'rs_ts' => $rs['ts'],
                    'n1_ts' => $n1['ts'],
                    'n2_ts' => $n2['ts'],
                    'depth_pct' => $depthPct,
                ],
                // Use fixed neckline@asOf as a level for 1m confirm (practical)
                'trigger' => [
                    'type' => 'close_above_level',
                    'level' => $neckAtLast,
                ],
                'notes' => 'L-H-L-H-L pivot structure; breakout above neckline confirms',
            ];
        }

        return null;
    }

    // -------------------- Pattern: Falling Wedge --------------------

    private function detectFallingWedge(array $bars, array $pivots): ?array
    {
        $recent = array_slice($pivots, -20);
        $highs = array_values(array_filter($recent, fn ($p) => $p['type'] === 'H'));
        $lows = array_values(array_filter($recent, fn ($p) => $p['type'] === 'L'));

        if (count($highs) < self::MIN_TOUCHES || count($lows) < self::MIN_TOUCHES) {
            return null;
        }

        $upper = $this->linearRegression(array_map(fn ($p) => [(int) $p['i'], (float) $p['price']], $highs));
        $lower = $this->linearRegression(array_map(fn ($p) => [(int) $p['i'], (float) $p['price']], $lows));
        if (! $upper || ! $lower) {
            return null;
        }

        if (! ($upper['m'] < 0.0 && $lower['m'] < 0.0)) {
            return null;
        }

        $x0 = (int) $recent[0]['i'];
        $x1 = (int) $recent[array_key_last($recent)]['i'];
        $gap0 = ($upper['m'] * $x0 + $upper['b']) - ($lower['m'] * $x0 + $lower['b']);
        $gap1 = ($upper['m'] * $x1 + $upper['b']) - ($lower['m'] * $x1 + $lower['b']);

        if (! ($gap1 > 0 && $gap0 > 0 && $gap1 < $gap0)) {
            return null;
        }

        $touchHi = $this->countTouches($highs, $upper['m'], $upper['b'], self::LINE_TOUCH_TOL);
        $touchLo = $this->countTouches($lows, $lower['m'], $lower['b'], self::LINE_TOUCH_TOL);
        if ($touchHi < self::MIN_TOUCHES || $touchLo < self::MIN_TOUCHES) {
            return null;
        }

        $lastIdx = array_key_last($bars);
        $upperAtLast = $upper['m'] * $lastIdx + $upper['b'];
        $lastClose = (float) $bars[$lastIdx]['close'];

        $score = 0.0;
        $score += min(4.0, 200.0 * (($gap0 - $gap1) / max(1e-9, $gap0)));
        $score += min(3.0, 0.8 * ($touchHi + $touchLo));
        if ($lastClose > $upperAtLast) {
            $score += 2.0;
        }

        return [
            'pattern' => 'BULL_FALLING_WEDGE',
            'score' => round($score, 3),
            'levels' => [
                'upper_m' => $upper['m'],
                'upper_b' => $upper['b'],
                'lower_m' => $lower['m'],
                'lower_b' => $lower['b'],
                'upper_at_asof' => $upperAtLast,
                'gap_start' => $gap0,
                'gap_end' => $gap1,
                'touch_highs' => $touchHi,
                'touch_lows' => $touchLo,
            ],
            // fixed line value at asOf for 1m confirm
            'trigger' => [
                'type' => 'close_above_level',
                'level' => $upperAtLast,
            ],
            'notes' => 'Regression-fit wedge; breakout above upper line confirms',
        ];
    }

    // -------------------- Pattern: Bull Flag / Pennant --------------------

    private function detectFlagOrPennant(array $bars, array $pivots): array
    {
        $n = count($bars);
        if ($n < 80) {
            return [];
        }

        $results = [];

        $consLen = 30; // last 150 minutes
        $poleLen = 24; // previous 120 minutes

        $consStart = max(0, $n - $consLen);
        $poleStart = max(0, $consStart - $poleLen);

        $poleBars = array_slice($bars, $poleStart, $consStart - $poleStart);
        $consBars = array_slice($bars, $consStart);

        if (count($poleBars) < 10 || count($consBars) < 20) {
            return [];
        }

        $poleLow = min(array_map(fn ($b) => (float) $b['low'], $poleBars));
        $poleHigh = max(array_map(fn ($b) => (float) $b['high'], $poleBars));
        $polePct = ($poleHigh - $poleLow) / max(1e-9, $poleLow);

        if ($polePct < self::FLAG_MIN_POLE_PCT) {
            return [];
        }

        $consHigh = max(array_map(fn ($b) => (float) $b['high'], $consBars));
        $consLow = min(array_map(fn ($b) => (float) $b['low'], $consBars));
        $consDepthPct = ($consHigh - $consLow) / max(1e-9, $consHigh);

        if ($consDepthPct > self::FLAG_MAX_CONSOL_PCT) {
            return [];
        }

        $pivInCons = array_values(array_filter($pivots, fn ($p) => (int) $p['i'] >= $consStart));
        if (count($pivInCons) < 6) {
            return [];
        }

        $highs = array_values(array_filter($pivInCons, fn ($p) => $p['type'] === 'H'));
        $lows = array_values(array_filter($pivInCons, fn ($p) => $p['type'] === 'L'));
        if (count($highs) < 2 || count($lows) < 2) {
            return [];
        }

        $upper = $this->linearRegression(array_map(fn ($p) => [(int) $p['i'], (float) $p['price']], $highs));
        $lower = $this->linearRegression(array_map(fn ($p) => [(int) $p['i'], (float) $p['price']], $lows));
        if (! $upper || ! $lower) {
            return [];
        }

        $lastIdx = array_key_last($bars);
        $upperAtLast = $upper['m'] * $lastIdx + $upper['b'];
        $lastClose = (float) $bars[$lastIdx]['close'];

        $x0 = $consStart;
        $x1 = $lastIdx;
        $gap0 = ($upper['m'] * $x0 + $upper['b']) - ($lower['m'] * $x0 + $lower['b']);
        $gap1 = ($upper['m'] * $x1 + $upper['b']) - ($lower['m'] * $x1 + $lower['b']);

        $parallel = abs($upper['m'] - $lower['m']) <= 0.0005;
        $converging = ($gap1 > 0 && $gap0 > 0 && $gap1 < $gap0);

        if (! $parallel && ! $converging) {
            return [];
        }

        $pattern = $converging ? 'BULL_PENNANT' : 'BULL_FLAG';

        $score = 0.0;
        $score += min(5.0, 100.0 * $polePct);
        $score += max(0.0, 3.0 - 80.0 * $consDepthPct);
        if ($lastClose > $upperAtLast) {
            $score += 2.0;
        }

        $results[] = [
            'pattern' => $pattern,
            'score' => round($score, 3),
            'levels' => [
                'pole_low' => $poleLow,
                'pole_high' => $poleHigh,
                'pole_pct' => $polePct,
                'cons_low' => $consLow,
                'cons_high' => $consHigh,
                'cons_depth_pct' => $consDepthPct,
                'upper_m' => $upper['m'],
                'upper_b' => $upper['b'],
                'lower_m' => $lower['m'],
                'lower_b' => $lower['b'],
                'upper_at_asof' => $upperAtLast,
                'gap_start' => $gap0,
                'gap_end' => $gap1,
            ],
            // fixed value at asOf for 1m confirm
            'trigger' => [
                'type' => 'close_above_level',
                'level' => $upperAtLast,
            ],
            'notes' => $converging ? 'Impulse + converging consolidation' : 'Impulse + parallel consolidation',
        ];

        return $results;
    }

    // -------------------- 1m Confirmation --------------------

    /**
     * @param  array<string,mixed>  $triggerSpec
     * @return array{0:bool,1:?string} [triggered, trigger_ts_est]
     */
    private function confirmOn1m(string $symbol, string $assetType, string $asOfEst, array $triggerSpec): array
    {
        $start = $this->estMinusMinutes($asOfEst, 45);
        $bars1m = $this->fetchBars1m($symbol, $assetType, $start, $asOfEst);
        if (count($bars1m) < 10) {
            return [false, null];
        }

        $type = (string) ($triggerSpec['type'] ?? '');
        if ($type !== 'close_above_level') {
            return [false, null];
        }

        $level = (float) ($triggerSpec['level'] ?? 0.0);
        if ($level <= 0) {
            return [false, null];
        }

        foreach ($bars1m as $b) {
            if ((float) $b['close'] > $level) {
                return [true, $b['ts_est']];
            }
        }

        return [false, null];
    }

    // -------------------- Write signal --------------------

    private function writeSignal(
        string $symbol,
        string $assetType,
        string $asOfEst,
        string $pattern,
        array $levels,
        float $score,
        bool $triggered,
        ?string $triggerTsEst,
        ?string $notes
    ): bool {
        $now = now();

        try {
            DB::table('trade_signals')->updateOrInsert(
                [
                    'symbol' => $symbol,
                    'asset_type' => $assetType,
                    'asof_ts_est' => $asOfEst,
                    'pattern' => $pattern,
                ],
                [
                    'levels_json' => json_encode($levels, JSON_UNESCAPED_SLASHES),
                    'score' => $score,
                    'triggered' => $triggered ? 1 : 0,
                    'trigger_ts_est' => $triggerTsEst,
                    'timeframe' => '5m',
                    'notes' => $notes,
                    'updated_at' => $now,
                    'created_at' => $now,
                ]
            );

            return true;
        } catch (\Throwable $e) {
            $this->warn("Write failed {$symbol} {$pattern}: ".$e->getMessage());

            return false;
        }
    }

    // -------------------- Math helpers --------------------

    private function pctDiff(float $a, float $b): float
    {
        $den = max(1e-12, min($a, $b));

        return abs($a - $b) / $den;
    }

    private function linearRegression(array $points): ?array
    {
        $n = count($points);
        if ($n < 2) {
            return null;
        }

        $sumX = $sumY = $sumXY = $sumXX = 0.0;
        foreach ($points as [$x, $y]) {
            $sumX += $x;
            $sumY += $y;
            $sumXY += $x * $y;
            $sumXX += $x * $x;
        }

        $den = ($n * $sumXX - $sumX * $sumX);
        if (abs($den) < 1e-12) {
            return null;
        }

        $m = ($n * $sumXY - $sumX * $sumY) / $den;
        $b = ($sumY - $m * $sumX) / $n;

        $err = 0.0;
        foreach ($points as [$x, $y]) {
            $yHat = $m * $x + $b;
            $err += ($y - $yHat) ** 2;
        }
        $rmse = sqrt($err / $n);

        return ['m' => $m, 'b' => $b, 'rmse' => $rmse];
    }

    private function lineFrom2Points(int $x1, float $y1, int $x2, float $y2): ?array
    {
        $dx = $x2 - $x1;
        if ($dx === 0) {
            return null;
        }
        $m = ($y2 - $y1) / $dx;
        $b = $y1 - $m * $x1;

        return ['m' => $m, 'b' => $b];
    }

    private function countTouches(array $pivotPoints, float $m, float $b, float $tolFrac): int
    {
        $touch = 0;
        foreach ($pivotPoints as $p) {
            $x = (int) $p['i'];
            $y = (float) $p['price'];
            $yHat = $m * $x + $b;
            $distFrac = abs($y - $yHat) / max(1e-12, $yHat);
            if ($distFrac <= $tolFrac) {
                $touch++;
            }
        }

        return $touch;
    }

    // -------------------- Time helpers (EST strings) --------------------

    private function parseAsOfEst(string $asOfInput): string
    {
        if ($asOfInput === 'now' || trim($asOfInput) === '') {
            // Use NY as "EST/ET" for your ts_est alignment
            return now()->setTimezone('America/New_York')->format('Y-m-d H:i:s');
        }

        return trim($asOfInput);
    }

    private function estMinusMinutes(string $est, int $mins): string
    {
        $dt = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $est, new \DateTimeZone('America/New_York'));
        if (! $dt) {
            $dt = new \DateTimeImmutable($est, new \DateTimeZone('America/New_York'));
        }

        return $dt->modify("-{$mins} minutes")->format('Y-m-d H:i:s');
    }
}
