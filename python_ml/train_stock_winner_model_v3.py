#!/usr/bin/env python3
"""
train_stock_winner_model_v3.py — Extends v2 with signal-latency features.

Key additions over v2:
  1. alert_age_minutes  — For actual Alpaca fills: TIMESTAMPDIFF(signal_ts_est → filled_at) / 60
     (real positive ages matching live distribution).  For BT rows: TIMESTAMPDIFF(signal_ts_est → entry_ts_est) / 60
     (near-zero/negative, representing the ideal BT entry baseline).
     At live-scoring time this becomes (NOW − signal_ts_est) / 60, which tells
     the model how stale the alert is when the order would be placed.
  2. price_move_since_signal_pct — (1m entry bar price − signal bar price) / signal bar price × 100
     Captures how far the stock already moved between signal detection and entry
     confirmation.  A large positive value means the stock "ran" before we could enter.
  3. Derived flags: alert_stale (>5 min), alert_very_stale (>8 min),
     stock_already_ran (>0.5%), stock_ran_hard (>1.5%), abs_price_move_since_signal.

The model payload is compatible with score_single_alert_v3.py (same contract as v2
but with the additional features populated before prediction).

Usage:
  python python_ml/train_stock_winner_model_v3.py train \\
      --start 2025-01-01 --end 2026-06-01 \\
      --pipeline H --train-full \\
      --model-out python_ml/models/winner_model_pipeline_h_v3.joblib
"""

from __future__ import annotations

import os
import argparse
from dataclasses import dataclass
from typing import List, Tuple

import numpy as np
import pandas as pd

from pathlib import Path
from dotenv import load_dotenv

from sqlalchemy import create_engine, text
from sqlalchemy.engine import URL
from sklearn.metrics import roc_auc_score, classification_report, confusion_matrix
from sklearn.pipeline import Pipeline
from sklearn.compose import ColumnTransformer
from sklearn.impute import SimpleImputer
from sklearn.preprocessing import StandardScaler
from sklearn.linear_model import LogisticRegression
import xgboost as xgb
import joblib


# -----------------------
# Config
# -----------------------

def _get_benchmark_symbol() -> str:
    load_parent_env()
    return os.environ.get("TRADING_MARKET_BENCHMARK_SYMBOL", "QQQ")


@dataclass
class DBConfig:
    host: str
    port: int
    name: str
    user: str
    password: str


# Explicit feature list — only columns available at live-scoring time.
# V3 adds signal-latency features (alert_age_minutes, price_move_since_signal_pct)
# and derived flags so the model can learn "stale alert → worse outcome".
FEATURE_COLUMNS = [
    # alert-side features (v2)
    "alert_score",
    "alert_vol_ratio",
    "alert_atr",
    "alert_atr_pct",
    "daily_trend_5d_pct",
    "range_position_60m",
    "alert_rsi_14_1m",
    "five_min_directional_changes",
    "five_min_green_bar_pct",
    "five_min_net_progress",
    "consolidation_bars",
    "breakout_volume_ratio",
    "pct_below_intraday_high",
    "minutes_since_high",
    "price_velocity_5min",
    "price_velocity_10min",
    "failed_rally_count",
    # 1m bar features at entry (v2)
    "vwap_dist_pct",
    "above_vwap",
    "ema9_ema21_spread",
    "ema9_above_ema21",
    "omp_atr_pct",
    # 5m bar features near signal (v2)
    "fmp_vwap_dist_pct",
    "fmp_above_vwap",
    "fmp_ema_spread",
    "fmp_ema9_above_ema21",
    "fmp_atr_pct",
    "fmp_rsi_14",
    # derived features — v2
    "abs_vwap_dist_pct",
    "abs_ema_spread",
    "alert_rsi_centered",
    "fmp_rsi_centered",
    "trend_alignment_1m",
    "trend_alignment_5m",
    "spread_1m_minus_5m",
    "rsi_1m_overbought",
    "rsi_1m_oversold",
    "rsi_1m_extreme",
    "rsi_5m_overbought",
    "rsi_5m_oversold",
    "vwap_extended",
    "vwap_very_extended",
    "vol_ratio_extreme",
    "vol_ratio_moderate",
    "atr_too_low",
    "atr_too_high",
    "atr_sweet_spot",
    "green_bars_high",
    "green_bars_balanced",
    "choppy",
    "clean_trend",
    "near_high",
    "off_highs",
    "overextension_score",
    "healthy_setup_score",
    # market-context features (v2)
    "mkt_day_pct",
    "mkt_5m_ema_trend",
    "mkt_5m_vwap_dist",
    "mkt_5m_rsi",
    "recent_losses_today",
    # derived market features (v2)
    "mkt_is_green",
    "mkt_is_strong",
    "mkt_is_weak",
    "mkt_trending_up",
    "rs_spread_vs_market",
    # time of day (v2)
    "minutes_since_open",
    # ---- V3 ADDITIONS: signal latency / "already ran" detection ----
    "alert_age_minutes",            # minutes from signal_ts_est to entry_ts_est (training) / NOW (scoring)
    "price_move_since_signal_pct",  # % price move from signal bar to entry bar
    "alert_stale",                  # 1 if alert_age_minutes > 5
    "alert_very_stale",             # 1 if alert_age_minutes > 8
    "abs_price_move_since_signal",  # |price_move_since_signal_pct|
    "stock_already_ran",            # 1 if price_move_since_signal_pct > 0.5%
    "stock_ran_hard",               # 1 if price_move_since_signal_pct > 1.5%
]


def load_parent_env() -> None:
    script_path = Path(__file__).resolve()
    env_path = script_path.parents[1] / ".env"
    if env_path.exists():
        load_dotenv(dotenv_path=env_path, override=False)


def get_db_config_from_env() -> DBConfig:
    load_parent_env()
    return DBConfig(
        host=os.environ.get("DB_HOST", "127.0.0.1"),
        port=int(os.environ.get("DB_PORT", "3306")),
        name=os.environ.get("DB_DATABASE", os.environ.get("DB_NAME", "trading")),
        user=os.environ.get("DB_USERNAME", os.environ.get("DB_USER", "root")),
        password=os.environ.get("DB_PASSWORD", os.environ.get("DB_PASS", "")),
    )


def make_engine(cfg: DBConfig):
    url = URL.create(
        "mysql+pymysql",
        username=cfg.user,
        password=cfg.password,
        host=cfg.host,
        port=cfg.port,
        database=cfg.name,
    )
    return create_engine(url, pool_pre_ping=True)


