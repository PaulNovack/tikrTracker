<?php

namespace App\Services\Trading;

/**
 * Version 50.0 - Signal Scanner (EntryScore-based, using one_minute_prices)
 *
 * Reads score window + limit from config('trading.*') so it works with config:cache.
 *
 * Suggested config/trading.php:
 * return [
 *   'entry_score_min'   => (float) env('ENTRY_SCORE_MIN', 80),
 *   'entry_score_max'   => (float) env('ENTRY_SCORE_MAX', 100),
 *   'entry_score_limit' => (int)   env('ENTRY_SCORE_LIMIT', 3),
 * ];
 */
class FiveMinuteSignalScannerV50_0
{
    use HasPriceTables;

    private string $version = 'v50.0';

    private string $name = 'Entry Score Based';

    public function getVersion(): string
    {
        return $this->version;
    }

    public function getName(): string
    {
        return $this->name;
    }

    /**
     * Drop-in compatible signature with older scanners.
     * Uses $lookbackMinutes as the scan window (recommend: 10).
     */
    public function scan(
        string $assetType,
        string $asOfTsEst,
        int $lookbackMinutes = 10,
        float $minMovePct = 0.5,
        float $volMult = 3.0,
        int $limit = 60
    ): array {
        $minScore = (float) config('trading.entry_score_min', 80);
        $maxScore = (float) config('trading.entry_score_max', 100);
        $topN = (int) config('trading.entry_score_limit', 3);

        if ($topN <= 0) {
            $topN = 3;
        }
        if ($maxScore <= 0) {
            $maxScore = 100.0;
        }
        if ($minScore > $maxScore) {
            [$minScore, $maxScore] = [$maxScore, $minScore];
        }

        $lookbackMinutes = max(1, (int) $lookbackMinutes);

        $sql = "
WITH params AS (
  SELECT
    ? AS p_asset_type,
    CAST(? AS DATETIME) AS p_asof,
    CAST(? AS DATETIME) - INTERVAL ? MINUTE AS p_from
),
base AS (
  SELECT
    omp.*,
    AVG(omp.volume) OVER (
      PARTITION BY omp.symbol, omp.asset_type
      ORDER BY omp.ts_est
      ROWS BETWEEN 20 PRECEDING AND 1 PRECEDING
    ) AS avg_vol_20,
    fmp.ema9_above_ema21 AS five_min_trend
  FROM one_minute_prices omp
  JOIN params p ON omp.asset_type = p.p_asset_type
  LEFT JOIN five_minute_prices fmp ON (
    fmp.symbol = omp.symbol
    AND fmp.asset_type = omp.asset_type
    AND fmp.ts_est <= omp.ts_est
    AND fmp.ts_est >= DATE(omp.ts_est)
    AND fmp.ts_est = (
      SELECT MAX(fmp2.ts_est)
      FROM five_minute_prices fmp2
      WHERE fmp2.symbol = omp.symbol
        AND fmp2.asset_type = omp.asset_type
        AND fmp2.ts_est <= omp.ts_est
        AND fmp2.ts_est >= DATE(omp.ts_est)
    )
  )
  WHERE omp.ts_est >= p.p_from
    AND omp.ts_est <  p.p_asof
    AND omp.trading_date_est = DATE(p.p_asof)
),
scored AS (
  SELECT
    b.*,
    GREATEST(LEAST(((b.ema9_ema21_spread / b.price) - 0.0005) / (0.0030 - 0.0005), 1), 0) AS spread_strength,
    GREATEST(1 - (ABS(b.vwap_dist_pct - 0.15) / 0.30), 0) AS vwap_dist_score,
    (
      GREATEST(LEAST((b.atr_pct - 0.08) / (0.20 - 0.08), 1), 0)
      *
      (1 - GREATEST(LEAST((b.atr_pct - 0.50) / (1.50 - 0.50), 1), 0))
    ) AS atr_score,
    CASE
      WHEN b.avg_vol_20 IS NULL OR b.avg_vol_20 = 0 OR b.volume IS NULL THEN 0
      ELSE GREATEST(LEAST(((b.volume / b.avg_vol_20) - 0.8) / (2.5 - 0.8), 1), 0)
    END AS vol_score,
    CASE
      WHEN b.high IS NULL OR b.low IS NULL OR (b.high - b.low) <= 0 THEN 0
      ELSE GREATEST(LEAST(((((b.price - b.low) / (b.high - b.low)) - 0.45) / (0.80 - 0.45)), 1), 0)
    END AS candle_score,
    CASE
      WHEN TIME(b.ts_est) <= '10:30:00' THEN 1.0
      WHEN TIME(b.ts_est) <= '11:00:00' THEN 0.5
      ELSE 0.0
    END AS time_bonus
  FROM base b
),
ranked AS (
  SELECT
    s.symbol,
    s.asset_type,
    s.ts_est AS signal_ts_est,
    ROUND(
      100 * (
        0.30*(0.70*s.ema9_above_ema21 + 0.30*s.spread_strength) +
        0.25*(s.above_vwap * s.vwap_dist_score) +
        0.10*(s.atr_score) +
        0.20*(s.vol_score) +
        0.10*(s.candle_score) +
        0.05*(s.time_bonus)
      ),
      2
    ) AS entry_score,
    ROW_NUMBER() OVER (
      PARTITION BY s.symbol
      ORDER BY
        (
          0.30*(0.70*s.ema9_above_ema21 + 0.30*s.spread_strength) +
          0.25*(s.above_vwap * s.vwap_dist_score) +
          0.10*(s.atr_score) +
          0.20*(s.vol_score) +
          0.10*(s.candle_score) +
          0.05*(s.time_bonus)
        ) DESC,
        s.ts_est DESC
    ) AS rn
  FROM scored s
  WHERE COALESCE(s.ema9_above_ema21,0)=1
    AND COALESCE(s.above_vwap,0)=1
    AND COALESCE(s.five_min_trend,0)=1
)
SELECT symbol, asset_type, signal_ts_est, entry_score
FROM ranked
WHERE rn = 1
  AND entry_score BETWEEN ? AND ?
ORDER BY entry_score DESC, signal_ts_est DESC
LIMIT ?
";

        $rows = $this->dbSelect($sql, [
            $assetType,
            $asOfTsEst,
            $asOfTsEst,
            $lookbackMinutes,
            $minScore,
            $maxScore,
            $topN,
        ]);

        $out = [];
        foreach ($rows as $r) {
            $out[] = [
                'symbol' => (string) $r->symbol,
                'asset_type' => (string) $r->asset_type,
                'signal_type' => 'ENTRY_SCORE',
                'signal_ts_est' => (string) $r->signal_ts_est,
                'score' => (float) $r->entry_score,
                'meta' => [
                    'entry_score' => (float) $r->entry_score,
                    'score_min' => $minScore,
                    'score_max' => $maxScore,
                    'window_minutes' => $lookbackMinutes,
                    'version' => $this->version,
                ],
            ];
        }

        return $out;
    }
}
