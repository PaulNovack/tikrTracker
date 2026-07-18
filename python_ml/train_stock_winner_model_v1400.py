#!/usr/bin/env python3
"""
Train a Pipeline M v1400.0-specific ML model to predict winning trades.

This script is specialized for v1400's "Tight Stops Clean Trend" strategy.
It adds unique features that matter for smooth, low-choppiness intraday trends:
- trend_smoothness: Inverse of directional changes (low = clean)
- max_drawdown_pct: Maximum pullback during setup period
- risk_score: Trend % ÷ Max Drawdown % (higher = better risk-adjusted)
- bars_above_ema9: Trend persistence strength
- avg_bar_body_size: Clean bars vs wicks (body = close - open)
- choppiness_index: Directional strength (0-100, lower = cleaner trend)
- consecutive_green_bars: Smooth uninterrupted momentum

✅ Uses only v1400.0 alerts (pipeline_run='M', version='v1400.0')
✅ Includes all standard features plus v1400-specific ones
✅ Supports --train-full to evaluate on 20% then retrain on 100%
✅ Optimized for tight stop strategies (1-2% stops, high win rate focus)

Example usage:
    # Evaluate on 20%, then retrain final model on 100%
    python python_ml/train_stock_winner_model_v1400.py train \\
      --start "2026-04-04" \\
      --end "2026-08-01" \\
      --table trade_alerts \\
      --test-size 0.2 \\
      --train-full \\
      --model-out python_ml/models/winner_model_v1400.joblib

    # Train on 100% without test split
    python python_ml/train_stock_winner_model_v1400.py train \\
      --start "2026-04-04" \\
      --end "2026-08-01" \\
      --table trade_alerts \\
      --test-size 0 \\
      --model-out python_ml/models/winner_model_v1400.joblib
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

@dataclass
class DBConfig:
    host: str
    port: int
    name: str
    user: str
    password: str


def load_parent_env() -> None:
    """Load .env from the directory ABOVE this script file."""
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
    url = f"mysql+pymysql://{cfg.user}:{cfg.password}@{cfg.host}:{cfg.port}/{cfg.name}"
    return create_engine(url, pool_pre_ping=True)


# -----------------------
# Data loading - v1400 ONLY
# -----------------------
TRAIN_SQL_V1400_TEMPLATE = """
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
        
        -- v1400-specific scanner data stored in alert
        ta.trend_pct AS scanner_trend_pct,
        ta.max_drawdown_pct AS scanner_max_drawdown_pct,
        ta.risk_score AS scanner_risk_score,
        
        ta.pipeline_run,
        ta.version
    FROM {table_name} ta
    WHERE ta.pipeline_run = 'M'
      AND ta.version = 'v1400.0'
      AND ta.entry_ts_est >= :start_dt
      AND ta.entry_ts_est <  :end_dt
      AND ta.pnl_percent IS NOT NULL
),
bars AS (
    -- Pull 1m bars window for RSI calculation
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
            AVG(gain)  OVER (PARTITION BY alert_id ORDER BY ts_est ROWS BETWEEN 13 PRECEDING AND CURRENT ROW) AS avg_gain,
            AVG(loss)  OVER (PARTITION BY alert_id ORDER BY ts_est ROWS BETWEEN 13 PRECEDING AND CURRENT ROW) AS avg_loss,
            COUNT(gain) OVER (PARTITION BY alert_id ORDER BY ts_est ROWS BETWEEN 13 PRECEDING AND CURRENT ROW) AS cnt_gain,
            COUNT(loss) OVER (PARTITION BY alert_id ORDER BY ts_est ROWS BETWEEN 13 PRECEDING AND CURRENT ROW) AS cnt_loss
        FROM deltas
    ) x
),
-- v1400 SPECIFIC: Compute additional features from 1-minute bars during setup
setup_bars AS (
    SELECT
        t.alert_id,
        -- Count bars where price is above EMA9 (trend persistence)
        SUM(CASE WHEN omp.price > omp.ema9 THEN 1 ELSE 0 END) AS bars_above_ema9_count,
        COUNT(*) AS total_bars,
        -- Average bar body size (clean bars have large bodies vs wicks)
        AVG(ABS(omp.close - omp.open)) AS avg_bar_body_size,
        -- Consecutive green bars (max streak)
        MAX(green_streak) AS max_consecutive_green_bars
    FROM ta_base t
    JOIN one_minute_prices_full omp
      ON omp.symbol = t.symbol
     AND omp.asset_type = t.asset_type
     -- Look at bars in the 2-hour setup window before entry
     AND omp.ts_est BETWEEN (t.entry_ts_est - INTERVAL 120 MINUTE) AND t.entry_ts_est
    LEFT JOIN LATERAL (
        -- Count consecutive green bars ending at current bar
        SELECT COUNT(*) AS green_streak
        FROM one_minute_prices_full omp2
        WHERE omp2.symbol = t.symbol
          AND omp2.asset_type = t.asset_type
          AND omp2.ts_est <= omp.ts_est
          AND omp2.ts_est >= (omp.ts_est - INTERVAL 30 MINUTE)
          AND omp2.close > omp2.open
        ORDER BY omp2.ts_est DESC
    ) AS streaks ON TRUE
    GROUP BY t.alert_id
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
    t.pnl_percent,
    t.pnl_dollar,
    t.r_multiple,
    t.exit_reason,
    t.hold_time_minutes,

    -- standard alert features
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
    
    -- v1400-specific scanner features
    t.scanner_trend_pct,
    t.scanner_max_drawdown_pct,
    t.scanner_risk_score,
    
    -- v1400-specific computed features from setup bars
    sb.bars_above_ema9_count,
    sb.total_bars,
    sb.avg_bar_body_size,
    sb.max_consecutive_green_bars,
    
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

    -- 5-minute context
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
    fmp.rsi_14 AS fmp_rsi_14

FROM ta_base t

JOIN one_minute_prices_full omp
  ON omp.symbol = t.symbol
 AND omp.asset_type = t.asset_type
 AND omp.ts_est = t.entry_ts_est

LEFT JOIN rsi_calc r
  ON r.alert_id = t.alert_id
 AND r.ts_est = t.entry_ts_est

LEFT JOIN setup_bars sb
  ON sb.alert_id = t.alert_id

LEFT JOIN five_minute_prices_full fmp
  ON fmp.symbol = t.symbol
 AND fmp.asset_type = t.asset_type
 AND fmp.ts_est = (
      SELECT MAX(f2.ts_est)
      FROM five_minute_prices_full f2
      WHERE f2.symbol = t.symbol
        AND f2.asset_type = t.asset_type
        AND f2.ts_est <= t.signal_ts_est
 );
"""


def load_training_data(engine, start_dt: str, end_dt: str, table_name: str = "trade_alerts") -> pd.DataFrame:
    sql = TRAIN_SQL_V1400_TEMPLATE.format(table_name=table_name)
    with engine.connect() as conn:
        df = pd.read_sql(
            text(sql),
            conn,
            params={"start_dt": start_dt, "end_dt": end_dt},
        )
    return df


# -----------------------
# Feature engineering - v1400 SPECIFIC
# -----------------------

def make_label(df: pd.DataFrame, win_threshold_pct: float) -> pd.Series:
    if "pnl_percent" not in df.columns:
        raise KeyError(f"Missing 'pnl_percent' column. Columns: {list(df.columns)}")
    return (df["pnl_percent"].astype(float) >= float(win_threshold_pct)).astype(int)


def add_derived_features_v1400(df: pd.DataFrame) -> pd.DataFrame:
    """Add all standard features plus v1400-specific derived features."""
    d = df.copy()

    # Standard features from base model
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

    # ========== v1400-SPECIFIC FEATURES ==========
    
    # 1. TREND SMOOTHNESS: Inverse of directional changes (lower = smoother)
    if "five_min_directional_changes" in d.columns:
        # Normalize: 0-10 changes → smoothness 1.0-0.0
        d["trend_smoothness"] = 1.0 - (d["five_min_directional_changes"].astype(float) / 10.0).clip(0, 1)
        d["clean_trend"] = (d["five_min_directional_changes"].astype(float) <= 3).astype(float)
        d["choppy"] = (d["five_min_directional_changes"].astype(float) > 6).astype(float)
    
    # 2. MAX DRAWDOWN PCT: Already loaded from scanner (scanner_max_drawdown_pct)
    # No derivation needed - this is the actual max pullback
    
    # 3. RISK SCORE: Already loaded from scanner (scanner_risk_score)
    # This is Trend % ÷ Max Drawdown % - higher = better risk-adjusted returns
    
    # 4. BARS ABOVE EMA9: Trend persistence (from setup_bars query)
    if "bars_above_ema9_count" in d.columns and "total_bars" in d.columns:
        d["bars_above_ema9_pct"] = (
            d["bars_above_ema9_count"].astype(float) / d["total_bars"].astype(float).clip(lower=1)
        ) * 100.0
        d["strong_trend_persistence"] = (d["bars_above_ema9_pct"].astype(float) > 80).astype(float)
    
    # 5. AVG BAR BODY SIZE: Already loaded (avg_bar_body_size)
    # Clean bars have large bodies, wicks indicate indecision
    if "avg_bar_body_size" in d.columns:
        d["clean_bars"] = (d["avg_bar_body_size"].astype(float) > 0.10).astype(float)
    
    # 6. CHOPPINESS INDEX: Calculated from directional changes and net progress
    if "five_min_directional_changes" in d.columns and "five_min_net_progress" in d.columns:
        # Choppiness = (directional changes / net progress) × 100
        # Lower = cleaner trend (more progress per direction change)
        net_prog = d["five_min_net_progress"].astype(float).clip(lower=0.01)  # Avoid div by zero
        d["choppiness_index"] = (d["five_min_directional_changes"].astype(float) / net_prog) * 10.0
        d["choppiness_index"] = d["choppiness_index"].clip(0, 100)  # Cap at 100
        d["low_choppiness"] = (d["choppiness_index"].astype(float) < 30).astype(float)
    
    # 7. CONSECUTIVE GREEN BARS: Already loaded (max_consecutive_green_bars)
    if "max_consecutive_green_bars" in d.columns:
        d["smooth_momentum"] = (d["max_consecutive_green_bars"].astype(float) >= 5).astype(float)
        d["very_smooth_momentum"] = (d["max_consecutive_green_bars"].astype(float) >= 8).astype(float)
    
    # v1400 COMPOSITE SCORES
    
    # Quality score: Sum of positive v1400 characteristics
    quality_cols = [c for c in d.columns if c in [
        'clean_trend', 'low_choppiness', 'strong_trend_persistence',
        'smooth_momentum', 'clean_bars'
    ]]
    if quality_cols:
        d["v1400_quality_score"] = d[quality_cols].sum(axis=1).astype(float)
    
    # Risk score boost: Combine scanner risk score with quality
    if "scanner_risk_score" in d.columns and "v1400_quality_score" in d.columns:
        d["combined_risk_quality"] = (
            d["scanner_risk_score"].astype(float) * (1 + d["v1400_quality_score"].astype(float) * 0.1)
        )
    
    # Ideal v1400 setup: High risk score + low choppiness + smooth momentum
    ideal_flags = [c for c in d.columns if c in [
        'low_choppiness', 'smooth_momentum', 'strong_trend_persistence'
    ]]
    if ideal_flags and "scanner_risk_score" in d.columns:
        d["ideal_v1400_setup"] = (
            (d[ideal_flags].sum(axis=1) >= 2).astype(float) *
            (d["scanner_risk_score"].astype(float) > 2.0).astype(float)
        )
    
    # Standard over-extension detection (from base model)
    if "alert_rsi_14_1m" in d.columns:
        d["rsi_1m_overbought"] = (d["alert_rsi_14_1m"].astype(float) > 70).astype(float)
        d["rsi_1m_extreme"] = ((d["alert_rsi_14_1m"].astype(float) > 75) | 
                               (d["alert_rsi_14_1m"].astype(float) < 25)).astype(float)
    
    if "vwap_dist_pct" in d.columns:
        d["vwap_extended"] = (d["vwap_dist_pct"].astype(float).abs() > 1.0).astype(float)
    
    if "alert_vol_ratio" in d.columns:
        d["vol_ratio_moderate"] = ((d["alert_vol_ratio"].astype(float) >= 2.0) & 
                                   (d["alert_vol_ratio"].astype(float) <= 4.0)).astype(float)
    
    if "alert_atr_pct" in d.columns:
        d["atr_sweet_spot"] = ((d["alert_atr_pct"].astype(float) >= 0.3) & 
                               (d["alert_atr_pct"].astype(float) <= 0.8)).astype(float)
    
    if "five_min_green_bar_pct" in d.columns:
        d["green_bars_balanced"] = ((d["five_min_green_bar_pct"].astype(float) >= 50) & 
                                    (d["five_min_green_bar_pct"].astype(float) <= 70)).astype(float)

    return d


def split_by_time(df: pd.DataFrame, test_size: float = 0.2) -> Tuple[pd.DataFrame, pd.DataFrame]:
    """Time-aware split: sort by entry_ts_est and take last X% as test."""
    if "entry_ts_est" not in df.columns:
        raise KeyError(f"Missing 'entry_ts_est' column. Columns: {list(df.columns)}")

    d = df.sort_values("entry_ts_est").reset_index(drop=True)
    n = len(d)
    if test_size <= 0:
        return d.copy(), d.iloc[0:0].copy()
    cut = int(np.floor((1.0 - test_size) * n))
    train_df = d.iloc[:cut].copy()
    test_df = d.iloc[cut:].copy()
    return train_df, test_df


# -----------------------
# Training
# -----------------------

def build_model(numeric_features: List[str], use_baseline: bool = False) -> Pipeline:
    """Build XGBoost model optimized for v1400 (tight stops, high win rate)."""
    numeric_transformer = Pipeline(
        steps=[
            ("imputer", SimpleImputer(strategy="median")),
        ]
    )

    pre = ColumnTransformer(
        transformers=[
            ("num", numeric_transformer, numeric_features),
        ],
        remainder="drop",
    )

    if use_baseline:
        clf = LogisticRegression(
            max_iter=2000,
            class_weight="balanced",
            n_jobs=None,
        )
    else:
        # XGBoost tuned for v1400: Favor precision (tight stops = less tolerance for losers)
        clf = xgb.XGBClassifier(
            max_depth=4,
            learning_rate=0.05,
            n_estimators=250,  # More trees for nuanced patterns
            subsample=0.8,
            colsample_bytree=0.8,
            reg_alpha=0.2,  # Slightly higher L1 for feature selection
            reg_lambda=1.0,
            random_state=42,
            n_jobs=-1,
            eval_metric="auc",
        )

    return Pipeline([("pre", pre), ("clf", clf)])


def precision_at_k(y_true, y_proba, k=10):
    """Calculate precision@K: what % of top K picks are actual winners."""
    idx_sorted = np.argsort(-y_proba)
    top_k_idx = idx_sorted[:k]
    top_k_true = y_true.iloc[top_k_idx] if isinstance(y_true, pd.Series) else y_true[top_k_idx]
    return top_k_true.mean()


def dump_topk_examples(df: pd.DataFrame, model: Pipeline, win_threshold_pct: float, k: int = 10) -> None:
    """Show top K picks by model probability."""
    d = add_derived_features_v1400(df)

    numeric_cols = model.named_steps["pre"].transformers_[0][2]
    X = d.reindex(columns=numeric_cols)
    p = model.predict_proba(X)[:, 1]

    out = df.copy()
    out["win_prob"] = p
    out["is_win"] = (out["pnl_percent"].astype(float) >= float(win_threshold_pct)).astype(int)

    cols = [
        "symbol", "signal_type", "entry_type", "time_of_day",
        "entry_ts_est", "entry", "stop",
        "pnl_percent", "r_multiple",
        "win_prob", "is_win",
        "scanner_risk_score", "trend_smoothness", "choppiness_index",
    ]
    cols = [c for c in cols if c in out.columns]

    print(f"\n=== TOP {k} v1400 PICKS (by win_prob) ===")
    print(out.sort_values("win_prob", ascending=False).head(k)[cols].to_string(index=False))


# -----------------------
# Main training logic
# -----------------------

def do_train(
    start_dt: str,
    end_dt: str,
    table_name: str,
    win_threshold_pct: float,
    test_size: float,
    train_full: bool,
    use_baseline: bool,
    model_out: str,
    top_k: int = 10,
    debug: bool = False,
):
    cfg = get_db_config_from_env()
    engine = make_engine(cfg)

    print(f"[v1400] Loading v1400.0 trade alerts from {start_dt} to {end_dt} (table={table_name})...")
    df_raw = load_training_data(engine, start_dt, end_dt, table_name=table_name)
    print(f"[v1400] Loaded {len(df_raw)} v1400.0 alerts")

    if debug:
        print("\n=== LOADED COLUMNS ===")
        print(df_raw.columns.tolist())

    if df_raw.empty:
        print("[v1400] ERROR: No data loaded. Check date range and table name.")
        return

    # Create labels
    y = make_label(df_raw, win_threshold_pct)
    print(f"[v1400] Class distribution: {y.sum()} wins, {(~y).sum()} losses")

    # Add derived features
    df_feat = add_derived_features_v1400(df_raw)

    # Define feature columns (v1400 specific + standard)
    numeric_features = [
        # Standard features
        "alert_score", "alert_vol_ratio", "alert_atr", "alert_atr_pct",
        "daily_trend_5d_pct", "range_position_60m", "alert_rsi_14_1m",
        "five_min_directional_changes", "five_min_green_bar_pct", "five_min_net_progress",
        "consolidation_bars", "breakout_volume_ratio",
        "pct_below_intraday_high", "minutes_since_high",
        "price_velocity_5min", "price_velocity_10min", "failed_rally_count",
        
        # v1400 scanner features
        "scanner_trend_pct", "scanner_max_drawdown_pct", "scanner_risk_score",
        
        # v1400 computed features
        "bars_above_ema9_count", "total_bars", "avg_bar_body_size", "max_consecutive_green_bars",
        
        # 1m price context
        "omp_entry_price", "omp_open", "omp_high", "omp_low", "omp_volume",
        "vwap", "vwap_dist_pct", "above_vwap",
        "ema9", "ema21", "ema9_ema21_spread", "ema9_above_ema21",
        "omp_atr", "omp_atr_pct",
        
        # 5m price context
        "fmp_price", "fmp_open", "fmp_high", "fmp_low", "fmp_volume",
        "fmp_vwap", "fmp_vwap_dist_pct", "fmp_above_vwap",
        "fmp_ema9", "fmp_ema21", "fmp_ema_spread", "fmp_ema9_above_ema21",
        "fmp_atr", "fmp_atr_pct", "fmp_rsi_14",
        
        # Derived features
        "abs_vwap_dist_pct", "abs_ema_spread",
        "alert_rsi_centered", "fmp_rsi_centered",
        "trend_alignment_1m", "trend_alignment_5m",
        
        # v1400 derived features
        "trend_smoothness", "bars_above_ema9_pct", "choppiness_index",
        "v1400_quality_score", "combined_risk_quality",
        "clean_trend", "low_choppiness", "strong_trend_persistence",
        "smooth_momentum", "very_smooth_momentum", "clean_bars",
        "ideal_v1400_setup",
        
        # Standard flags
        "rsi_1m_overbought", "rsi_1m_extreme", "vwap_extended",
        "vol_ratio_moderate", "atr_sweet_spot", "green_bars_balanced", "choppy",
    ]

    # Filter to existing columns
    numeric_features = [c for c in numeric_features if c in df_feat.columns]
    print(f"[v1400] Using {len(numeric_features)} features")

    # Convert to numeric
    for col in numeric_features:
        df_feat[col] = pd.to_numeric(df_feat[col], errors="coerce")

    X = df_feat[numeric_features]

    # Build model
    model_type = "LogisticRegression" if use_baseline else "XGBoost"
    print(f"[v1400] Building {model_type} model...")
    model = build_model(numeric_features, use_baseline=use_baseline)

    # Train/test split logic
    if test_size <= 0:
        # No test split - train on 100%
        print(f"[v1400] Training on 100% of data ({len(X)} samples)...")
        
        # Calculate scale_pos_weight
        n_neg = (~y).sum()
        n_pos = y.sum()
        scale_pos_weight = n_neg / max(n_pos, 1)
        
        if not use_baseline:
            model.named_steps["clf"].set_params(scale_pos_weight=scale_pos_weight)
        
        model.fit(X, y)
        
        print(f"[v1400] Model trained on {len(X)} samples")
        print(f"[v1400] Class balance: {n_pos} wins, {n_neg} losses (scale_pos_weight={scale_pos_weight:.2f})")
        
        # Save model
        joblib.dump(model, model_out)
        print(f"[v1400] Model saved to {model_out}")
        
    else:
        # Split by time
        train_df, test_df = split_by_time(df_feat, test_size=test_size)
        X_train = train_df[numeric_features]
        y_train = y.loc[train_df.index]
        X_test = test_df[numeric_features]
        y_test = y.loc[test_df.index]
        
        print(f"[v1400] Train: {len(X_train)} samples")
        print(f"[v1400] Test:  {len(X_test)} samples")
        
        # Calculate scale_pos_weight from training set
        n_neg_train = (~y_train).sum()
        n_pos_train = y_train.sum()
        scale_pos_weight = n_neg_train / max(n_pos_train, 1)
        
        if not use_baseline:
            model.named_steps["clf"].set_params(scale_pos_weight=scale_pos_weight)
        
        print(f"[v1400] Training class balance: {n_pos_train} wins, {n_neg_train} losses")
        print(f"[v1400] scale_pos_weight={scale_pos_weight:.2f}")
        
        # Train on training set
        model.fit(X_train, y_train)
        
        # Evaluate on test set
        p_test = model.predict_proba(X_test)[:, 1]
        p_train = model.predict_proba(X_train)[:, 1]
        
        auc_test = roc_auc_score(y_test, p_test)
        auc_train = roc_auc_score(y_train, p_train)
        
        prec_at_k_test = precision_at_k(y_test, p_test, k=top_k)
        prec_at_k_train = precision_at_k(y_train, p_train, k=top_k)
        
        print("\n=== v1400 MODEL EVALUATION ===")
        print(f"Train AUC: {auc_train:.4f}")
        print(f"Test AUC:  {auc_test:.4f}")
        print(f"Train Precision@{top_k}: {prec_at_k_train*100:.1f}%")
        print(f"Test Precision@{top_k}:  {prec_at_k_test*100:.1f}%")
        
        # Classification report on test set
        y_pred_test = (p_test >= 0.5).astype(int)
        print("\n=== TEST SET CLASSIFICATION REPORT ===")
        print(classification_report(y_test, y_pred_test, target_names=["Loss", "Win"]))
        
        # Confusion matrix
        print("\n=== CONFUSION MATRIX (Test) ===")
        cm = confusion_matrix(y_test, y_pred_test)
        print(f"              Predicted Loss  Predicted Win")
        print(f"Actual Loss   {cm[0,0]:14d}  {cm[0,1]:13d}")
        print(f"Actual Win    {cm[1,0]:14d}  {cm[1,1]:13d}")
        
        # Show top K picks
        dump_topk_examples(test_df, model, win_threshold_pct, k=top_k)
        
        # Optionally retrain on 100% for final model
        if train_full:
            print(f"\n[v1400] --train-full enabled: Retraining on 100% of data for final model...")
            
            # Recalculate scale_pos_weight on full dataset
            n_neg_full = (~y).sum()
            n_pos_full = y.sum()
            scale_pos_weight_full = n_neg_full / max(n_pos_full, 1)
            
            if not use_baseline:
                model.named_steps["clf"].set_params(scale_pos_weight=scale_pos_weight_full)
            
            model.fit(X, y)
            print(f"[v1400] Final model trained on {len(X)} samples")
            print(f"[v1400] Full class balance: {n_pos_full} wins, {n_neg_full} losses (scale_pos_weight={scale_pos_weight_full:.2f})")
        
        # Save final model
        joblib.dump(model, model_out)
        print(f"\n[v1400] Model saved to {model_out}")


# -----------------------
# CLI
# -----------------------

def main():
    parser = argparse.ArgumentParser(
        description="Train Pipeline M v1400.0-specific ML model with clean trend features",
        formatter_class=argparse.RawDescriptionHelpFormatter,
    )
    sub = parser.add_subparsers(dest="command")

    train_parser = sub.add_parser("train", help="Train a v1400 model")
    train_parser.add_argument("--start", required=True, help="Start date (YYYY-MM-DD)")
    train_parser.add_argument("--end", required=True, help="End date (YYYY-MM-DD)")
    train_parser.add_argument("--table", default="trade_alerts", help="Trade alerts table name")
    train_parser.add_argument("--win-threshold", type=float, default=1.0, help="Win threshold (%)")
    train_parser.add_argument("--test-size", type=float, default=0.2, help="Test split size (0-1, 0=no split)")
    train_parser.add_argument("--train-full", action="store_true", help="After evaluation, retrain on 100%")
    train_parser.add_argument("--baseline", action="store_true", help="Use LogisticRegression instead of XGBoost")
    train_parser.add_argument("--model-out", default="python_ml/models/winner_model_v1400.joblib", help="Output model path")
    train_parser.add_argument("--top-k", type=int, default=10, help="Evaluate precision@K")
    train_parser.add_argument("--debug", action="store_true", help="Print loaded columns for debugging")

    args = parser.parse_args()

    if args.command == "train":
        do_train(
            start_dt=args.start,
            end_dt=args.end,
            table_name=args.table,
            win_threshold_pct=args.win_threshold,
            test_size=args.test_size,
            train_full=args.train_full,
            use_baseline=args.baseline,
            model_out=args.model_out,
            top_k=args.top_k,
            debug=args.debug,
        )
    else:
        parser.print_help()


if __name__ == "__main__":
    main()
