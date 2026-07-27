<?php

namespace App\Services\Trading;

/**
 * Redis-backed version of the v1500.0 Opening Range Breakout scanner.
 *
 * Pipeline O uses Redis for symbol scanning (scanSymbol) in the event-driven
 * bar-events:consume path, and falls back to SQL for the batch scan.
 *
 * Since V1500_0 is a standalone scanner (not extending AbstractSignalScanner),
 * we provide a full doScan() override that checks shouldUseRedis() and falls
 * back to parent SQL when disabled.
 */
class FiveMinuteSignalScannerV1500_0Redis extends FiveMinuteSignalScannerV1500_0
{
    use UsesRedisForScanning;

    /**
     * Per-symbol ORB signal check for the event-driven path.
     *
     * Reads 5m bars from Redis and evaluates Opening Range Breakout criteria:
     * - Compute 9:30-10:00 opening range high/low from Redis bars
     * - Check if latest bar breaks above OR high with volume confirmation
     * - Score based on vol ratio + breakout strength + range quality
     */
    public function scanSymbol(string $symbol, string $asOfTsEst): ?array
    {
        $tradeDate = substr($asOfTsEst, 0, 10);
        $bars = $this->redisRepo()->getBars('5m', $symbol, 'stock', $tradeDate.' 09:30:00', $asOfTsEst, 80);

        if (count($bars) < 7) {
            return null;
        }

        // Compute opening range (9:30-10:00 bars)
        $orHigh = 0.0;
        $orLow = PHP_FLOAT_MAX;
        $orVolSum = 0.0;
        $orVolCount = 0;

        foreach ($bars as $bar) {
            $barTime = substr($bar->tsEst, 11, 8);
            if ($barTime >= '09:30:00' && $barTime <= '10:00:00') {
                $orHigh = max($orHigh, $bar->high);
                $orLow = min($orLow, $bar->low);
                $orVolSum += $bar->volume;
                $orVolCount++;
            }
        }

        if ($orHigh <= 0 || $orLow >= PHP_FLOAT_MAX || $orVolCount < 5) {
            return null;
        }

        $orAvgVol = $orVolSum / $orVolCount;

        // Get the latest bar
        $last = $bars[count($bars) - 1];
        $lastClose = $last->close;
        $lastHigh = $last->high;
        $lastTsEst = $last->tsEst;

        // Time check
        $barTime = substr($lastTsEst, 11, 8);
        if ($barTime < $this->timeWindowStart) {
            return null;
        }

        // Price filter
        if ($lastClose < $this->minPrice || $lastClose > $this->maxPrice) {
            return null;
        }

        // ORB breakout: current high must be above opening range high
        if ($lastHigh <= $orHigh) {
            return null;
        }

        // Volume confirmation
        $volRatio = $orAvgVol > 0 ? $last->volume / $orAvgVol : 0;
        if ($volRatio < $this->minVolRatio) {
            return null;
        }

        // Breakout strength
        $breakoutPct = 100.0 * (($lastHigh - $orHigh) / $orHigh);
        $orRangePct = 100.0 * (($orHigh - $orLow) / $orHigh);

        // Score
        $score = ($volRatio * 10) + ($breakoutPct * 5) + $orRangePct;

        // ATR
        $atrVal = 0.0;
        $atrPct = 0.0;
        $atrBars = array_slice($bars, max(0, count($bars) - 15));
        $trs = [];
        for ($i = 1, $n = count($atrBars); $i < $n; $i++) {
            $prevC = $atrBars[$i - 1]->close;
            $h = $atrBars[$i]->high;
            $l = $atrBars[$i]->low;
            $trs[] = max($h - $l, abs($h - $prevC), abs($l - $prevC));
        }
        if (count($trs) > 0) {
            $atrVal = array_sum($trs) / count($trs);
            $atrPct = $lastClose > 0 ? ($atrVal / $lastClose) * 100 : 0.0;
        }
        $atr = ($atrPct !== 0.0 && $lastClose > 0) ? round(($atrPct / 100) * $lastClose, 6) : null;

        return [
            'symbol' => $symbol,
            'signal_type' => 'ORB_BREAKOUT',
            'signal_ts_est' => $lastTsEst,
            'score' => round($score, 3),
            'atr' => $atr,
            'atr_pct' => $atrPct,
            'meta' => [
                'move_30m_pct' => 0.0,
                'rvol_5m' => round($volRatio, 3),
                'atr_pct_5m' => round($atrPct, 3),
                'notional_last5m' => round($lastClose * $last->volume, 2),
                'pct_nd' => null,
                'spy_move_30m_pct' => 0.0,
                'universe_size' => 1,
                'signal_age_seconds' => 0,
                'version' => $this->getVersion(),
                'current_price' => $lastClose,
                'or_high' => round($orHigh, 4),
                'or_low' => round($orLow, 4),
                'or_range' => round($orHigh - $orLow, 4),
                'breakout_pct' => round($breakoutPct, 3),
                'vol_ratio' => round($volRatio, 3),
            ],
        ];
    }
}
