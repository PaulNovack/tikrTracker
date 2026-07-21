#!/usr/bin/env python3
"""
train_stock_winner_model_pipeline_h.py — Pipeline H (v25.2) specific ML training

Pipeline H uses OneMinuteEntryFinderV25_2 + FiveMinuteSignalScannerV25_2.
This script adds the v25.2-specific features (scanner features, room-to-run,
entry-type flags, pattern-specific quality metrics, and score sub-components)
on top of the shared base features from train_stock_winner_model_v2.py.

Key additions over the generic v2 script:
  - Scanner features: move_30m_pct, rvol_5m, atr_pct_5m, notional_last5m,
    pct_nd, spy_move_30m_pct, universe_size
  - Room-to-run: hod, room_to_hod_pct, room_to_hod_atr
  - VWAP entry distance: above_vwap_entry_pct
  - Entry type one-hot flags: is_vwap_reclaim_strong, is_orb_retest, is_ema9_pullback
  - Entry quality: entry_body_pct, entry_close_position, entry_volume_ratio, entry_notional_1m
  - Entry score sub-components: entry_spread_strength, entry_vwap_dist_score, entry_atr_score,
    entry_vol_score, entry_candle_score, entry_time_bonus
  - VWAP reclaim specific: vwap_reclaim_strength_pct, vwap_reclaim_wick_below_pct
  - ORB retest specific: or_retest_depth_pct, or_hold_close_pct, or_break_distance_pct,
    bars_since_or_break
  - EMA9 pullback specific: ema9_pullback_depth_pct, ema9_reclaim_pct
  - Risk/stop features: risk_pct, risk_per_share, suggested_trailing_stop_pct
  - Derived combos: rs_30m_vs_spy, move_rvol_combo, rvol_atr_combo, stop_distance_atr,
    trail_vs_risk, abs_above_vwap_entry_pct, vwap_chase_risk

Usage:
  # Train with holdout evaluation
  python python_ml/train_stock_winner_model_pipeline_h.py train \
    --start 2026-01-01 --end 2026-05-23 \
    --pipeline H \
    --model-out python_ml/models/winner_model_pipeline_h.joblib \
    --test-size 0.2 --train-full

  # Score a specific minute's alerts
  python python_ml/train_stock_winner_model_pipeline_h.py score \
    --model-in python_ml/models/winner_model_pipeline_h.joblib \
    --as-of-ts-est "2026-05-23 10:00:00"
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


# Pipeline H feature set — base generic features + v25.2-specific additions.
# New features are grouped by origin so it's easy to audit what's from where.
FEATURE_COLUMNS = [
    # ── Base alert features (shared with all pipelines) ─────────────────
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

    # ── 1m bar features at entry ────────────────────────────────────────
    "vwap_dist_pct",
    "above_vwap",
    "ema9_ema21_spread",
    "ema9_above_ema21",
    "omp_atr_pct",

    # ── 5m bar features near signal ──────────────────────────────────────
    "fmp_vwap_dist_pct",
    "fmp_above_vwap",
    "fmp_ema_spread",
    "fmp_ema9_above_ema21",
    "fmp_atr_pct",
    "fmp_rsi_14",

    # ── v25.2 scanner features (from FiveMinuteSignalScannerV25_2) ───────
    "move_30m_pct",
    "rvol_5m",
    "atr_pct_5m",
    "notional_last5m",
    "pct_nd",
    "spy_move_30m_pct",
    "universe_size",

    # ── v25.2 room-to-run features ────────────────────────────────────────
    "hod",
    "room_to_hod_pct",
    "room_to_hod_atr",

    # ── v25.2 VWAP entry distance ────────────────────────────────────────
    "above_vwap_entry_pct",

    # ── v25.2 entry quality ───────────────────────────────────────────────
    "entry_body_pct",
    "entry_close_position",
    "entry_volume_ratio",
    "entry_notional_1m",

    # ── v25.2 entry score sub-components ─────────────────────────────────
    "entry_spread_strength",
    "entry_vwap_dist_score",
    "entry_atr_score",
    "entry_vol_score",
    "entry_candle_score",
    "entry_time_bonus",

    # ── v25.2 risk/stop features ──────────────────────────────────────────
    "risk_pct",
    "risk_per_share",
    "suggested_trailing_stop_pct",

    # ── v25.2 VWAP reclaim specific ───────────────────────────────────────
    "vwap_reclaim_strength_pct",
    "vwap_reclaim_wick_below_pct",

    # ── v25.2 ORB retest specific ─────────────────────────────────────────
    "or_retest_depth_pct",
    "or_hold_close_pct",
    "or_break_distance_pct",
    "bars_since_or_break",

    # ── v25.2 EMA9 pullback specific ──────────────────────────────────────
    "ema9_pullback_depth_pct",
    "ema9_reclaim_pct",

    # ── Derived base features (added by add_derived_features) ────────────
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
    "overextension_score",
    "healthy_setup_score",

    # ── v25.2 derived features (added by add_h_derived_features) ─────────
    "rs_30m_vs_spy",
    "move_rvol_combo",
    "rvol_atr_combo",
    "stop_distance_atr",
    "trail_vs_risk",
    "abs_above_vwap_entry_pct",
    "vwap_chase_risk",
    # Entry type one-hot flags
    "is_vwap_reclaim_strong",
    "is_orb_retest",
    "is_ema9_pullback",

    # ── Market context features ───────────────────────────────────────────
    "mkt_day_pct",
    "mkt_5m_ema_trend",
    "mkt_5m_vwap_dist",
    "mkt_5m_rsi",
    "recent_losses_today",
    "mkt_is_green",
    "mkt_is_strong",
    "mkt_is_weak",
    "mkt_trending_up",
    "rs_spread_vs_market",
    "minutes_since_open",
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
# Training SQL
# -----------------------
# Extends the base v2 SQL with v25.2-specific columns stored in trade_alerts.
# All new columns are NULL for older rows (migrated) — they are median-imputed.
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

        ta.score                      AS alert_score,
        ta.vol_ratio                  AS alert_vol_ratio,
        ta.atr                        AS alert_atr,
        ta.atr_pct                    AS alert_atr_pct,
        ta.daily_trend_5d_pct,
        ta.range_position_60m,
        ta.rsi_14_1m                  AS alert_rsi_14_1m_stored,
        ta.five_min_directional_changes,
        ta.five_min_green_bar_pct,
        ta.five_min_net_progress,
        ta.risk_pct,
        ta.risk_per_share,
        ta.suggested_trailing_stop_pct,
        ta.pipeline_run,
        ta.version,

        -- v25.2 scanner features
        ta.move_30m_pct,
        ta.rvol_5m,
        ta.atr_pct_5m,
        ta.notional_last5m,
        ta.pct_nd,
        ta.spy_move_30m_pct,
        ta.universe_size,

        -- v25.2 room-to-run
        ta.hod,
        ta.room_to_hod_pct,
        ta.room_to_hod_atr,

        -- v25.2 VWAP entry distance
        ta.above_vwap_entry_pct,

        -- v25.2 entry quality
        ta.entry_body_pct,
        ta.entry_close_position,
        ta.entry_volume_ratio,
        ta.entry_notional_1m,

        -- v25.2 entry score sub-components
        ta.entry_spread_strength,
        ta.entry_vwap_dist_score,
        ta.entry_atr_score,
        ta.entry_vol_score,
        ta.entry_candle_score,
        ta.entry_time_bonus,

        -- v25.2 pattern-specific features
        ta.vwap_reclaim_strength_pct,
        ta.vwap_reclaim_wick_below_pct,
        ta.or_retest_depth_pct,
        ta.or_hold_close_pct,
        ta.or_break_distance_pct,
        ta.bars_since_or_break,
        ta.ema9_pullback_depth_pct,
        ta.ema9_reclaim_pct

    FROM {table_name} ta
    WHERE ta.entry_ts_est >= :start_dt
      AND ta.entry_ts_est <  :end_dt
      AND ta.pnl_percent IS NOT NULL
      AND ta.pipeline_run = 'H'
),

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
    t.risk_pct,
    t.risk_per_share,
    t.suggested_trailing_stop_pct,
    t.pipeline_run,
    t.version,

    -- v25.2 scanner features
    t.move_30m_pct,
    t.rvol_5m,
    t.atr_pct_5m,
    t.notional_last5m,
    t.pct_nd,
    t.spy_move_30m_pct,
    t.universe_size,

    -- v25.2 room-to-run
    t.hod,
    t.room_to_hod_pct,
    t.room_to_hod_atr,

    -- v25.2 VWAP entry distance
    t.above_vwap_entry_pct,

    -- v25.2 entry quality
    t.entry_body_pct,
    t.entry_close_position,
    t.entry_volume_ratio,
    t.entry_notional_1m,

    -- v25.2 entry score sub-components
    t.entry_spread_strength,
    t.entry_vwap_dist_score,
    t.entry_atr_score,
    t.entry_vol_score,
    t.entry_candle_score,
    t.entry_time_bonus,

    -- v25.2 pattern-specific features
    t.vwap_reclaim_strength_pct,
    t.vwap_reclaim_wick_below_pct,
    t.or_retest_depth_pct,
    t.or_hold_close_pct,
    t.or_break_distance_pct,
    t.bars_since_or_break,
    t.ema9_pullback_depth_pct,
    t.ema9_reclaim_pct,

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
    fmp.vwap_dist_pct AS fmp_vwap_dist_pct,
    fmp.above_vwap AS fmp_above_vwap,
    fmp.ema9_ema21_spread AS fmp_ema_spread,
    fmp.ema9_above_ema21 AS fmp_ema9_above_ema21,
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
 AND omp.ts_est = t.entry_ts_est

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
 );
"""


