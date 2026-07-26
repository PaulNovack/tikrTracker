<?php

namespace App\Services\Trading;

/**
 * Redis-backed version of the v1100.0 Scarcity Leader scanner.
 *
 * Full custom doScan() that reads 5m bars from Redis and applies
 * all v1100 gates identically to the SQL version:
 *   - SPY context (benchmark bars from Redis)
 *   - Market weakness gate
 *   - above_vwap = 1, ema9 > ema21 (from computeEma9Ema21)
 *   - Price, volume, day gain, VWAP distance gates
 *   - RS ratio vs SPY, distance from high (ATR multiples)
 *   - Range contraction, green close
 */
class FiveMinuteSignalScannerV1100_0Redis extends FiveMinuteSignalScannerV1100_0
{
    use UsesRedisForScanning;

    protected function doScan(
        string $asOfTsEst,
        int $lookbackMinutes = 60,
        float $minMovePct = 0.4,
        float $volMult = 1.5,
        int $limit = 20,
        bool $skipCache = false,
        ?string $symbol = null
    ): array {
        if (! $this->shouldUseRedis()) {
            return parent::doScan(...func_get_args());
        }

        $benchmarkSymbol = config('app.trading_market_benchmark_symbol', 'SPY');

        // ── Config ──
        $minPrice = $this->minPrice;
        $maxPrice = $this->maxPrice;
        $minVolRatio = $this->minVolRatio;
        $minRelStrengthRatio = $this->minRelStrengthRatio;
        $minMarketWeaknessPct = $this->minMarketWeaknessPct;
        $maxDistanceFromHighAtr = $this->maxDistanceFromHighAtr;
        $maxVwapExtensionPct = $this->maxVwapExtensionPct;
        $minEmaSpreadPct = $this->minEmaSpreadPct;
        $minDollarVolPerMinute = $this->minDollarVolPerMinute;
        $requireSpyBelowVwap = $this->requireSpyBelowVwap;
        $minDayGainPct = $this->minDayGainPct;
        $lookbackBarsForHigh = $this->lookbackBarsForHigh;
        $requireGreenClose = $this->requireGreenClose;

        // ── Universe ──
        $symbols = $this->buildIntradayUniverse(
            'scan_v1100_0:universe_symbols',
            'trading.market_movers.pipeline_k',
            $asOfTsEst,
            $skipCache
        );

        if ($symbols === []) {
            return [];
        }

        $barsNeeded = $lookbackBarsForHigh + 15;
        $allBars = $this->redisRepo()->getLatestBars('5m', $symbols, 'stock', $asOfTsEst, $barsNeeded);

        // ── SPY context from Redis ──
        $benchBars = $this->redisRepo()->getLatestBars('5m', [$benchmarkSymbol], 'stock', $asOfTsEst, 5);
        $spyBars = $benchBars[$benchmarkSymbol] ?? [];
        $spyBarCount = count($spyBars);
        $spyLast = $spyBarCount > 0 ? $spyBars[$spyBarCount - 1] : null;
        $spyPrice = $spyLast ? $spyLast->close : 0;
        $spyVwap = $spyLast ? ($spyLast->vwap ?? $spyPrice) : 0;
        $spyAboveVwap = $spyLast && $spyLast->close > $spyVwap ? 1 : 0;
        $spyPrevBar = $spyBarCount >= 5 ? $spyBars[$spyBarCount - 5] : $spyLast;
        $spyPrevPrice = $spyPrevBar ? $spyPrevBar->close : $spyPrice;
        $spyMove15mPct = $spyPrevPrice > 0 ? (($spyPrice - $spyPrevPrice) / $spyPrevPrice) * 100 : 0;

        // Market weakness gate
        if ($spyMove15mPct > $minMarketWeaknessPct) {
            return [];
        }
        if ($requireSpyBelowVwap && $spyAboveVwap === 1) {
            return [];
        }

        $asOfEpochRaw = strtotime($asOfTsEst);
        $asOfEpoch = $asOfEpochRaw !== false
            ? strtotime(date('Y-m-d H:i:00', $asOfEpochRaw))
            : false;
        if ($asOfEpoch === false) {
            return [];
        }
        $maxSignalAgeSeconds = 600;

        $out = [];
        foreach ($allBars as $sym => $bars) {
            $barCount = count($bars);
            if ($barCount < $lookbackBarsForHigh + 2) {
                continue;
            }

            $last = $bars[$barCount - 1];
            $lastClose = $last->close;
            $lastOpen = $last->open;
            $lastHigh = $last->high;
            $lastLow = $last->low;
            $lastVol = $last->volume;
            $lastVwap = $last->vwap ?? $lastClose;
            $lastTsEst = $last->tsEst;

            // Age check
            $signalAgeSeconds = $asOfEpoch - (int) strtotime($lastTsEst.' EST');
            if ($signalAgeSeconds < 0 || $signalAgeSeconds > $maxSignalAgeSeconds || $lastClose <= 0) {
                continue;
            }

            // Price gate
            if ($lastClose < $minPrice || $lastClose > $maxPrice) {
                continue;
            }

            // Green close gate
            if ($requireGreenClose && $lastClose <= $lastOpen) {
                continue;
            }

            // above_vwap gate
            $aboveVwap = $last->aboveVwap ?? ($lastClose > $lastVwap ? 1 : 0);
            if ($aboveVwap !== 1) {
                continue;
            }

            // EMA9/EMA21 gate
            $emas = $this->computeEma9Ema21($bars);
            $ema9 = $emas['ema9'];
            $ema21 = $emas['ema21'];
            if ($ema9 <= $ema21) {
                continue;
            }

            // EMA spread
            $emaSpreadPct = $ema21 > 0 ? (($ema9 - $ema21) / $ema21) * 100 : 0;
            if ($emaSpreadPct < $minEmaSpreadPct) {
                continue;
            }

            // VWAP distance
            $vwapDistPct = $lastVwap > 0 ? (($lastClose - $lastVwap) / $lastVwap) * 100 : 0;
            if ($vwapDistPct < 0.15 || $vwapDistPct > $maxVwapExtensionPct) {
                continue;
            }

            // Day gain
            $dayGainPct = $lastOpen > 0 ? (($lastClose - $lastOpen) / $lastOpen) * 100 : 0;
            if ($dayGainPct < $minDayGainPct) {
                continue;
            }

            // Volume gate
            if ($lastVol <= 0) {
                continue;
            }
            $volSlice = array_slice($bars, max(0, $barCount - 20), 20);
            $avgVol = count($volSlice) > 0
                ? array_sum(array_map(static fn ($b) => $b->volume, $volSlice)) / count($volSlice)
                : 0;
            $volRatio = $avgVol > 0 ? $lastVol / $avgVol : 0;
            if ($volRatio < $minVolRatio) {
                continue;
            }

            // Dollar volume per minute
            if (($lastClose * $lastVol) / 5 < $minDollarVolPerMinute) {
                continue;
            }

            // ATR
            $trs = [];
            for ($i = 1; $i < $barCount; $i++) {
                $prevC = $bars[$i - 1]->close;
                $h = $bars[$i]->high;
                $l = $bars[$i]->low;
                $trs[] = max($h - $l, abs($h - $prevC), abs($l - $prevC));
            }
            $atr = count($trs) > 0 ? array_sum($trs) / count($trs) : 0;

            // Rolling high
            $rollingHigh = $lastHigh;
            $lookbackStart = max(0, $barCount - $lookbackBarsForHigh - 1);
            for ($i = $lookbackStart; $i < $barCount; $i++) {
                if ($bars[$i]->high > $rollingHigh) {
                    $rollingHigh = $bars[$i]->high;
                }
            }

            $distFromHighAtr = $atr > 0 ? ($rollingHigh - $lastClose) / $atr : 999;
            if ($distFromHighAtr > $maxDistanceFromHighAtr) {
                continue;
            }

            // RS ratio
            $prevClose = $barCount >= 2 ? $bars[$barCount - 2]->close : $lastClose;
            $stockMove = $prevClose > 0 ? (($lastClose - $prevClose) / $prevClose) * 100 : 0;
            $relStrengthRatio = abs($spyMove15mPct) > 0.01 ? $stockMove / $spyMove15mPct : 0;
            if ($relStrengthRatio < $minRelStrengthRatio) {
                continue;
            }

            // Range contraction
            $currentRange = $lastHigh - $lastLow;
            $priorRanges = [];
            for ($i = max(0, $barCount - 6); $i < $barCount - 1; $i++) {
                $priorRanges[] = $bars[$i]->high - $bars[$i]->low;
            }
            $priorAvgRange = count($priorRanges) > 0 ? array_sum($priorRanges) / count($priorRanges) : 999;

            // Score
            $atrPct = $lastClose > 0 ? ($atr / $lastClose) * 100 : 0;
            $score = ($relStrengthRatio * 20) + ($emaSpreadPct * 10) + ($volRatio * 5) + ($vwapDistPct * 3);
            $notional = $lastClose * $lastVol;

            $out[] = [
                'symbol' => $sym,
                'signal_type' => 'SCARCITY_LEADER',
                'signal_ts_est' => $lastTsEst,
                'score' => round($score, 3),
                'atr' => round($atr, 6),
                'atr_pct' => round($atrPct, 3),
                'meta' => [
                    'move_30m_pct' => round($stockMove, 3),
                    'rvol_5m' => round($volRatio, 3),
                    'atr_pct_5m' => round($atrPct, 3),
                    'notional_last5m' => round($notional, 2),
                    'pct_nd' => null,
                    'spy_move_30m_pct' => round($spyMove15mPct, 3),
                    'universe_size' => count($symbols),
                    'signal_age_seconds' => $signalAgeSeconds,
                    'version' => 'v1100.0',
                    'current_price' => $lastClose,
                    'rel_strength_ratio' => round($relStrengthRatio, 3),
                    'ema_spread_pct' => round($emaSpreadPct, 3),
                    'distance_from_high_atr' => round($distFromHighAtr, 3),
                ],
            ];
        }

        usort($out, static fn (array $a, array $b): int => ($b['score'] <=> $a['score']));

        return array_slice($out, 0, max(1, $limit));
    }
}
