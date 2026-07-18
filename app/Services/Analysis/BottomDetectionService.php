<?php

namespace App\Services\Analysis;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class BottomDetectionService
{
    /**
     * Detect potential bottom formations in stocks
     */
    public function detectBottoms(
        string $assetType = 'stock',
        ?string $asOf = null,
        array $options = []
    ): array {
        // Default options
        $config = array_merge([
            'lookbackBars' => 260,
            'minRsi' => 28,
            'bbLen' => 20,
            'bbK' => 2.0,
            'oversoldLookback' => 80,
            'baseBars' => 9,
            'lowTolPct' => 0.0015,
            'requireVolContraction' => true,
            'volContractRatio' => 0.90,
            'requireRisingLows' => true,
            'emaFast' => 9,
            'requireBreakBaseHigh' => false,
            'minDollarVol' => 0,
            'maxGainFromBottomPct' => 15.0, // Maximum % gain from base low to exclude substantially risen stocks
        ], $options);

        $asOf = $asOf ?: Carbon::now('America/New_York')->format('Y-m-d H:i:s');

        // Get active symbols
        $symbols = $this->fetchActiveSymbols($assetType, $asOf);
        $candidates = [];

        foreach ($symbols as $symbolData) {
            $symbol = $symbolData->symbol;
            $assetId = $symbolData->asset_id;

            $bars = $this->fetchBarsForSymbol($symbol, $assetType, $asOf, $config['lookbackBars']);

            if (count($bars) < 120) {
                continue;
            }

            $candidate = $this->analyzeSymbolForBottom($symbol, $bars, $config, $asOf, $assetId);

            if ($candidate) {
                $candidates[] = $candidate;
            }
        }

        // Sort by score descending
        usort($candidates, fn ($a, $b) => $b['score'] <=> $a['score']);

        return $candidates;
    }

    /**
     * Analyze a single symbol for bottom patterns
     */
    private function analyzeSymbolForBottom(string $symbol, array $bars, array $config, string $asOf, int $assetId): ?array
    {
        $bars = array_reverse($bars); // oldest -> newest

        $tsEst = array_column($bars, 'ts_est');
        $price = array_map('floatval', array_column($bars, 'price'));
        $open = array_map('floatval', array_column($bars, 'open'));
        $high = array_map('floatval', array_column($bars, 'high'));
        $low = array_map('floatval', array_column($bars, 'low'));
        $vol = array_map('floatval', array_column($bars, 'volume'));

        $i = count($price) - 1;

        // Calculate indicators
        $rsi14 = $this->rsi($price, 14);
        [$bbMid, $bbUp, $bbLo] = $this->bollinger($price, $config['bbLen'], $config['bbK']);
        $ema = $this->ema($price, $config['emaFast']);
        $dollarVol20 = $this->rollingDollarVol($price, $vol, 20);

        if ($rsi14[$i] === null || $bbLo[$i] === null || $ema[$i] === null) {
            return null;
        }

        // Apply liquidity filter
        if ($config['minDollarVol'] > 0) {
            if ($dollarVol20[$i] === null || $dollarVol20[$i] < $config['minDollarVol']) {
                return null;
            }
        }

        // 1) Find most recent oversold event
        $oversoldIdx = $this->findMostRecentOversoldIdx(
            $price,
            $rsi14,
            $bbLo,
            $i,
            $config['oversoldLookback'],
            $config['minRsi']
        );

        if ($oversoldIdx === null) {
            return null;
        }

        // 2) Base confirmation
        $base = $this->baseConfirmedSoFar(
            $high,
            $low,
            $price,
            $oversoldIdx,
            $i,
            $config['baseBars'],
            $config['lowTolPct'],
            $config['requireRisingLows'],
            $config['requireVolContraction'],
            $config['volContractRatio']
        );

        if (! $base['ok']) {
            return null;
        }

        // 3) Reclaim trigger
        $flags = $this->stabilizationFlags($open, $high, $low, $price, $rsi14, $i);

        $reclaimEma = ($price[$i] > $ema[$i]);
        if (! $reclaimEma) {
            return null;
        }
        $flags[] = "RECLAIM_EMA{$config['emaFast']}";

        if ($config['requireBreakBaseHigh']) {
            if ($price[$i] <= $base['baseHigh']) {
                return null;
            }
            $flags[] = 'BREAK_BASE_HIGH';
        }

        // Add base flags
        $flags[] = 'BASE_NO_NEW_LOWS';
        if ($base['volContracted']) {
            $flags[] = 'VOL_CONTRACT';
        }
        if ($base['risingLows']) {
            $flags[] = 'RISING_LOWS';
        }

        // Filter out stocks that have already risen substantially from the base low
        $gainFromBaseLow = (($price[$i] - $base['baseLow']) / $base['baseLow']) * 100;
        if ($gainFromBaseLow > $config['maxGainFromBottomPct']) {
            return null; // Stock has already moved up too much from bottom
        }

        // Calculate score
        $score = $this->scoreBottomCandidate(
            $price,
            $rsi14,
            $bbLo,
            $ema,
            $i,
            $oversoldIdx,
            $base['baseStartIdx'],
            $config['baseBars']
        );

        return [
            'symbol' => $symbol,
            'asset_id' => $assetId,
            'asOf' => $asOf,
            'barTs' => $tsEst[$i],
            'price' => $price[$i],
            'baseLow' => $base['baseLow'],
            'gainFromBottomPct' => $gainFromBaseLow,
            'rsi' => $rsi14[$i],
            'bbLower' => $bbLo[$i],
            'emaFast' => $ema[$i],
            'oversoldTs' => $tsEst[$oversoldIdx],
            'baseStartTs' => $tsEst[$base['baseStartIdx']],
            'flags' => array_unique($flags),
            'score' => $score,
        ];
    }