# -----------------------
# Data loading
# -----------------------
TRAIN_SQL_TEMPLATE = """
WITH ta_base AS (
    SELECT
        ta.id AS alert_id,
        ta.symbol,
        ta.asset_type,
        ta.trading_date_est,
        ta.as_of_ts_est,
        ta.signal_type,
        ta.signal_ts_est,
        ta.time_of_day,
        ta.entry_type,
        ta.entry_ts_est,
        ta.entry,
        ta.stop,
        ta.pnl_percent,
        ta.pnl_dollar,
        ta.r_multiple,
        ta.exit_reason,
        ta.hold_time_minutes,

        ta.score AS alert_score,
        ta.vol_ratio AS alert_vol_ratio,
        ta.atr AS alert_atr,
        ta.atr_pct AS alert_atr_pct,
        ta.daily_trend_5d_pct,
        ta.range_position_60m,
        ta.rsi_14_1m AS alert_rsi_14_1m_stored,
        ta.five_min_directional_changes,
        ta.five_min_green_bar_pct,
        ta.five_min_net_progress,
        ta.consolidation_bars,
        ta.breakout_volume_ratio,
        ta.pct_below_intraday_high,
        ta.minutes_since_high,
        ta.price_velocity_5min,
        ta.price_velocity_10min,
        ta.failed_rally_count,
        ta.pipeline_run,
        ta.version
    FROM {table_name} ta
    WHERE ta.entry_ts_est >= :start_dt
      AND ta.entry_ts_est <  :end_dt
      AND ta.pnl_percent IS NOT NULL
      {pipeline_filter}
),

actual_fills AS (
    SELECT
        alert_id,
        AVG(actual_buy_price)  AS actual_buy_price,
        AVG(actual_sell_price) AS actual_sell_price,
        AVG(actual_pnl_pct)    AS actual_pnl_pct,
        MIN(actual_filled_at)  AS actual_filled_at
    FROM (
        SELECT
            CAST(REGEXP_REPLACE(bo.notes, '^.*alert_id:([0-9]+).*$', '$1') AS UNSIGNED) AS alert_id,
            bo.filled_avg_price                                                           AS actual_buy_price,
            so.filled_avg_price                                                           AS actual_sell_price,
            (so.filled_avg_price - bo.filled_avg_price)
                / bo.filled_avg_price * 100                                               AS actual_pnl_pct,
            bo.filled_at                                                                  AS actual_filled_at
        FROM alpaca_orders bo
        JOIN alpaca_orders so
          ON so.parent_alpaca_order_id = bo.alpaca_order_id
        WHERE bo.side = 'buy'
          AND so.side = 'sell'
          AND bo.status IN ('filled', 'partially_filled')
          AND so.status IN ('filled', 'partially_filled')
          AND bo.filled_at IS NOT NULL
          AND so.filled_at IS NOT NULL
          AND bo.notes REGEXP 'alert_id:[0-9]+'
    ) x
    GROUP BY alert_id
),

-- Per-pipeline average fill age from actual Alpaca trades.
-- Used to impute a realistic execution delay for BT rows that never traded,
-- so the training distribution of alert_age_minutes matches live scoring.
fill_ages AS (
    SELECT
        t.pipeline_run,
        AVG(TIMESTAMPDIFF(SECOND, t.signal_ts_est, CONVERT_TZ(af.actual_filled_at, 'UTC', 'America/New_York')) / 60.0) AS avg_fill_age_minutes
    FROM ta_base t
    JOIN actual_fills af ON af.alert_id = t.alert_id
    WHERE af.actual_filled_at IS NOT NULL
    GROUP BY t.pipeline_run
),

bars AS (
    SELECT
        t.alert_id,
        omp.ts_est,
        omp.price
    FROM ta_base t
    JOIN one_minute_prices_full omp
      ON omp.symbol = t.symbol
     AND omp.asset_type = t.asset_type
     AND omp.ts_est BETWEEN (t.entry_ts_est - INTERVAL 30 MINUTE) AND t.entry_ts_est
),
deltas AS (
    SELECT
        alert_id,
        ts_est,
        price,
        price - LAG(price) OVER (PARTITION BY alert_id ORDER BY ts_est) AS delta,
        CASE
            WHEN LAG(price) OVER (PARTITION BY alert_id ORDER BY ts_est) IS NULL THEN NULL
            WHEN price - LAG(price) OVER (PARTITION BY alert_id ORDER BY ts_est) > 0
                THEN price - LAG(price) OVER (PARTITION BY alert_id ORDER BY ts_est)
            ELSE 0
        END AS gain,
        CASE
            WHEN LAG(price) OVER (PARTITION BY alert_id ORDER BY ts_est) IS NULL THEN NULL
            WHEN price - LAG(price) OVER (PARTITION BY alert_id ORDER BY ts_est) < 0
                THEN (LAG(price) OVER (PARTITION BY alert_id ORDER BY ts_est) - price)
            ELSE 0
        END AS loss
    FROM bars
),
rsi_calc AS (
    SELECT
        alert_id,
        ts_est,
        CASE
            WHEN cnt_gain < 14 OR cnt_loss < 14 THEN NULL
            WHEN avg_loss = 0 THEN 100
            ELSE 100 - (100 / (1 + (avg_gain / avg_loss)))
        END AS rsi_14_sql
    FROM (
        SELECT
            alert_id,
            ts_est,
            AVG(gain)   OVER (PARTITION BY alert_id ORDER BY ts_est ROWS BETWEEN 13 PRECEDING AND CURRENT ROW) AS avg_gain,
            AVG(loss)   OVER (PARTITION BY alert_id ORDER BY ts_est ROWS BETWEEN 13 PRECEDING AND CURRENT ROW) AS avg_loss,
            COUNT(gain) OVER (PARTITION BY alert_id ORDER BY ts_est ROWS BETWEEN 13 PRECEDING AND CURRENT ROW) AS cnt_gain,
            COUNT(loss) OVER (PARTITION BY alert_id ORDER BY ts_est ROWS BETWEEN 13 PRECEDING AND CURRENT ROW) AS cnt_loss
        FROM deltas
    ) x
)

SELECT
    t.alert_id,
    t.symbol,
    t.asset_type,
    t.trading_date_est,
    t.as_of_ts_est,
    t.signal_type,
    t.signal_ts_est,
    t.time_of_day,
    t.entry_type,
    t.entry_ts_est,
    t.entry,
    t.stop,

    -- outcome
    COALESCE(af.actual_pnl_pct, t.pnl_percent) AS pnl_percent,
    af.actual_pnl_pct,
    af.actual_buy_price,
    af.actual_sell_price,
    CASE WHEN af.alert_id IS NOT NULL THEN 1 ELSE 0 END AS has_actual_fill,
    t.pnl_percent AS bt_pnl_percent,
    t.pnl_dollar,
    t.r_multiple,
    t.exit_reason,
    t.hold_time_minutes,

    -- alert-side features
    t.alert_score,
    t.alert_vol_ratio,
    t.alert_atr,
    t.alert_atr_pct,
    t.daily_trend_5d_pct,
    t.range_position_60m,
    COALESCE(t.alert_rsi_14_1m_stored, r.rsi_14_sql) AS alert_rsi_14_1m,
    t.five_min_directional_changes,
    t.five_min_green_bar_pct,
    t.five_min_net_progress,
    t.consolidation_bars,
    t.breakout_volume_ratio,
    t.pct_below_intraday_high,
    t.minutes_since_high,
    t.price_velocity_5min,
    t.price_velocity_10min,
    t.failed_rally_count,
    t.pipeline_run,
    t.version,

    -- 1m features AT ENTRY
    omp.price AS omp_entry_price,
    omp.open  AS omp_open,
    omp.high  AS omp_high,
    omp.low   AS omp_low,
    omp.volume AS omp_volume,
    omp.vwap,
    omp.vwap_dist_pct,
    omp.above_vwap,
    omp.ema9,
    omp.ema21,
    omp.ema9_ema21_spread,
    omp.ema9_above_ema21,
    omp.atr AS omp_atr,
    omp.atr_pct AS omp_atr_pct,

    -- 5m context near signal
    fmp.price AS fmp_price,
    fmp.open  AS fmp_open,
    fmp.high  AS fmp_high,
    fmp.low   AS fmp_low,
    fmp.volume AS fmp_volume,
    fmp.vwap AS fmp_vwap,
    fmp.vwap_dist_pct AS fmp_vwap_dist_pct,
    fmp.above_vwap AS fmp_above_vwap,
    fmp.ema9 AS fmp_ema9,
    fmp.ema21 AS fmp_ema21,
    fmp.ema9_ema21_spread AS fmp_ema_spread,
    fmp.ema9_above_ema21 AS fmp_ema9_above_ema21,
    fmp.atr AS fmp_atr,
    fmp.atr_pct AS fmp_atr_pct,
    fmp.rsi_14 AS fmp_rsi_14,

    -- Market context: benchmark ({benchmark_symbol}) at signal time
    CASE
        WHEN mkt_open.open IS NOT NULL AND mkt_open.open > 0 AND mkt_fmp.price IS NOT NULL
        THEN (mkt_fmp.price - mkt_open.open) / mkt_open.open * 100
        ELSE NULL
    END                          AS mkt_day_pct,
    mkt_fmp.ema9_above_ema21     AS mkt_5m_ema_trend,
    mkt_fmp.vwap_dist_pct        AS mkt_5m_vwap_dist,
    mkt_fmp.rsi_14               AS mkt_5m_rsi,

    -- Stock intraday %
    CASE
        WHEN stk_open.open IS NOT NULL AND stk_open.open > 0 AND fmp.price IS NOT NULL
        THEN (fmp.price - stk_open.open) / stk_open.open * 100
        ELSE NULL
    END                          AS stock_intraday_pct,

    -- V3: signal latency features
    -- Actual fills: use real filled_at (true positive age, matches live scoring).
    -- BT rows: impute per-pipeline average from actual fills so BT distribution
    --          matches live reality; fall back to 5 min if no fills exist yet.
    CASE WHEN af.actual_filled_at IS NOT NULL
         THEN TIMESTAMPDIFF(SECOND, t.signal_ts_est, CONVERT_TZ(af.actual_filled_at, 'UTC', 'America/New_York')) / 60.0
         ELSE COALESCE(fa.avg_fill_age_minutes, 5.0)
    END AS alert_age_minutes,
    sig_bar.price AS signal_price,
    CASE
        WHEN sig_bar.price IS NOT NULL AND sig_bar.price > 0
        THEN (omp.price - sig_bar.price) / sig_bar.price * 100
        ELSE NULL
    END AS price_move_since_signal_pct

FROM ta_base t

JOIN one_minute_prices_full omp
  ON omp.symbol = t.symbol
 AND omp.asset_type = t.asset_type
 AND omp.ts_est = t.entry_ts_est

LEFT JOIN rsi_calc r
  ON r.alert_id = t.alert_id
 AND r.ts_est = t.entry_ts_est

LEFT JOIN actual_fills af
  ON af.alert_id = t.alert_id

LEFT JOIN fill_ages fa
  ON fa.pipeline_run = t.pipeline_run

LEFT JOIN five_minute_prices_full fmp
  ON fmp.symbol = t.symbol
 AND fmp.asset_type = t.asset_type
 AND fmp.ts_est = (
      SELECT MAX(f2.ts_est)
      FROM five_minute_prices_full f2
      WHERE f2.symbol = t.symbol
        AND f2.asset_type = t.asset_type
        AND f2.ts_est <= t.signal_ts_est
 )

LEFT JOIN five_minute_prices_full mkt_fmp
  ON mkt_fmp.symbol     = '{benchmark_symbol}'
 AND mkt_fmp.asset_type = 'stock'
 AND mkt_fmp.ts_est     = (
      SELECT MAX(ts_est)
      FROM five_minute_prices_full
      WHERE symbol     = '{benchmark_symbol}'
        AND asset_type = 'stock'
        AND ts_est    <= t.signal_ts_est
 )

LEFT JOIN five_minute_prices_full mkt_open
  ON mkt_open.symbol     = '{benchmark_symbol}'
 AND mkt_open.asset_type = 'stock'
 AND mkt_open.ts_est     = (
      SELECT MIN(ts_est)
      FROM five_minute_prices_full
      WHERE symbol     = '{benchmark_symbol}'
        AND asset_type = 'stock'
        AND DATE(ts_est) = t.trading_date_est
 )

LEFT JOIN five_minute_prices_full stk_open
  ON stk_open.symbol     = t.symbol
 AND stk_open.asset_type = t.asset_type
 AND stk_open.ts_est     = (
      SELECT MIN(ts_est)
      FROM five_minute_prices_full
      WHERE symbol     = t.symbol
        AND asset_type = t.asset_type
        AND DATE(ts_est) = t.trading_date_est
 )

-- V3: 1m bar at signal detection time.
-- Direct equality join — signal_ts_est is minute-aligned, avoids O(n*m) correlated subquery.
LEFT JOIN one_minute_prices_full sig_bar
  ON sig_bar.symbol     = t.symbol
 AND sig_bar.asset_type = t.asset_type
 AND sig_bar.ts_est     = t.signal_ts_est;
"""


