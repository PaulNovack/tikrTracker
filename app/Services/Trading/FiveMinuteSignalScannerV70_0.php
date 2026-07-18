<?php

namespace App\Services\Trading;

/**
 * Version 70.0 - Volatility-Based Scanner
 *
 * Universe selection: Most volatile stocks with >1M volume (top 500)
 * - Volatility measured by average bar range (high-low) / price
 * - Then scored with EntryScore confirmation from 1m bars
 *
 * ENV / config('trading.v70.*'):
 * - V70_ENTRY_SCORE_MIN / V70_ENTRY_SCORE_MAX: score window
 * - V70_ENTRY_SCORE_LIMIT: max picks returned
 */
class FiveMinuteSignalScannerV70_0
{
    use HasPriceTables;

    private string $version = 'v70.0';

    private string $name = 'RSI Momentum Divergence';

    public function __construct() {}

    public function getVersion(): string
    {
        return $this->version;
    }

    public function getName(): string
    {
        return $this->name;
    }

    /**
     * Scan for volatile stocks with EntryScore confirmation.
     *
     * @param  string  $assetType  'stock' or 'crypto'
     * @param  string  $asOfTsEst  Current time in EST (Y-m-d H:i:s)
     * @param  int  $lookbackMinutes  How far back to analyze (default 15)
     * @param  float  $minMovePct  Minimum % move required (unused in v70, kept for compatibility)
     * @param  float  $volMult  Volume multiplier (unused in v70, kept for compatibility)
     * @param  int  $limit  Max signals to return
     * @return array Signals sorted by EntryScore desc
     */
    public function scan(
        string $assetType,
        string $asOfTsEst,
        int $lookbackMinutes = 15,
        float $minMovePct = 0.2,
        float $volMult = 2.00,
        int $limit = 60
    ): array {
        $minScore = (float) config('trading.v70.entry_score_min', 89);
        $maxScore = (float) config('trading.v70.entry_score_max', 95);
        $topN = (int) config('trading.v70.entry_score_limit', 60);

        if ($topN <= 0) {
            $topN = 60;
        }
        if ($maxScore <= 0) {
            $maxScore = 100.0;
        }
        if ($minScore > $maxScore) {
            [$minScore, $maxScore] = [$maxScore, $minScore];
        }

        $lookbackMinutes = max(5, (int) $lookbackMinutes);
        $limit = max(1, (int) $limit);

        // -----------------------------
        // 1) Universe: Most Volatile Stocks
        // -----------------------------
        $symbols = $this->getMostVolatileStocks($assetType, $asOfTsEst, 500, 100_000);

        // Add market movers to universe if enabled (top explosive movers from recent days)
        $moversLimit = (int) config('trading.market_movers.pipeline_g', 0);
        if ($moversLimit > 0) {
            $movers = app(\App\Services\MarketMoversService::class)->getTodaysTopMoversFromCache(null, $moversLimit);
            $symbols = array_values(array_unique(array_merge($symbols, $movers)));
        }

        if (empty($symbols)) {
            return [];
        }

        // Note: 5m reversal structure filter removed - was too restrictive
        // Original v70.0 backtest showed score 90+ winners without this filter

        // -----------------------------
        // 2) EntryScore confirmation from 1m bars
        //    Compute BEST (max) EntryScore per symbol inside the scan window.
        // -----------------------------
        $ph = implode(',', array_fill(0, count($symbols), '?'));

        $sql1mScore = "
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
    ) AS avg_vol_20
  FROM one_minute_prices omp
  JOIN params p ON omp.asset_type = p.p_asset_type
  WHERE omp.symbol IN ($ph)
    AND omp.ts_est >= p.p_from
    AND omp.ts_est <  p.p_asof
    AND omp.trading_date_est = DATE(p.p_asof)
),
scored AS (
  SELECT
    b.symbol,
    b.asset_type,
    b.ts_est,
    ROUND(
      100 * (
        0.30*(0.70*COALESCE(b.ema9_above_ema21,0) + 0.30*GREATEST(LEAST(((COALESCE(b.ema9_ema21_spread,0) / NULLIF(b.price,0)) - 0.0005) / (0.0030 - 0.0005), 1), 0)) +
        0.25*(COALESCE(b.above_vwap,0) * GREATEST(1 - (ABS(COALESCE(b.vwap_dist_pct,0) - 0.15) / 0.30), 0)) +
        0.10*((GREATEST(LEAST((COALESCE(b.atr_pct,0) - 0.08) / (0.20 - 0.08), 1), 0)) * (1 - GREATEST(LEAST((COALESCE(b.atr_pct,0) - 0.50) / (1.50 - 0.50), 1), 0))) +
        0.20*(CASE
          WHEN AVG_VOL_20 IS NULL OR AVG_VOL_20 = 0 OR b.volume IS NULL THEN 0
          ELSE GREATEST(LEAST(((b.volume / AVG_VOL_20) - 0.8) / (2.5 - 0.8), 1), 0)
        END) +
        0.10*(CASE
          WHEN b.high IS NULL OR b.low IS NULL OR (b.high - b.low) <= 0 THEN 0
          ELSE GREATEST(LEAST(((((b.price - b.low) / (b.high - b.low)) - 0.45) / (0.80 - 0.45)), 1), 0)
        END) +
        0.05*(CASE
          WHEN TIME(b.ts_est) <= '10:30:00' THEN 1.0
          WHEN TIME(b.ts_est) <= '11:00:00' THEN 0.5
          ELSE 0.0
        END)
      ),
      2
    ) AS entry_score
  FROM base b
  WHERE COALESCE(b.ema9_above_ema21,0)=1
    AND COALESCE(b.above_vwap,0)=1
)
SELECT symbol, MAX(entry_score) AS best_entry_score, MAX(ts_est) AS signal_ts_est,
       (SELECT price FROM base WHERE symbol = scored.symbol ORDER BY ts_est DESC LIMIT 1) AS current_price,
       (SELECT atr_pct FROM base WHERE symbol = scored.symbol ORDER BY ts_est DESC LIMIT 1) AS atr_pct