def load_training_data(engine, start_dt: str, end_dt: str, table_name: str = "trade_alerts") -> pd.DataFrame:
    sql = TRAIN_SQL_TEMPLATE.format(
        table_name=table_name,
        benchmark_symbol=_get_benchmark_symbol(),
    )
    with engine.connect() as conn:
        df = pd.read_sql(text(sql), conn, params={"start_dt": start_dt, "end_dt": end_dt})
    return df


# -----------------------
# Feature engineering
# -----------------------

def add_derived_features(df: pd.DataFrame) -> pd.DataFrame:
    """Base derived features (shared with the generic v2 script)."""
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

    warning_cols = [c for c in d.columns if c in [
        "rsi_1m_overbought", "rsi_5m_overbought", "vwap_extended",
        "vol_ratio_extreme", "green_bars_high", "rsi_1m_extreme",
    ]]
    if warning_cols:
        d["overextension_score"] = d[warning_cols].sum(axis=1).astype(float)

    healthy_cols = [c for c in d.columns if c in [
        "vol_ratio_moderate", "atr_sweet_spot", "green_bars_balanced", "clean_trend",
    ]]
    if healthy_cols:
        d["healthy_setup_score"] = d[healthy_cols].sum(axis=1).astype(float)

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

    return d


def add_h_derived_features(df: pd.DataFrame) -> pd.DataFrame:
    """
    Pipeline H / v25.2-specific derived features.
    Call AFTER add_derived_features().
    """
    d = df.copy()

    # Scanner combos
    if "move_30m_pct" in d.columns and "spy_move_30m_pct" in d.columns:
        d["rs_30m_vs_spy"] = d["move_30m_pct"].astype(float) - d["spy_move_30m_pct"].astype(float)

    if "move_30m_pct" in d.columns and "rvol_5m" in d.columns:
        d["move_rvol_combo"] = d["move_30m_pct"].astype(float) * d["rvol_5m"].astype(float)

    if "rvol_5m" in d.columns and "atr_pct_5m" in d.columns:
        d["rvol_atr_combo"] = d["rvol_5m"].astype(float) * d["atr_pct_5m"].astype(float)

    # Risk/stop derived
    if "risk_per_share" in d.columns and "alert_atr" in d.columns:
        atr_safe = d["alert_atr"].astype(float).replace(0, np.nan)
        d["stop_distance_atr"] = d["risk_per_share"].astype(float) / atr_safe

    if "suggested_trailing_stop_pct" in d.columns and "risk_pct" in d.columns:
        risk_safe = d["risk_pct"].astype(float).replace(0, np.nan)
        d["trail_vs_risk"] = d["suggested_trailing_stop_pct"].astype(float) / risk_safe

    # VWAP chase risk
    if "above_vwap_entry_pct" in d.columns:
        d["abs_above_vwap_entry_pct"] = d["above_vwap_entry_pct"].astype(float).abs()
        d["vwap_chase_risk"] = (d["above_vwap_entry_pct"].astype(float) > 0.8).astype(float)

    # Entry type one-hot flags
    if "entry_type" in d.columns:
        d["is_vwap_reclaim_strong"] = (d["entry_type"] == "VWAP_RECLAIM_STRONG").astype(float)
        d["is_orb_retest"]          = (d["entry_type"] == "ORB_RETEST").astype(float)
        d["is_ema9_pullback"]       = (d["entry_type"] == "EMA9_PULLBACK").astype(float)

    return d


