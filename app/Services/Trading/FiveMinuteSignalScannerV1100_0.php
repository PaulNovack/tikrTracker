<?php

namespace App\Services\Trading;

use Illuminate\Support\Facades\DB;

/**
 * Version 1100.0 - Relative Strength Scarcity Leader Scanner
 *
 * Purpose:
 * In weak / risk-off markets, find the few gainers that are:
 * - holding above VWAP
 * - trending cleanly on 5m
 * - showing relative strength vs SPY
 * - staying near highs instead of fading
 *
 * It is NOT a broad momentum scanner.
 * It is specifically for "leaders ignoring market weakness".
 *
 * Expected table:
 * - five_minute_prices
 *
 * Useful config keys under trading.v1100.*
 * - entry_score_min
 * - entry_score_max
 * - entry_score_limit
 * - min_price
 * - max_price
 * - min_vol_ratio
 * - min_rel_strength_ratio
 * - min_market_weakness_pct
 * - max_distance_from_high_atr
 * - max_vwap_extension_pct
 * - min_ema_spread_pct
 * - min_dollar_volume_per_minute
 * - require_spy_below_vwap
 * - min_day_gain_pct
 * - lookback_bars_for_high
 * - require_green_close
 * - min_range_contraction_bars
 */
class FiveMinuteSignalScannerV1100_0
{
    use HasPriceTables;

    private string $version = 'v1100.0';

    private string $name = 'Scarcity Leader (RS vs SPY)';

    // ── Scanner Configuration (public so entry finders can read) ──
    /** @var float Minimum entry score (0-100) */
    public float $entryScoreMin = 35;

    /** @var float Maximum entry score (0-100) */
    public float $entryScoreMax = 85;

    /** @var int Max number of signals to return */
    public int $entryScoreLimit = 60;

    /** @var float Minimum share price */
    public float $minPrice = 2.00;

    /** @var float Maximum share price */
    public float $maxPrice = 80.00;

    /** @var float Minimum volume ratio vs average */
    public float $minVolRatio = 1.8;

    /** @var float Minimum RS ratio vs SPY */
    public float $minRelStrengthRatio = 1.10;

    /** @var float Max market weakness % (negative = SPY must be weak) */
    public float $minMarketWeaknessPct = -0.10;

    /** @var float Max distance from recent high (ATR multiples) */
    public float $maxDistanceFromHighAtr = 1.0;

    /** @var float Max % above VWAP allowed for entry */
    public float $maxVwapExtensionPct = 3.0;

    /** @var float Minimum EMA spread % (trend strength) */
    public float $minEmaSpreadPct = 0.08;

    /** @var float Minimum $ volume per minute for liquidity */
    public float $minDollarVolPerMinute = 2500;

    /** @var bool Require SPY below VWAP (risk-off confirmation) */
    public bool $requireSpyBelowVwap = false;

    /** @var float Minimum day gain % for inclusion */
    public float $minDayGainPct = 2.5;

    /** @var int Lookback bars for recent high */
    public int $lookbackBarsForHigh = 12;

    /** @var bool Require last bar close to be green */
    public bool $requireGreenClose = true;

    /** @var int Minimum range contraction bars for setup */
    public int $minRangeContractionBars = 2;

    /** @return array<string, mixed> */
    public function scanConfig(): array
    {
        return [
            'entry_score_min' => $this->entryScoreMin,
            'entry_score_max' => $this->entryScoreMax,
            'entry_score_limit' => $this->entryScoreLimit,
            'min_price' => $this->minPrice,
            'max_price' => $this->maxPrice,
            'min_vol_ratio' => $this->minVolRatio,
            'min_rel_strength_ratio' => $this->minRelStrengthRatio,
            'min_market_weakness_pct' => $this->minMarketWeaknessPct,
            'max_distance_from_high_atr' => $this->maxDistanceFromHighAtr,
            'max_vwap_extension_pct' => $this->maxVwapExtensionPct,
            'min_ema_spread_pct' => $this->minEmaSpreadPct,
            'min_dollar_volume_per_minute' => $this->minDollarVolPerMinute,
            'require_spy_below_vwap' => $this->requireSpyBelowVwap,
            'min_day_gain_pct' => $this->minDayGainPct,
            'lookback_bars_for_high' => $this->lookbackBarsForHigh,
            'require_green_close' => $this->requireGreenClose,
            'min_range_contraction_bars' => $this->minRangeContractionBars,
        ];
    }