# Candidates SQL for offline batch scoring (score subcommand).
# Uses live tables (one_minute_prices / five_minute_prices) — not _full.
# Includes signal_bar join so v3 latency features can be computed post-query.
CANDIDATES_SQL = """
SELECT
    ta.id AS alert_id,
    ta.symbol,
    ta.asset_type,
    ta.trading_date_est,
    ta.as_of_ts_est,
    ta.signal_type,
    ta.signal_ts_est,
    ta.time_of_day,
    ta.entry_type,
    ta.entry_ts_est,
    ta.entry,
    ta.stop,

    ta.score AS alert_score,
    ta.vol_ratio AS alert_vol_ratio,
    ta.atr AS alert_atr,
    ta.atr_pct AS alert_atr_pct,
    ta.daily_trend_5d_pct,
    ta.range_position_60m,
    ta.rsi_14_1m AS alert_rsi_14_1m,
    ta.five_min_directional_changes,
    ta.five_min_green_bar_pct,
    ta.five_min_net_progress,
    ta.consolidation_bars,
    ta.breakout_volume_ratio,
    ta.pct_below_intraday_high,
    ta.minutes_since_high,
    ta.price_velocity_5min,
    ta.price_velocity_10min,
    ta.failed_rally_count,
    ta.pipeline_run,
    ta.version,

    omp.price AS omp_entry_price,
    omp.open  AS omp_open,
    omp.high  AS omp_high,
    omp.low   AS omp_low,
    omp.volume AS omp_volume,
    omp.vwap,
    omp.vwap_dist_pct,
    omp.above_vwap,
    omp.ema9,
    omp.ema21,
    omp.ema9_ema21_spread,
    omp.ema9_above_ema21,
    omp.atr AS omp_atr,
    omp.atr_pct AS omp_atr_pct,

    fmp.price AS fmp_price,
    fmp.open  AS fmp_open,
    fmp.high  AS fmp_high,
    fmp.low   AS fmp_low,
    fmp.volume AS fmp_volume,
    fmp.vwap AS fmp_vwap,
    fmp.vwap_dist_pct AS fmp_vwap_dist_pct,
    fmp.above_vwap AS fmp_above_vwap,
    fmp.ema9 AS fmp_ema9,
    fmp.ema21 AS fmp_ema21,
    fmp.ema9_ema21_spread AS fmp_ema_spread,
    fmp.ema9_above_ema21 AS fmp_ema9_above_ema21,
    fmp.atr AS fmp_atr,
    fmp.atr_pct AS fmp_atr_pct,
    fmp.rsi_14 AS fmp_rsi_14,

    CASE
        WHEN mkt_open.open IS NOT NULL AND mkt_open.open > 0 AND mkt_fmp.price IS NOT NULL
        THEN (mkt_fmp.price - mkt_open.open) / mkt_open.open * 100
        ELSE NULL
    END                          AS mkt_day_pct,
    mkt_fmp.ema9_above_ema21     AS mkt_5m_ema_trend,
    mkt_fmp.vwap_dist_pct        AS mkt_5m_vwap_dist,
    mkt_fmp.rsi_14               AS mkt_5m_rsi,

    CASE
        WHEN stk_open.open IS NOT NULL AND stk_open.open > 0 AND fmp.price IS NOT NULL
        THEN (fmp.price - stk_open.open) / stk_open.open * 100
        ELSE NULL
    END                          AS stock_intraday_pct,

    -- V3: signal bar price for latency features
    sig_bar.price AS signal_price

FROM trade_alerts ta

JOIN one_minute_prices omp
  ON omp.symbol = ta.symbol
 AND omp.asset_type = ta.asset_type
 AND omp.ts_est = ta.entry_ts_est

LEFT JOIN five_minute_prices fmp
  ON fmp.symbol = ta.symbol
 AND fmp.asset_type = ta.asset_type
 AND fmp.ts_est = (
      SELECT MAX(f2.ts_est)
      FROM five_minute_prices f2
      WHERE f2.symbol = ta.symbol
        AND f2.asset_type = ta.asset_type
        AND f2.ts_est <= ta.signal_ts_est
 )

LEFT JOIN five_minute_prices mkt_fmp
  ON mkt_fmp.symbol     = '{benchmark_symbol}'
 AND mkt_fmp.asset_type = 'stock'
 AND mkt_fmp.ts_est     = (
      SELECT MAX(ts_est)
      FROM five_minute_prices
      WHERE symbol     = '{benchmark_symbol}'
        AND asset_type = 'stock'
        AND ts_est    <= ta.signal_ts_est
 )

LEFT JOIN five_minute_prices mkt_open
  ON mkt_open.symbol     = '{benchmark_symbol}'
 AND mkt_open.asset_type = 'stock'
 AND mkt_open.ts_est     = (
      SELECT MIN(ts_est)
      FROM five_minute_prices
      WHERE symbol     = '{benchmark_symbol}'
        AND asset_type = 'stock'
        AND DATE(ts_est) = DATE(ta.signal_ts_est)
 )

LEFT JOIN five_minute_prices stk_open
  ON stk_open.symbol     = ta.symbol
 AND stk_open.asset_type = ta.asset_type
 AND stk_open.ts_est     = (
      SELECT MIN(ts_est)
      FROM five_minute_prices
      WHERE symbol     = ta.symbol
        AND asset_type = ta.asset_type
        AND DATE(ts_est) = DATE(ta.signal_ts_est)
 )

LEFT JOIN one_minute_prices sig_bar
  ON sig_bar.symbol     = ta.symbol
 AND sig_bar.asset_type = ta.asset_type
 AND sig_bar.ts_est     = (
      SELECT MAX(ts_est)
      FROM one_minute_prices
      WHERE symbol     = ta.symbol
        AND asset_type = ta.asset_type
        AND ts_est    <= ta.signal_ts_est
 )

WHERE ta.as_of_ts_est = :as_of_ts_est
  AND ta.entry IS NOT NULL
"""


