#!/usr/bin/env python3
"""
Train a model to learn patterns of winning trades from your MySQL dataset.

✅ Uses actual Alpaca fill P&L when available (via alpaca_orders joined on alert_id in notes)
✅ Falls back to `trade_alerts.pnl_percent` (BT-simulated) for alerts without real fills
✅ Joins `one_minute_prices` using `ts_est` (because trade_alerts timestamps are EST)
✅ Optionally brings in 5-minute context from `five_minute_prices` near the signal time
✅ Loads Laravel-style DB credentials from a `.env` file located ONE directory above this script

Example layout:
  /project/.env
  /project/python_ml/train_stock_winner_model.py   <-- this script

Updates in this version:
- Adds support for BOTH `--no-test-split` (legacy) and `--test-size` (new).
- Adds `--train-full` option (retrain final model on 100% after evaluation).
- Adds defensive checks + optional `--debug` to print returned columns.
- Avoids StandardScaler for XGBoost (trees don’t need scaling); keeps scaling for LogisticRegression baseline.
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


def dump_topk_examples(df: pd.DataFrame, model: Pipeline, win_threshold_pct: float, k: int = 10) -> None:
    d = add_derived_features(df)

    # columns model expects
    numeric_cols = model.named_steps["pre"].transformers_[0][2]
    X = d.reindex(columns=numeric_cols)
    p = model.predict_proba(X)[:, 1]

    out = df.copy()
    out["win_prob"] = p
    out["is_win"] = (out["pnl_percent"].astype(float) >= float(win_threshold_pct)).astype(int)

    cols = [
        "symbol", "signal_type", "entry_type", "time_of_day",
        "entry_ts_est", "entry", "stop",
        "pnl_percent", "actual_pnl_pct", "has_actual_fill", "r_multiple",
        "win_prob", "is_win",
    ]
    cols = [c for c in cols if c in out.columns]

    print(f"\n=== TOP {k} PICKS (by win_prob) ===")
    print(out.sort_values("win_prob", ascending=False).head(k)[cols].to_string(index=False))


def load_parent_env() -> None:
    """
    Load .env from the directory ABOVE this script file.

    Example:
      /project/python_ml/train_stock_winner_model.py
      /project/.env   <-- loaded
    """
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
    # pymysql is the simplest MySQL driver for SQLAlchemy
    url = f"mysql+pymysql://{cfg.user}:{cfg.password}@{cfg.host}:{cfg.port}/{cfg.name}"
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

-- Actual Alpaca fills matched by alert_id stored in buy order notes.
-- One row per real closed trade: buy + matched sell via parent_alpaca_order_id.
actual_fills AS (
    SELECT
        CAST(REGEXP_REPLACE(bo.notes, '^.*alert_id:([0-9]+).*$', '$1') AS UNSIGNED) AS alert_id,
        bo.filled_avg_price                                         AS actual_buy_price,
        so.filled_avg_price                                         AS actual_sell_price,
        (so.filled_avg_price - bo.filled_avg_price)
            / bo.filled_avg_price * 100                             AS actual_pnl_pct
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
),
bars AS (
    -- Pull a small window of 1m bars BEFORE/UP TO entry to compute RSI per alert_id
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

    -- 1-minute features AT ENTRY (join via ts_est)
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

    -- 5-minute context near signal time (optional)
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
 );
"""


def load_training_data(engine, start_dt: str, end_dt: str, table_name: str = "trade_alerts", pipeline_run: str = None) -> pd.DataFrame:
    # Add pipeline filter if specified
    pipeline_filter = ""
    if pipeline_run:
        # Support comma-separated pipelines: --pipeline A,B,C,D
        pipelines = [p.strip().upper() for p in pipeline_run.split(',')]
        if len(pipelines) == 1:
            pipeline_filter = f"AND ta.pipeline_run = '{pipelines[0]}'"
        else:
            pipeline_list = "', '".join(pipelines)
            pipeline_filter = f"AND ta.pipeline_run IN ('{pipeline_list}')"
    
    sql = TRAIN_SQL_TEMPLATE.format(table_name=table_name, pipeline_filter=pipeline_filter)
    with engine.connect() as conn:
        df = pd.read_sql(
            text(sql),
            conn,
            params={"start_dt": start_dt, "end_dt": end_dt},
        )
    return df


