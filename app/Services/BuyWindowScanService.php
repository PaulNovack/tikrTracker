<?php

namespace App\Services;

use App\Support\EstTimezoneHelper;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class BuyWindowScanService
{
    /**
     * Run Buy Window scan - fires buy signals ONLY during 10:00–11:30 AM ET
     * Based on v6-3 enhanced logic: Dynamic RSI, improved gates, better warmup handling
     */
    public function scan(
        ?string $asOfEst = null,
        string $assetType = 'stock',
        int $minScore = 6,
        int $lookback = 210,
        float $minNotional = 2000000.0,
        int $limit = 50
    ): array {
        // Convert to EST timezone
        $endEt = $asOfEst
            ? EstTimezoneHelper::parseEstTimestamp($asOfEst)
            : Carbon::now('America/New_York');

        // Check if we're in the buy window (10:00-11:30 AM ET)
        $timeStr = $endEt->format('H:i:s');
        $inWindow = ($timeStr >= '10:00:00' && $timeStr <= '11:30:00');

        // Define hard gates (v6.3)
        $gates = [
            'green_or_reclaim' => true,
            'min_vol_participation' => 0.55,
            'reject_breaking_structure_if_rsi_ge' => 60,
            'structure_requires_vol_under' => 1.25,
            'exhaustion_rsi70_and_low_volume' => true,
            'distribution_gate' => true,
            'avoid_whippy_baseRange_gt' => 2.5,
            'avoid_extended_distAboveVw_gt' => 1.2,
        ];

        // RSI periods to try (largest to smallest)
        $rsiPeriods = [14, 9, 7];

        if (! $inWindow) {
            return [
                'ok' => true,
                'inWindow' => false,
                'windowET' => '10:00:00-11:30:00',
                'endET' => $endEt->format('Y-m-d H:i:s'),
                'message' => 'Outside best buy window; no signals fired.',
                'hardGates' => $gates,
                'rsiPeriods' => $rsiPeriods,
                'signals' => [],
            ];
        }

        // Auto-adjust minBars based on session progress
        $minBars = 9; // Base minimum
        $warmupMinutes = 200;

        $sessionOpenEt = $endEt->copy()->setTime(9, 30, 0);
        if ($endEt <= $sessionOpenEt) {
            $minBars = 999999; // force no results before open
        } else {
            $minutesSinceOpen = $endEt->diffInMinutes($sessionOpenEt);
            $barsSinceOpen = intval($minutesSinceOpen / 5) + 1;
            $autoMinBars = max(8, intval(floor($barsSinceOpen * 0.70)));
            $minBars = min($minBars, $autoMinBars);
        }

        return $this->executeV6Analysis($endEt, $assetType, $lookback, $warmupMinutes, $minBars, $minNotional, $gates, $rsiPeriods, $minScore, $limit);
    }

    private function executeV6Analysis(
        Carbon $endEt,
        string $assetType,
        int $lookback,
        int $warmupMinutes,
        int $minBars,
        float $minNotional,
        array $gates,
        array $rsiPeriods,
        int $minScore,
        int $limit
    ): array {
        $assetTypeValue = ($assetType === 'crypto') ? 'crypto' : 'stock';

        // Use five_minute_prices table like the v6-3 script
        $symbolData = DB::table('five_minute_prices as fp')
            ->join('asset_info as ai', function ($join) use ($assetTypeValue) {
                $join->on('fp.symbol', '=', 'ai.symbol')
                    ->where('ai.asset_type', '=', $assetTypeValue)
                    ->whereNull('ai.deleted_at');
            })
            ->select('fp.symbol', 'ai.id as asset_id')
            ->where('fp.asset_type', $assetTypeValue)
            ->where(function ($query) use ($endEt, $lookback) {
                // Core bars time range
                $startTime = $endEt->copy()->subMinutes($lookback)->format('H:i:s');
                $endTime = $endEt->format('H:i:s');
                $date = $endEt->format('Y-m-d');

                $query->where('fp.trading_date_est', $date)
                    ->where('fp.ts_est', '>=', $date.' '.$startTime)
                    ->where('fp.ts_est', '<=', $date.' '.$endTime);
            })
            ->where('fp.volume', '>', 0)
            ->whereRaw('SECOND(SUBSTRING(fp.ts_est, 12, 8)) = 0')
            ->whereRaw('MOD(MINUTE(SUBSTRING(fp.ts_est, 12, 8)), 5) = 0')
            ->groupBy('fp.symbol', 'ai.id')
            ->havingRaw('COUNT(*) >= ?', [$minBars])
            ->havingRaw('AVG(fp.volume * fp.price) >= ?', [$minNotional])
            ->get()
            ->keyBy('symbol')
            ->toArray();

        if (empty($symbolData)) {
            return [
                'ok' => true,
                'inWindow' => true,
                'windowET' => '10:00:00-11:30:00',
                'endET' => $endEt->format('Y-m-d H:i:s'),
                'message' => 'No symbols met filters (strict 5-min bars + liquidity + minBars).',
                'hardGates' => $gates,
                'rsiPeriods' => $rsiPeriods,
                'signals' => [],
            ];
        }

        return $this->processV6Symbols($symbolData, $assetTypeValue, $endEt, $lookback, $warmupMinutes, $minBars, $gates, $rsiPeriods, $minScore, $limit);
    }

    private function processV6Symbols(
        array $symbolData,
        string $assetTypeValue,
        Carbon $endEt,
        int $lookback,
        int $warmupMinutes,
        int $minBars,
        array $gates,
        array $rsiPeriods,
        int $minScore,
        int $limit
    ): array {
        $signals = [];
        $rejected = [
            'red_no_reclaim' => 0,
            'break_struct_rsi' => 0,
            'break_struct_vol' => 0,
            'exhaustion_rsi70_lowvol' => 0,
            'distribution_gate' => 0,
            'low_vol_participation' => 0,
            'whippy_or_extended' => 0,
            'not_enough_bars' => 0,
        ];

        foreach ($symbolData as $symbol => $data) {
            // Fetch warmup bars (core + warmup)
            $barsWarm = $this->fetchV6SymbolBars($symbol, $assetTypeValue, $endEt, $lookback + $warmupMinutes);

            // Fetch core bars only
            $barsCore = $this->fetchV6SymbolBars($symbol, $assetTypeValue, $endEt, $lookback);

            if (count($barsCore) < $minBars) {
                $rejected['not_enough_bars']++;

                continue;
            }

            $result = $this->analyzeV6Symbol($barsWarm, $barsCore, $gates, $rsiPeriods);

            if (! $result['ok']) {
                $rejectReason = $result['reject'] ?? 'unknown';
                if (isset($rejected[$rejectReason])) {
                    $rejected[$rejectReason]++;
                }

                continue;
            }

            if (($result['score'] ?? 0) < $minScore) {
                continue;
            }

            $signals[] = $this->createV6SignalObject($symbol, $data, $assetTypeValue, $result, $endEt);
        }

        // Sort by score descending, then by volume surge, then by distance above VWAP
        usort($signals, function ($a, $b) {
            if ($a->score !== $b->score) {
                return $b->score <=> $a->score;
            }
            if ($a->metrics->volSurge !== $b->metrics->volSurge) {
                return $b->metrics->volSurge <=> $a->metrics->volSurge;
            }

            return $a->metrics->distAboveVwPct <=> $b->metrics->distAboveVwPct;
        });

        // Limit results
        if (count($signals) > $limit) {
            $signals = array_slice($signals, 0, $limit);
        }

        return [
            'ok' => true,
            'inWindow' => true,
            'windowET' => '10:00:00-11:30:00',
            'endET' => $endEt->format('Y-m-d H:i:s'),
            'hardGates' => $gates,
            'rsiPeriods' => $rsiPeriods,
            'rejectedCounts' => $rejected,
            'signalCount' => count($signals),
            'signals' => $signals,
        ];
    }

    private function fetchV6SymbolBars(string $symbol, string $assetType, Carbon $endEt, int $lookbackMinutes): array
    {
        $startTime = $endEt->copy()->subMinutes($lookbackMinutes)->format('H:i:s');
        $endTime = $endEt->format('H:i:s');
        $date = $endEt->format('Y-m-d');

        return DB::table('five_minute_prices')
            ->where('symbol', $symbol)
            ->where('asset_type', $assetType)
            ->where('trading_date_est', $date)
            ->where('ts_est', '>=', $date.' '.$startTime)
            ->where('ts_est', '<=', $date.' '.$endTime)
            ->where('volume', '>', 0)
            ->whereRaw('SECOND(SUBSTRING(ts_est, 12, 8)) = 0')
            ->whereRaw('MOD(MINUTE(SUBSTRING(ts_est, 12, 8)), 5) = 0')
            ->orderBy('ts_est')
            ->get()
            ->map(fn ($row) => [
                'ts' => $row->ts_est,
                'price' => (float) $row->price,
                'open' => (float) $row->open,
                'high' => (float) $row->high,
                'low' => (float) $row->low,
                'volume' => (float) $row->volume,
            ])
            ->toArray();
    }

    private function createV6SignalObject(string $symbol, object $data, string $assetTypeValue, array $result, Carbon $endEt): object
    {
        return (object) [
            'symbol' => $symbol,
            'asset_id' => $data->asset_id,
            'asset_type' => $assetTypeValue,
            'score' => $result['score'],
            'endET' => $endEt->format('Y-m-d H:i:s'),
            'metrics' => (object) $result['metrics'],
            'reasons' => $result['reasons'],
        ];
    }

    // V6.3 Enhanced Analysis Methods (continue in next part...)

    /**
     * Analyze symbol with v6.3 enhanced logic - separating warmup vs core bars
     */
    private function analyzeV6Symbol(array $barsWarm, array $barsCore, array $gates, array $rsiPeriods): array
    {
        $nCore = count($barsCore);
        $nWarm = count($barsWarm);

        // IMPORTANT: only require core bars for scoring/trading. Warmup is optional.
        if ($nCore < 9) {
            return ['ok' => false, 'reject' => 'not_enough_bars'];
        }

        $last = $barsCore[$nCore - 1];
        $lastClose = (float) $last['price'];
        $lastOpen = (float) $last['open'];
        $lastHigh = (float) $last['high'];
        $lastLow = (float) $last['low'];
        $lastVol = (float) $last['volume'];

        $closesWarm = array_map(fn ($b) => (float) $b['price'], ($nWarm > 0 ? $barsWarm : $barsCore));
        $nWarmEff = count($closesWarm);

        $ema9 = $this->ema($closesWarm, 9);
        $ema21 = $this->ema($closesWarm, 21);

        $e9 = $ema9[$nWarmEff - 1] ?? $closesWarm[$nWarmEff - 1];
        $e21 = $ema21[$nWarmEff - 1] ?? $closesWarm[$nWarmEff - 1];
        $e9prev = $ema9[$nWarmEff - 2] ?? $e9;

        $usedRsiPeriod = null;
        $rsiVal = $this->rsiDynamic($closesWarm, $rsiPeriods, $usedRsiPeriod);

        $highsCore = array_map(fn ($b) => (float) $b['high'], $barsCore);
        $lowsCore = array_map(fn ($b) => (float) $b['low'], $barsCore);
        $closesCore = array_map(fn ($b) => (float) $b['price'], $barsCore);
        $volsCore = array_map(fn ($b) => (float) $b['volume'], $barsCore);

        // VWAP-ish
        $sumPV = 0.0;
        $sumV = 0.0;
        for ($i = 0; $i < $nCore; $i++) {
            $tp = ($highsCore[$i] + $lowsCore[$i] + $closesCore[$i]) / 3.0;
            $v = $volsCore[$i];
            $sumPV += $tp * $v;
            $sumV += $v;
        }
        $vw = ($sumV > 0.0) ? ($sumPV / $sumV) : $lastClose;

        $chgPctLastBar = $this->safePct($lastClose - $lastOpen, $lastOpen);

        $recentHigh = max($highsCore);
        $distToRecentHighPct = ($recentHigh > 0.0) ? (($recentHigh - $lastClose) / $recentHigh) * 100.0 : 0.0;
        $distAboveVwPct = ($vw > 0.0) ? (($lastClose - $vw) / $vw) * 100.0 : 0.0;

        $avgVol = ($nCore > 1) ? (array_sum(array_slice($volsCore, 0, $nCore - 1)) / max(1, $nCore - 1)) : 0.0;
        $volSurge = ($avgVol > 0.0) ? ($lastVol / $avgVol) : 0.0;

        $sumAll = array_sum($volsCore);
        $sumLast3 = array_sum(array_slice($volsCore, max(0, $nCore - 3), 3));
        $volParticipation = ($sumAll > 0.0) ? ($sumLast3 / $sumAll) * ($nCore / 3.0) : 0.0;

        $baseBars = min(8, $nCore);
        $baseHigh = max(array_slice($highsCore, $nCore - $baseBars, $baseBars));
        $baseLow = min(array_slice($lowsCore, $nCore - $baseBars, $baseBars));
        $baseRangePct = ($lastClose > 0.0) ? (($baseHigh - $baseLow) / $lastClose) * 100.0 : 0.0;

        $range = max(1e-9, ($lastHigh - $lastLow));
        $bodyTop = max($lastClose, $lastOpen);
        $upperWickPct = (($lastHigh - $bodyTop) / $range) * 100.0;

        $higherLows = false;
        if ($nCore >= 4) {
            $l1 = (float) $barsCore[$nCore - 3]['low'];
            $l2 = (float) $barsCore[$nCore - 2]['low'];
            $l3 = (float) $barsCore[$nCore - 1]['low'];
            $higherLows = ($l1 < $l2 && $l2 < $l3);
        }

        $breaksStructure = false;
        if ($nCore >= 8) {
            $prior = array_slice($lowsCore, max(0, $nCore - 7), 6);
            $breaksStructure = ($lastLow < min($prior));
        }

        $isGreen = ($lastClose >= $lastOpen);

        $reclaimStrong = false;
        if ($nCore >= 3) {
            $prevCloses = array_slice($closesCore, $nCore - 3, 3);
            $hadBelow = false;
            foreach ($prevCloses as $c) {
                if ($vw > 0.0 && $c < $vw) {
                    $hadBelow = true;
                    break;
                }
            }
            $closesUpperHalf = ($lastClose > ($lastLow + 0.5 * ($lastHigh - $lastLow)));
            $reclaimStrong = ($lastClose > $vw && $hadBelow && $closesUpperHalf);
        }

        $pullbackPct = $distToRecentHighPct;
        $pullbackReclaim = ($pullbackPct <= 0.60 && $pullbackPct >= 0.02 && $distAboveVwPct >= 0.0);

        // Distribution risk
        $distributionRisk = false;
        if ($nCore >= 3) {
            $b1 = $barsCore[$nCore - 2];
            $b2 = $barsCore[$nCore - 1];
            $c1 = (float) $b1['price'];
            $o1 = (float) $b1['open'];
            $h1 = (float) $b1['high'];
            $l1 = (float) $b1['low'];
            $v1 = (float) $b1['volume'];
            $c2 = (float) $b2['price'];
            $o2 = (float) $b2['open'];
            $h2 = (float) $b2['high'];
            $l2 = (float) $b2['low'];
            $v2 = (float) $b2['volume'];

            $avgV = $avgVol > 0.0 ? $avgVol : max(1.0, ($v1 + $v2) / 2.0);
            $vHi1 = ($avgV > 0.0) ? ($v1 / $avgV) : 0.0;
            $vHi2 = ($avgV > 0.0) ? ($v2 / $avgV) : 0.0;

            $range2 = max(1e-9, $h2 - $l2);
            $wick2 = (($h2 - max($c2, $o2)) / $range2) * 100.0;
            $progress = $this->safePct($c2 - $c1, $c1);

            if (($vHi1 >= 1.8 || $vHi2 >= 1.8) && abs($progress) < 0.10 && $wick2 > 35.0) {
                $distributionRisk = true;
            }
        }

        // Exhaustion ONLY if RSI exists
        $exhaustionRisk = false;
        if ($rsiVal !== null) {
            if ($rsiVal >= 70.0 && $volSurge < 1.05 && $distAboveVwPct > 0.80) {
                $exhaustionRisk = true;
            }
        }

        $maeExpPct = $this->maeExpectancyPct($barsCore, 48);
        $stopPct = $this->suggestedStopPct($maeExpPct);
        $stopPrice = ($lastClose > 0.0) ? ($lastClose * (1.0 - $stopPct / 100.0)) : null;

        // ---- HARD GATES ----
        if ($gates['green_or_reclaim']) {
            if (! ($isGreen || $reclaimStrong)) {
                return ['ok' => false, 'reject' => 'red_no_reclaim'];
            }
        }

        if ($volParticipation < $gates['min_vol_participation']) {
            return ['ok' => false, 'reject' => 'low_vol_participation'];
        }

        if ($baseRangePct > $gates['avoid_whippy_baseRange_gt'] || $distAboveVwPct > $gates['avoid_extended_distAboveVw_gt']) {
            return ['ok' => false, 'reject' => 'whippy_or_extended'];
        }

        // Structure RSI gate only if RSI exists
        if ($breaksStructure && $rsiVal !== null) {
            if (
                $rsiVal >= $gates['reject_breaking_structure_if_rsi_ge'] &&
                $distAboveVwPct < 0.0 &&
                ($maeExpPct !== null && $maeExpPct > 0.35)
            ) {
                return ['ok' => false, 'reject' => 'break_struct_rsi'];
            }
        }

        if ($breaksStructure && $volSurge > $gates['structure_requires_vol_under']) {
            return ['ok' => false, 'reject' => 'break_struct_vol'];
        }

        if ($gates['exhaustion_rsi70_and_low_volume'] && $exhaustionRisk) {
            return ['ok' => false, 'reject' => 'exhaustion_rsi70_lowvol'];
        }

        if ($gates['distribution_gate'] && $distributionRisk) {
            return ['ok' => false, 'reject' => 'distribution_gate'];
        }

        // ---- V6.3 ENHANCED SCORING ----
        $score = 0;
        $reasons = [];

        if ($e9 > $e21) {
            $score += 2;
            $reasons[] = 'EMA9>EMA21';
        }
        if ($e9 > $e9prev) {
            $score += 1;
            $reasons[] = 'EMA9 rising';
        }

        if ($higherLows) {
            $score += 2;
            $reasons[] = 'higher-lows (structure)';
        } else {
            $score += 1; // v6.3 change: structure intact vs falling penalty
            $reasons[] = 'structure intact';
        }

        if ($pullbackReclaim) {
            $score += 2;
            $reasons[] = 'pullback+reclaim entry';
        }

        if ($baseRangePct <= 1.00) {
            $score += 1;
            $reasons[] = 'tight base';
        }
        if ($baseRangePct <= 0.55) {
            $score += 1;
            $reasons[] = 'very tight base';
        }

        if ($volSurge >= 1.15) {
            $score += 1;
            $reasons[] = 'volume surge';
        }
        if ($volSurge >= 1.80) {
            $score += 1;
            $reasons[] = 'big volume surge';
        }

        if ($chgPctLastBar > 0.05) {
            $score += 1;
            $reasons[] = 'positive last-bar momentum';
        }
        if ($chgPctLastBar > 0.30) {
            $score += 1;
            $reasons[] = 'strong last-bar momentum';
        }

        if ($distToRecentHighPct <= 0.25) {
            $score += 1;
            $reasons[] = 'near recent high';
        }

        if ($distAboveVwPct > 0.0) {
            $score += 1;
            $reasons[] = 'holding above VWAP-ish';
        }

        // v6.3: Notional threshold bonus
        $lastNotional = $lastVol * $lastClose;
        if ($lastNotional >= 2000000) {
            $score += 1;
            $reasons[] = 'liquid (notional)';
        }

        if ($maeExpPct !== null && $maeExpPct <= 0.18 && $distAboveVwPct > 0.0) {
            $score += 1;
            $reasons[] = 'low MAE + VWAP hold';
        }

        if ($upperWickPct > 35.0) {
            $score -= 1;
            $reasons[] = 'large upper wick (penalty)';
        }

        // v6.3: High RSI penalty
        if ($rsiVal !== null && $rsiVal >= 85.0) {
            $score -= 1;
            $reasons[] = 'high RSI (penalty)';
        }

        // ---- TAGS ----
        $tags = [];

        if (
            ($maeExpPct !== null && $maeExpPct <= 0.22) &&
            $stopPct <= 0.70 &&
            $baseRangePct <= 1.25 &&
            $distAboveVwPct > -0.10 &&
            $upperWickPct <= 30.0
        ) {
            $tags[] = 'SCALP_OK';
        }

        if (
            $higherLows &&
            ($e9 > $e21) &&
            $distAboveVwPct >= 0.05 &&
            $baseRangePct <= 1.90 &&
            $upperWickPct <= 35.0
        ) {
            $tags[] = 'TRAIL_OK';
        }

        if (
            $exhaustionRisk ||
            $distributionRisk ||
            ($rsiVal !== null && $rsiVal >= 72.0) ||
            $distAboveVwPct > 1.10 ||
            $baseRangePct > 2.20 ||
            $upperWickPct > 45.0 ||
            ($maeExpPct !== null && $maeExpPct > 0.55)
        ) {
            $tags[] = 'AVOID_OVERNIGHT';
        }

        $metrics = [
            'last' => $lastClose,
            'lastOpen' => $lastOpen,
            'isGreen' => $isGreen,
            'reclaimStrong' => $reclaimStrong,
            'chgPctLastBar' => $chgPctLastBar,
            'distToRecentHighPct' => $distToRecentHighPct,
            'volSurge' => $volSurge,
            'volParticipation' => $volParticipation,
            'vw' => $vw,
            'distAboveVwPct' => $distAboveVwPct,
            'pullbackPct' => $pullbackPct,
            'baseRangePct' => $baseRangePct,
            'upperWickPct' => $upperWickPct,
            'rsi' => $rsiVal,
            'rsiPeriodUsed' => $usedRsiPeriod,
            'higherLows' => $higherLows,
            'breaksStructure' => $breaksStructure,
            'exhaustionRisk' => $exhaustionRisk,
            'distributionRisk' => $distributionRisk,
            'maeExpPct' => $maeExpPct,
            'suggestedStopPct' => $stopPct,
            'suggestedStopPrice' => $stopPrice,
            'tags' => $tags,
        ];

        return ['ok' => true, 'score' => $score, 'metrics' => $metrics, 'reasons' => $reasons];
    }

    // Helper methods from v6.3 script below...

    /**
     * Calculate EMA
     */
    private function ema(array $values, int $period): array
    {
        $out = [];
        if (! $values) {
            return $out;
        }
        $k = 2.0 / ($period + 1.0);
        $e = $values[0];
        $out[] = $e;
        for ($i = 1; $i < count($values); $i++) {
            $e = ($values[$i] * $k) + ($e * (1.0 - $k));
            $out[] = $e;
        }

        return $out;
    }

    /**
     * Dynamic RSI: tries periods in order (e.g. 14 -> 9 -> 7) and returns first non-null.
     */
    private function rsiDynamic(array $closes, array $periods, ?int &$usedPeriod = null): ?float
    {
        foreach ($periods as $p) {
            $v = $this->rsiFixed($closes, (int) $p);
            if ($v !== null) {
                $usedPeriod = (int) $p;

                return $v;
            }
        }

        return null;
    }

    /**
     * RSI over last $period using simple gains/losses.
     * Needs at least $period+1 closes.
     */
    private function rsiFixed(array $closes, int $period): ?float
    {
        $n = count($closes);
        if ($n < $period + 1) {
            return null;
        }

        $g = 0.0;
        $l = 0.0;
        for ($i = $n - $period; $i < $n; $i++) {
            $chg = $closes[$i] - $closes[$i - 1];
            if ($chg >= 0) {
                $g += $chg;
            } else {
                $l += abs($chg);
            }
        }
        if ($l == 0.0) {
            return 100.0;
        }
        $rs = ($g / $period) / ($l / $period);

        return 100.0 - (100.0 / (1.0 + $rs));
    }

    /**
     * MAE Expectancy Percentage
     */
    private function maeExpectancyPct(array $bars, int $useLastBars = 48): ?float
    {
        $n = count($bars);
        if ($n < 12) {
            return null;
        }

        $start = max(0, $n - $useLastBars);
        $dds = [];
        for ($i = $start; $i < $n; $i++) {
            $c = (float) $bars[$i]['price'];
            $l = (float) $bars[$i]['low'];
            if ($c <= 0) {
                continue;
            }
            $dd = (($c - $l) / $c) * 100.0;
            $dds[] = $dd;
        }
        if (! $dds) {
            return null;
        }

        sort($dds);
        $m = count($dds);

        $median = ($m % 2 === 1)
            ? $dds[(int) floor($m / 2)]
            : 0.5 * ($dds[$m / 2 - 1] + $dds[$m / 2]);

        $p70Index = (int) floor(0.70 * ($m - 1));
        $p70 = $dds[$p70Index];

        return max($median, $p70);
    }

    /**
     * Suggested stop percentage
     */
    private function suggestedStopPct(?float $maeExpPct): float
    {
        if ($maeExpPct === null) {
            return 0.80;
        }
        $raw = $maeExpPct * 1.60;

        return $this->clamp($raw, 0.50, 1.35);
    }

    /**
     * Safe percentage calculation
     */
    private function safePct(float $num, float $den): float
    {
        if ($den == 0.0) {
            return 0.0;
        }

        return ($num / $den) * 100.0;
    }

    /**
     * Clamp value between min and max
     */
    private function clamp(float $x, float $lo, float $hi): float
    {
        return max($lo, min($hi, $x));
    }
}
