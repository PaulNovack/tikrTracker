<?php

namespace App\Services\Trading;

use Illuminate\Support\Facades\DB;

/**
 * Version 16.0 - Quality-First 5m Signal Scanner
 *
 * Goal: Improve average P&L by picking a higher-quality universe and ranking signals better.
 *
 * Key differences vs your V13 scanner:
 * - Universe is "today liquidity + activity" (not laggy 5-day winners/previous-day losers).
 * - Enforces minimum dollar volume and recent activity.
 * - Uses "not-overextended" scoring (avoid chasing).
 * - Optional relative strength filter vs SPY (kept, but not overly restrictive).
 *
 * Output shape matches your pipeline expectation.
 */
class FiveMinuteSignalScannerV16_0
{
    use HasPriceTables;

    private string $version = 'v16.0';

    private string $name = 'Base Pattern';

    // Universe controls (tune these once; keep scan() signature unchanged)
    private int $activeWindowMinutes = 30;       // symbol must have a 5m bar in last N minutes

    private int $universeWindowMinutes = 120;    // liquidity measured over last N minutes

    private float $minDollarVol = 2_000_000.0;   // min (sum price*volume) over universeWindow

    private int $maxUniverse = 700;              // cap to keep query fast

    // "Don't chase" guardrails
    private float $maxMovePctForChasePenalty = 4.0; // above this, score gets penalized

    public function getVersion(): string
    {
        return $this->version;
    }

    public function getName(): string
    {
        return $this->name;
    }

    /**
     * Returns candidate signals using five_minute_prices.
     *
     * @return array<int, array{
     *   symbol:string,
     *   asset_type:string,
     *   signal_type:string,
     *   signal_ts_est:string,
     *   score:float,
     *   meta:array<string,mixed>
     * }>
     */
    public function scan(
        string $assetType,
        string $asOfTsEst,
        int $lookbackMinutes = 60,
        float $minMovePct = 0.8,
        float $volMult = 2.0,
        int $limit = 60
    ): array {
        $assetType = strtolower(trim($assetType));
        if (! in_array($assetType, ['stock', 'crypto'], true)) {
            return [];
        }

        // N bars back in 5m bars
        $nback = max(2, (int) floor($lookbackMinutes / 5));

        // Universe + signal computation in one SQL pass (no huge IN lists).
        $sql = "
WITH params AS (
  SELECT
    CAST(? AS DATETIME) AS as_of,
    ? AS atype
),

-- 1) pick today's liquid + active universe
universe AS (
  SELECT
    f.symbol,
    f.asset_type,
    MAX(f.ts_est) AS last_ts_est,
    SUM(COALESCE(f.volume,0) * COALESCE(f.price,0)) AS dollar_vol,
    SUM(COALESCE(f.volume,0)) AS vol_sum
  FROM five_minute_prices f
  JOIN params p
    ON p.atype = f.asset_type
  WHERE f.ts_est <= (SELECT as_of FROM params)
    AND f.ts_est >= DATE_SUB((SELECT as_of FROM params), INTERVAL ? MINUTE)
    AND f.symbol <> 'SPY'
  GROUP BY f.symbol, f.asset_type
  HAVING
    MAX(f.ts_est) >= DATE_SUB((SELECT as_of FROM params), INTERVAL ? MINUTE)
    AND SUM(COALESCE(f.volume,0) * COALESCE(f.price,0)) >= ?
  ORDER BY dollar_vol DESC
  LIMIT ?
),

-- 2) load recent bars for those symbols
bars AS (
  SELECT
    f.symbol,
    f.asset_type,
    f.ts_est,
    f.price AS close,
    COALESCE(f.volume,0) AS volume,
    ROW_NUMBER() OVER (PARTITION BY f.symbol, f.asset_type ORDER BY f.ts_est DESC) AS rn
  FROM five_minute_prices f
  JOIN universe u
    ON u.symbol = f.symbol AND u.asset_type = f.asset_type
  WHERE f.ts_est <= (SELECT as_of FROM params)
    AND f.ts_est >= DATE_SUB((SELECT as_of FROM params), INTERVAL ? MINUTE)
),

agg AS (
  SELECT
    symbol,
    asset_type,
    MAX(CASE WHEN rn = 1 THEN ts_est END) AS signal_ts_est,
    MAX(CASE WHEN rn = 1 THEN close END)  AS last_close,
    MAX(CASE WHEN rn = 1 THEN volume END) AS last_vol,
    MAX(CASE WHEN rn = 1 + ? THEN close END) AS prev_close,
    AVG(volume) AS avg_vol,
    MAX(close) AS max_close_in_window,
    MIN(close) AS min_close_in_window
  FROM bars
  GROUP BY symbol, asset_type
)

