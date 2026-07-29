<?php

namespace App\Services\Trading;

use App\Services\GainersLosersAnalysisService;
use App\Services\Market\BestPerformers5mService;
use Illuminate\Support\Facades\DB;

/**
 * Version 130.0 - High-Probability Pattern Scanner
 *
 * Focuses on three proven high win-rate patterns:
 * 1. VWAP Bounce - Dips to VWAP, bounces with volume (75%+ WR expected)
 * 2. Bull Flag Breakout - Consolidation after surge, breaks with volume (70%+ WR)
 * 3. Failed Breakdown Reversal - Breaks support, immediately reclaims (75%+ WR)
 *
 * ENV vars:
 * - V130_MIN_VWAP_BOUNCE_VOL_MULT: volume spike on bounce (default 2.0)
 * - min_vwap_bounce_vol_mult: volume spike on bounce (default 2.0)
 * - min_flag_consolidation_bars: bars in flag (default 5)
 * - max_flag_range_pct: flag range max (default 0.3%)
 * - min_breakdown_reclaim_vol: reclaim volume mult (default 3.0)
 */
class FiveMinuteSignalScannerV130_0
{
    use HasPriceTables;

    private string $version = 'v130.0';

    private string $name = 'Elite Momentum Extended';

    // ── Scanner Configuration (public so entry finders can read) ──
    /** @var float Minimum score (0-100) */
    public float $minScore;

    /** @var float Maximum score (0-100) */
    public float $maxScore;

    /** @var int Max number of signals to return */
    public int $topN;

    /** @var float VWAP bounce volume multiplier */
    public float $minVwapBounceVolMult;

    /** @var float Max VWAP distance % for bounce */
    public float $maxVwapDistancePct;

    /** @var int Min flag consolidation bars */
    public int $minFlagConsolidationBars;

    /** @var int Max flag consolidation bars */
    public int $maxFlagConsolidationBars;

    /** @var float Max flag range % */
    public float $maxFlagRangePct;

    /** @var float Min flag breakout volume multiple */
    public float $minFlagBreakoutVolMult;

    /** @var float Min pole move % */
    public float $minPoleMovePct;

    /** @var float Min breakdown reclaim volume multiple */
    public float $minBreakdownReclaimVol;

    /** @var int Minutes before signal for entry finder */
    public int $beforeMinutes;

    /** @var int Minutes after signal for entry finder */
    public int $afterMinutes;

    /** @var int Volume lookback for entry finder */
    public int $volLookback;

    /** @var float ATR stop multiplier */
    public float $atrStopMult;

    /** @var float Min trail % */
    public float $minTrailPct;

    /** @return array<string, mixed> */
    public function scanConfig(): array
    {
        return [
            'min_score' => $this->minScore,
            'max_score' => $this->maxScore,
            'top_n' => $this->topN,
            'min_vwap_bounce_vol_mult' => $this->minVwapBounceVolMult,
            'max_vwap_distance_pct' => $this->maxVwapDistancePct,
            'min_flag_consolidation_bars' => $this->minFlagConsolidationBars,
            'max_flag_consolidation_bars' => $this->maxFlagConsolidationBars,
            'max_flag_range_pct' => $this->maxFlagRangePct,
            'min_flag_breakout_vol_mult' => $this->minFlagBreakoutVolMult,
            'min_pole_move_pct' => $this->minPoleMovePct,
            'min_breakdown_reclaim_vol' => $this->minBreakdownReclaimVol,
            'before_minutes' => $this->beforeMinutes,
            'after_minutes' => $this->afterMinutes,
            'vol_lookback' => $this->volLookback,
            'atr_stop_mult' => $this->atrStopMult,
            'min_trail_pct' => $this->minTrailPct,
        ];
    }