    /**
     * Fetch active symbols with their asset IDs
     */
    private function fetchActiveSymbols(string $assetType, string $asOf): array
    {
        $symbols = DB::table('five_minute_prices as fmp')
            ->join('asset_info as a', 'fmp.symbol', '=', 'a.symbol')
            ->select('fmp.symbol', 'a.id as asset_id')
            ->where('fmp.asset_type', $assetType)
            ->where('fmp.ts_est', '<=', $asOf)
            ->where('fmp.ts_est', '>=', DB::raw("DATE_SUB('{$asOf}', INTERVAL 5 DAY)"))
            ->whereNull('a.deleted_at')
            ->groupBy('fmp.symbol', 'a.id')
            ->orderBy('fmp.symbol')
            ->get()
            ->toArray();

        return $symbols;
    }

    /**
     * Fetch price bars for a symbol
     */
    private function fetchBarsForSymbol(string $symbol, string $assetType, string $asOf, int $lookbackBars): array
    {
        return DB::table('five_minute_prices')
            ->select(['ts_est', 'price', 'open', 'high', 'low', 'volume'])
            ->where('symbol', $symbol)
            ->where('asset_type', $assetType)
            ->where('ts_est', '<=', $asOf)
            ->orderBy('ts_est', 'desc')
            ->limit($lookbackBars)
            ->get()
            ->toArray();
    }

    /**
     * Find most recent oversold event
     */
    private function findMostRecentOversoldIdx(array $price, array $rsi, array $bbLo, int $i, int $lookback, float $minRsi): ?int
    {
        $from = max(0, $i - $lookback);
        for ($k = $i; $k >= $from; $k--) {
            if ($rsi[$k] === null || $bbLo[$k] === null) {
                continue;
            }
            if ($rsi[$k] <= $minRsi && $price[$k] <= $bbLo[$k]) {
                return $k;
            }
        }

        return null;
    }