def _sanitize_pipeline_values(pipeline_run: str) -> List[str]:
    pipelines = []
    for p in pipeline_run.split(","):
        clean = p.strip().upper()
        if clean and clean.isalnum():
            pipelines.append(clean)
        else:
            raise SystemExit(
                f"Invalid pipeline value: {p!r}. Pipeline names must be alphanumeric (e.g. A, B, M, N)."
            )
    return pipelines


def load_candidates(engine, as_of_ts_est: str) -> pd.DataFrame:
    sql = CANDIDATES_SQL.format(benchmark_symbol=_get_benchmark_symbol())
    with engine.connect() as conn:
        df = pd.read_sql(text(sql), conn, params={"as_of_ts_est": as_of_ts_est})
    return df


def load_training_data(
    engine,
    start_dt: str,
    end_dt: str,
    table_name: str = "trade_alerts",
    pipeline_run: str = None,
) -> pd.DataFrame:
    pipeline_filter = ""
    if pipeline_run:
        pipelines = _sanitize_pipeline_values(pipeline_run)
        if len(pipelines) == 1:
            pipeline_filter = f"AND ta.pipeline_run = '{pipelines[0]}'"
        else:
            pipeline_list = "', '".join(pipelines)
            pipeline_filter = f"AND ta.pipeline_run IN ('{pipeline_list}')"

        if pipelines == ["K"]:
            pipeline_filter += " AND ta.risk_pct < 2.0"

    sql = TRAIN_SQL_TEMPLATE.format(
        table_name=table_name,
        pipeline_filter=pipeline_filter,
        benchmark_symbol=_get_benchmark_symbol(),
    )
    with engine.connect() as conn:
        df = pd.read_sql(
            text(sql),
            conn,
            params={"start_dt": start_dt, "end_dt": end_dt},
        )
    return df


