<?php

namespace App\Services\Trading\Indicators;

use Illuminate\Support\Facades\DB;

class OneMinuteContextBuilder
{
    /**
     * Build last N 1-minute bars up to $asOfTsEst (inclusive).
     *
     * Expects one_minute_prices to have at least:
     * - symbol, asset_type, ts_est, price, volume
     *
     * If your table also has high/low/vwap1m, they will be used.
     *
     * @return array<int, array{
     *   ts_est:string,
     *   close:float,
     *   high:float,
     *   low:float,
     *   volume:float,
     *   vwap:?float
     * }>
     */
    public static function build(string $symbol, string $assetType, string $asOfTsEst, array $opts = []): array
    {
        $limit = (int) ($opts['limit'] ?? 120);

        // Detect optional columns once per call (cheap, cached by MySQL info_schema)
        $cols = self::getColumns('one_minute_prices');

        $hasHigh = isset($cols['high']);
        $hasLow = isset($cols['low']);
        $hasVwap1 = isset($cols['vwap1m']);

        $highExpr = $hasHigh ? 'high' : 'price';
        $lowExpr = $hasLow ? 'low' : 'price';
        $vwapExpr = $hasVwap1 ? 'vwap1m' : 'NULL';

        $sql = "
            SELECT
                ts_est,
                price AS close,
                {$highExpr} AS high,
                {$lowExpr}  AS low,
                volume,
                {$vwapExpr} AS vwap
            FROM one_minute_prices
            WHERE symbol = ? AND asset_type = ? AND ts_est <= ?
            ORDER BY ts_est DESC
            LIMIT {$limit}
        ";

        $rows = DB::select($sql, [$symbol, $assetType, $asOfTsEst]);

        // Oldest->newest is easier for indicator calcs
        $rows = array_reverse(array_map(function ($r) {
            return [
                'ts_est' => (string) $r->ts_est,
                'close' => (float) $r->close,
                'high' => (float) $r->high,
                'low' => (float) $r->low,
                'volume' => (float) $r->volume,
                'vwap' => $r->vwap !== null ? (float) $r->vwap : null,
            ];
        }, $rows));

        return $rows;
    }

    private static function getColumns(string $table): array
    {
        static $cache = [];

        if (isset($cache[$table])) {
            return $cache[$table];
        }

        $db = DB::getDatabaseName();
        $rows = DB::select(
            'SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?',
            [$db, $table]
        );

        $out = [];
        foreach ($rows as $r) {
            $out[(string) $r->COLUMN_NAME] = true;
        }

        return $cache[$table] = $out;
    }
}
