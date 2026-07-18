<?php

namespace App\Services\Trading;

/**
 * One Minute Entry Finder for Market Movers Momentum Strategy
 *
 * Finds optimal 1-minute entries after 5-minute two-bar momentum signal detected.
 *
 * Entry Types:
 * - MOMENTUM_CONTINUATION: Immediate entry on continuation bar
 * - PULLBACK_ENTRY: Entry on first minor pullback/consolidation
 * - BREAKOUT_ENTRY: Entry on break of recent high
 */
class OneMinuteEntryFinderMarketMovers
{
    use HasPriceTables;

    /**
     * Find best long entry on 1-minute bars after momentum signal
     */
    public function findBestLong(
        string $symbol,
        string $assetType,
        string $signalTsEst,
        string $asOfTsEst,
        int $beforeMinutes = 5,
        int $afterMinutes = 15,
        int $volLookback = 20,
        int $pivotLookback = 10,
        string $fillMethod = 'next_open'
    ): ?array {
        // Get 1-minute bars around the signal
        $bars = $this->getOneMinuteBars(
            $symbol,
            $assetType,
            $signalTsEst,
            $asOfTsEst,
            $beforeMinutes,
            $afterMinutes
        );

        if (empty($bars)) {
            return null;
        }

        // Try different entry strategies in order of preference
        $entries = [
            $this->findMomentumContinuation($bars, $signalTsEst, $fillMethod),
            $this->findPullbackEntry($bars, $signalTsEst, $fillMethod),
            $this->findBreakoutEntry($bars, $signalTsEst, $fillMethod),
        ];

        // Return first valid entry found
        foreach ($entries as $entry) {
            if ($entry !== null) {
                return $entry;
            }
        }

        return null;
    }

    private function getOneMinuteBars(
        string $symbol,
        string $assetType,
        string $signalTsEst,
        string $asOfTsEst,
        int $beforeMinutes,
        int $afterMinutes
    ): array {
        $startTs = date('Y-m-d H:i:s', strtotime($signalTsEst) - ($beforeMinutes * 60));
        $endTs = date('Y-m-d H:i:s', min(strtotime($asOfTsEst), strtotime($signalTsEst) + ($afterMinutes * 60)));

        $sql = '
            SELECT 
                ts_est,
                open,
                high,
                low,
                price as close,
                volume
            FROM one_minute_prices
            WHERE symbol = ?
                AND asset_type = ?
                AND ts_est >= ?
                AND ts_est <= ?
            ORDER BY ts_est ASC
        ';

        return $this->dbSelect($sql, [$symbol, $assetType, $startTs, $endTs]);
    }

    /**
     * MOMENTUM_CONTINUATION: Enter immediately on first bar at/after signal
     */
    private function findMomentumContinuation(array $bars, string $signalTsEst, string $fillMethod): ?array
    {
        $signalTime = strtotime($signalTsEst);

        foreach ($bars as $bar) {
            $barTime = strtotime($bar->ts_est);

            // Find first bar at or after signal time
            if ($barTime >= $signalTime) {
                $entry = $fillMethod === 'close' ? $bar->close : $bar->open;
                $stop = $bar->low * 0.995; // 0.5% below bar low

                return [
                    'type' => 'MOMENTUM_CONTINUATION',
                    'entry_ts_est' => $bar->ts_est,
                    'entry' => (float) $entry,
                    'stop' => (float) $stop,
                    'risk_pct' => (($entry - $stop) / $entry) * 100,
                    'risk_per_share' => (float) ($entry - $stop),
                    'score' => 100,
                    'vol_ratio' => null,
                ];
            }
        }

        return null;
    }

    /**
     * PULLBACK_ENTRY: Enter on first minor pullback/consolidation
     */
    private function findPullbackEntry(array $bars, string $signalTsEst, string $fillMethod): ?array
    {
        $signalTime = strtotime($signalTsEst);
        $afterSignal = false;
        $highSinceSignal = 0;
        $lookbackBars = [];

        foreach ($bars as $bar) {
            $barTime = strtotime($bar->ts_est);

            if ($barTime >= $signalTime) {
                $afterSignal = true;
                $highSinceSignal = max($highSinceSignal, $bar->high);
            }

            if ($afterSignal) {
                $lookbackBars[] = $bar;

                // Need at least 3 bars after signal to detect pullback
                if (count($lookbackBars) >= 3) {
                    $latest = $lookbackBars[count($lookbackBars) - 1];
                    $prev = $lookbackBars[count($lookbackBars) - 2];

                    // Pullback detected: price pulled back from high and now consolidating
                    $pullbackPct = (($highSinceSignal - $latest->close) / $highSinceSignal) * 100;

                    if ($pullbackPct >= 0.3 && $pullbackPct <= 2.0 && $latest->close > $prev->low) {
                        $entry = $fillMethod === 'close' ? $latest->close : $latest->open;
                        $stop = min($latest->low, $prev->low) * 0.995;

                        return [
                            'type' => 'PULLBACK_ENTRY',
                            'entry_ts_est' => $latest->ts_est,
                            'entry' => (float) $entry,
                            'stop' => (float) $stop,
                            'risk_pct' => (($entry - $stop) / $entry) * 100,
                            'risk_per_share' => (float) ($entry - $stop),
                            'score' => 95,
                            'vol_ratio' => null,
                        ];
                    }
                }
            }
        }

        return null;
    }

    /**
     * BREAKOUT_ENTRY: Enter on break of recent high
     */
    private function findBreakoutEntry(array $bars, string $signalTsEst, string $fillMethod): ?array
    {
        $signalTime = strtotime($signalTsEst);
        $lookbackBars = [];
        $afterSignal = false;

        // Calculate high of bars before signal
        $preSignalHigh = 0;
        foreach ($bars as $bar) {
            $barTime = strtotime($bar->ts_est);

            if ($barTime < $signalTime) {
                $preSignalHigh = max($preSignalHigh, $bar->high);
            }

            if ($barTime >= $signalTime) {
                $afterSignal = true;
            }

            if ($afterSignal) {
                $lookbackBars[] = $bar;

                // Check if current bar breaks pre-signal high
                if ($bar->high > $preSignalHigh && count($lookbackBars) >= 2) {
                    $entry = $fillMethod === 'close' ? $bar->close : max($bar->open, $preSignalHigh);

                    // Find recent swing low for stop
                    $recentLow = $bar->low;
                    for ($i = max(0, count($lookbackBars) - 5); $i < count($lookbackBars) - 1; $i++) {
                        $recentLow = min($recentLow, $lookbackBars[$i]->low);
                    }

                    $stop = $recentLow * 0.995;

                    return [
                        'type' => 'BREAKOUT_ENTRY',
                        'entry_ts_est' => $bar->ts_est,
                        'entry' => (float) $entry,
                        'stop' => (float) $stop,
                        'risk_pct' => (($entry - $stop) / $entry) * 100,
                        'risk_per_share' => (float) ($entry - $stop),
                        'score' => 90,
                        'vol_ratio' => null,
                    ];
                }
            }
        }

        return null;
    }
}
