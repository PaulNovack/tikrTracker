<?php

namespace App\Services\Trading;

use Illuminate\Support\Facades\DB;

/**
 * Version 31.0 - Volatile Swing Scanner
 * Purpose: Find stocks that have swung 10%+ (up or down) at least once in last 7 days
 * Strategy: Target high-volatility stocks that can make big moves
 * Changes from V26:
 * - Look for stocks with 10%+ intraday swings in last 7 trading days
 * - Prioritize stocks currently showing momentum
 * - Return top performers by recent volatility + current momentum
 */
class FiveMinuteSignalScannerV31_0
{
    use HasPriceTables;

    private string $version = 'v31.0';

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
     * Scan for volatile stocks with 10%+ swings in last 7 days.
     *
     * Process:
     * 1. Find stocks that had at least one 10%+ intraday swing in last 7 trading days
     * 2. Filter for stocks currently showing momentum (recent 5m bars)
     * 3. Return top N ranked by volatility score + current momentum
     *
     * @param  string  $assetType  'stock' or 'crypto'
     * @param  string  $asOfTsEst  Current timestamp (EST)
     * @param  int  $lookbackMinutes  Recent momentum window (default 60)
     * @param  float  $minMovePct  Minimum recent move % (default 1.0%)
     * @param  float  $volMult  Volume multiplier vs average (default 2.0)
     * @param  int  $limit  Max signals to return (default 25)
     * @return array Signals with symbol, score, volatility metrics
     */
    public function scan(
        string $assetType,
        string $asOfTsEst,
        int $lookbackMinutes = 60,
        float $minMovePct = 1.0,
        float $volMult = 2.0,
        int $limit = 25
    ): array {
        $currentDate = substr($asOfTsEst, 0, 10);

        // Get last 7 trading days
        $tradingDays = DB::table($this->fiveMinuteTable)
            ->where('asset_type', $assetType)
            ->where('trading_date_est', '<=', $currentDate)
            ->select('trading_date_est')
            ->distinct()
            ->orderBy('trading_date_est', 'desc')
            ->limit(7)
            ->pluck('trading_date_est')
            ->toArray();

        if (count($tradingDays) < 7) {
            return [];
        }

        // Find stocks with 10%+ intraday swings
        $sql = "
            WITH daily_ranges AS (
                SELECT 
                    symbol,
                    trading_date_est,
                    MIN(price) as day_low,
                    MAX(price) as day_high,
                    (MAX(price) - MIN(price)) / MIN(price) * 100 as intraday_range_pct
                FROM five_minute_prices
                WHERE asset_type = ?
                  AND trading_date_est IN (?, ?, ?, ?, ?, ?, ?)
                  AND TIME(ts_est) BETWEEN '09:30:00' AND '16:00:00'
                GROUP BY symbol, trading_date_est
                HAVING intraday_range_pct >= 10.0
            ),
            volatile_stocks AS (
                SELECT 
                    symbol,
                    COUNT(DISTINCT trading_date_est) as swing_days,
                    MAX(intraday_range_pct) as max_swing_pct,
                    AVG(intraday_range_pct) as avg_swing_pct
                FROM daily_ranges
                GROUP BY symbol
                HAVING swing_days >= 1
            )
            SELECT 
                symbol,
                swing_days,
                max_swing_pct,
                avg_swing_pct,
                (swing_days * avg_swing_pct) as volatility_score
            FROM volatile_stocks
            ORDER BY volatility_score DESC
            LIMIT 500
        ";

        $volatileStocks = $this->dbSelect($sql, array_merge([$assetType], $tradingDays));

        if (empty($volatileStocks)) {
            return [];
        }

        $symbols = array_column($volatileStocks, 'symbol');
        $volatilityMap = [];
        foreach ($volatileStocks as $stock) {
            $volatilityMap[$stock->symbol] = [
                'swing_days' => $stock->swing_days,
                'max_swing_pct' => $stock->max_swing_pct,
                'avg_swing_pct' => $stock->avg_swing_pct,
                'volatility_score' => $stock->volatility_score,
            ];
        }

        $placeholders = implode(',', array_fill(0, count($symbols), '?'));

        // Scan for current momentum signals
        $sql = "
WITH last_bar AS (
  SELECT
    symbol,
    asset_type,
    MAX(ts_est) AS last_ts_est
  FROM five_minute_prices
  WHERE asset_type = ?
    AND symbol IN ($placeholders)
    AND ts_est <= ?
    AND ts_est >= DATE_SUB(?, INTERVAL ? MINUTE)
  GROUP BY symbol, asset_type
),
bar_data AS (
  SELECT
    lb.symbol,
    lb.asset_type,
    lb.last_ts_est,
    cur.price AS last_close,
    old.price AS old_close,
    cur.volume AS last_volume,
    AVG(hist.volume) AS avg_volume
  FROM last_bar lb
  JOIN five_minute_prices cur
    ON cur.symbol = lb.symbol
    AND cur.asset_type = lb.asset_type
    AND cur.ts_est = lb.last_ts_est
  LEFT JOIN five_minute_prices old
    ON old.symbol = lb.symbol
    AND old.asset_type = lb.asset_type
    AND old.ts_est = (
      SELECT MAX(ts_est)
      FROM five_minute_prices
      WHERE symbol = lb.symbol
        AND asset_type = lb.asset_type
        AND ts_est < DATE_SUB(lb.last_ts_est, INTERVAL ? MINUTE)
    )
  LEFT JOIN five_minute_prices hist
    ON hist.symbol = lb.symbol
    AND hist.asset_type = lb.asset_type
    AND hist.ts_est >= DATE_SUB(lb.last_ts_est, INTERVAL ? MINUTE)
    AND hist.ts_est < lb.last_ts_est
  GROUP BY lb.symbol, lb.asset_type, lb.last_ts_est, cur.price, old.price, cur.volume
)
SELECT
  symbol,
  asset_type,
  last_ts_est,
  last_close,
  old_close,
  last_volume,
  avg_volume,
  ((last_close - old_close) / NULLIF(old_close, 0)) * 100.0 AS move_pct,
  (last_volume / NULLIF(avg_volume, 0)) AS vol_ratio
FROM bar_data
WHERE old_close IS NOT NULL
  AND last_close > old_close
  AND ((last_close - old_close) / NULLIF(old_close, 0)) * 100.0 >= ?
  AND (last_volume / NULLIF(avg_volume, 0)) >= ?
ORDER BY move_pct DESC
";

        $params = array_merge(
            [$assetType],
            $symbols,
            [$asOfTsEst, $asOfTsEst, $lookbackMinutes, $lookbackMinutes, $lookbackMinutes],
            [$minMovePct, $volMult]
        );

        $momentumSignals = $this->dbSelect($sql, $params);

        $results = [];
        foreach ($momentumSignals as $sig) {
            $volatility = $volatilityMap[$sig->symbol] ?? null;
            if (! $volatility) {
                continue;
            }

            // Combined score: volatility history + current momentum
            $momentumScore = ((float) $sig->move_pct * (float) $sig->vol_ratio);
            $combinedScore = $volatility['volatility_score'] + ($momentumScore * 2);

            $results[] = [
                'symbol' => $sig->symbol,
                'asset_type' => $sig->asset_type,
                'signal_type' => 'VOLATILE_SWING',
                'signal_ts_est' => $sig->last_ts_est,
                'score' => round($combinedScore, 2),
                'move_pct' => round((float) $sig->move_pct, 2),
                'vol_ratio' => round((float) $sig->vol_ratio, 2),
                'swing_days' => $volatility['swing_days'],
                'max_swing_pct' => round($volatility['max_swing_pct'], 2),
                'avg_swing_pct' => round($volatility['avg_swing_pct'], 2),
            ];
        }

        usort($results, fn ($a, $b) => $b['score'] <=> $a['score']);

        return array_slice($results, 0, $limit);
    }
}