# -----------------------
# Feature engineering
# -----------------------

def add_derived_features(df: pd.DataFrame) -> pd.DataFrame:
    d = df.copy()

    if "vwap_dist_pct" in d.columns:
        d["abs_vwap_dist_pct"] = d["vwap_dist_pct"].astype(float).abs()

    if "ema9_ema21_spread" in d.columns:
        d["abs_ema_spread"] = d["ema9_ema21_spread"].astype(float).abs()

    if "alert_rsi_14_1m" in d.columns:
        d["alert_rsi_centered"] = (d["alert_rsi_14_1m"].astype(float) - 50.0) / 50.0

    if "fmp_rsi_14" in d.columns:
        d["fmp_rsi_centered"] = (d["fmp_rsi_14"].astype(float) - 50.0) / 50.0

    if "above_vwap" in d.columns and "ema9_above_ema21" in d.columns:
        d["trend_alignment_1m"] = (
            d["above_vwap"].fillna(0).astype(float) * d["ema9_above_ema21"].fillna(0).astype(float)
        )

    if "fmp_above_vwap" in d.columns and "fmp_ema9_above_ema21" in d.columns:
        d["trend_alignment_5m"] = (
            d["fmp_above_vwap"].fillna(0).astype(float) * d["fmp_ema9_above_ema21"].fillna(0).astype(float)
        )

    if "ema9_ema21_spread" in d.columns and "fmp_ema_spread" in d.columns:
        d["spread_1m_minus_5m"] = d["ema9_ema21_spread"].astype(float) - d["fmp_ema_spread"].astype(float)

    # Over-extension detection (v2)
    if "alert_rsi_14_1m" in d.columns:
        d["rsi_1m_overbought"] = (d["alert_rsi_14_1m"].astype(float) > 70).astype(float)
        d["rsi_1m_oversold"]   = (d["alert_rsi_14_1m"].astype(float) < 30).astype(float)
        d["rsi_1m_extreme"]    = (
            (d["alert_rsi_14_1m"].astype(float) > 75) | (d["alert_rsi_14_1m"].astype(float) < 25)
        ).astype(float)

    if "fmp_rsi_14" in d.columns:
        d["rsi_5m_overbought"] = (d["fmp_rsi_14"].astype(float) > 70).astype(float)
        d["rsi_5m_oversold"]   = (d["fmp_rsi_14"].astype(float) < 30).astype(float)

    if "vwap_dist_pct" in d.columns:
        d["vwap_extended"]      = (d["vwap_dist_pct"].astype(float).abs() > 1.0).astype(float)
        d["vwap_very_extended"] = (d["vwap_dist_pct"].astype(float).abs() > 2.0).astype(float)

    if "alert_vol_ratio" in d.columns:
        d["vol_ratio_extreme"]  = (d["alert_vol_ratio"].astype(float) > 5.0).astype(float)
        d["vol_ratio_moderate"] = (
            (d["alert_vol_ratio"].astype(float) >= 2.0) & (d["alert_vol_ratio"].astype(float) <= 4.0)
        ).astype(float)

    if "alert_atr_pct" in d.columns:
        d["atr_too_low"]    = (d["alert_atr_pct"].astype(float) < 0.3).astype(float)
        d["atr_too_high"]   = (d["alert_atr_pct"].astype(float) > 2.0).astype(float)
        d["atr_sweet_spot"] = (
            (d["alert_atr_pct"].astype(float) >= 0.5) & (d["alert_atr_pct"].astype(float) <= 1.2)
        ).astype(float)

    if "five_min_green_bar_pct" in d.columns:
        d["green_bars_high"]     = (d["five_min_green_bar_pct"].astype(float) > 75).astype(float)
        d["green_bars_balanced"] = (
            (d["five_min_green_bar_pct"].astype(float) >= 50) & (d["five_min_green_bar_pct"].astype(float) <= 70)
        ).astype(float)

    if "five_min_directional_changes" in d.columns:
        d["choppy"]      = (d["five_min_directional_changes"].astype(float) > 6).astype(float)
        d["clean_trend"] = (d["five_min_directional_changes"].astype(float) <= 4).astype(float)

    if "pct_below_intraday_high" in d.columns:
        d["near_high"] = (d["pct_below_intraday_high"].astype(float) < 0.5).astype(float)
        d["off_highs"] = (d["pct_below_intraday_high"].astype(float) > 2.0).astype(float)

    warning_cols = [c for c in d.columns if c in [
        "rsi_1m_overbought", "rsi_5m_overbought", "vwap_extended",
        "vol_ratio_extreme", "green_bars_high", "near_high", "rsi_1m_extreme",
    ]]
    if warning_cols:
        d["overextension_score"] = d[warning_cols].sum(axis=1).astype(float)

    healthy_cols = [c for c in d.columns if c in [
        "vol_ratio_moderate", "atr_sweet_spot", "green_bars_balanced",
        "clean_trend", "off_highs",
    ]]
    if healthy_cols:
        d["healthy_setup_score"] = d[healthy_cols].sum(axis=1).astype(float)

    # Market context (v2)
    if "mkt_day_pct" in d.columns:
        mkt = d["mkt_day_pct"].astype(float)
        d["mkt_is_green"]  = (mkt > 0.0).astype(float)
        d["mkt_is_strong"] = (mkt > 0.5).astype(float)
        d["mkt_is_weak"]   = (mkt < -0.3).astype(float)
        if "mkt_5m_ema_trend" in d.columns:
            d["mkt_trending_up"] = (
                (d["mkt_5m_ema_trend"].fillna(0).astype(float) > 0) & (mkt > 0.0)
            ).astype(float)
        if "stock_intraday_pct" in d.columns:
            d["rs_spread_vs_market"] = d["stock_intraday_pct"].astype(float) - mkt

    if "entry_ts_est" in d.columns:
        ts = pd.to_datetime(d["entry_ts_est"])
        d["minutes_since_open"] = (ts.dt.hour - 9) * 60 + ts.dt.minute - 30
        d["minutes_since_open"] = d["minutes_since_open"].clip(lower=0).astype(float)

    # ---- V3: signal latency / "already ran" detection ----
    if "alert_age_minutes" in d.columns:
        age = d["alert_age_minutes"].astype(float)
        d["alert_stale"]      = (age > 5).astype(float)
        d["alert_very_stale"] = (age > 8).astype(float)

    if "price_move_since_signal_pct" in d.columns:
        mv = d["price_move_since_signal_pct"].astype(float)
        d["abs_price_move_since_signal"] = mv.abs()
        d["stock_already_ran"]           = (mv > 0.5).astype(float)
        d["stock_ran_hard"]              = (mv > 1.5).astype(float)

    return d


