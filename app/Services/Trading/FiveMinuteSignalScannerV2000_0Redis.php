<?php

namespace App\Services\Trading;

use Illuminate\Support\Facades\Redis;

/**
 * Redis-backed version of the v2000.0 Market Movers Universe scanner.
 *
 * Universe: symbols that appeared in the last 5 market-movers days with 4%+ gains.
 * Returns their latest 5m bar with universe metadata. Minimal gates — alerts
 * the full movers universe first, filtering comes later.
 */
class FiveMinuteSignalScannerV2000_0Redis extends FiveMinuteSignalScannerV2000_0
{
    use UsesRedisForScanning;

    protected function doScan(
        string $asOfTsEst,
        int $lookbackMinutes = 60,
        float $minMovePct = 0.4,
        float $volMult = 1.5,
        int $limit = 10000,
        bool $skipCache = false,
        ?string $symbol = null
    ): array {
        if (! $this->shouldUseRedis()) {
            return parent::doScan(...func_get_args());
        }

        $tradeDate = substr($asOfTsEst, 0, 10);

        // ── Universe: market movers from last 5 days, cached in Redis ──
        $cacheKey = 'scan_v2000_0:universe:'.$tradeDate;
        $cached = Redis::get($cacheKey);

        if ($cached !== null) {
            $universeRows = json_decode($cached, true);
        } else {
            // Fallback to MySQL for universe (market_movers table is MySQL-only)
            $universeRows = $this->dbSelect('
                SELECT
                    p.symbol,
                    COUNT(DISTINCT p.trading_date_est) AS days_appeared,
                    ROUND(MAX(((p.price - p.open) / p.open) * 100), 2) AS max_gain_pct
                FROM five_minute_prices p
                JOIN (
                    SELECT trading_date FROM market_movers
                    ORDER BY trading_date DESC LIMIT 5
                ) d ON d.trading_date = p.trading_date_est
                WHERE p.open > 0
                  AND ((p.price - p.open) / p.open) * 100 >= 4
                GROUP BY p.symbol
                ORDER BY days_appeared DESC, max_gain_pct DESC, p.symbol
            ');
            Redis::setex($cacheKey, 3600, json_encode($universeRows));
        }

        if (empty($universeRows)) {
            return [];
        }

        $symbols = array_values(array_filter(array_map(
            static fn ($row) => is_array($row) ? ($row['symbol'] ?? '') : ($row->symbol ?? ''),
            $universeRows
        )));

        if ($symbols === []) {
            return [];
        }

        // ── Read latest 5m bars from Redis ──
        $allBars = $this->redisRepo()->getLatestBars('5m', $symbols, 'stock', $asOfTsEst, 5);

        $out = [];
        foreach ($universeRows as $rank => $universeRow) {
            $symbol = is_array($universeRow) ? ($universeRow['symbol'] ?? '') : ($universeRow->symbol ?? '');
            $bars = $allBars[$symbol] ?? [];

            if (count($bars) === 0) {
                continue;
            }

            $last = $bars[count($bars) - 1];
            $daysAppeared = (int) (is_array($universeRow) ? ($universeRow['days_appeared'] ?? 0) : ($universeRow->days_appeared ?? 0));
            $maxGainPct = (float) (is_array($universeRow) ? ($universeRow['max_gain_pct'] ?? 0) : ($universeRow->max_gain_pct ?? 0));
            $score = round(($daysAppeared * 10) + $maxGainPct, 3);

            $out[] = [
                'symbol' => $symbol,
                'signal_type' => 'MOMO_5D_UNIVERSE',
                'signal_ts_est' => $last->tsEst,
                'score' => $score,
                'atr' => null,
                'atr_pct' => null,
                'meta' => [
                    'version' => 'v2000.0',
                    'universe_rank' => $rank + 1,
                    'universe_size' => count($universeRows),
                    'days_appeared' => $daysAppeared,
                    'max_gain_pct' => $maxGainPct,
                    'current_price' => $last->close,
                    'current_volume' => $last->volume,
                    'trading_date' => $tradeDate,
                    'universe_days' => 5,
                    'lookback_minutes' => $lookbackMinutes,
                    'min_move_pct' => $minMovePct,
                    'vol_mult' => $volMult,
                ],
            ];

            if (count($out) >= max(1, $limit)) {
                break;
            }
        }

        return $out;
    }
}