    public function __construct(
        private readonly BestPerformers5mService $bestPerformersService,
        private readonly GainersLosersAnalysisService $gainersLosersService
    ) {
        $this->minScore = (float) env('V130_MIN_SCORE', 45.0);
        $this->maxScore = (float) env('V130_MAX_SCORE', 100.0);
        $this->topN = (int) env('V130_TOP_N', 50);
        $this->minVwapBounceVolMult = (float) env('V130_MIN_VWAP_BOUNCE_VOL_MULT', 2.0);
        $this->maxVwapDistancePct = (float) env('V130_MAX_VWAP_DISTANCE_PCT', 0.2);
        $this->minFlagConsolidationBars = (int) env('V130_MIN_FLAG_CONSOLIDATION_BARS', 5);
        $this->maxFlagConsolidationBars = (int) env('V130_MAX_FLAG_CONSOLIDATION_BARS', 10);
        $this->maxFlagRangePct = (float) env('V130_MAX_FLAG_RANGE_PCT', 0.3);
        $this->minFlagBreakoutVolMult = (float) env('V130_MIN_FLAG_BREAKOUT_VOL_MULT', 2.5);
        $this->minPoleMovePct = (float) env('V130_MIN_POLE_MOVE_PCT', 3.0);
        $this->minBreakdownReclaimVol = (float) env('V130_MIN_BREAKDOWN_RECLAIM_VOL', 3.0);
        $this->beforeMinutes = (int) env('V130_BEFORE_MINUTES', 10);
        $this->afterMinutes = (int) env('V130_AFTER_MINUTES', 0);
        $this->volLookback = (int) env('V130_VOL_LOOKBACK', 20);
        $this->atrStopMult = (float) env('V130_ATR_STOP_MULT', 2.5);
        $this->minTrailPct = (float) env('V130_MIN_TRAIL_PCT', 0.60);
    }