def add_recent_loss_streak(df: pd.DataFrame) -> pd.DataFrame:
    d = df.copy()
    if "entry_ts_est" not in d.columns or "pnl_percent" not in d.columns:
        d["recent_losses_today"] = 0.0
        return d

    d = d.sort_values("entry_ts_est").reset_index(drop=True)
    d["_trade_date"] = pd.to_datetime(d["entry_ts_est"]).dt.date
    d["_is_loss"]    = (d["pnl_percent"].astype(float) < 0.0).astype(int)

    d["recent_losses_today"] = (
        d.groupby("_trade_date")["_is_loss"]
         .transform(lambda x: x.shift(1, fill_value=0).cumsum())
    ).astype(float)

    d = d.drop(columns=["_trade_date", "_is_loss"])
    return d


def split_by_time(df: pd.DataFrame, test_size: float = 0.2) -> Tuple[pd.DataFrame, pd.DataFrame]:
    if "entry_ts_est" not in df.columns:
        raise KeyError(f"Missing 'entry_ts_est' column. Columns: {list(df.columns)}")

    d   = df.sort_values("entry_ts_est").reset_index(drop=True)
    n   = len(d)
    if test_size <= 0:
        return d.copy(), d.iloc[0:0].copy()
    cut = int(np.floor((1.0 - test_size) * n))
    return d.iloc[:cut].copy(), d.iloc[cut:].copy()


# -----------------------
# Training helpers
# -----------------------

def make_label(df: pd.DataFrame, win_threshold_pct: float) -> pd.Series:
    if "pnl_percent" not in df.columns:
        raise KeyError(f"Missing 'pnl_percent' column. Columns: {list(df.columns)}")

    if "has_actual_fill" in df.columns:
        n_actual     = int(df["has_actual_fill"].sum())
        n_bt         = len(df) - n_actual
        n_win_actual = int(
            ((df["has_actual_fill"] == 1) & (df["pnl_percent"].astype(float) >= float(win_threshold_pct))).sum()
        )
        n_win_bt = int(
            ((df["has_actual_fill"] == 0) & (df["pnl_percent"].astype(float) >= float(win_threshold_pct))).sum()
        )
        print(
            f"[labels] actual fills: {n_actual} ({n_win_actual} wins @ {win_threshold_pct}%)  |  "
            f"BT-simulated: {n_bt} ({n_win_bt} wins @ {win_threshold_pct}%)"
        )

    return (df["pnl_percent"].astype(float) >= float(win_threshold_pct)).astype(int)


def build_sample_weights(df: pd.DataFrame, actual_fill_weight: float) -> np.ndarray:
    if "has_actual_fill" in df.columns and actual_fill_weight != 1.0:
        weights  = np.where(df["has_actual_fill"].to_numpy() == 1, actual_fill_weight, 1.0)
        n_actual = int((df["has_actual_fill"] == 1).sum())
        print(f"[weights] Boosting {n_actual} actual-fill rows by {actual_fill_weight}x")
        return weights
    return np.ones(len(df))


def resolve_numeric_cols(df: pd.DataFrame) -> List[str]:
    """Return FEATURE_COLUMNS that are present in df and numeric."""
    return [c for c in FEATURE_COLUMNS if c in df.columns and pd.api.types.is_numeric_dtype(df[c])]


def build_model(numeric_features: List[str], use_baseline: bool = False) -> Pipeline:
    if use_baseline:
        numeric_transformer = Pipeline(steps=[
            ("imputer", SimpleImputer(strategy="median")),
            ("scaler", StandardScaler()),
        ])
    else:
        numeric_transformer = Pipeline(steps=[
            ("imputer", SimpleImputer(strategy="median")),
        ])

    pre = ColumnTransformer(
        transformers=[("num", numeric_transformer, numeric_features)],
        remainder="drop",
    )

    if use_baseline:
        clf = LogisticRegression(max_iter=2000, class_weight="balanced", n_jobs=None)
    else:
        clf = xgb.XGBClassifier(
            max_depth=4,
            learning_rate=0.05,
            n_estimators=200,
            subsample=0.8,
            colsample_bytree=0.8,
            reg_alpha=0.1,
            reg_lambda=1.0,
            random_state=42,
            eval_metric="logloss",
        )

    return Pipeline(steps=[("pre", pre), ("clf", clf)])


def precision_at_k(y_true: np.ndarray, y_prob: np.ndarray, k: int) -> float:
    k = int(min(k, len(y_true)))
    if k <= 0:
        return float("nan")
    idx = np.argsort(-y_prob)[:k]
    return float(np.mean(y_true[idx]))


