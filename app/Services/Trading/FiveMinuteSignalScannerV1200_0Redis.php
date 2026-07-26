<?php

namespace App\Services\Trading;

use Illuminate\Support\Facades\Redis;

/**
 * Redis-backed version of the v1200.0 Two-Bar Momentum scanner.
 *
 * Universe: market movers (4%+ intraday gainers). Gates: last 2 of 3 bars rising,
 * volume above average, price range, time window.
 */
class FiveMinuteSignalScannerV1200_0Redis extends FiveMinuteSignalScannerV1200_0
{
    use UsesRedisForScanning;

    protected function doScan(
        string $asOfTsEst,
        int $lookbackMinutes = 60,
        float $minMovePct = 1.2,
        float $volMult = 3.5,
        int $limit = 60,
        bool $skipCache = false,
        ?string $symbol = null
    ): array {
        if (! $this->shouldUseRedis()) {
            return parent::doScan(...func_get_args());
        }

        $topMovers = $this->topMovers;
        $minGainPct = $this->minGainPct;
        $minPrice = $this->minPrice;
        $maxPrice = $this->maxPrice;
        $minVolRatio = $this->minVolRatio;
        $timeWindowStart = $this->timeWindowStart;
        $timeWindowEnd = $this->timeWindowEnd;
        $tradeDate = substr($asOfTsEst, 0, 10);

        // Time window gate
        $currentTime = substr($asOfTsEst, 11, 8);
        if ($currentTime < $timeWindowStart || $currentTime > $timeWindowEnd) {
            return [];
        }

        // ── Universe: market movers, cached in Redis ──
        $cacheKey = 'scan_v1200_0:universe:'.$tradeDate;
        $cached = Redis::get($cacheKey);
        if ($cached !== null) {
            $moverSymbols = json_decode($cached, true);
        } else {
            $moverSymbols = app(\App\Services\MarketMoversService::class)
                ->getTodaysTopMoversFromCache($tradeDate, $topMovers);
            Redis::setex($cacheKey, 3600, json_encode($moverSymbols));
        }

        if (empty($moverSymbols)) {
            return [];
        }

        // Read 5 bars from Redis per symbol (need 3 for two-bar momentum + 2 for vol avg)
        $allBars = $this->redisRepo()->getLatestBars('5m', $moverSymbols, 'stock', $asOfTsEst, 10);

        $out = [];
        foreach ($allBars as $sym => $bars) {
            $barCount = count($bars);
            if ($barCount < 3) {
                continue;
            }

            $last = $bars[$barCount - 1];
            $prev = $bars[$barCount - 2];
            $prev2 = $bars[$barCount - 3];

            // Price gate
            if ($last->close < $minPrice || $last->close > $maxPrice) {
                continue;
            }

            // Two-bar momentum: last 2 bars rising
            if ($prev->close <= $prev2->close || $last->close <= $prev->close) {
                continue;
            }

            // Volume gate: last bar volume > average
            $volSlice = array_slice($bars, max(0, $barCount - 5), 5);
            $avgVol = count($volSlice) > 0
                ? array_sum(array_map(static fn ($b) => $b->volume, $volSlice)) / count($volSlice)
                : 0.0;
            if ($avgVol > 0 && $last->volume / $avgVol < $minVolRatio) {
                continue;
            }

            $score = (($last->close - $prev2->close) / max($prev2->close, 0.01)) * 100;

            $out[] = [
                'symbol' => $sym,
                'signal_type' => 'TWO_BAR_MOMENTUM',
                'signal_ts_est' => $last->tsEst,
                'score' => round($score, 3),
                'atr' => null,
                'atr_pct' => null,
                'meta' => [
                    'version' => 'v1200.0',
                    'current_price' => $last->close,
                    'bar1_close' => $prev2->close,
                    'bar2_close' => $prev->close,
                    'bar3_close' => $last->close,
                    'vol_ratio' => $avgVol > 0 ? round($last->volume / $avgVol, 3) : 0,
                ],
            ];

            if (count($out) >= max(1, $limit)) {
                break;
            }
        }

        usort($out, static fn (array $a, array $b): int => ($b['score'] <=> $a['score']));

        return array_slice($out, 0, max(1, $limit));
    }
}