    /**
     * Base confirmation logic
     */
    private function baseConfirmedSoFar(
        array $high, array $low, array $close,
        int $oversoldIdx, int $i,
        int $baseBars, float $lowTolPct,
        bool $requireRisingLows,
        bool $requireVolContract,
        float $volContractRatio
    ): array {
        // Need at least baseBars bars after oversold
        if (($i - $oversoldIdx) < $baseBars) {
            return ['ok' => false];
        }

        $baseStartIdx = max($oversoldIdx + 1, $i - $baseBars + 1);
        $baseLow = $this->minInRange($low, $baseStartIdx, $i);
        $baseHigh = $this->maxInRange($high, $baseStartIdx, $i);

        $refLow = (float) $low[$oversoldIdx];
        $tol = $refLow * $lowTolPct;

        // 1) No meaningful new low
        if ($baseLow < ($refLow - $tol)) {
            return ['ok' => false];
        }

        // 2) Rising lows (simple last 3 bars)
        $risingLows = true;
        if ($requireRisingLows) {
            if ($i < 2) {
                return ['ok' => false];
            }
            $risingLows = ($low[$i] >= $low[$i - 1] && $low[$i - 1] >= $low[$i - 2]);
            if (! $risingLows) {
                return ['ok' => false];
            }
        }

        // 3) Volatility contraction
        $volContracted = true;
        if ($requireVolContract) {
            $priorEnd = $baseStartIdx - 1;
            $priorStart = $priorEnd - $baseBars + 1;
            if ($priorStart < 0) {
                return ['ok' => false];
            }
            $avgNow = $this->avgRange($high, $low, $baseStartIdx, $i);
            $avgPrior = $this->avgRange($high, $low, $priorStart, $priorEnd);
            if ($avgPrior <= 0) {
                return ['ok' => false];
            }
            $volContracted = ($avgNow <= $avgPrior * $volContractRatio);
            if (! $volContracted) {
                return ['ok' => false];
            }
        }

        return [
            'ok' => true,
            'baseStartIdx' => $baseStartIdx,
            'baseLow' => $baseLow,
            'baseHigh' => $baseHigh,
            'risingLows' => $risingLows,
            'volContracted' => $volContracted,
        ];
    }

    /**
     * Generate stabilization flags
     */
    private function stabilizationFlags(array $open, array $high, array $low, array $close, array $rsi, int $i): array
    {
        $flags = [];

        // GREEN candle
        if ($close[$i] > $open[$i]) {
            $flags[] = 'GREEN';
        }

        // Lower wick rejection
        $range = max(0.000001, $high[$i] - $low[$i]);
        $lowerWick = min($open[$i], $close[$i]) - $low[$i];
        if (($lowerWick / $range) >= 0.45) {
            $flags[] = 'WICK_REJECT';
        }

        // RSI rising
        if ($i > 0 && $rsi[$i] !== null && $rsi[$i - 1] !== null && $rsi[$i] > $rsi[$i - 1]) {
            $flags[] = 'RSI_UP';
        }

        return $flags;
    }

    /**
     * Score bottom candidate
     */
    private function scoreBottomCandidate(
        array $price, array $rsi, array $bbLo, array $ema,
        int $i, int $oversoldIdx, int $baseStartIdx, int $baseBars
    ): float {
        // 1) Current RSI depth (lower RSI => higher score)
        $rsiNow = (float) $rsi[$i];
        $rsiDepth = max(0.0, 35.0 - $rsiNow);

        // 2) BB breach strength at oversold bar
        $bb = (float) $bbLo[$oversoldIdx];
        $px = (float) $price[$oversoldIdx];
        $bbBreach = ($bb > 0) ? max(0.0, ($bb - $px) / $bb) : 0.0;
        $bbScore = $bbBreach * 1000.0;

        // 3) Base duration since oversold
        $barsSinceOversold = $i - $oversoldIdx;
        $baseScore = min(30.0, $barsSinceOversold);

        // 4) Reclaim strength above EMA
        $emaNow = (float) $ema[$i];
        $reclaim = ($emaNow > 0) ? max(0.0, ($price[$i] - $emaNow) / $emaNow) : 0.0;
        $reclaimScore = $reclaim * 500.0;

        return $rsiDepth + $bbScore + $baseScore + $reclaimScore;
    }

