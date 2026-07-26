<?php

namespace App\Services\Trading;

use Illuminate\Support\Facades\DB;

/**
 * Redis-backed version of the v1600.0 Early Momentum scanner.
 *
 * Uses the same BestPerformers + prior-day losers universe as the SQL version,
 * but reads 5m bars from Redis instead of MySQL CTE.
 */
class FiveMinuteSignalScannerV1600_0Redis extends FiveMinuteSignalScannerV1600_0
{
    use UsesRedisForScanning;

    protected function doScan(
        string $asOfTsEst,
        int $lookbackMinutes = 60,
        float $minMovePct = 0.4,
        float $volMult = 1.5,
        int $limit = 60,
        bool $skipCache = false,
        ?string $symbol = null
    ): array {
        if (! $this->shouldUseRedis()) {
            return parent::doScan(...func_get_args());
        }

        // ── Universe: same as SQL — BestPerformers + prior-day losers ──
        $topPerformers = $this->bestPerformersService->getBestPerformers([
            'assetType' => 'stock', 'testDateTime' => $asOfTsEst,
            'days' => $this->topDays, 'minBars' => 200, 'minVol' => 0,
            'rthOnly' => true, 'limit' => $this->topLimit,
            'tz' => 'America/New_York',
        ]);
        $symbols = array_column($topPerformers, 'symbol');

        try {
            $currentDate = substr($asOfTsEst, 0, 10);
            $prevTradingDay = DB::table($this->fiveMinuteTable)
                ->where('trading_date_est', '<', $currentDate)
                ->orderBy('trading_date_est', 'desc')->value('trading_date_est');
            if ($prevTradingDay) {
                $losersData = $this->gainersLosersService->getGainersAndLosers($prevTradingDay, $this->losersLimit);
                $loserSymbols = array_column($losersData['losers'] ?? [], 'symbol');
                $symbols = array_values(array_unique(array_merge($symbols, $loserSymbols)));
            }
        } catch (\Throwable) {
        }

        if ($symbols === []) {
            return [];
        }

        // ── Standard 6-gate scan from Redis ──
        $cfg = $this->scanConfig();
        $atrPeriod = (int) ($cfg['atr_period_5m'] ?? 14);
        $rvolLookback = (int) ($cfg['rvol_lookback_5m'] ?? 20);
        $moveBars = (int) ($cfg['move_bars_5m'] ?? 6);
        $activeWindowMinutes = (int) ($cfg['active_window_minutes'] ?? 8);
        $minNotional5m = (float) ($cfg['min_notional_5m'] ?? 150000);
        $minAtrPct5m = (float) ($cfg['min_atr_pct_5m'] ?? 0.55);
        $minRvol5m = (float) ($cfg['min_rvol_5m'] ?? 1.25);
        $minMove30m = (float) ($cfg['min_move_30m_pct'] ?? 0.45);
        $preBreakoutRvolMult = $this->preBreakoutRvolMult;

        $barsNeeded = max($moveBars, $rvolLookback, $atrPeriod) + 10;
        $allBars = $this->redisRepo()->getLatestBars('5m', $symbols, 'stock', $asOfTsEst, $barsNeeded);

        $spyMove30m = $this->getSpyMovement30m($asOfTsEst, $moveBars);
        $asOfEpochRaw = strtotime($asOfTsEst);
        $asOfEpoch = $asOfEpochRaw !== false ? strtotime(date('Y-m-d H:i:00', $asOfEpochRaw)) : false;
        if ($asOfEpoch === false) {
            return [];
        }
        $maxSignalAgeSeconds = max(1, $activeWindowMinutes) * 60;

        $out = [];
        foreach ($allBars as $sym => $bars) {
            $barCount = count($bars);
            if ($barCount < $moveBars + 2) {
                continue;
            }

            $last = $bars[$barCount - 1];
            $lastClose = $last->close;
            $lastVol = $last->volume;
            $lastTsEst = $last->tsEst;

            $signalAgeSeconds = $asOfEpoch - (int) strtotime($lastTsEst.' EST');
            if ($signalAgeSeconds < 0 || $signalAgeSeconds > $maxSignalAgeSeconds || $lastClose <= 0) {
                continue;
            }

            // 30m move
            $nbackIdx = $barCount - 1 - $moveBars;
            $closeNback = $nbackIdx >= 0 ? $bars[$nbackIdx]->close : null;
            $move30m = ($closeNback !== null && $closeNback > 0)
                ? (($lastClose - $closeNback) / $closeNback) * 100 : 0.0;

            // RVOL
            $volSlice = array_slice($bars, max(0, $barCount - $rvolLookback), $rvolLookback);
            $avgVol = count($volSlice) > 0
                ? array_sum(array_map(static fn ($b) => $b->volume, $volSlice)) / count($volSlice) : 0.0;
            $rvolRatio = $avgVol > 0 ? $lastVol / $avgVol : 0.0;

            // ATR
            $atrBars = array_slice($bars, max(0, $barCount - $atrPeriod - 1));
            $trs = [];
            for ($i = 1, $n = count($atrBars); $i < $n; $i++) {
                $prevC = $atrBars[$i - 1]->close;
                $h = $atrBars[$i]->high;
                $l = $atrBars[$i]->low;
                $trs[] = max($h - $l, abs($h - $prevC), abs($l - $prevC));
            }
            $atrVal = count($trs) > 0 ? array_sum($trs) / count($trs) : 0.0;
            $atrPct = $lastClose > 0 ? ($atrVal / $lastClose) * 100 : 0.0;
            $notional = $lastClose * $lastVol;

            // Gates
            if ($notional < $minNotional5m) {
                continue;
            }
            if ($atrPct < $minAtrPct5m) {
                continue;
            }
            if (! ($rvolRatio >= $minRvol5m || $move30m >= $minMove30m)) {
                continue;
            }
            if ($move30m < $minMovePct) {
                continue;
            }

            // Pre-breakout gate
            $isPreBreakout = $rvolRatio > 1.0 && $rvolRatio < $preBreakoutRvolMult;

            $rvolCapped = min(6.0, $rvolRatio);
            $score = ($move30m * 1.2) + ($rvolCapped * 1.0) + ($atrPct * 0.8);
            if ($isPreBreakout) {
                $score += 1.5; // pre-breakout boost
            }
            $atr = $atrPct > 0 ? round(($atrPct / 100) * $lastClose, 6) : null;

            $out[] = [
                'symbol' => $sym,
                'signal_type' => 'EARLY_MOMENTUM_5M',
                'signal_ts_est' => $lastTsEst,
                'score' => round($score, 3),
                'atr' => $atr,
                'atr_pct' => $atrPct,
                'meta' => [
                    'move_30m_pct' => round($move30m, 3),
                    'rvol_5m' => round($rvolRatio, 3),
                    'atr_pct_5m' => round($atrPct, 3),
                    'notional_last5m' => round($notional, 2),
                    'pct_nd' => null,
                    'spy_move_30m_pct' => round($spyMove30m, 3),
                    'universe_size' => count($symbols),
                    'signal_age_seconds' => $signalAgeSeconds,
                    'version' => 'v1600.0',
                    'current_price' => $lastClose,
                ],
            ];
        }

        usort($out, static fn (array $a, array $b): int => ($b['score'] <=> $a['score']));

        return array_slice($out, 0, max(1, $limit));
    }
}