SELECT
  a.symbol,
  a.asset_type,
  a.signal_ts_est,
  a.last_close,
  a.prev_close,
  a.last_vol,
  a.avg_vol,
  ((a.last_close - a.prev_close) / NULLIF(a.prev_close, 0)) * 100 AS move_pct,
  (a.last_vol / NULLIF(a.avg_vol, 0)) AS vol_ratio,
  -- extension proxy: where are we vs the window range (0..1). higher = more extended
  CASE
    WHEN (a.max_close_in_window - a.min_close_in_window) <= 0 THEN 0
    ELSE (a.last_close - a.min_close_in_window) / NULLIF((a.max_close_in_window - a.min_close_in_window), 0)
  END AS range_pos
FROM agg a
WHERE a.prev_close IS NOT NULL
  AND ((a.last_close - a.prev_close) / NULLIF(a.prev_close, 0)) * 100 >= ?
  AND (a.last_vol / NULLIF(a.avg_vol, 0)) >= ?
ORDER BY ( ((a.last_close - a.prev_close) / NULLIF(a.prev_close, 0)) * 100
          * (a.last_vol / NULLIF(a.avg_vol, 0)) ) DESC
LIMIT ?
";

        $params = [
            $asOfTsEst,
            $assetType,
            $this->universeWindowMinutes,
            $this->activeWindowMinutes,
            $this->minDollarVol,
            $this->maxUniverse,
            $lookbackMinutes,
            $nback,
            $minMovePct,
            $volMult,
            $limit,
        ];

        $rows = $this->dbSelect($sql, $params);
        if (! $rows) {
            return [];
        }

        // Relative strength filter vs SPY (stock only)
        $spyMovePct = 0.0;
        if ($assetType === 'stock') {
            $spyMovePct = $this->getSpyMovement($asOfTsEst, $lookbackMinutes);
        }

        $out = [];
        foreach ($rows as $r) {
            $move = (float) $r->move_pct;
            $vr = (float) $r->vol_ratio;
            $rangePos = (float) ($r->range_pos ?? 0.0);

            // Relative strength: if SPY is up, require modest outperformance; if SPY flat/down, allow
            if ($assetType === 'stock' && $spyMovePct > 0.15) {
                if ($move < $spyMovePct * 1.15) {
                    continue;
                }
            }

            // Score: move * vol_ratio, but penalize chasing (very extended)
            $baseScore = $move * $vr;

            $chasePenalty = 1.0;
            if ($move > $this->maxMovePctForChasePenalty || $rangePos > 0.92) {
                // penalize when late in the move / near top of range
                $chasePenalty = 0.75;
            } elseif ($rangePos < 0.55) {
                // slight boost when not extended (more room)
                $chasePenalty = 1.08;
            }

            $score = $baseScore * $chasePenalty;

            $out[] = [
                'symbol' => (string) $r->symbol,
                'asset_type' => (string) $r->asset_type,
                'signal_type' => 'QUALITY_5M',
                'signal_ts_est' => (string) $r->signal_ts_est,
                'score' => round($score, 3),
                'meta' => [
                    'move_pct' => round($move, 3),
                    'vol_ratio' => round($vr, 3),
                    'last_close' => (float) $r->last_close,
                    'prev_close' => (float) $r->prev_close,
                    'range_pos' => round($rangePos, 3),
                    'spy_move_pct' => round($spyMovePct, 3),
                    'rs_ratio' => ($spyMovePct != 0.0) ? round($move / $spyMovePct, 2) : null,
                    'universe' => [
                        'activeWindowMinutes' => $this->activeWindowMinutes,
                        'universeWindowMinutes' => $this->universeWindowMinutes,
                        'minDollarVol' => $this->minDollarVol,
                        'maxUniverse' => $this->maxUniverse,
                    ],
                ],
            ];
        }

        // Keep top-ranked
        usort($out, fn ($a, $b) => ($b['score'] <=> $a['score']));

        return array_slice($out, 0, max(1, $limit));
    }

    private function getSpyMovement(string $asOfTsEst, int $lookbackMinutes): float
    {
        $benchmarkSymbol = config('trading.market_benchmark_symbol', 'QQQM');
        $nback = max(2, (int) floor($lookbackMinutes / 5));

        // Use window functions with correct ordering: we want last_close at asOf and close N bars back.
        $sql = "
WITH w AS (
  SELECT
    ts_est,
    price AS close
  FROM five_minute_prices
  WHERE symbol = ?
    AND asset_type = 'stock'
    AND ts_est <= ?
    AND ts_est >= DATE_SUB(?, INTERVAL ? MINUTE)
  ORDER BY ts_est ASC
),
calc AS (
  SELECT
    close,
    LAG(close, ?) OVER (ORDER BY ts_est ASC) AS prev_close,
    ts_est
  FROM w
)
SELECT
  close AS last_close,
  prev_close
FROM calc
ORDER BY ts_est DESC
LIMIT 1
";
        $row = DB::selectOne($sql, [$asOfTsEst, $asOfTsEst, $lookbackMinutes, $nback]);

        if (! $row || ! $row->prev_close) {
            return 0.0;
        }

        $last = (float) $row->last_close;
        $prev = (float) $row->prev_close;
        if ($prev == 0.0) {
            return 0.0;
        }

        return (($last - $prev) / $prev) * 100.0;
    }
}