    public function getVersion(): string
    {
        return $this->version;
    }

    public function getName(): string
    {
        return $this->name;
    }

    /**
     * Find 5m long candidates in a weak market.
     *
     * @param  string  $asOfTsEst  Scan timestamp (EST)
     * @param  int  $limit  Max rows to return
     */
    public function scan(string $asOfTsEst, int $limit = 20): array
    {
        $benchmarkSymbol = config('app.trading_market_benchmark_symbol', 'SPY');

        $entryScoreMin = $this->entryScoreMin;
        $entryScoreMax = $this->entryScoreMax;
        $entryScoreLimit = $this->entryScoreLimit;

        $minPrice = $this->minPrice;
        $maxPrice = $this->maxPrice;
        $minVolRatio = $this->minVolRatio;
        $minRelStrengthRatio = $this->minRelStrengthRatio;
        $minMarketWeaknessPct = $this->minMarketWeaknessPct;
        $maxDistanceFromHighAtr = $this->maxDistanceFromHighAtr;
        $maxVwapExtensionPct = $this->maxVwapExtensionPct;
        $minEmaSpreadPct = $this->minEmaSpreadPct;
        $minDollarVolPerMinute = $this->minDollarVolPerMinute;
        $requireSpyBelowVwap = $this->requireSpyBelowVwap;
        $minDayGainPct = $this->minDayGainPct;
        $lookbackBarsForHigh = $this->lookbackBarsForHigh;
        $requireGreenClose = $this->requireGreenClose;
        $minRangeContractionBars = $this->minRangeContractionBars;

        // Extract trading date from asOfTsEst for scoping queries to a single day
        $tradingDateEst = substr($asOfTsEst, 0, 10);

        // Lookback window: enough bars for rolling_high (lookbackBarsForHigh * 5 min) + buffer
        $lookbackMinutes = ($lookbackBarsForHigh + 3) * 5;
        $windowStartTsEst = date('Y-m-d H:i:s', strtotime($asOfTsEst." -{$lookbackMinutes} minutes"));

        // Convert booleans to integers for SQL interpolation
        $requireSpyBelowVwapInt = $requireSpyBelowVwap ? 1 : 0;
        $requireGreenCloseInt = $requireGreenClose ? 1 : 0;

        // Calculate market context in PHP (optimization: avoid expensive CTEs with window functions)
        $benchmarkBars = DB::table('five_minute_prices')
            ->select('ts_est', 'price', 'vwap', 'above_vwap')
            ->where('symbol', $benchmarkSymbol)
            ->where('asset_type', 'stock')
            ->where('ts_est', '<=', $asOfTsEst)
            ->orderBy('ts_est', 'desc')
            ->limit(4)
            ->get();

        $spyPrice = $benchmarkBars[0]->price ?? 0;
        $spyVwap = $benchmarkBars[0]->vwap ?? 0;
        $spyAboveVwap = $benchmarkBars[0]->above_vwap ?? 0;
        $spyPrice3BarsAgo = $benchmarkBars[3]->price ?? 0;
        $spyMove15mPct = ($spyPrice3BarsAgo > 0)
            ? (($spyPrice - $spyPrice3BarsAgo) / $spyPrice3BarsAgo) * 100
            : 0;

        $sql = <<<SQL
WITH
candidates AS (
    /* Step 1: cheaply find the latest bar per qualifying symbol — no window functions */
    SELECT f.symbol, f.asset_type, f.ts_est AS latest_ts
    FROM five_minute_prices f
    INNER JOIN (
        SELECT symbol, asset_type, MAX(ts_est) AS max_ts
        FROM five_minute_prices
        WHERE asset_type = 'stock'
          AND trading_date_est = ?
          AND ts_est <= ?
        GROUP BY symbol, asset_type
    ) latest ON f.symbol = latest.symbol
             AND f.asset_type = latest.asset_type
             AND f.ts_est = latest.max_ts
    WHERE f.trading_date_est = ?
      AND f.above_vwap = 1
      AND f.ema9_above_ema21 = 1
      AND f.price BETWEEN {$minPrice} AND {$maxPrice}
      AND f.change_from_open >= {$minDayGainPct}
      AND f.vwap_dist_pct BETWEEN 0.15 AND {$maxVwapExtensionPct}
      AND f.volume > 0
),
base AS (
    /* Step 2: window functions only over the small set of qualifying symbols */
    SELECT
        f.symbol,
        f.asset_type,
        f.ts_est,
        f.trading_date_est,
        f.trading_time_est,
        f.open,
        f.high,
        f.low,
        f.price,
        f.volume,
        f.vwap,
        f.vwap_dist_pct,
        f.above_vwap,
        f.ema9,
        f.ema21,
        f.ema9_above_ema21,
        f.ema9_ema21_spread,
        f.atr,
        f.atr_pct,
        f.rsi_14,

        ((f.price - f.open) / NULLIF(f.open, 0)) * 100 AS bar_gain_pct,

        LAG(f.price, 1) OVER (PARTITION BY f.symbol, f.asset_type ORDER BY f.ts_est) AS prev_price,
        LAG(f.low,   1) OVER (PARTITION BY f.symbol, f.asset_type ORDER BY f.ts_est) AS prev_low,
        LAG(f.high,  1) OVER (PARTITION BY f.symbol, f.asset_type ORDER BY f.ts_est) AS prev_high,
        LAG(f.volume,1) OVER (PARTITION BY f.symbol, f.asset_type ORDER BY f.ts_est) AS prev_volume,

        MAX(f.high) OVER (
            PARTITION BY f.symbol, f.asset_type
            ORDER BY f.ts_est
            ROWS BETWEEN {$lookbackBarsForHigh} PRECEDING AND CURRENT ROW
        ) AS rolling_high,

        MIN(f.low) OVER (
            PARTITION BY f.symbol, f.asset_type
            ORDER BY f.ts_est
            ROWS BETWEEN 2 PRECEDING AND CURRENT ROW
        ) AS recent_3bar_low,

        AVG((f.high - f.low) / NULLIF(f.price, 0) * 100) OVER (
            PARTITION BY f.symbol, f.asset_type
            ORDER BY f.ts_est
            ROWS BETWEEN 4 PRECEDING AND 1 PRECEDING
        ) AS prior_avg_range_pct,

        ((f.high - f.low) / NULLIF(f.price, 0)) * 100 AS current_range_pct,

        f.change_from_open AS day_gain_pct,

        ROW_NUMBER() OVER (PARTITION BY f.symbol, f.asset_type ORDER BY f.ts_est DESC) AS rn
    FROM five_minute_prices f
    INNER JOIN candidates c ON f.symbol = c.symbol AND f.asset_type = c.asset_type
    WHERE f.trading_date_est = ?
      AND f.ts_est >= ?
      AND f.ts_est <= ?
),
ranked AS (
    SELECT
        b.*,
        ? AS spy_price,
        ? AS spy_vwap,
        ? AS spy_above_vwap,
        ? AS spy_move_15m_pct,

        CASE
            WHEN b.rolling_high IS NULL OR b.atr IS NULL OR b.atr = 0 THEN 999
            ELSE (b.rolling_high - b.price) / b.atr
        END AS distance_from_high_atr,

        CASE
            WHEN ABS(COALESCE(?, 0)) < 0.01 THEN 0
            ELSE
                (
                    CASE
                        WHEN b.prev_price IS NULL OR b.prev_price = 0 THEN 0
                        ELSE ((b.price - b.prev_price) / b.prev_price) * 100
                    END
                ) / ABS(?)
        END AS rel_strength_ratio,

        (b.price * b.volume) AS bar_dollar_volume,

        CASE WHEN b.price > b.open THEN 1 ELSE 0 END AS green_bar,

        CASE
            WHEN b.current_range_pct < COALESCE(b.prior_avg_range_pct, 999) THEN 1
            ELSE 0
        END AS is_contracting
    FROM base b
    WHERE b.rn = 1
),
scored AS (
    SELECT
        r.*,

        (
            /* Core structure */
            (CASE WHEN r.above_vwap = 1 THEN 20 ELSE 0 END) +
            (CASE WHEN r.ema9_above_ema21 = 1 THEN 15 ELSE 0 END) +
            (CASE WHEN r.vwap_dist_pct BETWEEN 0.15 AND {$maxVwapExtensionPct} THEN 10 ELSE 0 END) +
            (CASE WHEN r.rel_strength_ratio >= {$minRelStrengthRatio} THEN 20 ELSE 0 END) +
            (CASE WHEN r.distance_from_high_atr <= {$maxDistanceFromHighAtr} THEN 15 ELSE 0 END) +
            (CASE WHEN r.bar_dollar_volume >= {$minDollarVolPerMinute} THEN 5 ELSE 0 END) +
            (CASE WHEN r.volume >= COALESCE(r.prev_volume, 0) THEN 5 ELSE 0 END) +
            (CASE WHEN r.day_gain_pct >= {$minDayGainPct} THEN 5 ELSE 0 END) +
            (CASE WHEN r.ema9_ema21_spread / NULLIF(r.price, 0) * 100 >= {$minEmaSpreadPct} THEN 5 ELSE 0 END)
        ) AS entry_score
    FROM ranked r
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
    rsi_14,
    spy_price,
    spy_vwap,
    spy_above_vwap,
    spy_move_15m_pct,
    day_gain_pct,
    distance_from_high_atr,
    rel_strength_ratio,
    bar_dollar_volume,
    green_bar,
    is_contracting,
    entry_score,
    '{$this->version}' AS algo_version
FROM scored
WHERE price BETWEEN {$minPrice} AND {$maxPrice}
  AND volume > 0
  AND above_vwap = 1
  AND ema9_above_ema21 = 1
  AND vwap_dist_pct BETWEEN 0.15 AND {$maxVwapExtensionPct}
  AND COALESCE(rel_strength_ratio, 0) >= {$minRelStrengthRatio}
  AND COALESCE(distance_from_high_atr, 999) <= {$maxDistanceFromHighAtr}
  AND bar_dollar_volume >= {$minDollarVolPerMinute}
  AND day_gain_pct >= {$minDayGainPct}
  AND (ema9_ema21_spread / NULLIF(price, 0) * 100) >= {$minEmaSpreadPct}
  AND (
      spy_move_15m_pct <= {$minMarketWeaknessPct}
      OR spy_above_vwap = 0
      OR {$requireSpyBelowVwapInt} = 0
  )
  AND ({$requireGreenCloseInt} = 0 OR green_bar = 1)
  AND entry_score BETWEEN {$entryScoreMin} AND {$entryScoreMax}
ORDER BY entry_score DESC, rel_strength_ratio DESC, distance_from_high_atr ASC
LIMIT {$entryScoreLimit}
SQL;

        return $this->dbSelect($sql, [
            $tradingDateEst,     // candidates: trading_date_est = ?
            $asOfTsEst,          // candidates: ts_est <= ? (inner GROUP BY)
            $tradingDateEst,     // candidates: outer WHERE trading_date_est = ?
            $tradingDateEst,     // base CTE: WHERE f.trading_date_est = ?
            $windowStartTsEst,   // base CTE: WHERE f.ts_est >= ?
            $asOfTsEst,          // base CTE: WHERE f.ts_est <= ?
            $spyPrice,           // ranked: ? AS spy_price
            $spyVwap,            // ranked: ? AS spy_vwap
            $spyAboveVwap,       // ranked: ? AS spy_above_vwap
            $spyMove15mPct,      // ranked: ? AS spy_move_15m_pct
            $spyMove15mPct,      // ranked: ABS(COALESCE(?, 0))
            $spyMove15mPct,      // ranked: / ABS(?)
        ]);
    }
}