def _print_subset_metrics(name: str, y_true: np.ndarray, y_prob: np.ndarray, top_k: int) -> None:
    print(f"\n  [{name}] rows={len(y_true)}  win_rate={float(np.mean(y_true)):.3f}")
    if len(np.unique(y_true)) > 1:
        print(f"  [{name}] AUC={roc_auc_score(y_true, y_prob):.4f}")
    print(f"  [{name}] Precision@{top_k}={precision_at_k(y_true, y_prob, top_k):.3f}")


def train_and_eval(
    df: pd.DataFrame,
    win_threshold_pct: float,
    top_k: int,
    baseline: bool,
    test_size: float = 0.2,
    actual_fill_weight: float = 1.0,
) -> Tuple[Pipeline, dict, pd.DataFrame]:
    df2 = add_derived_features(df)

    if "pnl_percent" not in df2.columns:
        raise KeyError(f"Expected pnl_percent but it is missing. Columns: {list(df2.columns)}")

    numeric_cols = resolve_numeric_cols(df2)
    print(f"[features] Using {len(numeric_cols)} explicit feature columns (v3).")

    # Print v3-specific coverage
    v3_features = ["alert_age_minutes", "price_move_since_signal_pct",
                   "alert_stale", "alert_very_stale", "stock_already_ran",
                   "stock_ran_hard", "abs_price_move_since_signal"]
    present_v3  = [f for f in v3_features if f in numeric_cols]
    missing_v3  = [f for f in v3_features if f not in numeric_cols]
    print(f"[v3 features] present={present_v3}")
    if missing_v3:
        print(f"[v3 features] missing (will be imputed as NaN): {missing_v3}")

    train_df, test_df = split_by_time(df2, test_size=test_size)

    y_train = make_label(train_df, win_threshold_pct).to_numpy()

    if len(np.unique(y_train)) < 2:
        raise SystemExit(
            f"Training set has only one class. "
            f"wins={int(np.sum(y_train == 1))}, losses={int(np.sum(y_train == 0))}. "
            "Use a wider date range, lower win threshold, or different pipeline filter."
        )

    X_train             = train_df[numeric_cols]
    sample_weight_train = build_sample_weights(train_df, actual_fill_weight)

    model = build_model(numeric_cols, use_baseline=baseline)

    if not baseline:
        num_negative     = int(np.sum(y_train == 0))
        num_positive     = int(np.sum(y_train == 1))
        scale_pos_weight = num_negative / num_positive if num_positive > 0 else 1.0
        print(f"\nClass distribution: {num_positive} wins, {num_negative} losses")
        print(f"Using scale_pos_weight={scale_pos_weight:.2f} for XGBoost")
        model.named_steps["clf"].set_params(scale_pos_weight=scale_pos_weight)

    model.fit(X_train, y_train, clf__sample_weight=sample_weight_train)

    if len(test_df) == 0:
        metrics = {
            "test_auc": float("nan"),
            "confusion_matrix": [],
            "classification_report": "No test split (test_size=0). Train-only model.",
            "precision_at_k": float("nan"),
            "win_rate_test": float("nan"),
            "rows_train": int(len(train_df)),
            "rows_test": 0,
            "features_used": numeric_cols,
        }
        return model, metrics, pd.DataFrame()

    y_test  = make_label(test_df, win_threshold_pct).to_numpy()
    X_test  = test_df[numeric_cols]
    p_test  = model.predict_proba(X_test)[:, 1]

    metrics = {
        "test_auc": roc_auc_score(y_test, p_test) if len(np.unique(y_test)) > 1 else float("nan"),
        "confusion_matrix": confusion_matrix(y_test, (p_test >= 0.5).astype(int)).tolist(),
        "classification_report": classification_report(y_test, (p_test >= 0.5).astype(int), digits=4),
        "precision_at_k": precision_at_k(y_test, p_test, top_k),
        "win_rate_test": float(np.mean(y_test)),
        "rows_train": int(len(train_df)),
        "rows_test": int(len(test_df)),
        "features_used": numeric_cols,
    }

    if "has_actual_fill" in test_df.columns:
        print("\n=== Subset Metrics ===")
        for subset_name, mask in {
            "actual_only": test_df["has_actual_fill"].to_numpy() == 1,
            "bt_only": test_df["has_actual_fill"].to_numpy() == 0,
        }.items():
            if int(mask.sum()) > 20:
                _print_subset_metrics(subset_name, y_test[mask], p_test[mask], top_k)
            else:
                print(f"\n  [{subset_name}] only {int(mask.sum())} rows — skipping subset metrics")

    scored = test_df.copy()
    scored["win_prob"] = p_test
    scored["is_win"]   = (scored["pnl_percent"].astype(float) >= float(win_threshold_pct)).astype(int)

    show_cols = [c for c in [
        "symbol", "signal_type", "entry_type", "time_of_day",
        "entry_ts_est", "entry", "stop",
        "pnl_percent", "r_multiple", "win_prob", "is_win",
        "pipeline_run", "version",
        "alert_age_minutes", "price_move_since_signal_pct",
    ] if c in scored.columns]

    topk_df = (
        scored.sort_values("win_prob", ascending=False)
              .head(int(top_k))
              .loc[:, show_cols]
              .reset_index(drop=True)
    )

    return model, metrics, topk_df


def train_full_model(
    df: pd.DataFrame,
    win_threshold_pct: float,
    baseline: bool,
    numeric_cols: List[str],
    actual_fill_weight: float = 1.0,
) -> Pipeline:
    y             = make_label(df, win_threshold_pct).to_numpy()
    X             = df[numeric_cols]
    sample_weight = build_sample_weights(df, actual_fill_weight)

    if len(np.unique(y)) < 2:
        raise SystemExit(
            f"Full-training set has only one class. "
            f"wins={int(np.sum(y == 1))}, losses={int(np.sum(y == 0))}."
        )

    model = build_model(numeric_cols, use_baseline=baseline)

    if not baseline:
        num_negative     = int(np.sum(y == 0))
        num_positive     = int(np.sum(y == 1))
        scale_pos_weight = num_negative / num_positive if num_positive > 0 else 1.0
        print(f"\n[TRAIN FULL] Class distribution: {num_positive} wins, {num_negative} losses")
        print(f"[TRAIN FULL] Using scale_pos_weight={scale_pos_weight:.2f}")
        model.named_steps["clf"].set_params(scale_pos_weight=scale_pos_weight)

    model.fit(X, y, clf__sample_weight=sample_weight)
    return model