    /**
     * Calculate EMA
     */
    private function ema(array $x, int $len): array
    {
        $out = array_fill(0, count($x), null);
        if ($len <= 0 || count($x) === 0) {
            return $out;
        }
        $alpha = 2.0 / ($len + 1.0);

        if (count($x) < $len) {
            return $out;
        }
        $sum = 0.0;
        for ($i = 0; $i < $len; $i++) {
            $sum += $x[$i];
        }
        $ema = $sum / $len;
        $out[$len - 1] = $ema;

        for ($i = $len; $i < count($x); $i++) {
            $ema = ($x[$i] * $alpha) + ($ema * (1.0 - $alpha));
            $out[$i] = $ema;
        }

        return $out;
    }

    /**
     * Calculate RSI
     */
    private function rsi(array $close, int $len): array
    {
        $out = array_fill(0, count($close), null);
        if (count($close) <= $len) {
            return $out;
        }

        $gain = 0.0;
        $loss = 0.0;
        for ($i = 1; $i <= $len; $i++) {
            $chg = $close[$i] - $close[$i - 1];
            if ($chg >= 0) {
                $gain += $chg;
            } else {
                $loss += abs($chg);
            }
        }
        $avgGain = $gain / $len;
        $avgLoss = $loss / $len;

        $rs = ($avgLoss == 0.0) ? INF : ($avgGain / $avgLoss);
        $out[$len] = 100.0 - (100.0 / (1.0 + $rs));

        for ($i = $len + 1; $i < count($close); $i++) {
            $chg = $close[$i] - $close[$i - 1];
            $g = ($chg > 0) ? $chg : 0.0;
            $l = ($chg < 0) ? abs($chg) : 0.0;

            $avgGain = (($avgGain * ($len - 1)) + $g) / $len;
            $avgLoss = (($avgLoss * ($len - 1)) + $l) / $len;

            $rs = ($avgLoss == 0.0) ? INF : ($avgGain / $avgLoss);
            $out[$i] = 100.0 - (100.0 / (1.0 + $rs));
        }

        return $out;
    }

    /**
     * Calculate SMA
     */
    private function sma(array $x, int $len): array
    {
        $out = array_fill(0, count($x), null);
        $sum = 0.0;
        for ($i = 0; $i < count($x); $i++) {
            $sum += $x[$i];
            if ($i >= $len) {
                $sum -= $x[$i - $len];
            }
            if ($i >= $len - 1) {
                $out[$i] = $sum / $len;
            }
        }

        return $out;
    }

    /**
     * Calculate standard deviation
     */
    private function stddev(array $x, int $len, array $mean): array
    {
        $out = array_fill(0, count($x), null);
        for ($i = 0; $i < count($x); $i++) {
            if ($i < $len - 1 || $mean[$i] === null) {
                continue;
            }
            $m = $mean[$i];
            $ss = 0.0;
            for ($j = $i - $len + 1; $j <= $i; $j++) {
                $d = $x[$j] - $m;
                $ss += $d * $d;
            }
            $out[$i] = sqrt($ss / $len);
        }

        return $out;
    }

    /**
     * Calculate Bollinger Bands
     */
    private function bollinger(array $close, int $len, float $k): array
    {
        $mid = $this->sma($close, $len);
        $sd = $this->stddev($close, $len, $mid);
        $lo = array_fill(0, count($close), null);
        $up = array_fill(0, count($close), null);

        for ($i = 0; $i < count($close); $i++) {
            if ($mid[$i] === null || $sd[$i] === null) {
                continue;
            }
            $up[$i] = $mid[$i] + $k * $sd[$i];
            $lo[$i] = $mid[$i] - $k * $sd[$i];
        }

        return [$mid, $up, $lo];
    }