# -----------------------
# Feature engineering + labels
# -----------------------

def make_label(df: pd.DataFrame, win_threshold_pct: float) -> pd.Series:
    # Binary label: win if pnl_percent >= threshold.
    # pnl_percent is already the hybrid column: actual Alpaca fill P&L when available,
    # BT-simulated pnl_percent otherwise.
    if "pnl_percent" not in df.columns:
        raise KeyError(f"Missing 'pnl_percent' column in dataframe. Columns: {list(df.columns)}")

    if "has_actual_fill" in df.columns:
        n_actual = int(df["has_actual_fill"].sum())
        n_bt = len(df) - n_actual
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


def add_derived_features(df: pd.DataFrame) -> pd.DataFrame:
    d = df.copy()

    # Robust safe transforms (no lookahead)
    if "vwap_dist_pct" in d.columns:
        d["abs_vwap_dist_pct"] = d["vwap_dist_pct"].astype(float).abs()

    if "ema9_ema21_spread" in d.columns:
        d["abs_ema_spread"] = d["ema9_ema21_spread"].astype(float).abs()

    # RSI transforms (both 1m stored on alert and 5m RSI)
    if "alert_rsi_14_1m" in d.columns:
        d["alert_rsi_centered"] = (d["alert_rsi_14_1m"].astype(float) - 50.0) / 50.0

    if "fmp_rsi_14" in d.columns:
        d["fmp_rsi_centered"] = (d["fmp_rsi_14"].astype(float) - 50.0) / 50.0

    # Simple alignment ideas
    if "above_vwap" in d.columns and "ema9_above_ema21" in d.columns:
        d["trend_alignment_1m"] = (
            d["above_vwap"].fillna(0).astype(float) * d["ema9_above_ema21"].fillna(0).astype(float)
        )

    if "fmp_above_vwap" in d.columns and "fmp_ema9_above_ema21" in d.columns:
        d["trend_alignment_5m"] = (
            d["fmp_above_vwap"].fillna(0).astype(float) * d["fmp_ema9_above_ema21"].fillna(0).astype(float)
        )

    # If you have both 1m and 5m spreads, compare them
    if "ema9_ema21_spread" in d.columns and "fmp_ema_spread" in d.columns:
        d["spread_1m_minus_5m"] = d["ema9_ema21_spread"].astype(float) - d["fmp_ema_spread"].astype(float)

    # ========== NEW: OVER-EXTENSION DETECTION FEATURES ==========
    # These help the model identify setups that are too extended and prone to reversal
    
    # 1. Overbought/Oversold RSI flags (70/30 thresholds)
    if "alert_rsi_14_1m" in d.columns:
        d["rsi_1m_overbought"] = (d["alert_rsi_14_1m"].astype(float) > 70).astype(float)
        d["rsi_1m_oversold"] = (d["alert_rsi_14_1m"].astype(float) < 30).astype(float)
        d["rsi_1m_extreme"] = ((d["alert_rsi_14_1m"].astype(float) > 75) | 
                               (d["alert_rsi_14_1m"].astype(float) < 25)).astype(float)
    
    if "fmp_rsi_14" in d.columns:
        d["rsi_5m_overbought"] = (d["fmp_rsi_14"].astype(float) > 70).astype(float)
        d["rsi_5m_oversold"] = (d["fmp_rsi_14"].astype(float) < 30).astype(float)
    
    # 2. VWAP extension flags (>1% above VWAP = extended)
    if "vwap_dist_pct" in d.columns:
        d["vwap_extended"] = (d["vwap_dist_pct"].astype(float).abs() > 1.0).astype(float)
        d["vwap_very_extended"] = (d["vwap_dist_pct"].astype(float).abs() > 2.0).astype(float)
    
    # 3. Volume ratio flags (very high volume can signal exhaustion)
    if "alert_vol_ratio" in d.columns:
        d["vol_ratio_extreme"] = (d["alert_vol_ratio"].astype(float) > 5.0).astype(float)
        d["vol_ratio_moderate"] = ((d["alert_vol_ratio"].astype(float) >= 2.0) & 
                                   (d["alert_vol_ratio"].astype(float) <= 4.0)).astype(float)
    
    # 4. ATR flags (too low ATR = choppy, too high = volatile/risky)
    if "alert_atr_pct" in d.columns:
        d["atr_too_low"] = (d["alert_atr_pct"].astype(float) < 0.3).astype(float)
        d["atr_too_high"] = (d["alert_atr_pct"].astype(float) > 2.0).astype(float)
        d["atr_sweet_spot"] = ((d["alert_atr_pct"].astype(float) >= 0.5) & 
                               (d["alert_atr_pct"].astype(float) <= 1.2)).astype(float)
    
    # 5. Green bar percentage flags (too many green bars = extended)
    if "five_min_green_bar_pct" in d.columns:
        d["green_bars_high"] = (d["five_min_green_bar_pct"].astype(float) > 75).astype(float)
        d["green_bars_balanced"] = ((d["five_min_green_bar_pct"].astype(float) >= 50) & 
                                    (d["five_min_green_bar_pct"].astype(float) <= 70)).astype(float)
    
    # 6. Directional changes (high choppiness = risky)
    if "five_min_directional_changes" in d.columns:
        d["choppy"] = (d["five_min_directional_changes"].astype(float) > 6).astype(float)
        d["clean_trend"] = (d["five_min_directional_changes"].astype(float) <= 4).astype(float)
    
    # 7. Distance from intraday high (catching the high = risky)
    if "pct_below_intraday_high" in d.columns:
        d["near_high"] = (d["pct_below_intraday_high"].astype(float) < 0.5).astype(float)
        d["off_highs"] = (d["pct_below_intraday_high"].astype(float) > 2.0).astype(float)
    
    # 8. Composite "over-extended" score (sum of warning flags)
    warning_cols = [c for c in d.columns if c in [
        'rsi_1m_overbought', 'rsi_5m_overbought', 'vwap_extended', 
        'vol_ratio_extreme', 'green_bars_high', 'near_high', 'rsi_1m_extreme'
    ]]
    if warning_cols:
        d["overextension_score"] = d[warning_cols].sum(axis=1).astype(float)
    
    # 9. Composite "healthy setup" score (sum of positive flags)
    healthy_cols = [c for c in d.columns if c in [
        'vol_ratio_moderate', 'atr_sweet_spot', 'green_bars_balanced', 
        'clean_trend', 'off_highs'
    ]]
    if healthy_cols:
        d["healthy_setup_score"] = d[healthy_cols].sum(axis=1).astype(float)

    return d