def add_all_features(df: pd.DataFrame) -> pd.DataFrame:
    """Apply all feature engineering in the correct order."""
    d = add_derived_features(df)
    d = add_h_derived_features(d)
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
    d = df.sort_values("entry_ts_est").reset_index(drop=True)
    n = len(d)
    if test_size <= 0:
        return d.copy(), d.iloc[0:0].copy()
    cut = int(np.floor((1.0 - test_size) * n))
    return d.iloc[:cut].copy(), d.iloc[cut:].copy()


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
            max_depth=5,
            learning_rate=0.05,
            n_estimators=300,
            subsample=0.8,
            colsample_bytree=0.7,
            min_child_weight=3,
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
    df2 = add_all_features(df)

    if "pnl_percent" not in df2.columns:
        raise KeyError(f"Expected pnl_percent from SQL but it is missing. Columns: {list(df2.columns)}")

    numeric_cols = resolve_numeric_cols(df2)
    print(f"[features] Using {len(numeric_cols)} explicit feature columns (Pipeline H v25.2).")

    # Log how many v25.2-specific features were resolved (for diagnostics)
    h_specific = [
        "move_30m_pct", "rvol_5m", "atr_pct_5m", "notional_last5m", "pct_nd",
        "spy_move_30m_pct", "universe_size", "room_to_hod_pct", "room_to_hod_atr",
        "above_vwap_entry_pct", "entry_body_pct", "entry_close_position",
        "vwap_reclaim_strength_pct", "or_retest_depth_pct", "ema9_pullback_depth_pct",
        "rs_30m_vs_spy", "move_rvol_combo", "is_vwap_reclaim_strong",
    ]
    resolved_h = [c for c in h_specific if c in numeric_cols]
    null_h = [c for c in h_specific if c not in numeric_cols]
    print(f"[features] v25.2-specific resolved: {len(resolved_h)}")
    if null_h:
        print(f"[features] v25.2-specific MISSING (will be imputed/zero): {null_h}")

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
    model               = build_model(numeric_cols, use_baseline=baseline)

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

    y_test = make_label(test_df, win_threshold_pct).to_numpy()
    X_test = test_df[numeric_cols]
    p_test = model.predict_proba(X_test)[:, 1]

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

    # Per-entry-type metrics
    if "entry_type" in test_df.columns:
        print("\n=== Metrics by Entry Type ===")
        for etype in ["VWAP_RECLAIM_STRONG", "ORB_RETEST", "EMA9_PULLBACK"]:
            mask = test_df["entry_type"].to_numpy() == etype
            if int(mask.sum()) > 10:
                _print_subset_metrics(etype, y_test[mask], p_test[mask], min(top_k, int(mask.sum())))
            else:
                print(f"\n  [{etype}] only {int(mask.sum())} rows — skipping")

    scored = test_df.copy()
    scored["win_prob"] = p_test
    scored["is_win"]   = (scored["pnl_percent"].astype(float) >= float(win_threshold_pct)).astype(int)

    show_cols = [c for c in [
        "symbol", "signal_type", "entry_type", "time_of_day",
        "entry_ts_est", "entry", "stop",
        "pnl_percent", "r_multiple", "win_prob", "is_win",
        "move_30m_pct", "rvol_5m", "room_to_hod_pct",
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
            f"wins={int(np.sum(y == 1))}, losses={int(np.sum(y == 0))}. "
            "Use a wider date range or lower win threshold."
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

    print(f"Loading Pipeline H training data: {args.start} → {args.end} (table={args.table})")
    df = load_training_data(engine, args.start, args.end, args.table)
    df = add_recent_loss_streak(df)

    if args.debug:
        print("Loaded rows:", len(df))
        print("Columns:", list(df.columns))

    if df.empty:
        raise SystemExit(
            "No rows returned. Check:\n"
            "- Date range\n"
            "- Pipeline H alerts exist in trade_alerts with pnl_percent populated\n"
            "- one_minute_prices_full has matching ts_est for entry_ts_est values"
        )

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
        df2          = add_all_features(df)
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
            "pipeline": "H",
            "script": "train_stock_winner_model_pipeline_h.py",
            "win_threshold_pct": args.win_threshold,
            "top_k_eval": args.top_k,
            "baseline": args.baseline,
            "table_name": args.table,
            "test_size": args.test_size,
            "trained_full": bool(args.train_full),
            "metrics": metrics,
            "feature_columns": metrics["features_used"],
            "model_version": f"pipeline_h_v252",
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


def main():
    ap  = argparse.ArgumentParser(description="Pipeline H (v25.2) ML Training")
    sub = ap.add_subparsers(dest="cmd", required=True)

    ap_train = sub.add_parser("train", help="Train Pipeline H winner model from trade_alerts outcomes")
    ap_train.add_argument("--start",    required=True, help="EST date (YYYY-MM-DD)")
    ap_train.add_argument("--end",      required=True, help="EST date (YYYY-MM-DD)")
    ap_train.add_argument("--table",    default="trade_alerts",
                          choices=["trade_alerts", "trade_alerts_unfiltered"],
                          help="Table to train from (Pipeline H only)")
    ap_train.add_argument("--win-threshold",      type=float, default=1.0,
                          help="Win if pnl_percent >= threshold (default: 1.0%%)")
    ap_train.add_argument("--top-k",              type=int,   default=10,
                          help="Evaluate precision@K on the test split")
    ap_train.add_argument("--baseline",           action="store_true",
                          help="Use logistic regression baseline instead of XGBoost")
    ap_train.add_argument("--test-size",          type=float, default=0.2,
                          help="Time-based holdout fraction (e.g. 0.2 = last 20%% is test). Use 0 for no split.")
    ap_train.add_argument("--train-full",         action="store_true",
                          help="After eval, retrain final model on 100%% of rows and save that model")
    ap_train.add_argument("--no-test-split",      dest="no_test_split", action="store_true",
                          help="Disable holdout split (same as --test-size 0)")
    ap_train.add_argument("--model-out",          default="python_ml/models/winner_model_pipeline_h.joblib")
    ap_train.add_argument("--actual-fill-weight", type=float, default=1.0,
                          help="Sample weight multiplier for rows with actual Alpaca fills (default: 1x)")
    ap_train.add_argument("--debug",              action="store_true",
                          help="Print debug info (rows + columns loaded)")
    ap_train.set_defaults(func=cmd_train)

    args = ap.parse_args()
    args.func(args)


if __name__ == "__main__":
    main()
