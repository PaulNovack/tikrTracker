#!/usr/bin/env python3
"""
Train a model with PnL-weighted samples: bigger winners get higher weight.

This variant weights training samples by their actual PnL magnitude:
- 8% winner gets 8× the influence of a 1% winner
- Losers can be weighted by absolute loss or uniform weight

Otherwise identical to train_stock_winner_model.py
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
# Data loading (same as original)
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
),
bars AS (
    SELECT
        t.alert_id,
        omp.ts_est,
        omp.price
    FROM ta_base t
    JOIN one_minute_prices omp
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

    t.pnl_percent,
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
    fmp.rsi_14 AS fmp_rsi_14

FROM ta_base t

JOIN one_minute_prices omp
  ON omp.symbol = t.symbol
 AND omp.asset_type = t.asset_type
 AND omp.ts_est = t.entry_ts_est

LEFT JOIN rsi_calc r
  ON r.alert_id = t.alert_id
 AND r.ts_est = t.entry_ts_est

LEFT JOIN five_minute_prices fmp
  ON fmp.symbol = t.symbol
 AND fmp.asset_type = t.asset_type
 AND fmp.ts_est = (
      SELECT MAX(f2.ts_est)
      FROM five_minute_prices f2
      WHERE f2.symbol = t.symbol
        AND f2.asset_type = t.asset_type
        AND f2.ts_est <= t.signal_ts_est
 );
"""


def load_training_data(engine, start_dt: str, end_dt: str, table_name: str = "trade_alerts") -> pd.DataFrame:
    sql = TRAIN_SQL_TEMPLATE.format(table_name=table_name)
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
    if "pnl_percent" not in df.columns:
        raise KeyError(f"Missing 'pnl_percent' column in dataframe. Columns: {list(df.columns)}")
    return (df["pnl_percent"].astype(float) >= float(win_threshold_pct)).astype(int)


def compute_sample_weights(df: pd.DataFrame, win_threshold_pct: float, weight_losses: bool = True) -> np.ndarray:
    """
    Compute sample weights based on PnL magnitude.
    
    Winners: weighted by pnl_percent (8% gets weight 8.0)
    Losers: 
      - If weight_losses=True: weighted by abs(pnl_percent) 
      - If weight_losses=False: uniform weight of 1.0
    
    Ensures minimum weight of 0.1 to avoid zero-weight samples.
    """
    pnl = df["pnl_percent"].astype(float).to_numpy()
    is_win = (pnl >= float(win_threshold_pct))
    
    weights = np.ones(len(pnl))
    
    # Winners: weight by magnitude
    weights[is_win] = np.abs(pnl[is_win])
    
    # Losers: optionally weight by magnitude of loss
    if weight_losses:
        weights[~is_win] = np.abs(pnl[~is_win])
    else:
        weights[~is_win] = 1.0
    
    # Ensure minimum weight
    weights = np.maximum(weights, 0.1)
    
    return weights


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

    return d


def split_by_time(df: pd.DataFrame, test_size: float = 0.2) -> Tuple[pd.DataFrame, pd.DataFrame]:
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
# Training (MODIFIED for sample weights)
# -----------------------

def build_model(numeric_features: List[str], use_baseline: bool = False) -> Pipeline:
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
            class_weight=None,  # We'll use sample_weight instead
            n_jobs=None,
        )
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
            use_label_encoder=False,
        )

    return Pipeline(steps=[("pre", pre), ("clf", clf)])


def precision_at_k(y_true: np.ndarray, y_prob: np.ndarray, k: int) -> float:
    k = int(min(k, len(y_true)))
    if k <= 0:
        return float("nan")
    idx = np.argsort(-y_prob)[:k]
    return float(np.mean(y_true[idx]))


def train_full_model(
    df: pd.DataFrame,
    win_threshold_pct: float,
    baseline: bool,
    numeric_cols: List[str],
    weight_losses: bool = True,
) -> Pipeline:
    """
    Train on 100% of data with PnL-weighted samples.
    """
    y = make_label(df, win_threshold_pct).to_numpy()
    X = df[numeric_cols]
    weights = compute_sample_weights(df, win_threshold_pct, weight_losses)

    model = build_model(numeric_cols, use_baseline=baseline)

    num_negative = int(np.sum(y == 0))
    num_positive = int(np.sum(y == 1))
    avg_win_weight = float(np.mean(weights[y == 1]))
    avg_loss_weight = float(np.mean(weights[y == 0]))
    
    print(f"\n[TRAIN FULL] Class distribution: {num_positive} wins, {num_negative} losses")
    print(f"[TRAIN FULL] Avg winner weight: {avg_win_weight:.2f}, Avg loser weight: {avg_loss_weight:.2f}")

    model.fit(X, y, clf__sample_weight=weights)
    return model