def score_candidates(model: Pipeline, candidates: pd.DataFrame, top_n: int, feature_columns: List[str] = None) -> pd.DataFrame:
    d = add_derived_features(candidates)

    if feature_columns:
        numeric_cols = feature_columns
    else:
        numeric_cols = model.named_steps["pre"].transformers_[0][2]

    X     = d.reindex(columns=numeric_cols)
    proba = model.predict_proba(X)[:, 1]

    out          = candidates.copy()
    out["win_prob"] = proba
    out = out.sort_values("win_prob", ascending=False).head(int(top_n)).reset_index(drop=True)
    return out


# -----------------------
# CLI
# -----------------------

def cmd_train(args):
    if getattr(args, "no_test_split", False):
        args.test_size = 0.0

    if not (0.0 <= args.test_size < 1.0):
        raise SystemExit("--test-size must be >= 0 and < 1 (e.g. 0.2 for 20% holdout)")

    cfg    = get_db_config_from_env()
    engine = make_engine(cfg)

    df = load_training_data(engine, args.start, args.end, args.table, args.pipeline)
    df = add_recent_loss_streak(df)

    if args.debug:
        print("Loaded rows:", len(df))
        print("Columns:", list(df.columns))
        if args.pipeline:
            pipelines = _sanitize_pipeline_values(args.pipeline)
            print(f"Filtered to pipeline(s): {', '.join(pipelines)}")

    if df.empty:
        raise SystemExit("No rows returned. Check date range and one_minute_prices coverage.")

    if "pnl_percent" not in df.columns:
        raise SystemExit(f"Expected 'pnl_percent' in training data. Columns: {list(df.columns)}")

    actual_fill_weight = getattr(args, "actual_fill_weight", 1.0)
    print(f"[config] actual_fill_weight={actual_fill_weight}x for rows with real Alpaca fills")

    model, metrics, topk_df = train_and_eval(
        df=df,
        win_threshold_pct=args.win_threshold,
        top_k=args.top_k,
        baseline=args.baseline,
        test_size=args.test_size,
        actual_fill_weight=actual_fill_weight,
    )

    if args.train_full:
        df2          = add_derived_features(df)
        numeric_cols = metrics["features_used"]
        model        = train_full_model(
            df=df2,
            win_threshold_pct=args.win_threshold,
            baseline=args.baseline,
            numeric_cols=numeric_cols,
            actual_fill_weight=actual_fill_weight,
        )
        print("\nTrained FINAL model on 100% of data (after evaluation).")

    os.makedirs(os.path.dirname(args.model_out) or ".", exist_ok=True)
    payload = {
        "model": model,
        "meta": {
            "trainer": "train_stock_winner_model_v3",
            "win_threshold_pct": args.win_threshold,
            "top_k_eval": args.top_k,
            "baseline": args.baseline,
            "table_name": args.table,
            "test_size": args.test_size,
            "trained_full": bool(args.train_full),
            "metrics": metrics,
            "feature_columns": metrics["features_used"],
        },
    }
    joblib.dump(payload, args.model_out)
    print("Saved model to:", args.model_out)

    print("\n=== Metrics (evaluation split) ===")
    print("Rows train:", metrics["rows_train"], " Rows test:", metrics["rows_test"])
    print("Test AUC:", metrics["test_auc"])
    print(f"Precision@{args.top_k}:", metrics["precision_at_k"])
    print("Test win rate:", metrics["win_rate_test"])

    if metrics["confusion_matrix"]:
        print("\nConfusion matrix [ [TN, FP], [FN, TP] ]:", metrics["confusion_matrix"])
        print("\nClassification report:\n", metrics["classification_report"])
        if not topk_df.empty:
            print(f"\n=== TOP {args.top_k} TEST PICKS (by win_prob) ===")
            print(topk_df.to_string(index=False))
    else:
        print("\n(No test split; test_size=0)")

    if args.train_full:
        print("\n=== Saved model is FULL-DATA model ===")
        if args.test_size > 0:
            print("Evaluation metrics above are from the holdout split.")


def cmd_score(args):
    payload          = joblib.load(args.model_in)
    model            = payload["model"]
    feature_columns  = payload.get("meta", {}).get("feature_columns")

    cfg    = get_db_config_from_env()
    engine = make_engine(cfg)

    cand = load_candidates(engine, args.as_of_ts_est)
    if cand.empty:
        raise SystemExit(
            "No candidates returned. Check:\n"
            "- You passed an EST timestamp matching trade_alerts.as_of_ts_est\n"
            "- trade_alerts has rows for that minute with entry IS NOT NULL\n"
            "- one_minute_prices has matching ts_est for those entry_ts_est values\n"
        )

    ranked = score_candidates(model, cand, top_n=args.top_n, feature_columns=feature_columns)
    print(ranked.to_string(index=False))


def main():
    ap  = argparse.ArgumentParser()
    sub = ap.add_subparsers(dest="cmd", required=True)

    ap_train = sub.add_parser("train", help="Train a v3 winner model (includes signal-latency features)")
    ap_train.add_argument("--start",    required=True)
    ap_train.add_argument("--end",      required=True)
    ap_train.add_argument("--table",    default="trade_alerts",
                          choices=["trade_alerts", "trade_alerts_unfiltered"])
    ap_train.add_argument("--pipeline", default=None)
    ap_train.add_argument("--win-threshold",    type=float, default=1.0)
    ap_train.add_argument("--top-k",            type=int,   default=10)
    ap_train.add_argument("--baseline",         action="store_true")
    ap_train.add_argument("--test-size",        type=float, default=0.2)
    ap_train.add_argument("--train-full",       action="store_true")
    ap_train.add_argument("--no-test-split",    dest="no_test_split", action="store_true")
    ap_train.add_argument("--model-out",        default="models/winner_model_v3.joblib")
    ap_train.add_argument("--actual-fill-weight", type=float, default=1.0)
    ap_train.add_argument("--debug",            action="store_true")
    ap_train.set_defaults(func=cmd_train)

    ap_score = sub.add_parser("score", help="Score trade alerts at a specific EST minute timestamp")
    ap_score.add_argument("--model-in",     required=True)
    ap_score.add_argument("--as-of-ts-est", dest="as_of_ts_est", required=True,
                          help="EST datetime (YYYY-MM-DD HH:MM:SS) matching trade_alerts.as_of_ts_est")
    ap_score.add_argument("--top-n",        type=int, default=10)
    ap_score.set_defaults(func=cmd_score)

    args = ap.parse_args()
    args.func(args)


if __name__ == "__main__":
    main()