    public function getVersion(): string
    {
        return $this->version;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setFullTable(bool $full): void
    {
        $this->fiveMinuteTable = $full ? 'five_minute_prices_full' : 'five_minute_prices';
        $this->oneMinuteTable = $full ? 'one_minute_prices_full' : 'one_minute_prices';
        $this->bestPerformersService->setFullTable($full);
        $this->gainersLosersService->setFullTable($full);
    }

    public function scan(
        string $asOfTsEst,
        int $lookbackMinutes = 30,
        float $minMovePct = 0.5,
        float $volMult = 1.50,
        int $limit = 80
    ): array {
        // Get movers with basic volume/movement criteria
        $topPerformers = $this->bestPerformersService->getBestPerformers([
            'assetType' => 'stock',
            'testDateTime' => $asOfTsEst,
            'days' => 5,
            'minBars' => 200,
            'minVol' => 0,
            'rthOnly' => true,
            'limit' => 500,
            'tz' => 'America/New_York',
        ]);

        // Get losers from previous trading day (bounce candidates)
        $losers = [];
        try {
            $currentDate = substr($asOfTsEst, 0, 10);
            $prevTradingDay = DB::table($this->fiveMinuteTable)
                ->where('trading_date_est', '<', $currentDate)
                ->orderBy('trading_date_est', 'desc')
                ->value('trading_date_est');

            if ($prevTradingDay) {
                $losersData = $this->gainersLosersService->getGainersAndLosers(
                    $prevTradingDay,
                    75
                );
                $losers = $losersData['losers'] ?? [];
            }
        } catch (\Exception $e) {
            // Continue without losers if error
        }

        $movers = array_merge($topPerformers, $losers);
        if (empty($movers)) {
            return [];
        }

        $candidates = [];

        foreach ($movers as $mover) {
            $symbol = $mover['symbol'];
            $asOf = $asOfTsEst;
            $lookbackStart = date('Y-m-d H:i:s', strtotime($asOf.' -'.$lookbackMinutes.' minutes'));

            // Get 5-minute bars for pattern detection
            $bars = $this->get5MinBars($symbol, $lookbackStart, $asOf);
            if (count($bars) < 8) {
                continue;
            }

            // Price filter: $3-$50 range (avoid penny stocks and expensive stocks)
            $lastBar = end($bars);
            $currentPrice = (float) ($lastBar->price ?? 0);
            if ($currentPrice < 3.0 || $currentPrice > 50.0) {
                continue;
            }

            // Require strong uptrend: EMA9 > EMA21 on at least last 3 bars
            $trendBars = 0;
            for ($i = count($bars) - 3; $i < count($bars); $i++) {
                if (isset($bars[$i]->ema9, $bars[$i]->ema21) && $bars[$i]->ema9 > $bars[$i]->ema21) {
                    $trendBars++;
                }
            }
            if ($trendBars < 3) {
                continue;
            }

            // Pattern 1: VWAP Bounce (only in strong uptrend)
            $vwapBounce = $this->detectVwapBounce($bars, $symbol);
            if ($vwapBounce) {
                $candidates[] = $vwapBounce;
            }

            // Pattern 2: Bull Flag Breakout (tight consolidation after surge)
            $bullFlag = $this->detectBullFlag($bars, $symbol);
            if ($bullFlag) {
                $candidates[] = $bullFlag;
            }

            // Pattern 3: Failed Breakdown Reversal (strong rejection)
            $failedBreakdown = $this->detectFailedBreakdown($bars, $symbol);
            if ($failedBreakdown) {
                $candidates[] = $failedBreakdown;
            }
        }

        // Rank by pattern strength score
        usort($candidates, fn ($a, $b) => ($b['score'] ?? 0) <=> ($a['score'] ?? 0));

        return array_slice($candidates, 0, $limit);
    }

    private function get5MinBars(string $symbol, string $from, string $to): array
    {
        return $this->dbSelect('
            SELECT 
                ts_est,
                open,
                high,
                low,
                price,
                volume,
                vwap,
                ema9,
                ema21,
                ema9_above_ema21
            FROM five_minute_prices
            WHERE symbol = ?

              AND ts_est >= ?
              AND ts_est <= ?
            ORDER BY ts_est ASC
        ', [$symbol, $from, $to]);
    }

    /**
     * Pattern 1: VWAP Bounce
     * - Stock dips to or below VWAP
     * - Volume dries up on dip
     * - Bounces back above VWAP with 2x+ volume
     */
    private function detectVwapBounce(array $bars, string $symbol): ?array
    {
        $minBounceVol = $this->minVwapBounceVolMult;
        $count = count($bars);

        for ($i = 3; $i < $count; $i++) {
            $current = $bars[$i];
            $prev1 = $bars[$i - 1];
            $prev2 = $bars[$i - 2];
            $prev3 = $bars[$i - 3];

            $vwap = (float) ($current->vwap ?? 0);
            $price = (float) ($current->price ?? 0);
            $prevPrice = (float) ($prev1->price ?? 0);

            if ($vwap <= 0) {
                continue;
            }

            // Check if previous bar was at/below VWAP and current is above
            $wasAtVwap = $prevPrice <= ($vwap * 1.001); // Within 0.1% of VWAP
            $nowAboveVwap = $price > ($vwap * 1.003); // At least 0.3% above

            if (! $wasAtVwap || ! $nowAboveVwap) {
                continue;
            }

            // Volume confirmation - current bar has surge
            $avgVol = ((float) $prev1->volume + (float) $prev2->volume + (float) $prev3->volume) / 3;
            $currentVol = (float) ($current->volume ?? 0);
            $volRatio = $avgVol > 0 ? $currentVol / $avgVol : 0;

            if ($volRatio < $minBounceVol) {
                continue;
            }

            // Calculate score
            $score = $this->scoreVwapBounce($bars, $i, $volRatio);

            return [
                'symbol' => $symbol,
                'signal_type' => 'VWAP_BOUNCE',
                'signal_ts_est' => (string) $current->ts_est,
                'price' => $price,
                'score' => $score,
                'vol_ratio' => $volRatio,
                'meta' => [
                    'vwap' => $vwap,
                    'bounce_pct' => round((($price - $vwap) / $vwap) * 100, 2),
                    'volume_surge' => round($volRatio, 2),
                    'pattern' => 'VWAP_BOUNCE',
                ],
            ];
        }

        return null;
    }

    /**
     * Pattern 2: Bull Flag Breakout
     * - Strong move up (3%+ in 10-15 min)
     * - Consolidates 5-10 bars within 0.3% range
     * - Volume drops during consolidation
     * - Breaks high with 2.5x+ volume
     */
    private function detectBullFlag(array $bars, string $symbol): ?array
    {
        $minFlagBars = $this->minFlagConsolidationBars;
        $maxFlagBars = $this->maxFlagConsolidationBars;
        $maxFlagRange = $this->maxFlagRangePct;
        $minPoleMove = $this->minPoleMovePct;
        $count = count($bars);

        // Need enough bars for pole + flag + breakout
        if ($count < ($maxFlagBars + 5)) {
            return null;
        }

        $current = $bars[$count - 1];
        $currentPrice = (float) ($current->price ?? 0);
        $currentVol = (float) ($current->volume ?? 0);

        // Look for flag consolidation before current bar
        for ($flagLen = $minFlagBars; $flagLen <= $maxFlagBars; $flagLen++) {
            $flagStart = $count - 1 - $flagLen;
            if ($flagStart < 3) {
                continue;
            }

            // Get flag bars
            $flagBars = array_slice($bars, $flagStart, $flagLen);
            $flagHigh = max(array_map(fn ($b) => (float) ($b->high ?? 0), $flagBars));
            $flagLow = min(array_map(fn ($b) => (float) ($b->low ?? 0), $flagBars));

            if ($flagLow <= 0) {
                continue;
            }

            $flagRange = (($flagHigh - $flagLow) / $flagLow) * 100;

            // Flag must be tight
            if ($flagRange > $maxFlagRange) {
                continue;
            }

            // Check for pole before flag (2-3 bars)
            $poleEnd = $flagStart - 1;
            $poleStart = $poleEnd - 2;

            if ($poleStart < 0) {
                continue;
            }

            $poleStartBar = $bars[$poleStart];
            $poleEndBar = $bars[$poleEnd];

            $poleStartPrice = (float) ($poleStartBar->low ?? 0);
            $poleEndPrice = (float) ($poleEndBar->high ?? 0);

            if ($poleStartPrice <= 0) {
                continue;
            }

            $poleMove = (($poleEndPrice - $poleStartPrice) / $poleStartPrice) * 100;

            // Pole must be strong
            if ($poleMove < $minPoleMove) {
                continue;
            }

            // Current bar must break flag high
            if ($currentPrice <= $flagHigh) {
                continue;
            }

            // Volume confirmation on breakout
            $flagAvgVol = array_sum(array_map(fn ($b) => (float) ($b->volume ?? 0), $flagBars)) / count($flagBars);
            $volRatio = $flagAvgVol > 0 ? $currentVol / $flagAvgVol : 0;

            if ($volRatio < 2.5) {
                continue;
            }

            $score = $this->scoreBullFlag($poleMove, $flagRange, $volRatio);

            return [
                'symbol' => $symbol,
                'signal_type' => 'BULL_FLAG_BREAKOUT',
                'signal_ts_est' => (string) $current->ts_est,
                'price' => $currentPrice,
                'score' => $score,
                'vol_ratio' => $volRatio,
                'meta' => [
                    'pole_move_pct' => round($poleMove, 2),
                    'flag_range_pct' => round($flagRange, 2),
                    'flag_bars' => count($flagBars),
                    'breakout_vol_ratio' => round($volRatio, 2),
                    'flag_high' => $flagHigh,
                    'pattern' => 'BULL_FLAG_BREAKOUT',
                ],
            ];
        }

        return null;
    }

    /**
     * Pattern 3: Failed Breakdown Reversal
     * - Breaks key support (VWAP, EMA9, or prior low)
     * - Immediately reclaims with 3x+ volume
     * - Shows strength - sellers exhausted
     */
    private function detectFailedBreakdown(array $bars, string $symbol): ?array
    {
        $minReclaimVol = $this->minBreakdownReclaimVol;
        $count = count($bars);

        for ($i = 2; $i < $count; $i++) {
            $current = $bars[$i];
            $prev = $bars[$i - 1];
            $prev2 = $bars[$i - 2];

            $vwap = (float) ($current->vwap ?? 0);
            $ema9 = (float) ($current->ema9 ?? 0);
            $prevLow = (float) ($prev->low ?? 0);
            $prev2Low = (float) ($prev2->low ?? 0);
            $currentPrice = (float) ($current->price ?? 0);
            $prevPrice = (float) ($prev->price ?? 0);

            // Check if previous bar broke below support
            $support = max($vwap, $ema9, $prev2Low);
            if ($support <= 0) {
                continue;
            }

            $brokeSupport = $prevLow < ($support * 0.997); // Broke 0.3% below support
            $reclaimedSupport = $currentPrice > ($support * 1.003); // Reclaimed 0.3% above

            if (! $brokeSupport || ! $reclaimedSupport) {
                continue;
            }

            // Volume surge on reclaim
            $prevVol = (float) ($prev->volume ?? 0);
            $currentVol = (float) ($current->volume ?? 0);
            $volRatio = $prevVol > 0 ? $currentVol / $prevVol : 0;

            if ($volRatio < $minReclaimVol) {
                continue;
            }

            $score = $this->scoreFailedBreakdown($currentPrice, $support, $volRatio);

            return [
                'symbol' => $symbol,
                'signal_type' => 'FAILED_BREAKDOWN',
                'signal_ts_est' => (string) $current->ts_est,
                'price' => $currentPrice,
                'score' => $score,
                'vol_ratio' => $volRatio,
                'meta' => [
                    'support_level' => $support,
                    'reclaim_pct' => round((($currentPrice - $support) / $support) * 100, 2),
                    'volume_surge' => round($volRatio, 2),
                    'pattern' => 'FAILED_BREAKDOWN',
                ],
            ];
        }

        return null;
    }

    private function scoreVwapBounce(array $bars, int $idx, float $volRatio): float
    {
        $bar = $bars[$idx];
        $vwap = (float) ($bar->vwap ?? 0);
        $price = (float) ($bar->price ?? 0);
        $ema9AboveEma21 = (int) ($bar->ema9_above_ema21 ?? 0);

        $score = 50.0;

        // Volume strength (0-30 pts)
        $score += min(30, ($volRatio - 2.0) * 10);

        // Distance above VWAP (0-15 pts)
        $vwapDist = (($price - $vwap) / $vwap) * 100;
        $score += min(15, $vwapDist * 30);

        // Trend alignment (0-10 pts)
        if ($ema9AboveEma21) {
            $score += 10;
        }

        return min(100, max(0, $score));
    }

    private function scoreBullFlag(float $poleMove, float $flagRange, float $volRatio): float
    {
        $score = 50.0;

        // Strong pole (0-25 pts)
        $score += min(25, ($poleMove - 3.0) * 3);

        // Tight flag (0-20 pts) - tighter is better
        $score += max(0, 20 - ($flagRange * 50));

        // Breakout volume (0-25 pts)
        $score += min(25, ($volRatio - 2.5) * 8);

        return min(100, max(0, $score));
    }

    private function scoreFailedBreakdown(float $price, float $support, float $volRatio): float
    {
        $score = 50.0;

        // Strong reclaim (0-25 pts)
        $reclaimPct = (($price - $support) / $support) * 100;
        $score += min(25, $reclaimPct * 40);

        // Volume surge (0-30 pts)
        $score += min(30, ($volRatio - 3.0) * 8);

        return min(100, max(0, $score));
    }
}