def train_and_eval(
    df: pd.DataFrame,
    win_threshold_pct: float,
    top_k: int,
    baseline: bool,
    test_size: float = 0.2,
    weight_losses: bool = True,
) -> Tuple[Pipeline, dict, pd.DataFrame]:
    df2 = add_derived_features(df)

    if "pnl_percent" not in df2.columns:
        raise KeyError(f"Expected pnl_percent from SQL but it is missing. Columns: {list(df2.columns)}")

    drop_cols = {
        "alert_id", "symbol", "asset_type", "trading_date_est",
        "as_of_ts_est", "signal_ts_est", "entry_ts_est",
        "pnl_percent", "pnl_dollar", "r_multiple", "exit_reason",
        "hold_time_minutes",
    }
    feature_cols = [c for c in df2.columns if c not in drop_cols]
    numeric_cols = [c for c in feature_cols if pd.api.types.is_numeric_dtype(df2[c])]

    train_df, test_df = split_by_time(df2, test_size=test_size)

    y_train = make_label(train_df, win_threshold_pct).to_numpy()
    X_train = train_df[numeric_cols]
    weights_train = compute_sample_weights(train_df, win_threshold_pct, weight_losses)

    model = build_model(numeric_cols, use_baseline=baseline)

    num_negative = int(np.sum(y_train == 0))
    num_positive = int(np.sum(y_train == 1))
    avg_win_weight = float(np.mean(weights_train[y_train == 1]))
    avg_loss_weight = float(np.mean(weights_train[y_train == 0]))
    
    print(f"\nClass distribution: {num_positive} wins, {num_negative} losses")
    print(f"Winner avg weight: {avg_win_weight:.2f}, Loser avg weight: {avg_loss_weight:.2f}")

    # Pass sample_weight to the classifier via pipeline
    model.fit(X_train, y_train, clf__sample_weight=weights_train)

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
            "weighted_training": True,
            "weight_losses": weight_losses,
        }
        topk_df = pd.DataFrame()
        return model, metrics, topk_df

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
        "weighted_training": True,
        "weight_losses": weight_losses,
    }

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
# CLI
# -----------------------

def cmd_train(args):
    if getattr(args, "no_test_split", False):
        args.test_size = 0.0

    cfg = get_db_config_from_env()
    engine = make_engine(cfg)

    df = load_training_data(engine, args.start, args.end, args.table)

    if args.debug:
        print("Loaded rows:", len(df))
        print("Columns:", list(df.columns))

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

    # Train with weighted samples
    model, metrics, topk_df = train_and_eval(
        df=df,
        win_threshold_pct=args.win_threshold,
        top_k=args.top_k,
        baseline=args.baseline,
        test_size=args.test_size,
        weight_losses=args.weight_losses,
    )

    # Optionally retrain on 100% data
    if args.train_full:
        df2 = add_derived_features(df)
        numeric_cols = metrics["features_used"]
        model = train_full_model(
            df=df2,
            win_threshold_pct=args.win_threshold,
            baseline=args.baseline,
            numeric_cols=numeric_cols,
            weight_losses=args.weight_losses,
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
            "weighted_training": True,
            "weight_losses": args.weight_losses,
            "metrics": metrics,
        },
    }
    joblib.dump(payload, args.model_out)

    print("\n✅ Saved WEIGHTED model to:", args.model_out)

    print("\n=== Metrics (evaluation split) ===")
    print("Rows train:", metrics["rows_train"], " Rows test:", metrics["rows_test"])
    print("Test AUC:", metrics["test_auc"])
    print(f"Precision@{args.top_k}:", metrics["precision_at_k"])
    print("Test win rate:", metrics["win_rate_test"])
    print("Weighted training:", metrics["weighted_training"])
    print("Weight losses:", metrics["weight_losses"])

    if metrics["confusion_matrix"]:
        print("\nConfusion matrix [ [TN, FP], [FN, TP] ]:", metrics["confusion_matrix"])
        print("\nClassification report:\n", metrics["classification_report"])
        if not topk_df.empty:
            print(f"\n=== TOP {args.top_k} TEST PICKS (by win_prob) ===")
            print(topk_df.to_string(index=False))
    else:
        print("\n(No test split; test_size=0)")

    if args.train_full:
        print("\n=== Saved model is FULL-DATA WEIGHTED model ===")
        if args.test_size > 0:
            print("Evaluation metrics above are from the holdout split.")


def main():
    ap = argparse.ArgumentParser()
    sub = ap.add_subparsers(dest="cmd", required=True)

    ap_train = sub.add_parser("train", help="Train with PnL-weighted samples")
    ap_train.add_argument("--start", required=True, help="EST datetime (YYYY-MM-DD or YYYY-MM-DD HH:MM:SS)")
    ap_train.add_argument("--end", required=True, help="EST datetime (YYYY-MM-DD or YYYY-MM-DD HH:MM:SS)")
    ap_train.add_argument("--table", default="trade_alerts", choices=["trade_alerts", "trade_alerts_unfiltered"],
                          help="Table to train from")
    ap_train.add_argument("--win-threshold", type=float, default=1.0, help="Win if pnl_percent >= threshold")
    ap_train.add_argument("--top-k", type=int, default=10, help="Evaluate precision@K on the test split")
    ap_train.add_argument("--baseline", action="store_true", help="Use logistic regression baseline")

    ap_train.add_argument("--test-size", type=float, default=0.2,
                          help="Time-based holdout fraction for evaluation (e.g. 0.2 = last 20 percent is test). Use 0 for no split.")

    ap_train.add_argument("--train-full", action="store_true",
                          help="After eval, retrain final model on 100 percent of rows and save that model")

    ap_train.add_argument("--no-test-split", dest="no_test_split", action="store_true",
                          help="Disable holdout split (same as --test-size 0)")

    # NEW: control whether to weight losses by magnitude
    ap_train.add_argument("--weight-losses", dest="weight_losses", action="store_true", default=True,
                          help="Weight losers by abs(pnl_percent) - default True")
    ap_train.add_argument("--no-weight-losses", dest="weight_losses", action="store_false",
                          help="Give losers uniform weight of 1.0")

    ap_train.add_argument("--model-out", default="models/winner_model_weighted.joblib")
    ap_train.add_argument("--debug", action="store_true", help="Print debug info (rows + columns loaded)")
    ap_train.set_defaults(func=cmd_train)

    args = ap.parse_args()
    args.func(args)


if __name__ == "__main__":
    main()
