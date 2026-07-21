<?php

namespace App\Services\Trading;

/**
 * Version 1100.0 - Entry Finder for Scarcity Leaders
 *
 * Goal:
 * Enter only clean continuation patterns in the few names showing true strength
 * while the market is weak.
 *
 * Entry types:
 * - VWAP_PULLBACK_HOLD
 * - EMA9_BOUNCE
 * - TIGHT_FLAG_BREAK
 * - OR_BREAKOUT_RETEST
 *
 * Avoid:
 * - raw vertical chase candles
 * - overextended entries too far from VWAP
 * - weak tape-followers
 */
class OneMinuteEntryFinderV1100_0
{
    use HasPriceTables;

    private string $version = 'v1100.0';

    public function getVersion(): string
    {
        return $this->version;
    }

    /**
     * Find best long entry after a 5m scarcity-leader signal.
     *
     * @param  string  $fillModel  next_open|close
     */
    public function findBestLong(
        string $symbol,
        string $assetType,
        string $signalTsEst,
        string $asOfTsEst,
        int $beforeMinutes = 10,
        int $afterMinutes = 30,
        int $volLookback = 20,
        int $pivotLookback = 15,
        string $fillModel = 'next_open'
    ): array {
        $benchmarkSymbol = config('app.trading_market_benchmark_symbol', 'SPY');

        $entryScoreMin = (float) config('trading.v1100.entry_score_min', 40);
        $entryScoreMax = (float) config('trading.v1100.entry_score_max', 95);

        $minVolRatio = (float) config('trading.v1100.entry_min_vol_ratio', 1.8);
        $maxVwapExtensionPct = (float) config('trading.v1100.entry_max_vwap_extension_pct', 2.5);
        $maxExtensionFromEma9Pct = (float) config('trading.v1100.entry_max_extension_from_ema9_pct', 1.5);
        $minPullbackDepthPct = (float) config('trading.v1100.entry_min_pullback_depth_pct', 0.20);
        $maxPullbackDepthPct = (float) config('trading.v1100.entry_max_pullback_depth_pct', 2.00);
        $minFlagBars = (int) config('trading.v1100.entry_min_flag_bars', 2);
        $maxFlagBars = (int) config('trading.v1100.entry_max_flag_bars', 6);
        $minBreakoutVolRatio = (float) config('trading.v1100.entry_min_breakout_vol_ratio', 2.0);
        $maxChaseBarPct = (float) config('trading.v1100.entry_max_chase_bar_pct', 1.25);
        $min5mTrendSpreadPct = (float) config('trading.v1100.entry_min_5m_trend_spread_pct', 0.08);
        $require5mAboveVwap = (bool) config('trading.v1100.entry_require_5m_above_vwap', true);
        $require5mBullTrend = (bool) config('trading.v1100.entry_require_5m_bull_trend', true);

        // Convert booleans to integers for SQL interpolation
        $require5mAboveVwapInt = $require5mAboveVwap ? 1 : 0;
        $require5mBullTrendInt = $require5mBullTrend ? 1 : 0;

        $sql = <<<SQL
WITH signal_5m AS (
    SELECT
        f.symbol,
        f.asset_type,
        f.ts_est,
        f.price AS fmp_price,
        f.vwap  AS fmp_vwap,
        f.above_vwap AS fmp_above_vwap,
        f.ema9  AS fmp_ema9,
        f.ema21 AS fmp_ema21,
        f.ema9_above_ema21 AS fmp_ema9_above_ema21,
        f.ema9_ema21_spread AS fmp_ema9_ema21_spread,
        f.atr AS fmp_atr,
        f.atr_pct AS fmp_atr_pct,
        f.rsi_14 AS fmp_rsi_14
    FROM five_minute_prices f
    WHERE f.symbol = ?
      AND f.asset_type = ?
      AND f.ts_est <= ?
    ORDER BY f.ts_est DESC
    LIMIT 1
),
spy_1m AS (
    SELECT
        o.ts_est,
        o.price AS spy_price,
        o.vwap  AS spy_vwap,
        o.above_vwap AS spy_above_vwap,
        LAG(o.price, 5) OVER (ORDER BY o.ts_est) AS spy_price_5m_ago
    FROM one_minute_prices o
    WHERE o.symbol = ?
      AND o.asset_type = 'stock'
      AND o.ts_est BETWEEN DATE_SUB(?, INTERVAL {$beforeMinutes} MINUTE)
                      AND ?
),
spy_last AS (
    SELECT
        ts_est,
        spy_price,
        spy_vwap,
        spy_above_vwap,
        CASE
            WHEN spy_price_5m_ago IS NULL OR spy_price_5m_ago = 0 THEN 0
            ELSE ((spy_price - spy_price_5m_ago) / spy_price_5m_ago) * 100
        END AS spy_move_5m_pct
    FROM spy_1m
),
base AS (
    SELECT
        o.symbol,
        o.asset_type,
        o.ts_est,
        o.trading_date_est,
        o.trading_time_est,
        o.open,
        o.high,
        o.low,
        o.price,
        o.volume,
        o.vwap,
        o.vwap_dist_pct,
        o.above_vwap,
        o.ema9,
        o.ema21,
        o.ema9_above_ema21,
        o.ema9_ema21_spread,
        o.atr,
        o.atr_pct,

        LAG(o.price, 1) OVER (PARTITION BY o.symbol, o.asset_type, o.trading_date_est ORDER BY o.ts_est) AS prev_price,
        LAG(o.high,  1) OVER (PARTITION BY o.symbol, o.asset_type, o.trading_date_est ORDER BY o.ts_est) AS prev_high,
        LAG(o.low,   1) OVER (PARTITION BY o.symbol, o.asset_type, o.trading_date_est ORDER BY o.ts_est) AS prev_low,
        LAG(o.vwap,  1) OVER (PARTITION BY o.symbol, o.asset_type, o.trading_date_est ORDER BY o.ts_est) AS prev_vwap,
        LAG(o.ema9,  1) OVER (PARTITION BY o.symbol, o.asset_type, o.trading_date_est ORDER BY o.ts_est) AS prev_ema9,

        AVG(o.volume) OVER (
            PARTITION BY o.symbol, o.asset_type, o.trading_date_est
            ORDER BY o.ts_est
            ROWS BETWEEN {$volLookback} PRECEDING AND 1 PRECEDING
        ) AS avg_vol_prev,

        MAX(o.high) OVER (
            PARTITION BY o.symbol, o.asset_type, o.trading_date_est
            ORDER BY o.ts_est
            ROWS BETWEEN {$pivotLookback} PRECEDING AND 1 PRECEDING
        ) AS pivot_high,

        MIN(o.low) OVER (
            PARTITION BY o.symbol, o.asset_type, o.trading_date_est
            ORDER BY o.ts_est
            ROWS BETWEEN {$pivotLookback} PRECEDING AND 1 PRECEDING
        ) AS pivot_low,

        MAX(o.high) OVER (
            PARTITION BY o.symbol, o.asset_type, o.trading_date_est
            ORDER BY o.ts_est
            ROWS BETWEEN 5 PRECEDING AND 1 PRECEDING
        ) AS high_5bars,

        MIN(o.low) OVER (
            PARTITION BY o.symbol, o.asset_type, o.trading_date_est
            ORDER BY o.ts_est
            ROWS BETWEEN 5 PRECEDING AND 1 PRECEDING
        ) AS low_5bars,

        MAX(o.high) OVER (
            PARTITION BY o.symbol, o.asset_type, o.trading_date_est
            ORDER BY o.ts_est
            ROWS BETWEEN 15 PRECEDING AND CURRENT ROW
        ) AS recent_high_15,

        MIN(o.low) OVER (
            PARTITION BY o.symbol, o.asset_type, o.trading_date_est
            ORDER BY o.ts_est
            ROWS BETWEEN 15 PRECEDING AND CURRENT ROW
        ) AS recent_low_15,

        FIRST_VALUE(o.high) OVER (
            PARTITION BY o.symbol, o.asset_type, o.trading_date_est
            ORDER BY o.ts_est
            ROWS BETWEEN UNBOUNDED PRECEDING AND UNBOUNDED FOLLOWING
        ) AS first_bar_high,

        FIRST_VALUE(o.low) OVER (
            PARTITION BY o.symbol, o.asset_type, o.trading_date_est
            ORDER BY o.ts_est
            ROWS BETWEEN UNBOUNDED PRECEDING AND UNBOUNDED FOLLOWING
        ) AS first_bar_low
    FROM one_minute_prices o
    WHERE o.symbol = ?
      AND o.asset_type = ?
      AND o.ts_est BETWEEN DATE_SUB(?, INTERVAL {$beforeMinutes} MINUTE)
                      AND ?
),
joined AS (
    SELECT
        b.*,
        s5.fmp_price,
        s5.fmp_vwap,
        s5.fmp_above_vwap,
        s5.fmp_ema9,
        s5.fmp_ema21,
        s5.fmp_ema9_above_ema21,
        s5.fmp_ema9_ema21_spread,
        s5.fmp_atr,
        s5.fmp_atr_pct,
        s5.fmp_rsi_14,

        spy.spy_price,
        spy.spy_vwap,
        spy.spy_above_vwap,
        spy.spy_move_5m_pct,

        CASE
            WHEN COALESCE(b.avg_vol_prev, 0) = 0 THEN 0
            ELSE b.volume / b.avg_vol_prev
        END AS vol_ratio,

        ((b.price - b.open) / NULLIF(b.open, 0)) * 100 AS bar_gain_pct,

        CASE
            WHEN b.recent_high_15 IS NULL OR b.recent_high_15 = 0 THEN 999
            ELSE ((b.recent_high_15 - b.price) / b.recent_high_15) * 100
        END AS pullback_from_recent_high_pct,

        CASE
            WHEN b.ema9 IS NULL OR b.ema9 = 0 THEN 999
            ELSE ((b.price - b.ema9) / b.ema9) * 100
        END AS extension_from_ema9_pct,

        CASE
            WHEN b.vwap IS NULL OR b.vwap = 0 THEN 999
            ELSE ((b.price - b.vwap) / b.vwap) * 100
        END AS extension_from_vwap_pct,

        CASE
            WHEN b.high_5bars IS NULL OR b.low_5bars IS NULL OR b.price = 0 THEN 999
            ELSE ((b.high_5bars - b.low_5bars) / b.price) * 100
        END AS range_5bars_pct
    FROM base b
    CROSS JOIN signal_5m s5
    LEFT JOIN spy_last spy
        ON spy.ts_est = b.ts_est
),
typed AS (
    SELECT
        j.*,

        CASE
            WHEN
                j.above_vwap = 1
                AND j.prev_low <= j.prev_vwap
                AND j.low >= j.vwap
                AND j.price > j.prev_high
                AND j.pullback_from_recent_high_pct BETWEEN {$minPullbackDepthPct} AND {$maxPullbackDepthPct}
                AND j.vol_ratio >= {$minBreakoutVolRatio}
            THEN 'VWAP_PULLBACK_HOLD'

            WHEN
                j.above_vwap = 1
                AND j.ema9_above_ema21 = 1
                AND j.prev_low <= j.prev_ema9
                AND j.low >= j.ema9
                AND j.price > j.prev_high
                AND j.extension_from_ema9_pct <= {$maxExtensionFromEma9Pct}
                AND j.vol_ratio >= {$minVolRatio}
            THEN 'EMA9_BOUNCE'

            WHEN
                j.above_vwap = 1
                AND j.ema9_above_ema21 = 1
                AND j.range_5bars_pct <= 1.25
                AND j.price > j.high_5bars
                AND j.pullback_from_recent_high_pct BETWEEN {$minPullbackDepthPct} AND 1.25
                AND j.vol_ratio >= {$minBreakoutVolRatio}
            THEN 'TIGHT_FLAG_BREAK'

            WHEN
                j.above_vwap = 1
                AND j.price > j.first_bar_high
                AND j.prev_low <= j.first_bar_high
                AND j.low >= j.first_bar_high
                AND j.vol_ratio >= {$minBreakoutVolRatio}
            THEN 'OR_BREAKOUT_RETEST'

            ELSE NULL
        END AS entry_type
    FROM joined j
),
scored AS (
    SELECT
        t.*,

        (
            (CASE WHEN t.entry_type IS NOT NULL THEN 25 ELSE 0 END) +
            (CASE WHEN t.above_vwap = 1 THEN 10 ELSE 0 END) +
            (CASE WHEN t.ema9_above_ema21 = 1 THEN 10 ELSE 0 END) +
            (CASE WHEN t.vol_ratio >= {$minVolRatio} THEN 10 ELSE 0 END) +
            (CASE WHEN t.vol_ratio >= {$minBreakoutVolRatio} THEN 5 ELSE 0 END) +
            (CASE WHEN t.extension_from_vwap_pct BETWEEN 0.10 AND {$maxVwapExtensionPct} THEN 10 ELSE 0 END) +
            (CASE WHEN t.extension_from_ema9_pct <= {$maxExtensionFromEma9Pct} THEN 5 ELSE 0 END) +
            (CASE WHEN t.pullback_from_recent_high_pct BETWEEN {$minPullbackDepthPct} AND {$maxPullbackDepthPct} THEN 10 ELSE 0 END) +
            (CASE WHEN t.spy_above_vwap = 0 OR t.spy_move_5m_pct <= 0 THEN 5 ELSE 0 END) +
            (CASE WHEN t.fmp_above_vwap = 1 THEN 5 ELSE 0 END) +
            (CASE WHEN t.fmp_ema9_above_ema21 = 1 THEN 5 ELSE 0 END)
        ) AS entry_score
    FROM typed t
)
SELECT
    symbol,
    asset_type,
    ts_est,
    trading_date_est,
    trading_time_est,
    open,
    high,
    low,
    price,
    volume,
    vwap,
    vwap_dist_pct,
    above_vwap,
    ema9,
    ema21,
    ema9_above_ema21,
    ema9_ema21_spread,
    atr,
    atr_pct,
    fmp_price,
    fmp_vwap,
    fmp_above_vwap,
    fmp_ema9,
    fmp_ema21,
    fmp_ema9_above_ema21,
    fmp_ema9_ema21_spread,
    fmp_atr,
    fmp_atr_pct,
    fmp_rsi_14,
    spy_price,
    spy_vwap,
    spy_above_vwap,
    spy_move_5m_pct,
    vol_ratio,
    bar_gain_pct,
    pullback_from_recent_high_pct,
    extension_from_ema9_pct,
    extension_from_vwap_pct,
    range_5bars_pct,
    entry_type,
    entry_score,
    CASE
        WHEN '{$fillModel}' = 'close' THEN price
        ELSE LEAD(open, 1) OVER (PARTITION BY symbol, asset_type, trading_date_est ORDER BY ts_est)
    END AS suggested_entry,
    '{$this->version}' AS algo_version
FROM scored
WHERE entry_type IS NOT NULL
  AND entry_score BETWEEN {$entryScoreMin} AND {$entryScoreMax}
  AND vol_ratio >= {$minVolRatio}
  AND extension_from_vwap_pct <= {$maxVwapExtensionPct}
  AND extension_from_ema9_pct <= {$maxExtensionFromEma9Pct}
  AND bar_gain_pct <= {$maxChaseBarPct}
  AND ({$require5mAboveVwapInt} = 0 OR fmp_above_vwap = 1)
  AND ({$require5mBullTrendInt} = 0 OR fmp_ema9_above_ema21 = 1)
  AND ((fmp_ema9_ema21_spread / NULLIF(fmp_price, 0)) * 100) >= {$min5mTrendSpreadPct}
ORDER BY entry_score DESC, vol_ratio DESC, ts_est ASC
LIMIT 1
SQL;

        $rows = $this->dbSelect($sql, [
            $symbol,
            $assetType,
            $signalTsEst,
            $benchmarkSymbol,
            $signalTsEst,
            $asOfTsEst,
            $symbol,
            $assetType,
            $signalTsEst,
            $asOfTsEst,
        ]);

        return $rows ? (array) $rows[0] : [];
    }
}
