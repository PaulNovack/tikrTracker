<?php

namespace App\Services\Trading;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * FiveMinuteSignalScannerV30_0
 *
 * TradePipelineRunB may call:
 *   $scanner->scan($assetType, $asOfTsEst, $lookbackMinutes)
 * OR:
 *   $scanner->scan($assetType, $asOfTsEst, $optsArray)
 *
 * Output signals:
 * [
 *   'symbol' => 'AAPL',
 *   'asset_type' => 'stock',
 *   'signal_type' => 'MOMO_5M',
 *   'signal_ts_est' => 'YYYY-mm-dd HH:MM:SS',
 *   'score' => 12.34,
 *   'meta' => [...],
 * ]
 *
 * Assumptions:
 * - five_minute_prices has: symbol, asset_type, ts_est, price, volume
 */
class FiveMinuteSignalScannerV30_0
{
    use HasPriceTables;

    private string $version = 'v30.0';

    private string $name = 'Momentum 5M';

    public function getVersion(): string
    {
        return $this->version;
    }

    public function getName(): string
    {
        return $this->name;
    }

    /**
     * @param  array<string,mixed>|int|string|null  $opts
     * @return array<int,array<string,mixed>>
     */
    public function scan(string $assetType, string $asOfTsEst, array|int|string|null $opts = []): array
    {
        // Normalize opts (Pipeline sometimes passes lookback minutes as an int)
        if (! is_array($opts)) {
            if (is_numeric($opts)) {
                $opts = ['lookbackMinutes' => (int) $opts];
            } else {
                $opts = [];
            }
        }

        $lookbackMinutes = (int) ($opts['lookbackMinutes'] ?? ($opts['lookback'] ?? 60));
        $minMovePct = (float) ($opts['minMovePct'] ?? ($opts['minMove'] ?? 0.50)); // percent
        $volMult = (float) ($opts['volMult'] ?? 1.30);
        $limit = (int) ($opts['limit'] ?? ($opts['top'] ?? 200));
        $minBars = (int) ($opts['minBars'] ?? 6);
        $useUniverse = (bool) ($opts['useUniverse'] ?? true);

        $tradingDate = substr($asOfTsEst, 0, 10);

        $universeSymbols = [];
        if ($useUniverse) {
            try {
                $universeSymbols = $this->loadUniverse($assetType, $tradingDate);
            } catch (\Throwable $e) {
                $universeSymbols = [];
            }
        }

        $signals = $this->querySignals(
            $assetType,
            $asOfTsEst,
            $lookbackMinutes,
            $minMovePct,
            $volMult,
            $limit,
            $minBars,
            $universeSymbols
        );

        Log::channel('trading')->info('[FiveMinuteSignalScannerV30_0] scan done', [
            'asset_type' => $assetType,
            'as_of_ts_est' => $asOfTsEst,
            'lookback_minutes' => $lookbackMinutes,
            'min_move_pct' => $minMovePct,
            'vol_mult' => $volMult,
            'limit' => $limit,
            'min_bars' => $minBars,
            'universe_count' => count($universeSymbols),
            'signals' => count($signals),
            'version' => $this->version,
        ]);

        return $signals;
    }

    /**
     * @return array<int,string>
     */
    private function loadUniverse(string $assetType, string $tradingDateEst): array
    {
        $rows = DB::table('eligible_symbols')
            ->select('symbol')
            ->where('asset_type', $assetType)
            ->where('trading_date_est', $tradingDateEst)
            ->limit(5000)
            ->get();

        $out = [];
        foreach ($rows as $r) {
            $s = strtoupper(trim((string) $r->symbol));
            if ($s !== '') {
                $out[] = $s;
            }
        }

        return array_values(array_unique($out));
    }

