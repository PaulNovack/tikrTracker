<?php

namespace App\Services\Market;

use App\Services\Trading\HasPriceTables;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;

class BestPerformers5mService
{
    use HasPriceTables;

    /**
     * Find best performers over the last N days using five_minute_prices.
     *
     * Ranking metric:
     *   pct_return = (last_price - first_price) / first_price
     *
     * Cached per (assetType, minBars, minVol, rthOnly, dateBucket) for 30 minutes.
     *
     * @return array<int, array{
     *   symbol:string,
     *   bars:int,
     *   vol_sum:int,
     *   first_ts:string,
     *   last_ts:string,
     *   first_price:string,
     *   last_price:string,
     *   pct_return:float,
     *   pct_return_pct:float
     * }>
     */
    public function getBestPerformers(array $opts = []): array
    {
        $days = (int) ($opts['days'] ?? 7);
        $assetType = (string) ($opts['assetType'] ?? 'stock');
        $limit = (int) ($opts['limit'] ?? 300);
        $minBars = (int) ($opts['minBars'] ?? 200);
        $minVol = (int) ($opts['minVol'] ?? 0);
        $rthOnly = (bool) ($opts['rthOnly'] ?? false);
        $tz = (string) ($opts['tz'] ?? 'America/New_York');
        $minPrice = (float) ($opts['minPrice'] ?? 0);
        $maxPrice = (float) ($opts['maxPrice'] ?? 999999);

        // Allow override of "now" for testing with historical data (e.g., "2025-12-15 10:15:00")
        $testDateTime = $opts['testDateTime'] ?? null;

        $now = $testDateTime
            ? CarbonImmutable::parse($testDateTime, $tz)
            : CarbonImmutable::now($tz);

        // Round to 5-minute bucket for cache key (same pattern as V25_0 scanner)
        $bucketTs = $now->format('Y-m-d H:i');
        $bucketTs = substr($bucketTs, 0, 15).'0:00';

        $cacheKey = "best_performers_5m:{$assetType}:{$minBars}:{$minVol}:{$rthOnly}:{$bucketTs}";
        $cached = Cache::get($cacheKey);

        if ($cached !== null) {
            if (count($cached) >= $limit) {
                return array_slice($cached, 0, $limit);
            }

            return $cached;
        }

        $lock = Cache::lock("lock:{$cacheKey}", 60);

        if ($lock->get()) {
            try {
                $result = $this->computeBestPerformers($opts, $now, $limit, $assetType, $minBars, $minVol, $rthOnly, $minPrice, $maxPrice);
                Cache::put($cacheKey, $result, 1800); // 30 minutes

                return $result;
            } finally {
                $lock->release();
            }
        }

        // Wait for lock owner to populate, or fall back to direct query
        return Cache::get($cacheKey) ?? $this->computeBestPerformers($opts, $now, $limit, $assetType, $minBars, $minVol, $rthOnly, $minPrice, $maxPrice);
    }

    private function computeBestPerformers(array $opts, CarbonImmutable $now, int $limit, string $assetType, int $minBars, int $minVol, bool $rthOnly, float $minPrice, float $maxPrice): array
    {
        $days = (int) ($opts['days'] ?? 7);
        $start = $now->subDays($days);

        $startTs = $start->format('Y-m-d H:i:s');
        $endTs = $now->format('Y-m-d H:i:s');

        $rthWhere = '';
        if ($rthOnly) {
            $rthWhere = " AND TIME(ts_est) >= '09:30:00' AND TIME(ts_est) <= '16:00:00' ";
        }

        // Note: Using a raw query because of CTE + LIMIT bind.
        $sql = "
            WITH recent AS (
                SELECT symbol, asset_type, ts_est, price, volume
                FROM {$this->fiveMinuteTable}
                WHERE asset_type = ?
                  AND ts_est >= ?
                  AND ts_est <= ?
                  {$rthWhere}
            ),
            bounds AS (
                SELECT
                    symbol,
                    MIN(ts_est) AS first_ts,
                    MAX(ts_est) AS last_ts,
                    COUNT(*) AS bars,
                    COALESCE(SUM(volume),0) AS vol_sum
                FROM recent
                GROUP BY symbol
                HAVING bars >= ?
                   AND vol_sum >= ?
            ),
            first_last AS (
                SELECT
                    b.symbol,
                    b.bars,
                    b.vol_sum,
                    r1.price AS first_price,
                    r2.price AS last_price,
                    b.first_ts,
                    b.last_ts
                FROM bounds b
                JOIN recent r1
                  ON r1.symbol = b.symbol AND r1.ts_est = b.first_ts
                JOIN recent r2
                  ON r2.symbol = b.symbol AND r2.ts_est = b.last_ts
                WHERE r2.price >= ? AND r2.price <= ?
            )
            SELECT
                fl.symbol,
                ai.id AS asset_id,
                fl.bars,
                fl.vol_sum,
                fl.first_ts,
                fl.last_ts,
                fl.first_price,
                fl.last_price,
                (fl.last_price - fl.first_price) / NULLIF(fl.first_price, 0) AS pct_return
            FROM first_last fl
            LEFT JOIN asset_info ai ON ai.symbol = fl.symbol AND ai.asset_type = ?
            ORDER BY pct_return DESC
            LIMIT {$limit}
        ";

        $rows = $this->dbSelect($sql, [
            $assetType,
            $startTs,
            $endTs,
            $minBars,
            $minVol,
            $minPrice,
            $maxPrice,
            $assetType,
        ]);

        // Normalize output
        $out = [];
        foreach ($rows as $r) {
            $pct = (float) $r->pct_return;
            $out[] = [
                'symbol' => (string) $r->symbol,
                'asset_id' => $r->asset_id !== null ? (int) $r->asset_id : null,
                'bars' => (int) $r->bars,
                'vol_sum' => (int) $r->vol_sum,
                'first_ts' => (string) $r->first_ts,
                'last_ts' => (string) $r->last_ts,
                'first_price' => (string) $r->first_price,
                'last_price' => (string) $r->last_price,
                'pct_return' => $pct,
                'pct_return_pct' => $pct * 100.0,
            ];
        }

        return $out;
    }
}
