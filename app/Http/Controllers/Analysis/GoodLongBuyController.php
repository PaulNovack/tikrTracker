<?php

declare(strict_types=1);

namespace App\Http\Controllers\Analysis;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class GoodLongBuyController extends Controller
{
    /**
     * Show intraday long candidates scored using multi-factor ranking
     * (momentum, volume, VWAP, EMA trend, breakout structure).
     */
    public function index(Request $request): Response
    {
        $limit = min((int) $request->get('limit', 25), 50);

        $rows = DB::connection('mysql')->select('
            WITH
            tuning AS (
                SELECT
                    \'STRICT_INTRADAY_LONG\' AS tuning_profile,
                    90  AS lookback_minutes,
                    2   AS max_signal_age_minutes,
                    1.00   AS min_price,
                    200.00 AS max_price,
                    100000.00 AS min_dollar_vol_5m,
                    2.00      AS min_vol_ratio,
                    0.00 AS min_ret_1m_pct,
                    0.80 AS max_ret_1m_pct,
                    0.10 AS min_ret_3m_pct,
                    0.15 AS min_ret_5m_pct,
                    3.00 AS max_ret_5m_pct,
                    0.05 AS min_vwap_dist_pct,
                    1.75 AS max_vwap_dist_pct,
                    4.00 AS max_move_from_15m_low_pct,
                    0.10 AS min_atr_pct,
                    3.00 AS max_atr_pct,
                    65.00 AS min_buy_score
            ),

            params AS (
                SELECT
                    CONVERT_TZ(UTC_TIMESTAMP(), \'UTC\', \'America/New_York\') AS now_est,
                    TIMESTAMP(
                        DATE(CONVERT_TZ(UTC_TIMESTAMP(), \'UTC\', \'America/New_York\')),
                        \'09:30:00\'
                    ) AS session_start_est,
                    TIMESTAMP(
                        DATE(CONVERT_TZ(UTC_TIMESTAMP(), \'UTC\', \'America/New_York\')),
                        \'16:00:00\'
                    ) AS session_end_est
            ),

            latest AS (
                SELECT
                    MAX(omp.ts_est) AS latest_ts_est
                FROM one_minute_prices omp
                CROSS JOIN params p
                WHERE omp.asset_type = \'stock\'
                  AND omp.ts_est >= p.session_start_est
                  AND omp.ts_est <  p.session_end_est
            ),

            bars AS (
                SELECT
                    omp.symbol,
                    omp.asset_type,
                    omp.ts_est,
                    omp.trading_date_est,
                    omp.trading_time_est,
                    omp.price,
                    omp.open,
                    omp.high,
                    omp.low,
                    omp.volume,
                    omp.vwap,
                    omp.vwap_dist_pct,
                    omp.above_vwap,
                    omp.ema9,
                    omp.ema21,
                    omp.ema9_ema21_spread,
                    omp.ema9_above_ema21,
                    omp.atr,
                    omp.atr_pct
                FROM one_minute_prices omp
                CROSS JOIN params p
                CROSS JOIN latest l
                CROSS JOIN tuning t
                WHERE omp.asset_type = \'stock\'
                  AND l.latest_ts_est IS NOT NULL
                  AND omp.ts_est >= GREATEST(
                        p.session_start_est,
                        l.latest_ts_est - INTERVAL t.lookback_minutes MINUTE
                      )
                  AND omp.ts_est <= l.latest_ts_est
                  AND omp.ts_est < p.session_end_est
                  AND omp.price BETWEEN t.min_price AND t.max_price
                  AND omp.open IS NOT NULL
                  AND omp.high IS NOT NULL
                  AND omp.low IS NOT NULL
                  AND omp.volume IS NOT NULL
                  AND omp.volume > 0
            ),

            features AS (
                SELECT
                    b.*,
                    LAG(price, 1) OVER w AS price_1m_ago,
                    LAG(price, 3) OVER w AS price_3m_ago,
                    LAG(price, 5) OVER w AS price_5m_ago,
                    LAG(above_vwap, 1) OVER w AS prev_above_vwap,
                    LAG(price, 1)      OVER w AS prev_price,
                    LAG(ema9, 1)       OVER w AS prev_ema9,
                    LAG(ema21, 1)      OVER w AS prev_ema21,
                    AVG(volume) OVER (
                        PARTITION BY symbol, asset_type
                        ORDER BY ts_est
                        ROWS BETWEEN 20 PRECEDING AND 1 PRECEDING
                    ) AS avg_vol_20m,
                    SUM(volume) OVER (
                        PARTITION BY symbol, asset_type
                        ORDER BY ts_est
                        ROWS BETWEEN 4 PRECEDING AND CURRENT ROW
                    ) AS vol_5m,
                    SUM(volume * price) OVER (
                        PARTITION BY symbol, asset_type
                        ORDER BY ts_est
                        ROWS BETWEEN 4 PRECEDING AND CURRENT ROW
                    ) AS dollar_vol_5m,
                    MAX(high) OVER (
                        PARTITION BY symbol, asset_type
                        ORDER BY ts_est
                        ROWS BETWEEN 30 PRECEDING AND 1 PRECEDING
                    ) AS prior_high_30m,
                    MAX(high) OVER (
                        PARTITION BY symbol, asset_type
                        ORDER BY ts_est
                        ROWS BETWEEN 14 PRECEDING AND CURRENT ROW
                    ) AS high_15m,
                    MIN(low) OVER (
                        PARTITION BY symbol, asset_type
                        ORDER BY ts_est
                        ROWS BETWEEN 14 PRECEDING AND CURRENT ROW
                    ) AS low_15m,
                    ROW_NUMBER() OVER (
                        PARTITION BY symbol, asset_type
                        ORDER BY ts_est DESC
                    ) AS rn
                FROM bars b
                WINDOW w AS (
                    PARTITION BY symbol, asset_type
                    ORDER BY ts_est
                )
            ),

            calc AS (
                SELECT
                    f.*,
                    ROUND(((price / NULLIF(price_1m_ago, 0)) - 1) * 100, 4) AS ret_1m_pct,
                    ROUND(((price / NULLIF(price_3m_ago, 0)) - 1) * 100, 4) AS ret_3m_pct,
                    ROUND(((price / NULLIF(price_5m_ago, 0)) - 1) * 100, 4) AS ret_5m_pct,
                    ROUND(volume / NULLIF(avg_vol_20m, 0), 4) AS vol_ratio,
                    ROUND(((price / NULLIF(low_15m, 0)) - 1) * 100, 4) AS move_from_15m_low_pct,
                    ROUND(((price / NULLIF(high_15m, 0)) - 1) * 100, 4) AS dist_from_15m_high_pct,
                    ROUND(price * volume, 2) AS dollar_vol_1m,
                    ROUND(
                        COALESCE(
                            vwap_dist_pct,
                            CASE
                                WHEN vwap IS NOT NULL AND vwap > 0
                                    THEN ((price - vwap) / vwap) * 100
                                ELSE NULL
                            END
                        ),
                        4
                    ) AS effective_vwap_dist_pct
                FROM features f
            ),

            classified AS (
                SELECT
                    c.*,
                    CASE
                        WHEN c.price > c.prior_high_30m
                             AND c.ret_1m_pct BETWEEN 0.03 AND 0.80
                             AND c.ret_3m_pct >= 0.15
                             AND c.above_vwap = 1
                             AND c.ema9_above_ema21 = 1
                            THEN \'FRESH_30M_BREAKOUT\'
                        WHEN c.above_vwap = 1
                             AND c.prev_above_vwap = 0
                             AND c.price > c.vwap
                             AND c.price > c.ema9
                             AND c.ret_1m_pct BETWEEN 0.00 AND 0.70
                             AND c.ret_3m_pct >= 0.05
                            THEN \'VWAP_RECLAIM\'
                        WHEN c.price > c.ema9
                             AND c.prev_price <= c.prev_ema9
                             AND c.ema9 > c.ema21
                             AND c.ret_3m_pct >= 0.10
                            THEN \'EMA9_BOUNCE\'
                        WHEN c.price > c.vwap
                             AND c.low <= c.vwap * 1.003
                             AND c.price > c.open
                             AND c.ema9 > c.ema21
                             AND c.ret_3m_pct >= 0.05
                            THEN \'VWAP_PULLBACK_HOLD\'
                        ELSE \'IGNORE\'
                    END AS setup_type,
                    ROUND(
                        GREATEST(0,
                            LEAST(COALESCE(GREATEST(c.ret_1m_pct, 0), 0) / 0.40, 1) * 18
                          + LEAST(COALESCE(GREATEST(c.ret_3m_pct, 0), 0) / 0.90, 1) * 17
                          + LEAST(COALESCE(GREATEST(c.ret_5m_pct, 0), 0) / 1.50, 1) * 15
                          + LEAST(COALESCE(c.vol_ratio, 0) / 3.00, 1) * 20
                          + CASE WHEN c.above_vwap = 1 THEN 10 ELSE 0 END
                          + CASE WHEN c.ema9_above_ema21 = 1 THEN 10 ELSE 0 END
                          + CASE WHEN c.price > c.prior_high_30m THEN 10 ELSE 0 END
                          + CASE
                                WHEN c.prev_above_vwap = 0 AND c.above_vwap = 1 THEN 8
                                WHEN c.prev_price <= c.prev_ema9 AND c.price > c.ema9 THEN 6
                                ELSE 0
                            END
                          - CASE
                                WHEN c.effective_vwap_dist_pct > 2.00
                                    THEN LEAST((c.effective_vwap_dist_pct - 2.00) / 2.00, 1) * 20
                                ELSE 0
                            END
                          - CASE
                                WHEN c.move_from_15m_low_pct > 4.00
                                    THEN LEAST((c.move_from_15m_low_pct - 4.00) / 4.00, 1) * 15
                                ELSE 0
                            END
                          - CASE
                                WHEN c.dollar_vol_5m < 100000 THEN 10
                                ELSE 0
                            END
                        ),
                        2
                    ) AS buy_score
                FROM calc c
            )

            SELECT
                c.symbol,
                ai.id AS asset_info_id,
                \'BUY\' AS action,
                CASE
                    WHEN c.buy_score >= 80 THEN \'A_BUY_NOW_CONFIRMED\'
                    WHEN c.buy_score >= 70 THEN \'B_BUY_NOW_CONFIRMED\'
                    WHEN c.buy_score >= 65 THEN \'C_BUY_NOW_CONFIRMED\'
                    ELSE \'IGNORE\'
                END AS buy_grade,
                CASE
                    WHEN c.setup_type = \'FRESH_30M_BREAKOUT\' THEN \'FRESH_15M_BREAKOUT_CONFIRMED\'
                    WHEN c.setup_type = \'VWAP_RECLAIM\' THEN \'VWAP_RECLAIM_CONFIRMED\'
                    WHEN c.setup_type = \'EMA9_BOUNCE\' THEN \'EMA9_BOUNCE_CONFIRMED\'
                    WHEN c.setup_type = \'VWAP_PULLBACK_HOLD\' THEN \'VWAP_PULLBACK_HOLD_CONFIRMED\'
                    ELSE c.setup_type
                END AS confirmed_pattern,
                t.tuning_profile,
                c.ts_est AS signal_ts_est,
                TIMESTAMPDIFF(SECOND, c.ts_est, l.latest_ts_est) AS signal_age_seconds,
                c.price AS last_price,
                ROUND(c.vwap * 1.002, 2) AS suggested_limit_buy_price,
                ROUND(ROUND(c.vwap * 1.002, 2) - (c.atr * 1.5), 2) AS suggested_stop_price,
                ROUND((c.atr * 1.5) / NULLIF(ROUND(c.vwap * 1.002, 2), 0) * 100, 2) AS initial_risk_pct,
                ROUND(ROUND(c.vwap * 1.002, 2) + (c.atr * 1.5 * 1.5), 2) AS target_1_5r,
                ROUND(ROUND(c.vwap * 1.002, 2) + (c.atr * 1.5 * 2.0), 2) AS target_2r,
                c.vwap,
                c.effective_vwap_dist_pct AS vwap_dist_pct,
                c.ema9,
                c.ema21,
                c.ema9_ema21_spread,
                c.ret_1m_pct,
                c.ret_3m_pct,
                c.ret_5m_pct,
                c.volume,
                ROUND(c.avg_vol_20m, 0) AS avg_vol_20m,
                c.vol_ratio,
                c.dollar_vol_1m,
                ROUND(c.dollar_vol_5m, 2) AS dollar_vol_5m,
                c.atr,
                c.atr_pct,
                c.prior_high_30m AS prev_high,
                NULL AS prior_high_5m,
                c.high_15m AS prior_high_15m,
                c.low_15m,
                c.move_from_15m_low_pct,
                c.buy_score,
                CASE
                    WHEN c.setup_type = \'FRESH_30M_BREAKOUT\'
                        THEN \'Buy only if next 1m candle holds breakout and spread is tight\'
                    WHEN c.setup_type = \'VWAP_RECLAIM\'
                        THEN \'Buy only if price holds above VWAP on next candle\'
                    WHEN c.setup_type = \'EMA9_BOUNCE\'
                        THEN \'Buy only if price stays above EMA9 and breaks prior 1m high\'
                    WHEN c.setup_type = \'VWAP_PULLBACK_HOLD\'
                        THEN \'Buy only if VWAP holds and volume expands\'
                    ELSE \'Ignore\'
                END AS reason
            FROM classified c
            CROSS JOIN latest l
            CROSS JOIN tuning t
            LEFT JOIN asset_info ai ON ai.symbol = c.symbol AND ai.asset_type = \'stock\'
            WHERE c.rn = 1
              AND c.ts_est >= l.latest_ts_est - INTERVAL t.max_signal_age_minutes MINUTE
              AND c.setup_type <> \'IGNORE\'
              AND c.above_vwap = 1
              AND c.ema9_above_ema21 = 1
              AND c.price > c.vwap
              AND c.price > c.ema9
              AND c.ema9 > c.ema21
              AND c.ret_1m_pct >= t.min_ret_1m_pct
              AND c.ret_1m_pct <= t.max_ret_1m_pct
              AND c.ret_3m_pct >= t.min_ret_3m_pct
              AND c.ret_5m_pct >= t.min_ret_5m_pct
              AND c.ret_5m_pct <= t.max_ret_5m_pct
              AND c.vol_ratio >= t.min_vol_ratio
              AND c.dollar_vol_5m >= t.min_dollar_vol_5m
              AND c.effective_vwap_dist_pct BETWEEN t.min_vwap_dist_pct AND t.max_vwap_dist_pct
              AND c.move_from_15m_low_pct <= t.max_move_from_15m_low_pct
              AND c.atr_pct BETWEEN t.min_atr_pct AND t.max_atr_pct
              AND c.buy_score >= t.min_buy_score
            ORDER BY
                c.buy_score DESC,
                c.dollar_vol_5m DESC,
                c.vol_ratio DESC
            LIMIT ?
        ', [$limit]);

        $stocks = array_map(function ($row) {
            return [
                'symbol' => $row->symbol,
                'asset_info_id' => $row->asset_info_id ? (int) $row->asset_info_id : null,
                'action' => $row->action,
                'buy_grade' => $row->buy_grade,
                'confirmed_pattern' => $row->confirmed_pattern,
                'tuning_profile' => $row->tuning_profile,
                'signal_ts_est' => $row->signal_ts_est,
                'signal_age_seconds' => (int) $row->signal_age_seconds,
                'last_price' => (float) $row->last_price,
                'suggested_limit_buy_price' => (float) $row->suggested_limit_buy_price,
                'suggested_stop_price' => (float) $row->suggested_stop_price,
                'initial_risk_pct' => (float) $row->initial_risk_pct,
                'target_1_5r' => (float) $row->target_1_5r,
                'target_2r' => (float) $row->target_2r,
                'vwap' => (float) $row->vwap,
                'vwap_dist_pct' => (float) $row->vwap_dist_pct,
                'ema9' => (float) $row->ema9,
                'ema21' => (float) $row->ema21,
                'ema9_ema21_spread' => (float) $row->ema9_ema21_spread,
                'ret_1m_pct' => (float) $row->ret_1m_pct,
                'ret_3m_pct' => (float) $row->ret_3m_pct,
                'ret_5m_pct' => (float) $row->ret_5m_pct,
                'volume' => (int) $row->volume,
                'avg_vol_20m' => (int) $row->avg_vol_20m,
                'vol_ratio' => (float) $row->vol_ratio,
                'dollar_vol_1m' => (float) $row->dollar_vol_1m,
                'dollar_vol_5m' => (float) $row->dollar_vol_5m,
                'atr' => (float) $row->atr,
                'atr_pct' => (float) $row->atr_pct,
                'prev_high' => $row->prev_high ? (float) $row->prev_high : null,
                'prior_high_5m' => $row->prior_high_5m ? (float) $row->prior_high_5m : null,
                'prior_high_15m' => $row->prior_high_15m ? (float) $row->prior_high_15m : null,
                'low_15m' => (float) $row->low_15m,
                'move_from_15m_low_pct' => (float) $row->move_from_15m_low_pct,
                'buy_score' => (float) $row->buy_score,
                'reason' => $row->reason,
            ];
        }, $rows);

        return Inertia::render('analysis/good-long-buy/index', [
            'stocks' => $stocks,
            'totalSymbols' => count($stocks),
        ]);
    }
}