def split_by_time(df: pd.DataFrame, test_size: float = 0.2) -> Tuple[pd.DataFrame, pd.DataFrame]:
    """
    Time-aware split: sort by entry_ts_est and take the last X% as test.
    Avoids leakage from random splitting.
    """
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
    """
    Two options:
    - baseline logistic regression (fast + interpretable) -> uses scaling
    - XGBoost (strong non-linear model) -> no scaling needed
    """
    if use_baseline:
        numeric_transformer = Pipeline(
            steps=[
                ("imputer", SimpleImputer(strategy="median")),
                ("scaler", StandardScaler()),
            ]
        )
    else:
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
        # XGBoost with scale_pos_weight for class imbalance
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
            use_label_encoder=False,  # harmless; xgboost warns it's unused in recent versions
        )

    return Pipeline(steps=[("pre", pre), ("clf", clf)])


def precision_at_k(y_true: np.ndarray, y_prob: np.ndarray, k: int) -> float:
    k = int(min(k, len(y_true)))
    if k <= 0:
        return float("nan")
    idx = np.argsort(-y_prob)[:k]
    return float(np.mean(y_true[idx]))


def build_sample_weights(df: pd.DataFrame, actual_fill_weight: float) -> np.ndarray:
    """Returns a sample weight array: actual_fill_weight for rows with real fills, 1.0 otherwise."""
    if "has_actual_fill" in df.columns and actual_fill_weight != 1.0:
        weights = np.where(df["has_actual_fill"].to_numpy() == 1, actual_fill_weight, 1.0)
        n_actual = int((df["has_actual_fill"] == 1).sum())
        print(f"[weights] Boosting {n_actual} actual-fill rows by {actual_fill_weight}x")
        return weights
    return np.ones(len(df))