FROM scored
GROUP BY symbol
";

        $params1mScore = array_merge(
            [$assetType, $asOfTsEst, $asOfTsEst, $lookbackMinutes],
            $symbols
        );

        $scoreRows = $this->dbSelect($sql1mScore, $params1mScore);

        // -----------------------------
        // 3) Filter by EntryScore window + rank by score
        // -----------------------------
        $ranked = [];
        foreach ($scoreRows as $sr) {
            $entryScore = (float) $sr->best_entry_score;

            if ($entryScore < $minScore || $entryScore > $maxScore) {
                continue;
            }

            $currentPrice = $sr->current_price ?? null;
            $atrPct = $sr->atr_pct ?? null;
            $atr = ($atrPct && $currentPrice) ? round(($atrPct / 100) * $currentPrice, 6) : null;

            $ranked[] = [
                'symbol' => (string) $sr->symbol,
                'asset_type' => $assetType,
                'signal_ts_est' => (string) $sr->signal_ts_est,
                'entry_score' => $entryScore,
                'current_price' => $currentPrice,
                'atr' => $atr,
                'atr_pct' => $atrPct,
            ];
        }

        if (empty($ranked)) {
            return [];
        }

        usort($ranked, fn ($a, $b) => ($b['entry_score'] <=> $a['entry_score']));

        $finalLimit = min($limit, $topN, count($ranked));
        $ranked = array_slice($ranked, 0, $finalLimit);

        // Output signals
        $out = [];
        foreach ($ranked as $r) {
            $out[] = [
                'symbol' => (string) $r['symbol'],
                'asset_type' => (string) $r['asset_type'],
                'signal_type' => 'VOLATILITY_HYBRID_ENTRY',
                'signal_ts_est' => (string) $r['signal_ts_est'],
                'score' => round((float) $r['entry_score'], 3),
                'atr' => $r['atr'] ?? null,
                'atr_pct' => $r['atr_pct'] ?? null,
                'meta' => [
                    'version' => $this->version,
                    'entry_score' => round((float) $r['entry_score'], 2),
                    'entry_score_min' => $minScore,
                    'entry_score_max' => $maxScore,
                    'window_minutes' => $lookbackMinutes,
                    'universe_method' => 'volatility_based',
                    'current_price' => $r['current_price'] ?? null,
                ],
            ];
        }

        return $out;
    }

    /**
     * Get most volatile stocks based on average bar range / price
     *
     * @param  string  $asOfTsEst  Current time
     * @param  int  $limit  Number of stocks to return
     * @param  int  $minVolume  Minimum volume threshold per bar
     * @return array Symbol list
     */
    private function getMostVolatileStocks(string $assetType, string $asOfTsEst, int $limit, int $minVolume): array
    {
        $tradeDate = substr($asOfTsEst, 0, 10);

        // Calculate volatility using 1-minute bars from today
        // Volatility = average of (high - low) / price per bar
        $sql = '
WITH bars AS (
  SELECT
    symbol,
    asset_type,
    ts_est,
    price,
    high,
    low,
    volume,
    (high - low) / NULLIF(price, 0) AS bar_range_pct
  FROM one_minute_prices
  WHERE asset_type = ?
    AND trading_date_est = ?
    AND ts_est <= ?
    AND volume > ?
),
volatility AS (
  SELECT
    symbol,
    asset_type,
    AVG(bar_range_pct) AS avg_range_pct,
    SUM(volume) AS total_volume,
    COUNT(*) AS bar_count,
    MAX(ts_est) AS last_ts
  FROM bars
  GROUP BY symbol, asset_type
  HAVING bar_count >= 10
    AND total_volume >= 5000000
)
SELECT symbol
FROM volatility
ORDER BY avg_range_pct DESC
LIMIT ?
        ';

        $rows = $this->dbSelect($sql, [
            $assetType,
            $tradeDate,
            $asOfTsEst,
            $minVolume,
            $limit,
        ]);

        return array_map(fn ($r) => $r->symbol, $rows);
    }

    /**
     * Filter symbols by checking if the last 5m bar shows reversal/bottom structure.
     *
     * Requirements for a valid bottom:
     * - Close near high (bullish candle)
     * - Lower wick >= 40% of bar range (shows rejection of lower prices)
     * - Price bouncing off support (near VWAP or EMA9)
     * - Above average volume
     *
     * @param  array  $symbols  Symbols to check
     * @param  string  $assetType  'stock' or 'crypto'
     * @param  string  $asOfTsEst  Current time in EST
     * @return array Filtered symbols showing reversal structure
     */
    private function filterByReversalStructure(array $symbols, string $assetType, string $asOfTsEst): array
    {
        if (empty($symbols)) {
            return [];
        }

        $ph = implode(',', array_fill(0, count($symbols), '?'));

        $sql = "
WITH recent_5m AS (
  SELECT
    f.symbol,
    f.open,
    f.high,
    f.low,
    f.price,
    f.volume,
    f.vwap,
    f.ema9,
    f.ts_est,
    (f.high - f.low) AS bar_range,
    (f.price - f.low) AS lower_wick,
    (f.high - f.price) AS upper_wick,
    AVG(f.volume) OVER (
      PARTITION BY f.symbol
      ORDER BY f.ts_est
      ROWS BETWEEN 10 PRECEDING AND 1 PRECEDING
    ) AS avg_vol_10,
    ROW_NUMBER() OVER (PARTITION BY f.symbol ORDER BY f.ts_est DESC) AS rn
  FROM five_minute_prices f
  WHERE f.asset_type = ?
    AND f.symbol IN ($ph)
    AND f.ts_est <= ?
    AND f.ts_est >= CAST(? AS DATETIME) - INTERVAL 2 HOUR
)
SELECT symbol
FROM recent_5m
WHERE rn = 1
  AND bar_range > 0
  AND lower_wick >= 0.40 * bar_range  -- Strong rejection of lower prices
  AND price > open  -- Bullish candle
  AND (price - low) >= 0.60 * bar_range  -- Close near high
  AND (
    ABS(price - vwap) / NULLIF(price, 0) <= 0.01  -- Near VWAP (within 1%)
    OR ABS(price - ema9) / NULLIF(price, 0) <= 0.01  -- Near EMA9 (within 1%)
  )
  AND volume >= avg_vol_10 * 1.2  -- Above average volume
        ";

        $params = array_merge(
            [$assetType],
            $symbols,
            [$asOfTsEst, $asOfTsEst]
        );

        $rows = $this->dbSelect($sql, $params);

        return array_map(fn ($r) => $r->symbol, $rows);
    }
}
