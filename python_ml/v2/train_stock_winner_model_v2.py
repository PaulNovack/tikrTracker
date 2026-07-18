#!/usr/bin/env python3
"""
train_stock_winner_model_v2.py — Improved version of train_stock_winner_model.py

Key changes from v1:
  1. Live scoring now queries trade_alerts + omp + fmp joins, matching training features
     exactly (fixes the biggest calibration bug: training on alert features but scoring
     raw one_minute_prices rows).
  2. Explicit FEATURE_COLUMNS list replaces the broad "every numeric column" approach.
     Prevents accidental feature leakage if new columns are added to the SQL.
  3. actual_fills CTE deduplicates to one row per alert_id via GROUP BY, preventing
     duplicate training rows when an alert has multiple matched sell orders.
  4. Separate evaluation metrics for actual-fill rows vs BT-only rows.
  5. SQLAlchemy URL.create() for DB credentials (safe with special characters in passwords).
  6. Removed use_label_encoder=False from XGBClassifier (produces warnings in recent xgb).
  7. Fixed --actual-fill-weight help text (was "default: 10x" but default value is 1.0).
  8. Pipeline filter sanitized with isalnum() check to prevent accidental SQL injection.
  9. Validates test_size is in [0, 1).
 10. Validates training set has both classes before fitting.
 11. Feature list saved in model metadata; score_candidates reads it from there.
"""

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
from sklearn.metrics import (
    roc_auc_score,
    classification_report,
    confusion_matrix,
)
from sklearn.pipeline import Pipeline
from sklearn.compose import ColumnTransformer
from sklearn.impute import SimpleImputer
from sklearn.preprocessing import StandardScaler, OneHotEncoder
from sklearn.linear_model import LogisticRegression
import xgboost as xgb
import joblib
from sklearn.calibration import CalibratedClassifierCV


# -----------------------
# Config
# -----------------------

# Benchmark symbol used for market-context features.
# Reads from .env (TRADING_MARKET_BENCHMARK_SYMBOL); falls back to QQQ.
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
# If you add new SQL columns, deliberately add them here instead of
# silently including them via "every numeric column not in drop_cols".
FEATURE_COLUMNS = [
    # alert-side features
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
    # 1m bar features at entry
    "vwap_dist_pct",
    "above_vwap",
    "ema9_ema21_spread",
    "ema9_above_ema21",
    "omp_atr_pct",
    # 5m bar features near signal
    "fmp_vwap_dist_pct",
    "fmp_above_vwap",
    "fmp_ema_spread",
    "fmp_ema9_above_ema21",
    "fmp_atr_pct",
    "fmp_rsi_14",
    # derived features (added by add_derived_features)
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
    # market-context features (benchmark at signal time)
    "mkt_day_pct",
    "mkt_5m_ema_trend",
    "mkt_5m_vwap_dist",
    "mkt_5m_rsi",
    # NOTE: recent_losses_today removed from FEATURE_COLUMNS — it is computed
    # from pnl_percent (the outcome), creating a train/score mismatch.
    # At scoring time it defaults to 0, so training on it teaches the model
    # a pattern it can never see live. Retained as available column for
    # future use if scoring infra passes actual streak count.
    # derived market features
    "mkt_is_green",
    "mkt_is_strong",
    "mkt_is_weak",
    "mkt_trending_up",
    # relative strength vs market
    "rs_spread_vs_market",
    # time of day
    "minutes_since_open",
]

# Categorical features — signal_type, entry_type, time_of_day, and pipeline_run
# encode very different base win odds that numeric features alone can't capture.
CATEGORICAL_FEATURES = [
    "signal_type",
    "entry_type",
    "time_of_day",
    "pipeline_run",
]


def load_parent_env() -> None:
    script_path = Path(__file__).resolve()
    env_path = script_path.parents[2] / ".env"
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
    """Build SQLAlchemy engine using URL.create() — safe with special characters in passwords."""
    url = URL.create(
        "mysql+pymysql",
        username=cfg.user,
        password=cfg.password,
        host=cfg.host,
        port=cfg.port,
        database=cfg.name,
    )
    return create_engine(url, pool_pre_ping=True, connect_args={
        'read_timeout': None,
        'write_timeout': None,
        'init_command': (
            "SET SESSION net_read_timeout = 28800, "
            "SESSION net_write_timeout = 28800, "
            "SESSION wait_timeout = 86400"
        ),
    })


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
      {actual_fills_filter}
      {pipeline_filter}
    ORDER BY ta.entry_ts_est DESC
    LIMIT {row_limit}
),