def train_full_model(
    df: pd.DataFrame,
    win_threshold_pct: float,
    baseline: bool,
    numeric_cols: List[str],
    actual_fill_weight: float = 1.0,
) -> Pipeline:
    """
    Train on 100% of data (no test split). Use after you've evaluated your setup.
    """
    y = make_label(df, win_threshold_pct).to_numpy()
    X = df[numeric_cols]
    sample_weight = build_sample_weights(df, actual_fill_weight)

    model = build_model(numeric_cols, use_baseline=baseline)

    if not baseline:
        num_negative = int(np.sum(y == 0))
        num_positive = int(np.sum(y == 1))
        scale_pos_weight = num_negative / num_positive if num_positive > 0 else 1.0
        print(f"\n[TRAIN FULL] Class distribution: {num_positive} wins, {num_negative} losses")
        print(f"[TRAIN FULL] Using scale_pos_weight={scale_pos_weight:.2f} for XGBoost")
        model.named_steps["clf"].set_params(scale_pos_weight=scale_pos_weight)

    model.fit(X, y, clf__sample_weight=sample_weight)
    return model


def train_and_eval(
    df: pd.DataFrame,
    win_threshold_pct: float,
    top_k: int,
    baseline: bool,
    test_size: float = 0.2,
    actual_fill_weight: float = 1.0,
) -> Tuple[Pipeline, dict, pd.DataFrame]:
    df2 = add_derived_features(df)

    # Ensure label exists early (better error than late KeyError)
    if "pnl_percent" not in df2.columns:
        raise KeyError(f"Expected pnl_percent from SQL but it is missing. Columns: {list(df2.columns)}")

    drop_cols = {
        "alert_id", "symbol", "asset_type", "trading_date_est",
        "as_of_ts_est", "signal_ts_est", "entry_ts_est",

        "pnl_percent", "pnl_dollar", "r_multiple", "exit_reason",

        # 🚫 POST-TRADE / LOOKAHEAD — not available at scoring time
        "hold_time_minutes",
        "actual_pnl_pct", "actual_buy_price", "actual_sell_price", "bt_pnl_percent",

        # 🚫 FILL LEAKAGE — live scoring always sets this to 0 (unfilled),
        # so training on it creates systematic miscalibration: the model
        # learns filled=winner but never sees filled=1 in production.
        "has_actual_fill",
    }
    feature_cols = [c for c in df2.columns if c not in drop_cols]
    numeric_cols = [c for c in feature_cols if pd.api.types.is_numeric_dtype(df2[c])]

    train_df, test_df = split_by_time(df2, test_size=test_size)

    y_train = make_label(train_df, win_threshold_pct).to_numpy()
    X_train = train_df[numeric_cols]
    sample_weight_train = build_sample_weights(train_df, actual_fill_weight)

    # Fit model on train split
    model = build_model(numeric_cols, use_baseline=baseline)

    if not baseline:
        num_negative = int(np.sum(y_train == 0))
        num_positive = int(np.sum(y_train == 1))
        scale_pos_weight = num_negative / num_positive if num_positive > 0 else 1.0
        print(f"\nClass distribution: {num_positive} wins, {num_negative} losses")
        print(f"Using scale_pos_weight={scale_pos_weight:.2f} for XGBoost")
        model.named_steps["clf"].set_params(scale_pos_weight=scale_pos_weight)

    model.fit(X_train, y_train, clf__sample_weight=sample_weight_train)

    # If no test split, return train-only metadata and an empty top-k table
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
        topk_df = pd.DataFrame()
        return model, metrics, topk_df

    # Evaluate on test split
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

    # Build a readable Top-K table from the TEST set
    scored = test_df.copy()
    scored["win_prob"] = p_test
    scored["is_win"] = (scored["pnl_percent"].astype(float) >= float(win_threshold_pct)).astype(int)

    show_cols = [
        "symbol",
        "signal_type",
        "entry_type",
        "time_of_day",
        "entry_ts_est",
        "entry",
        "stop",
        "pnl_percent",
        "r_multiple",
        "win_prob",
        "is_win",
        "pipeline_run",
        "version",
    ]
    show_cols = [c for c in show_cols if c in scored.columns]

    topk_df = (
        scored.sort_values("win_prob", ascending=False)
              .head(int(top_k))
              .loc[:, show_cols]
              .reset_index(drop=True)
    )

    return model, metrics, topk_df