    /**
     * @param  array<int,string>  $universeSymbols
     * @return array<int,array<string,mixed>>
     */
    private function querySignals(
        string $assetType,
        string $asOfTsEst,
        int $lookbackMinutes,
        float $minMovePct,
        float $volMult,
        int $limit,
        int $minBars,
        array $universeSymbols
    ): array {
        $useUniverse = count($universeSymbols) > 0;

        $inPlaceholders = '';
        $inParams = [];
        if ($useUniverse) {
            $inPlaceholders = implode(',', array_fill(0, count($universeSymbols), '?'));
            $inParams = $universeSymbols;
        }

        // NOTE: uses f.price (NOT f.close)
        // NOTE: no trailing ')' after LIMIT
        $sql = '
WITH params AS (
    SELECT
        CAST(? AS DATETIME) AS as_of_ts,
        CAST(? AS DATETIME) - INTERVAL ? MINUTE AS start_ts
),
bars AS (
    SELECT
        f.symbol,
        f.asset_type,
        f.ts_est,
        f.price AS close_px,
        f.volume
    FROM five_minute_prices f
    JOIN params p
      ON f.ts_est BETWEEN p.start_ts AND p.as_of_ts
    WHERE f.asset_type = ?
      AND f.ts_est <= (SELECT as_of_ts FROM params)
      '.($useUniverse ? " AND f.symbol IN ($inPlaceholders) " : '').'
),
agg AS (
    SELECT
        b.symbol,
        b.asset_type,
        COUNT(*) AS bars,
        MAX(b.ts_est) AS last_ts,
        MIN(b.ts_est) AS first_ts
    FROM bars b
    GROUP BY b.symbol, b.asset_type
),
first_last AS (
    SELECT
        a.symbol,
        a.asset_type,
        a.bars,
        a.first_ts,
        a.last_ts,
        (SELECT b1.close_px FROM bars b1
          WHERE b1.symbol=a.symbol AND b1.asset_type=a.asset_type AND b1.ts_est=a.first_ts
          LIMIT 1) AS first_close,
        (SELECT b2.close_px FROM bars b2
          WHERE b2.symbol=a.symbol AND b2.asset_type=a.asset_type AND b2.ts_est=a.last_ts
          LIMIT 1) AS last_close,
        (SELECT b3.volume FROM bars b3
          WHERE b3.symbol=a.symbol AND b3.asset_type=a.asset_type AND b3.ts_est=a.last_ts
          LIMIT 1) AS last_vol,
        (SELECT AVG(b4.volume) FROM bars b4
          WHERE b4.symbol=a.symbol AND b4.asset_type=a.asset_type AND b4.ts_est < a.last_ts
        ) AS avg_vol
    FROM agg a
)
SELECT
    fl.symbol,
    fl.asset_type,
    fl.last_ts,
    fl.bars,
    fl.first_close,
    fl.last_close,
    fl.last_vol,
    fl.avg_vol,
    ((fl.last_close - fl.first_close) / fl.first_close) * 100.0 AS pct_move,
    CASE
        WHEN fl.avg_vol IS NULL OR fl.avg_vol <= 0 THEN 0
        ELSE (fl.last_vol / fl.avg_vol)
    END AS vol_ratio
FROM first_last fl
WHERE fl.bars >= ?
  AND fl.first_close > 0
  AND fl.last_close > 0
  AND (((fl.last_close - fl.first_close) / fl.first_close) * 100.0) >= ?
  AND (CASE WHEN fl.avg_vol IS NULL OR fl.avg_vol <= 0 THEN 0 ELSE (fl.last_vol / fl.avg_vol) END) >= ?
ORDER BY
    ( (((fl.last_close - fl.first_close) / fl.first_close) * 100.0) * 1.0 )
    + ( (CASE WHEN fl.avg_vol IS NULL OR fl.avg_vol <= 0 THEN 0 ELSE (fl.last_vol / fl.avg_vol) END) * 2.0 )
DESC
LIMIT ?
        ';

        $params = [];
        $params[] = $asOfTsEst;        // params.as_of_ts
        $params[] = $asOfTsEst;        // params.as_of_ts (again)
        $params[] = $lookbackMinutes;  // params.start_ts offset
        $params[] = $assetType;        // WHERE f.asset_type = ?

        if ($useUniverse) {
            foreach ($inParams as $p) {
                $params[] = $p;
            }
        }

        $params[] = $minBars;
        $params[] = $minMovePct;
        $params[] = $volMult;
        $params[] = $limit;

        $rows = $this->dbSelect($sql, $params);

        $out = [];
        foreach ($rows as $r) {
            $pctMove = (float) $r->pct_move;
            $volRatio = (float) $r->vol_ratio;

            $score = ($pctMove * 1.0) + ($volRatio * 2.0);

            $out[] = [
                'symbol' => (string) $r->symbol,
                'asset_type' => (string) $r->asset_type,
                'signal_type' => 'MOMO_5M',
                'signal_ts_est' => (string) $r->last_ts,
                'score' => $score,
                'meta' => [
                    'bars' => (int) $r->bars,
                    'first_close' => (float) $r->first_close,
                    'last_close' => (float) $r->last_close,
                    'pct_move' => $pctMove,
                    'last_vol' => (float) $r->last_vol,
                    'avg_vol' => (float) ($r->avg_vol ?? 0),
                    'vol_ratio' => $volRatio,
                    'lookback_minutes' => $lookbackMinutes,
                    'min_move_pct' => $minMovePct,
                    'vol_mult' => $volMult,
                    'version' => $this->version,
                ],
            ];
        }

        return $out;
    }
}