-- Actual Alpaca fills matched by alert_id stored in buy order notes.
-- Deduplicated to ONE row per alert_id to prevent duplicate training rows
-- when an alert has multiple matched sell orders (partial fills, stop replacements, etc.)
actual_fills AS (
    SELECT
        alert_id,
        AVG(actual_buy_price)  AS actual_buy_price,
        AVG(actual_sell_price) AS actual_sell_price,
        AVG(actual_pnl_pct)    AS actual_pnl_pct
    FROM (
        SELECT
            CAST(REGEXP_REPLACE(bo.notes, '^.*alert_id:([0-9]+).*$', '$1') AS UNSIGNED) AS alert_id,
            bo.filled_avg_price                                                           AS actual_buy_price,
            so.filled_avg_price                                                           AS actual_sell_price,
            (so.filled_avg_price - bo.filled_avg_price)
                / bo.filled_avg_price * 100                                               AS actual_pnl_pct
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

    -- outcome: prefer actual Alpaca fills, fall back to BT-simulated
    COALESCE(af.actual_pnl_pct, t.pnl_percent) AS pnl_percent,
    af.actual_pnl_pct                           AS actual_pnl_pct,
    af.actual_buy_price,
    af.actual_sell_price,
    CASE WHEN af.alert_id IS NOT NULL THEN 1 ELSE 0 END AS has_actual_fill,
    t.pnl_percent                               AS bt_pnl_percent,
    t.pnl_dollar,
    t.r_multiple,
    t.exit_reason,
    t.hold_time_minutes,

    -- stored alert-side features
    t.alert_score,
    t.alert_vol_ratio,
    t.alert_atr,
    t.alert_atr_pct,
    t.daily_trend_5d_pct,
    t.range_position_60m,
    t.alert_rsi_14_1m_stored AS alert_rsi_14_1m,
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

    -- 1-minute features AT ENTRY
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

    -- 5-minute context near signal time
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

    -- Stock intraday % (for RS spread vs market)
    CASE
        WHEN stk_open.open IS NOT NULL AND stk_open.open > 0 AND fmp.price IS NOT NULL
        THEN (fmp.price - stk_open.open) / stk_open.open * 100
        ELSE NULL
    END                          AS stock_intraday_pct

FROM ta_base t

JOIN one_minute_prices_full omp
    ON omp.symbol = t.symbol
 AND omp.asset_type = t.asset_type
 AND omp.ts_est = (
            SELECT MAX(omp2.ts_est)
            FROM one_minute_prices_full omp2
            WHERE omp2.symbol = t.symbol
                AND omp2.asset_type = t.asset_type
                AND omp2.ts_est <= t.entry_ts_est
 )

LEFT JOIN actual_fills af
  ON af.alert_id = t.alert_id

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

-- Market context: benchmark most-recent 5m bar at or before signal time
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

-- Market context: benchmark first bar of the day (for day % calculation)
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

-- Stock first bar of the day (for stock intraday % calculation)
LEFT JOIN five_minute_prices_full stk_open
  ON stk_open.symbol     = t.symbol
 AND stk_open.asset_type = t.asset_type
 AND stk_open.ts_est     = (
      SELECT MIN(ts_est)
      FROM five_minute_prices_full
      WHERE symbol     = t.symbol
        AND asset_type = t.asset_type
        AND DATE(ts_est) = t.trading_date_est
 );
"""


def _sanitize_pipeline_values(pipeline_run: str) -> List[str]:
    """
    Sanitize pipeline filter values. Only alphanumeric values are allowed,
    preventing accidental SQL injection even though this is a CLI tool.
    """
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


def load_training_data(
    engine,
    start_dt: str,
    end_dt: str,
    table_name: str = "trade_alerts",
    pipeline_run: str = None,
    limit: int = 0,
    actual_fills_only: bool = False,
) -> pd.DataFrame:
    pipeline_filter = ""
    actual_fills_filter = ""
    if actual_fills_only:
        actual_fills_filter = (
            "AND EXISTS (\n"
            "    SELECT 1 FROM alpaca_orders bo\n"
            "    WHERE bo.side = 'buy'\n"
            "      AND bo.status IN ('filled', 'partially_filled')\n"
            "      AND bo.filled_at IS NOT NULL\n"
            "      AND bo.notes REGEXP CONCAT('alert_id:', ta.id, '(\\\\D|$)')\n"
            ")\n"
        )
    if pipeline_run:
        pipelines = _sanitize_pipeline_values(pipeline_run)
        if len(pipelines) == 1:
            pipeline_filter = f"AND ta.pipeline_run = '{pipelines[0]}'"
        else:
            pipeline_list = "', '".join(pipelines)
            pipeline_filter = f"AND ta.pipeline_run IN ('{pipeline_list}')"

        # Pipeline K execution filter: exclude risk_pct >= 2.0% trades from training.
        # These are skipped at order placement time, so training on them pollutes the model
        # with a population it will never actually trade.
        if pipelines == ["K"]:
            pipeline_filter += " AND ta.risk_pct < 2.0"

    row_limit = int(limit) if limit and int(limit) > 0 else 1000000000
    sql = TRAIN_SQL_TEMPLATE.format(
        table_name=table_name,
        pipeline_filter=pipeline_filter,
        actual_fills_filter=actual_fills_filter,
        benchmark_symbol=_get_benchmark_symbol(),
        row_limit=row_limit,
    )
    with engine.connect() as conn:
        df = pd.read_sql(
            text(sql),
            conn,
            params={"start_dt": start_dt, "end_dt": end_dt},
        )
    return df


# -----------------------
# Live-scoring candidates SQL
# -----------------------
# Scores recent trade_alerts (not raw one_minute_prices) so the live feature
# distribution matches training. Requires an as_of_ts_est that matches
# trade_alerts.as_of_ts_est (the minute the alert was generated).
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

    -- Market context: benchmark at signal time (symbol resolved at load time via Python format)
    CASE
        WHEN mkt_open.open IS NOT NULL AND mkt_open.open > 0 AND mkt_fmp.price IS NOT NULL
        THEN (mkt_fmp.price - mkt_open.open) / mkt_open.open * 100
        ELSE NULL
    END                          AS mkt_day_pct,
    mkt_fmp.ema9_above_ema21     AS mkt_5m_ema_trend,
    mkt_fmp.vwap_dist_pct        AS mkt_5m_vwap_dist,
    mkt_fmp.rsi_14               AS mkt_5m_rsi,

    -- Stock intraday % (for RS spread vs market)
    CASE
        WHEN stk_open.open IS NOT NULL AND stk_open.open > 0 AND fmp.price IS NOT NULL
        THEN (fmp.price - stk_open.open) / stk_open.open * 100
        ELSE NULL
    END                          AS stock_intraday_pct

FROM trade_alerts ta

JOIN one_minute_prices_full omp
  ON omp.symbol = ta.symbol
 AND omp.asset_type = ta.asset_type
 AND omp.ts_est = (
      SELECT MAX(omp2.ts_est)
      FROM one_minute_prices_full omp2
      WHERE omp2.symbol = ta.symbol
        AND omp2.asset_type = ta.asset_type
        AND omp2.ts_est <= ta.entry_ts_est
 )

LEFT JOIN five_minute_prices_full fmp
  ON fmp.symbol = ta.symbol
 AND fmp.asset_type = ta.asset_type
 AND fmp.ts_est = (
      SELECT MAX(f2.ts_est)
      FROM five_minute_prices_full f2
      WHERE f2.symbol = ta.symbol
        AND f2.asset_type = ta.asset_type
        AND f2.ts_est <= ta.signal_ts_est
 )

-- Market context: benchmark most-recent 5m bar at or before signal time
LEFT JOIN five_minute_prices_full mkt_fmp
  ON mkt_fmp.symbol     = '{benchmark_symbol}'
 AND mkt_fmp.asset_type = 'stock'
 AND mkt_fmp.ts_est     = (
      SELECT MAX(ts_est)
      FROM five_minute_prices_full
      WHERE symbol     = '{benchmark_symbol}'
        AND asset_type = 'stock'
        AND ts_est    <= ta.signal_ts_est
 )

-- Market context: benchmark first bar of the day
LEFT JOIN five_minute_prices_full mkt_open
  ON mkt_open.symbol     = '{benchmark_symbol}'
 AND mkt_open.asset_type = 'stock'
 AND mkt_open.ts_est     = (
      SELECT MIN(ts_est)
      FROM five_minute_prices_full
      WHERE symbol     = '{benchmark_symbol}'
        AND asset_type = 'stock'
        AND DATE(ts_est) = DATE(ta.signal_ts_est)
 )

-- Stock first bar of the day (for stock intraday % calculation)
LEFT JOIN five_minute_prices_full stk_open
  ON stk_open.symbol     = ta.symbol
 AND stk_open.asset_type = ta.asset_type
 AND stk_open.ts_est     = (
      SELECT MIN(ts_est)
      FROM five_minute_prices_full
      WHERE symbol     = ta.symbol
        AND asset_type = ta.asset_type
        AND DATE(ts_est) = DATE(ta.signal_ts_est)
 )

WHERE ta.as_of_ts_est = :as_of_ts_est
  AND ta.entry IS NOT NULL
  {pipeline_filter}
"""


def load_candidates(engine, as_of_ts_est: str, pipeline_run: str = None) -> pd.DataFrame:
    pipeline_filter = ""
    if pipeline_run:
        pipelines = _sanitize_pipeline_values(pipeline_run)
        if len(pipelines) == 1:
            pipeline_filter = f"AND ta.pipeline_run = '{pipelines[0]}'"
        else:
            pipeline_list = "', '".join(pipelines)
            pipeline_filter = f"AND ta.pipeline_run IN ('{pipeline_list}')"
    sql = CANDIDATES_SQL.format(benchmark_symbol=_get_benchmark_symbol(),
                                pipeline_filter=pipeline_filter)
    with engine.connect() as conn:
        df = pd.read_sql(text(sql), conn, params={"as_of_ts_est": as_of_ts_est})
    return df


# -----------------------
# Feature engineering
# -----------------------

def add_derived_features(df: pd.DataFrame) -> pd.DataFrame:
    d = df.copy()

    def _to_float(series: pd.Series, default: float = 0.0) -> pd.Series:
        return pd.to_numeric(series, errors="coerce").fillna(default).astype(float)

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
            _to_float(d["above_vwap"]) * _to_float(d["ema9_above_ema21"])
        )

    if "fmp_above_vwap" in d.columns and "fmp_ema9_above_ema21" in d.columns:
        d["trend_alignment_5m"] = (
            _to_float(d["fmp_above_vwap"]) * _to_float(d["fmp_ema9_above_ema21"])
        )

    if "ema9_ema21_spread" in d.columns and "fmp_ema_spread" in d.columns:
        d["spread_1m_minus_5m"] = d["ema9_ema21_spread"].astype(float) - d["fmp_ema_spread"].astype(float)

    # Over-extension detection
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

    # Market context derived features
    if "mkt_day_pct" in d.columns:
        mkt = _to_float(d["mkt_day_pct"])
        d["mkt_is_green"]  = (mkt > 0.0).astype(float)
        d["mkt_is_strong"] = (mkt > 0.5).astype(float)
        d["mkt_is_weak"]   = (mkt < -0.3).astype(float)
        if "mkt_5m_ema_trend" in d.columns:
            d["mkt_trending_up"] = (
                (_to_float(d["mkt_5m_ema_trend"]) > 0) & (mkt > 0.0)
            ).astype(float)
        if "stock_intraday_pct" in d.columns:
            d["rs_spread_vs_market"] = d["stock_intraday_pct"].astype(float) - mkt

    # Minutes since market open (9:30 EST)
    if "entry_ts_est" in d.columns:
        ts = pd.to_datetime(d["entry_ts_est"])
        d["minutes_since_open"] = (ts.dt.hour - 9) * 60 + ts.dt.minute - 30
        d["minutes_since_open"] = d["minutes_since_open"].clip(lower=0).astype(float)

    return d


def add_recent_loss_streak(df: pd.DataFrame) -> pd.DataFrame:
    """
    For each trade row, compute how many losing trades occurred earlier that same day.
    Requires columns: entry_ts_est, pnl_percent.
    Safe to call before/after add_derived_features.
    """
    d = df.copy()
    if "entry_ts_est" not in d.columns or "pnl_percent" not in d.columns:
        d["recent_losses_today"] = 0.0
        return d

    d = d.sort_values("entry_ts_est").reset_index(drop=True)
    d["_trade_date"] = pd.to_datetime(d["entry_ts_est"]).dt.date
    d["_is_loss"]    = (d["pnl_percent"].astype(float) < 0.0).astype(int)

    # cumulative losses on the same day, EXCLUDING the current row (shift by 1)
    d["recent_losses_today"] = (
        d.groupby("_trade_date")["_is_loss"]
         .transform(lambda x: x.shift(1, fill_value=0).cumsum())
    ).astype(float)

    d = d.drop(columns=["_trade_date", "_is_loss"])
    return d


def split_by_time(df: pd.DataFrame, test_size: float = 0.2) -> Tuple[pd.DataFrame, pd.DataFrame]:
    """Time-aware split: sort by entry_ts_est and take the last X% as test."""
    if "entry_ts_est" not in df.columns:
        raise KeyError(f"Missing 'entry_ts_est' column. Columns: {list(df.columns)}")

    d = df.sort_values("entry_ts_est").reset_index(drop=True)
    n = len(d)
    if test_size <= 0:
        return d.copy(), d.iloc[0:0].copy()
    cut = int(np.floor((1.0 - test_size) * n))
    return d.iloc[:cut].copy(), d.iloc[cut:].copy()


def split_by_trading_day(df: pd.DataFrame, test_size: float = 0.2) -> Tuple[pd.DataFrame, pd.DataFrame]:
    """Day-aware split: hold out the last X% of trading dates as test."""
    if "trading_date_est" not in df.columns:
        raise KeyError(f"Missing 'trading_date_est' column. Columns: {list(df.columns)}")

    d = df.copy()
    d["_trade_date"] = pd.to_datetime(d["trading_date_est"], errors="coerce").dt.date
    d = d.dropna(subset=["_trade_date"])

    unique_dates = sorted(d["_trade_date"].unique())
    if test_size <= 0:
        return d.sort_values("entry_ts_est").drop(columns=["_trade_date"], errors="ignore").copy(), d.iloc[0:0].copy()

    if len(unique_dates) <= 1:
        print("[split] Only one trading day available; falling back to time-based holdout.")
        return split_by_time(df, test_size=test_size)

    cut = int(np.floor((1.0 - test_size) * len(unique_dates)))
    cut = min(max(cut, 1), len(unique_dates) - 1)

    train_dates = set(unique_dates[:cut])
    test_dates = set(unique_dates[cut:])

    train_df = d[d["_trade_date"].isin(train_dates)].drop(columns=["_trade_date"]).sort_values("entry_ts_est").reset_index(drop=True)
    test_df = d[d["_trade_date"].isin(test_dates)].drop(columns=["_trade_date"]).sort_values("entry_ts_est").reset_index(drop=True)
    return train_df, test_df


def keep_most_recent_rows(df: pd.DataFrame, limit: int) -> pd.DataFrame:
    if limit is None or int(limit) <= 0 or len(df) <= int(limit):
        return df.copy()

    if "entry_ts_est" not in df.columns:
        raise KeyError(f"Missing 'entry_ts_est' column. Columns: {list(df.columns)}")

    d = df.copy()
    d["_entry_ts_sort"] = pd.to_datetime(d["entry_ts_est"], errors="coerce")
    d = d.sort_values("_entry_ts_sort", ascending=False).head(int(limit))
    d = d.sort_values("_entry_ts_sort").drop(columns=["_entry_ts_sort"]).reset_index(drop=True)

    print(f"[limit] Keeping most recent {int(limit)} rows out of {len(df)} filtered rows.")
    return d


# -----------------------
# Training helpers
# -----------------------

def make_label(df: pd.DataFrame, win_threshold_pct: float) -> pd.Series:
    if "pnl_percent" not in df.columns:
        raise KeyError(f"Missing 'pnl_percent' column. Columns: {list(df.columns)}")

    if "has_actual_fill" in df.columns:
        n_actual   = int(df["has_actual_fill"].sum())
        n_bt       = len(df) - n_actual
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
    """Returns a sample weight array: actual_fill_weight for rows with real fills, 1.0 otherwise."""
    if "has_actual_fill" in df.columns and actual_fill_weight != 1.0:
        weights  = np.where(df["has_actual_fill"].to_numpy() == 1, actual_fill_weight, 1.0)
        n_actual = int((df["has_actual_fill"] == 1).sum())
        print(f"[weights] Boosting {n_actual} actual-fill rows by {actual_fill_weight}x")
        return weights
    return np.ones(len(df))


def resolve_numeric_cols(df: pd.DataFrame) -> List[str]:
    """
    Return FEATURE_COLUMNS that are present in df and numeric.
    Using an explicit whitelist prevents accidental leakage if new SQL columns appear.
    """
    return [
        c for c in FEATURE_COLUMNS
        if c in df.columns
        and pd.api.types.is_numeric_dtype(df[c])
        and df[c].notna().any()
    ]


def coerce_feature_columns(df: pd.DataFrame) -> pd.DataFrame:
    """
    MySQL DECIMAL columns often arrive as object/Decimal, not native float.
    This forces all FEATURE_COLUMNS to numeric so resolve_numeric_cols()
    doesn't silently drop them. Also coerces CATEGORICAL_FEATURES to string.
    """
    d = df.copy()
    for c in FEATURE_COLUMNS:
        if c in d.columns:
            d[c] = pd.to_numeric(d[c], errors="coerce")
    for c in CATEGORICAL_FEATURES:
        if c in d.columns:
            d[c] = d[c].astype(str)
    return d


def resolve_present_categorical_cols(df: pd.DataFrame) -> List[str]:
    """Return CATEGORICAL_FEATURES that are present in df."""
    return [c for c in CATEGORICAL_FEATURES if c in df.columns]


def build_model(numeric_features: List[str], use_baseline: bool = False,
                scale_pos_weight: float = 1.0, calibrate: bool = False,
                categorical_features: List[str] | None = None) -> Pipeline:
    if use_baseline:
        numeric_transformer = Pipeline(steps=[
            ("imputer", SimpleImputer(strategy="median")),
            ("scaler", StandardScaler()),
        ])
    else:
        numeric_transformer = Pipeline(steps=[
            ("imputer", SimpleImputer(strategy="median")),
        ])

    cat_transformer = Pipeline(steps=[
        ("imputer", SimpleImputer(strategy="constant", fill_value="missing")),
        ("onehot", OneHotEncoder(handle_unknown="ignore")),
    ])

    # Only include categorical features that are present in the dataframe
    cat_cols = categorical_features if categorical_features is not None else CATEGORICAL_FEATURES

    transformers = [
        ("num", numeric_transformer, numeric_features),
    ]
    if cat_cols:
        transformers.append(("cat", cat_transformer, cat_cols))

    pre = ColumnTransformer(
        transformers=transformers,
        remainder="drop",
    )

    if use_baseline:
        clf = LogisticRegression(max_iter=2000, class_weight="balanced", n_jobs=None)
    else:
        # Moderate XGBoost — strong enough to find signal, not so conservative it underfits.
        xgb_base = xgb.XGBClassifier(
            max_depth=3,
            learning_rate=0.03,
            n_estimators=300,
            subsample=0.8,
            colsample_bytree=0.8,
            reg_alpha=0.5,
            reg_lambda=3.0,
            min_child_weight=8,
            scale_pos_weight=scale_pos_weight,
            random_state=42,
            eval_metric="logloss",
            verbosity=0,
        )

        # Calibration is opt-in only (--calibrate flag) and gated by >=100 actual-fill rows.
        if calibrate:
            clf = CalibratedClassifierCV(
                xgb_base,
                method="isotonic",
                cv=3,
            )
        else:
            clf = xgb_base

    return Pipeline(steps=[("pre", pre), ("clf", clf)])


def precision_at_k(y_true: np.ndarray, y_prob: np.ndarray, k: int) -> float:
    k = int(min(k, len(y_true)))
    if k <= 0:
        return float("nan")
    idx = np.argsort(-y_prob)[:k]
    return float(np.mean(y_true[idx]))


def _print_subset_metrics(
    name: str,
    y_true: np.ndarray,
    y_prob: np.ndarray,
    top_k: int,
) -> None:
    print(f"\n  [{name}] rows={len(y_true)}  win_rate={float(np.mean(y_true)):.3f}")
    if len(np.unique(y_true)) > 1:
        print(f"  [{name}] AUC={roc_auc_score(y_true, y_prob):.4f}")
    print(f"  [{name}] Precision@{top_k}={precision_at_k(y_true, y_prob, top_k):.3f}")


def _print_feature_importance(model: Pipeline, feature_names: List[str]) -> None:
    """Dump feature importance so low-signal features can be pruned."""
    try:
        estimator = model.named_steps["clf"]
        if hasattr(estimator, "calibrated_classifiers_"):
            all_importances = []
            for calibrated in estimator.calibrated_classifiers_:
                if hasattr(calibrated.base_estimator, "feature_importances_"):
                    all_importances.append(calibrated.base_estimator.feature_importances_)
            if all_importances:
                importances = np.mean(all_importances, axis=0)
            else:
                return
        elif hasattr(estimator, "feature_importances_"):
            importances = estimator.feature_importances_
        else:
            return

        if len(importances) != len(feature_names):
            return

        feature_imp = sorted(zip(feature_names, importances), key=lambda x: -x[1])
        print(f"\n[feature_importance] Top 15 features:")
        for name, imp in feature_imp[:15]:
            print(f"  {name:30s} {imp:.6f}")
        zero_imp = [name for name, imp in feature_imp if imp < 0.001]
        if zero_imp:
            print(
                f"[feature_importance] {len(zero_imp)} features with near-zero importance "
                f"(candidates for removal): {zero_imp[:10]}{'...' if len(zero_imp) > 10 else ''}"
            )
    except Exception:
        pass  # Non-critical — feature importance is informational only


def print_probability_buckets(scored: pd.DataFrame, win_threshold_pct: float, top_k: int = 20) -> None:
    """Print win rate and avg PnL by predicted-probability bucket."""
    d = scored.copy()
    d["is_win"] = (d["pnl_percent"].astype(float) >= float(win_threshold_pct)).astype(int)
    d["bucket"] = pd.cut(
        d["win_prob"],
        bins=[0, .3, .4, .5, .6, .7, .8, .9, 1.0],
        include_lowest=True,
    )

    out = (
        d.groupby("bucket", observed=True)
         .agg(
             rows=("is_win", "size"),
             win_rate=("is_win", "mean"),
             avg_pnl=("pnl_percent", "mean"),
             median_pnl=("pnl_percent", "median"),
         )
         .reset_index()
    )

    print("\n=== Probability Buckets ===")
    print("(When model says X%, is actual win rate really X%? Are high-prob bins profitable?)")
    print(out.to_string(index=False))


# -----------------------
# train_and_eval
# -----------------------

def train_and_eval(
    df: pd.DataFrame,
    win_threshold_pct: float,
    top_k: int,
    baseline: bool,
    test_size: float = 0.2,
    split_mode: str = "time",
    actual_fill_weight: float = 1.0,
    eval_on_actual_only: bool = False,
    calibrate: bool = False,
) -> Tuple[Pipeline, dict, pd.DataFrame]:
    df2 = coerce_feature_columns(add_derived_features(df))

    if "pnl_percent" not in df2.columns:
        raise KeyError(f"Expected pnl_percent from SQL but it is missing. Columns: {list(df2.columns)}")

    if split_mode == "day":
        train_df, test_df = split_by_trading_day(df2, test_size=test_size)
    else:
        train_df, test_df = split_by_time(df2, test_size=test_size)

    numeric_cols = resolve_numeric_cols(train_df)
    cat_cols = resolve_present_categorical_cols(train_df)
    print(f"[features] Using {len(numeric_cols)} numeric + {len(cat_cols)} categorical feature columns.")

    y_train = make_label(train_df, win_threshold_pct).to_numpy()

    # Guard: XGBoost requires both classes in training data
    if len(np.unique(y_train)) < 2:
        raise SystemExit(
            f"Training set has only one class. "
            f"wins={int(np.sum(y_train == 1))}, losses={int(np.sum(y_train == 0))}. "
            "Use a wider date range, lower win threshold, or different pipeline filter."
        )

    feature_cols = numeric_cols + cat_cols
    X_train              = train_df[feature_cols]
    sample_weight_train  = build_sample_weights(train_df, actual_fill_weight)

    # Compute scale_pos_weight before building model
    num_negative     = int(np.sum(y_train == 0))
    num_positive     = int(np.sum(y_train == 1))
    scale_pos_weight = num_negative / num_positive if num_positive > 0 else 1.0
    if not baseline:
        print(f"\nClass distribution: {num_positive} wins, {num_negative} losses")
        print(f"Using scale_pos_weight={scale_pos_weight:.2f} for XGBoost")

    # Gate calibration: skip if <100 actual-fill rows in training set.
    # Isotonic calibration with too few positives per CV fold collapses
    # to "predict all losses" mode.
    if calibrate and not baseline and "has_actual_fill" in train_df.columns:
        actual_count = int((train_df["has_actual_fill"] == 1).sum())
        if actual_count < 100:
            print(f"[calibration] Disabled — only {actual_count} actual-fill rows in train "
                  f"(need >=100 for reliable isotonic calibration)")
            calibrate = False

    model = build_model(numeric_cols, use_baseline=baseline,
                        scale_pos_weight=scale_pos_weight,
                        calibrate=calibrate,
                        categorical_features=cat_cols)

    model.fit(X_train, y_train, clf__sample_weight=sample_weight_train)

    # Fix 6: Dump feature importance so low-signal features can be pruned
    if not baseline:
        _print_feature_importance(model, numeric_cols)

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
    X_test  = test_df[feature_cols]
    p_test  = model.predict_proba(X_test)[:, 1]

    metrics = {
        "test_auc": roc_auc_score(y_test, p_test) if len(np.unique(y_test)) > 1 else float("nan"),
        "confusion_matrix": confusion_matrix(y_test, (p_test >= 0.5).astype(int)).tolist(),
        "classification_report": classification_report(y_test, (p_test >= 0.5).astype(int), digits=4),
        "precision_at_k": precision_at_k(y_test, p_test, top_k),
        "win_rate_test": float(np.mean(y_test)),
        "rows_train": int(len(train_df)),
        "rows_test": int(len(test_df)),
        "features_used": feature_cols,
    }

    # Per-subset metrics: actual-fill rows vs BT-only rows
    if "has_actual_fill" in test_df.columns:
        print("\n=== Subset Metrics ===")
        for subset_name, mask in {
            "actual_only": test_df["has_actual_fill"].to_numpy() == 1,
            "bt_only": test_df["has_actual_fill"].to_numpy() == 0,
        }.items():
            actual_count = int(mask.sum())
            if actual_count > 20:
                _print_subset_metrics(
                    subset_name,
                    y_test[mask],
                    p_test[mask],
                    top_k,
                )
            else:
                print(f"\n  [{subset_name}] only {actual_count} rows — skipping subset metrics")

        # Fix 3: When eval_on_actual_only, add dedicated actual-only metrics to the metrics dict
        if eval_on_actual_only:
            actual_mask = test_df["has_actual_fill"].to_numpy() == 1
            if actual_mask.sum() > 5:
                y_actual = y_test[actual_mask]
                p_actual = p_test[actual_mask]
                metrics["test_auc_actual_only"] = (
                    roc_auc_score(y_actual, p_actual)
                    if len(np.unique(y_actual)) > 1
                    else float("nan")
                )
                metrics["precision_at_k_actual_only"] = precision_at_k(y_actual, p_actual, top_k)
                metrics["rows_test_actual_only"] = int(actual_mask.sum())
                metrics["win_rate_actual_only"] = float(np.mean(y_actual))
                print(f"\n[actual_only_eval] rows={int(actual_mask.sum())}  "
                      f"win_rate={float(np.mean(y_actual)):.3f}  "
                      f"AUC={metrics['test_auc_actual_only']:.4f}  "
                      f"Precision@{top_k}={metrics['precision_at_k_actual_only']:.3f}")

    # Build Top-K table from test set
    scored = test_df.copy()
    scored["win_prob"] = p_test
    scored["is_win"]   = (scored["pnl_percent"].astype(float) >= float(win_threshold_pct)).astype(int)

    # Print probability-bucket calibration table
    print_probability_buckets(scored, win_threshold_pct, top_k)

    show_cols = [c for c in [
        "symbol", "signal_type", "entry_type", "time_of_day",
        "entry_ts_est", "entry", "stop",
        "pnl_percent", "r_multiple", "win_prob", "is_win",
        "pipeline_run", "version",
    ] if c in scored.columns]

    topk_df = (
        scored.sort_values("win_prob", ascending=False)
              .head(int(top_k))
              .loc[:, show_cols]
              .reset_index(drop=True)
    )

    return model, metrics, topk_df


# -----------------------
# train_full_model
# -----------------------

def train_full_model(
    df: pd.DataFrame,
    win_threshold_pct: float,
    baseline: bool,
    numeric_cols: List[str],
    actual_fill_weight: float = 1.0,
) -> Pipeline:
    """Train on 100% of data (no test split). Use after holdout evaluation."""
    cat_cols = resolve_present_categorical_cols(df)

    # If numeric_cols already includes categorical cols (from train_and_eval's
    # metrics["features_used"]), strip them to avoid double-listing in ColumnTransformer.
    cat_set = set(cat_cols)
    clean_numeric_cols = [c for c in numeric_cols if c not in cat_set]

    feature_cols = clean_numeric_cols + cat_cols

    y             = make_label(df, win_threshold_pct).to_numpy()
    X             = df[feature_cols]
    sample_weight = build_sample_weights(df, actual_fill_weight)

    if len(np.unique(y)) < 2:
        raise SystemExit(
            f"Full-training set has only one class. "
            f"wins={int(np.sum(y == 1))}, losses={int(np.sum(y == 0))}. "
            "Use a wider date range or lower win threshold."
        )

    if not baseline:
        num_negative     = int(np.sum(y == 0))
        num_positive     = int(np.sum(y == 1))
        scale_pos_weight = num_negative / num_positive if num_positive > 0 else 1.0
        print(f"\n[TRAIN FULL] Class distribution: {num_positive} wins, {num_negative} losses")
        print(f"[TRAIN FULL] Using scale_pos_weight={scale_pos_weight:.2f}")
    else:
        scale_pos_weight = 1.0

    model = build_model(clean_numeric_cols, use_baseline=baseline,
                        scale_pos_weight=scale_pos_weight,
                        calibrate=False,  # Never calibrate full model — holdout eval is enough
                        categorical_features=cat_cols)

    model.fit(X, y, clf__sample_weight=sample_weight)
    return model


# -----------------------
# Scoring
# -----------------------

def score_candidates(model: Pipeline, candidates: pd.DataFrame, top_n: int, feature_columns: List[str] = None) -> pd.DataFrame:
    d = coerce_feature_columns(add_derived_features(candidates))

    # Prefer explicit feature list from saved metadata over introspecting pipeline internals
    if feature_columns:
        keep_cols = feature_columns
    else:
        keep_cols = model.named_steps["pre"].transformers_[0][2]

    X     = d.reindex(columns=keep_cols)
    proba = model.predict_proba(X)[:, 1]

    out          = candidates.copy()
    out["win_prob"] = proba
    out = out.sort_values("win_prob", ascending=False).head(int(top_n)).reset_index(drop=True)
    return out


def dump_topk_examples(df: pd.DataFrame, model: Pipeline, win_threshold_pct: float, k: int = 10, feature_columns: List[str] = None) -> None:
    d = add_derived_features(df)

    if feature_columns:
        keep_cols = feature_columns
    else:
        keep_cols = model.named_steps["pre"].transformers_[0][2]

    X = d.reindex(columns=keep_cols)
    p = model.predict_proba(X)[:, 1]

    out          = df.copy()
    out["win_prob"] = p
    out["is_win"]   = (out["pnl_percent"].astype(float) >= float(win_threshold_pct)).astype(int)

    cols = [c for c in [
        "symbol", "signal_type", "entry_type", "time_of_day",
        "entry_ts_est", "entry", "stop",
        "pnl_percent", "actual_pnl_pct", "has_actual_fill", "r_multiple",
        "win_prob", "is_win",
    ] if c in out.columns]

    print(f"\n=== TOP {k} PICKS (by win_prob) ===")
    print(out.sort_values("win_prob", ascending=False).head(k)[cols].to_string(index=False))


# -----------------------
# CLI
# -----------------------

def cmd_train(args):
    # Back-compat: legacy --no-test-split flag
    if getattr(args, "no_test_split", False):
        args.test_size = 0.0

    if not (0.0 <= args.test_size < 1.0):
        raise SystemExit("--test-size must be >= 0 and < 1 (e.g. 0.2 for 20% holdout)")

    # Setup logging to training_logs/
    import sys as _sys
    from datetime import datetime as _dt
    _log_dir = os.path.join(os.path.dirname(__file__), "training_logs")
    os.makedirs(_log_dir, exist_ok=True)
    _log_date = _dt.now().strftime("%Y-%m-%d_%H")
    _pipeline_suffix = args.pipeline or "all"
    _log_path = os.path.join(_log_dir, f"{_pipeline_suffix}-{_log_date}.log")
    _log_fh = open(_log_path, 'w')
    class _Tee:  # noqa
        def __init__(self, *files): self.files = files
        def write(self, s):
            for f in self.files: f.write(s); f.flush()
        def flush(self):
            for f in self.files: f.flush()
    _sys.stdout = _Tee(_sys.stdout, _log_fh)
    _sys.stderr = _sys.stdout
    print(f"[v2] Log: {_log_path}")

    cfg    = get_db_config_from_env()
    engine = make_engine(cfg)

    df = load_training_data(engine, args.start, args.end, args.table, args.pipeline, args.limit, getattr(args, "actual_fills_only", False))

    # BT-only filter: exclude trades with real Alpaca fills so the model
    # trains on backtest-simulated outcomes only (no live-trade distribution shift).
    if getattr(args, "bt_only", False) and "has_actual_fill" in df.columns:
        n_before = len(df)
        df = df[df["has_actual_fill"] == 0].copy()
        n_actual_excluded = n_before - len(df)
        if n_actual_excluded > 0:
            print(f"[bt-only] Excluded {n_actual_excluded} trades with real Alpaca fills "
                  f"({len(df)} BT-simulated trades remain)")

    df = keep_most_recent_rows(df, args.limit)

    if args.debug:
        print("Loaded rows:", len(df))
        print("Columns:", list(df.columns))
        if args.pipeline:
            pipelines = _sanitize_pipeline_values(args.pipeline)
            print(f"Filtered to pipeline(s): {', '.join(pipelines)}")

    if df.empty:
        raise SystemExit(
            "No rows returned. Check:\n"
            "- Date range\n"
            "- That one_minute_prices has matching ts_est for trade_alerts.entry_ts_est\n"
            "- That trade_alerts.pnl_percent is populated for those rows"
        )

    if "pnl_percent" not in df.columns:
        raise SystemExit(
            "Expected 'pnl_percent' in training data but it was missing.\n"
            f"Columns returned: {list(df.columns)}"
        )

    actual_fill_weight = getattr(args, "actual_fill_weight", 1.0)
    print(f"[config] actual_fill_weight={actual_fill_weight}x for rows with real Alpaca fills")

    eval_on_actual = getattr(args, "eval_on_actual_only", False)
    if eval_on_actual:
        print("[config] eval_on_actual_only=True — metrics report actual-fill performance separately")
    calibrate = bool(getattr(args, "calibrate", False))

    model, metrics, topk_df = train_and_eval(
        df=df,
        win_threshold_pct=args.win_threshold,
        top_k=args.top_k,
        baseline=args.baseline,
        test_size=args.test_size,
        split_mode=args.split_mode,
        actual_fill_weight=actual_fill_weight,
        eval_on_actual_only=eval_on_actual,
        calibrate=calibrate,
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
            "win_threshold_pct": args.win_threshold,
            "top_k_eval": args.top_k,
            "baseline": args.baseline,
            "table_name": args.table,
            "test_size": args.test_size,
            "trained_full": bool(args.train_full),
            "pipeline": args.pipeline,
            "metrics": metrics,
            # Saved explicitly so score_candidates doesn't need to introspect pipeline internals
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

    if metrics.get("test_auc_actual_only") is not None:
        print(f"  Win rate (actual): {metrics['win_rate_actual_only']:.4f}")
        print(f"  AUC (actual):      {metrics['test_auc_actual_only']:.4f}")
        print(f"  Precision@{args.top_k} (actual): {metrics['precision_at_k_actual_only']:.3f}")
        print(f"  Rows (actual):     {metrics['rows_test_actual_only']}")

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

    cand = load_candidates(engine, args.as_of_ts_est, pipeline_run=args.pipeline)
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

    ap_train = sub.add_parser("train", help="Train a winner model from trade_alerts outcomes")
    ap_train.add_argument("--start",    required=True, help="EST datetime (YYYY-MM-DD or YYYY-MM-DD HH:MM:SS)")
    ap_train.add_argument("--end",      required=True, help="EST datetime (YYYY-MM-DD or YYYY-MM-DD HH:MM:SS)")
    ap_train.add_argument("--table",    default="trade_alerts",
                          choices=["trade_alerts", "trade_alerts_unfiltered"],
                          help="Table to train from")
    ap_train.add_argument("--pipeline", default=None,
                          help="Filter to specific pipeline(s) — single: 'M' or multiple: 'A,B,C,D'")
    ap_train.add_argument("--limit",         type=int, default=8000,
                          help="Keep the most recent N filtered rows before splitting; use 0 to disable")
    ap_train.add_argument("--bt-only",        action="store_true", default=False,
                          help="Exclude trades with real Alpaca fills (train on BT-simulated outcomes only)")
    ap_train.add_argument("--include-actual-fills", dest="bt_only", action="store_false",
                          help="Include trades with real Alpaca fills in training data")
    ap_train.add_argument("--win-threshold",    type=float, default=2.0,
                          help="Win if pnl_percent >= threshold")
    ap_train.add_argument("--top-k",            type=int,   default=10,
                          help="Evaluate precision@K on the test split")
    ap_train.add_argument("--baseline",         action="store_true",
                          help="Use logistic regression baseline instead of XGBoost")
    ap_train.add_argument("--test-size",        type=float, default=0.2,
                          help="Time-based holdout fraction (e.g. 0.2 = last 20%% is test). Use 0 for no split.")
    ap_train.add_argument("--split-mode",       choices=["time", "day"], default="time",
                          help="How to build the holdout split: time sorts by entry_ts_est; day holds out trading dates.")
    ap_train.add_argument("--train-full",       action="store_true",
                          help="After eval, retrain final model on 100%% of rows and save that model")
    ap_train.add_argument("--no-test-split",    dest="no_test_split", action="store_true",
                          help="Disable holdout split (same as --test-size 0)")
    ap_train.add_argument("--model-out",        default="models/winner_model.joblib")
    ap_train.add_argument("--actual-fill-weight", type=float, default=1.0,
                          help="Sample weight multiplier for rows with actual Alpaca fills (default: 1x). "
                               "Try 10-20x to emphasize real fills when BT-simulated labels dominate.")
    ap_train.add_argument("--actual-fills-only", action="store_true",
                          help="Train and evaluate ONLY on rows with actual Alpaca fills "
                               "(ignores all BT-simulated labels). Requires sufficient live trading history.")
    ap_train.add_argument("--eval-on-actual-only", action="store_true",
                          help="Train on all data but report additional evaluation metrics "
                               "computed ONLY on actual-fill test rows.")
    ap_train.add_argument("--calibrate", action="store_true",
                          help="Enable isotonic probability calibration. "
                               "Automatically disabled if <100 actual-fill rows in training set.")
    ap_train.add_argument("--debug",            action="store_true",
                          help="Print debug info (rows + columns loaded)")
    ap_train.set_defaults(func=cmd_train)

    ap_score = sub.add_parser("score",
                              help="Score trade alerts at a specific EST minute timestamp")
    ap_score.add_argument("--model-in",       required=True)
    ap_score.add_argument("--as-of-ts-est",   dest="as_of_ts_est", required=True,
                          help="EST datetime (YYYY-MM-DD HH:MM:SS) matching trade_alerts.as_of_ts_est")
    ap_score.add_argument("--top-n",          type=int, default=10)
    ap_score.add_argument("--pipeline",       default=None,
                          help="Filter candidates to this pipeline (must match training pipeline)")
    ap_score.set_defaults(func=cmd_score)

    args = ap.parse_args()
    args.func(args)


if __name__ == "__main__":
    main()