# -----------------------
# Save / Load / Score candidates
# -----------------------
# Important: caller passes EST timestamp; we query by ts_est.
CANDIDATES_SQL = """
SELECT
    omp.symbol,
    omp.asset_type,
    omp.ts_est AS as_of_ts_est,

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
WHERE omp.ts_est = :as_of_ts_est
;
"""


def load_candidates(engine, as_of_ts_est: str) -> pd.DataFrame:
    with engine.connect() as conn:
        df = pd.read_sql(text(CANDIDATES_SQL), conn, params={"as_of_ts_est": as_of_ts_est})
    return df


def score_candidates(model: Pipeline, candidates: pd.DataFrame, top_n: int) -> pd.DataFrame:
    d = add_derived_features(candidates)

    # Use the exact feature list the model was trained on:
    numeric_cols = model.named_steps["pre"].transformers_[0][2]

    X = d.reindex(columns=numeric_cols)
    proba = model.predict_proba(X)[:, 1]

    out = candidates.copy()
    out["win_prob"] = proba
    out = out.sort_values("win_prob", ascending=False).head(int(top_n)).reset_index(drop=True)
    return out


# -----------------------
# CLI
# -----------------------

def cmd_train(args):
    # Back-compat: if --no-test-split is used, force test_size=0
    if getattr(args, "no_test_split", False):
        args.test_size = 0.0

    cfg = get_db_config_from_env()
    engine = make_engine(cfg)

    df = load_training_data(engine, args.start, args.end, args.table, args.pipeline)

    if args.debug:
        print("Loaded rows:", len(df))
        print("Columns:", list(df.columns))
        if args.pipeline:
            pipelines = [p.strip().upper() for p in args.pipeline.split(',')]
            if len(pipelines) == 1:
                print(f"Filtered to pipeline: {pipelines[0]}")
            else:
                print(f"Filtered to pipelines: {', '.join(pipelines)}")

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
            f"Columns returned: {list(df.columns)}\n"
            "This usually means you are running a different script version than you edited, "
            "or the SQL query was modified."
        )

    actual_fill_weight = getattr(args, "actual_fill_weight", 1.0)
    print(f"[config] actual_fill_weight={actual_fill_weight}x for rows with real Alpaca fills")

    # 1) Evaluate with time-split (or train-only if test_size=0)
    model, metrics, topk_df = train_and_eval(
        df=df,
        win_threshold_pct=args.win_threshold,
        top_k=args.top_k,
        baseline=args.baseline,
        test_size=args.test_size,
        actual_fill_weight=actual_fill_weight,
    )

    # 2) Optionally retrain FINAL model on 100% of rows (recommended for deployment)
    if args.train_full:
        df2 = add_derived_features(df)
        numeric_cols = metrics["features_used"]  # ensure exact same feature list
        model = train_full_model(
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
            "metrics": metrics,
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
    payload = joblib.load(args.model_in)
    model = payload["model"]

    cfg = get_db_config_from_env()
    engine = make_engine(cfg)

    cand = load_candidates(engine, args.as_of_ts_est)
    if cand.empty:
        raise SystemExit(
            "No candidates returned. Check:\n"
            "- You passed an EST timestamp\n"
            "- one_minute_prices.ts_est has that exact minute\n"
        )

    ranked = score_candidates(model, cand, top_n=args.top_n)
    print(ranked.to_string(index=False))


def main():
    ap = argparse.ArgumentParser()
    sub = ap.add_subparsers(dest="cmd", required=True)

    ap_train = sub.add_parser("train", help="Train a winner model from trade_alerts outcomes")
    ap_train.add_argument("--start", required=True, help="EST datetime (YYYY-MM-DD or YYYY-MM-DD HH:MM:SS)")
    ap_train.add_argument("--end", required=True, help="EST datetime (YYYY-MM-DD or YYYY-MM-DD HH:MM:SS)")
    ap_train.add_argument("--table", default="trade_alerts", choices=["trade_alerts", "trade_alerts_unfiltered"],
                          help="Table to train from")
    ap_train.add_argument("--pipeline", default=None, help="Filter to specific pipeline(s) - single: 'M' or multiple: 'A,B,C,D'")
    ap_train.add_argument("--win-threshold", type=float, default=1.0, help="Win if pnl_percent >= threshold")
    ap_train.add_argument("--top-k", type=int, default=10, help="Evaluate precision@K on the test split")
    ap_train.add_argument("--baseline", action="store_true", help="Use logistic regression baseline")

    # NEW: time-split evaluation fraction (0 = no split)
    ap_train.add_argument("--test-size", type=float, default=0.2,
                          help="Time-based holdout fraction for evaluation (e.g. 0.2 = last 20 percent is test). Use 0 for no split.")

    # NEW: retrain on full data after evaluation and save that final model
    ap_train.add_argument("--train-full", action="store_true",
                          help="After eval, retrain final model on 100 percent of rows and save that model")

    # BACK-COMPAT: legacy flag used in older runs
    ap_train.add_argument("--no-test-split", dest="no_test_split", action="store_true",
                          help="Disable holdout split (same as --test-size 0)")

    ap_train.add_argument("--model-out", default="models/winner_model.joblib")
    ap_train.add_argument("--actual-fill-weight", type=float, default=1.0,
                          help="Sample weight multiplier for rows with actual Alpaca fills (default: 10x)")
    ap_train.add_argument("--debug", action="store_true", help="Print debug info (rows + columns loaded)")
    ap_train.set_defaults(func=cmd_train)

    ap_score = sub.add_parser("score", help="Score candidates at a specific EST minute timestamp (uses one_minute_prices.ts_est)")
    ap_score.add_argument("--model-in", required=True)
    ap_score.add_argument("--as-of-ts-est", dest="as_of_ts_est", required=True,
                          help="EST datetime (YYYY-MM-DD HH:MM:SS) that matches one_minute_prices.ts_est")
    ap_score.add_argument("--top-n", type=int, default=10)
    ap_score.set_defaults(func=cmd_score)

    args = ap.parse_args()
    args.func(args)


if __name__ == "__main__":
    main()
