<?php

namespace App\Services\Trading;

use Illuminate\Support\Facades\DB;

class OneMinuteUniverseScannerV1
{
    private string $version = 'v1';

    public function getVersion(): string
    {
        return $this->version;
    }

    /**
     * Pick symbols that are "alive" as-of a given EST timestamp from one_minute_prices.
     * Only includes symbols marked for 1-minute processing (1_min=1).
     *
     * Filters:
     * - has bars within the last $activeMinutes
     * - min volume over last $activeMinutes
     * - optional min notional over last $activeMinutes: SUM(volume * close)
     * - only symbols marked with 1_min=1 (top 1500 most liquid stocks)
     */
    public function activeSymbols(
        string $assetType,
        string $asOfTsEst,
        int $activeMinutes = 3,
        int $minVolume = 100000,
        float $minNotional = 0.0,
        int $limit = 300
    ): array {
        // Join with asset_info to filter by 1_min=1 (top 1500 stocks only)
        $sql = '
            SELECT
              omp.symbol,
              SUM(COALESCE(omp.volume,0)) AS vol_sum,
              SUM(COALESCE(omp.volume,0) * COALESCE(omp.price,0)) AS notional_sum,
              MAX(omp.ts_est) AS last_ts_est
            FROM one_minute_prices omp FORCE INDEX (idx_omp_buy_signals_batch_covering)
            INNER JOIN asset_info ai ON omp.symbol = ai.symbol
            WHERE omp.asset_type = ?
              AND ai.asset_type = ?
              AND ai.1_min = 1
              AND ai.deleted_at IS NULL
              AND ai.1_min = 1
              AND omp.ts_est <= ?
              AND omp.ts_est >= DATE_SUB(?, INTERVAL ? MINUTE)
            GROUP BY omp.symbol
            HAVING vol_sum >= ?
               AND (? = 0 OR notional_sum >= ?)
            ORDER BY notional_sum DESC
            LIMIT ?
        ';

        // IMPORTANT: LIMIT binding must be integer; Laravel PDO handles it fine in MySQL.
        $rows = DB::select($sql, [
            $assetType,
            $assetType,  // For asset_info filter
            $asOfTsEst,
            $asOfTsEst,
            $activeMinutes,
            $minVolume,
            $minNotional,
            $minNotional,
            $limit,
        ]);

        return array_map(fn ($r) => [
            'symbol' => (string) $r->symbol,
            'vol_sum' => (int) $r->vol_sum,
            'notional_sum' => (float) $r->notional_sum,
            'last_ts_est' => (string) $r->last_ts_est,
        ], $rows);
    }
}