    /**
     * Calculate rolling dollar volume
     */
    private function rollingDollarVol(array $close, array $vol, int $len): array
    {
        $out = array_fill(0, count($close), null);
        $sum = 0.0;
        for ($i = 0; $i < count($close); $i++) {
            $dv = $close[$i] * ($vol[$i] ?? 0);
            $sum += $dv;
            if ($i >= $len) {
                $sum -= $close[$i - $len] * ($vol[$i - $len] ?? 0);
            }
            if ($i >= $len - 1) {
                $out[$i] = $sum / $len;
            }
        }

        return $out;
    }

    /**
     * Utility functions
     */
    private function minInRange(array $arr, int $from, int $to): float
    {
        $m = INF;
        for ($i = $from; $i <= $to; $i++) {
            $m = min($m, (float) $arr[$i]);
        }

        return $m;
    }

    private function maxInRange(array $arr, int $from, int $to): float
    {
        $m = -INF;
        for ($i = $from; $i <= $to; $i++) {
            $m = max($m, (float) $arr[$i]);
        }

        return $m;
    }

    private function avgRange(array $high, array $low, int $from, int $to): float
    {
        $sum = 0.0;
        $n = 0;
        for ($i = $from; $i <= $to; $i++) {
            $sum += max(0.0, (float) $high[$i] - (float) $low[$i]);
            $n++;
        }

        return ($n > 0) ? ($sum / $n) : 0.0;
    }

    /**
     * Calculate RSI (Relative Strength Index)
     */
    public function calculateRSI(array $prices, int $period = 14): float
    {
        if (count($prices) < $period + 1) {
            return 50; // Default neutral RSI if insufficient data
        }

        $gains = [];
        $losses = [];

        // Calculate daily gains and losses
        for ($i = 1; $i < count($prices); $i++) {
            $change = $prices[$i] - $prices[$i - 1];
            $gains[] = max($change, 0);
            $losses[] = max(-$change, 0);
        }

        // Calculate average gains and losses for the first period
        $avgGain = array_sum(array_slice($gains, 0, $period)) / $period;
        $avgLoss = array_sum(array_slice($losses, 0, $period)) / $period;

        // Calculate subsequent smoothed averages
        for ($i = $period; $i < count($gains); $i++) {
            $avgGain = (($avgGain * ($period - 1)) + $gains[$i]) / $period;
            $avgLoss = (($avgLoss * ($period - 1)) + $losses[$i]) / $period;
        }

        if ($avgLoss == 0) {
            return 100;
        }

        $rs = $avgGain / $avgLoss;

        return 100 - (100 / (1 + $rs));
    }

    /**
     * Calculate EMA (Exponential Moving Average)
     */
    public function calculateEMA(array $prices, int $period): array
    {
        $ema = [];
        $multiplier = 2 / ($period + 1);

        // First EMA is just the first price
        $ema[0] = $prices[0];

        // Calculate subsequent EMAs
        for ($i = 1; $i < count($prices); $i++) {
            $ema[$i] = ($prices[$i] * $multiplier) + ($ema[$i - 1] * (1 - $multiplier));
        }

        return $ema;
    }

    /**
     * Calculate Bollinger Bands
     */
    public function calculateBollingerBands(array $prices, int $period, float $multiplier): array
    {
        if (count($prices) < $period) {
            return [
                'upper' => max($prices),
                'middle' => array_sum($prices) / count($prices),
                'lower' => min($prices),
            ];
        }

        // Calculate SMA (middle band)
        $smaValues = [];
        for ($i = $period - 1; $i < count($prices); $i++) {
            $smaValues[] = array_sum(array_slice($prices, $i - $period + 1, $period)) / $period;
        }

        // Use the last SMA value as the middle band
        $middle = end($smaValues);

        // Calculate standard deviation for the last period
        $lastPeriodPrices = array_slice($prices, -$period);
        $variance = 0;
        foreach ($lastPeriodPrices as $price) {
            $variance += pow($price - $middle, 2);
        }
        $stdDev = sqrt($variance / $period);

        return [
            'upper' => $middle + ($stdDev * $multiplier),
            'middle' => $middle,
            'lower' => $middle - ($stdDev * $multiplier),
        ];
    }
}
