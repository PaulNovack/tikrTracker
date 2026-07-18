#!/usr/bin/env python3
import os, argparse
from pathlib import Path
from datetime import datetime

import numpy as np
import pandas as pd
from dotenv import load_dotenv
from sqlalchemy import create_engine, text

from sklearn.model_selection import TimeSeriesSplit
from sklearn.metrics import roc_auc_score, classification_report
from sklearn.pipeline import Pipeline
from sklearn.compose import ColumnTransformer
from sklearn.preprocessing import OneHotEncoder
from sklearn.impute import SimpleImputer
from xgboost import XGBClassifier
import joblib

# .env one directory above python_ml/
env_path = Path(__file__).resolve().parents[1] / ".env"
load_dotenv(env_path)

def make_engine():
    host = os.getenv("DB_HOST","127.0.0.1")
    port = os.getenv("DB_PORT","3306")
    db   = os.getenv("DB_DATABASE","laravelInvest")
    user = os.getenv("DB_USERNAME","laravel")
    pw   = os.getenv("DB_PASSWORD","laravel")
    return create_engine(f"mysql+pymysql://{user}:{pw}@{host}:{port}/{db}", pool_pre_ping=True)

TRAIN_SQL = """
SELECT
  ta.id,
  ta.symbol,
  ta.asset_type,
  ta.trading_date_est,
  ta.entry_ts_est,

  ta.signal_type,
  ta.entry_type,
  ta.version,
  ta.pipeline_run,

  ta.score                AS entry_score,
  ta.vol_ratio            AS vol_ratio_1m,
  ta.atr_pct              AS alert_atr_pct,
  ta.risk_pct,
  ta.consolidation_bars,
  ta.breakout_volume_ratio,
  ta.five_min_green_bar_pct,
  ta.five_min_net_progress,
  ta.five_min_directional_changes,
  ta.rsi_14_1m            AS alert_rsi_14_1m,
  ta.pct_below_intraday_high,
  ta.minutes_since_high,
  ta.price_velocity_5min,
  ta.price_velocity_10min,
  ta.failed_rally_count,
  ta.avg_dollar_volume_per_minute,

  HOUR(ta.entry_ts_est)   AS hour_of_day,
  DAYOFWEEK(ta.trading_date_est) AS day_of_week,
  MINUTE(ta.entry_ts_est) AS minute_of_hour,

  omp.vwap_dist_pct       AS omp_vwap_dist_pct,
  omp.above_vwap          AS omp_above_vwap,
  omp.ema9_ema21_spread   AS omp_ema_spread,
  omp.ema9_above_ema21    AS omp_ema9_above_ema21,
  omp.atr_pct             AS omp_atr_pct,

  fmp.vwap_dist_pct       AS fmp_vwap_dist_pct,
  fmp.above_vwap          AS fmp_above_vwap,
  fmp.ema9_ema21_spread   AS fmp_ema_spread,
  fmp.ema9_above_ema21    AS fmp_ema9_above_ema21,
  fmp.atr_pct             AS fmp_atr_pct,
  fmp.rsi_14              AS fmp_rsi_14,

  ta.pnl_percent
FROM trade_alerts ta
LEFT JOIN one_minute_prices omp
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
        AND f2.ts_est <= ta.entry_ts_est
        AND f2.trading_date_est = ta.trading_date_est
  )
WHERE ta.analyzed = 1
  AND ta.pnl_percent IS NOT NULL
  AND ta.entry_ts_est >= :start_ts
  AND ta.entry_ts_est <  :end_ts
ORDER BY ta.entry_ts_est ASC
"""

def make_labels(df: pd.DataFrame, win_threshold: float) -> pd.Series:
    # pnl_percent is DECIMAL(8,2) => 1.25 means +1.25%
    return (df["pnl_percent"].astype(float) >= float(win_threshold)).astype(int)

def build_pipeline(num_cols, cat_cols, scale_pos_weight: float):
    pre = ColumnTransformer(
        transformers=[
            ("num", Pipeline([
                ("imp", SimpleImputer(strategy="median")),
            ]), num_cols),
            ("cat", Pipeline([
                ("imp", SimpleImputer(strategy="most_frequent")),
                ("oh", OneHotEncoder(handle_unknown="ignore")),
            ]), cat_cols),
        ],
        remainder="drop",
        verbose_feature_names_out=False,
    )

    clf = XGBClassifier(
        n_estimators=600,
        max_depth=6,
        learning_rate=0.05,
        subsample=0.8,
        colsample_bytree=0.8,
        min_child_weight=3,
        gamma=0.1,
        reg_lambda=1.0,
        random_state=42,
        n_jobs=-1,
        tree_method="hist",
        eval_metric="auc",
        scale_pos_weight=scale_pos_weight,
    )

    return Pipeline([("pre", pre), ("clf", clf)])

def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--start-ts", required=True, help="YYYY-MM-DD or YYYY-MM-DD HH:MM:SS")
    ap.add_argument("--end-ts", required=True)
    ap.add_argument("--win-threshold", type=float, default=1.0, help="pnl_percent threshold (e.g. 1.0 = +1.0%)")
    ap.add_argument("--test-pct", type=float, default=0.2)
    ap.add_argument("--output", default="python_ml/models/winner_model.joblib")
    args = ap.parse_args()

    engine = make_engine()
    df = pd.read_sql(text(TRAIN_SQL), engine, params={"start_ts": args.start_ts, "end_ts": args.end_ts})

    if df.empty:
        raise SystemExit("No rows returned; check dates/analyzed flag.")

    # define columns
    cat_cols = ["signal_type", "entry_type", "version", "pipeline_run", "asset_type"]
    num_cols = [c for c in df.columns if c not in (["id","symbol","trading_date_est","entry_ts_est","pnl_percent"] + cat_cols)]

    y = make_labels(df, args.win_threshold)

    # time split
    split_idx = int(len(df) * (1 - args.test_pct))
    train_df, test_df = df.iloc[:split_idx].copy(), df.iloc[split_idx:].copy()
    y_train, y_test = y.iloc[:split_idx], y.iloc[split_idx:]

    # class weight from TRAIN ONLY
    wins = int(y_train.sum())
    losses = int(len(y_train) - wins)
    spw = losses / max(wins, 1)

    X_train = train_df[num_cols + cat_cols]
    X_test  = test_df[num_cols + cat_cols]

    model = build_pipeline(num_cols, cat_cols, spw)
    model.fit(X_train, y_train)

    proba = model.predict_proba(X_test)[:, 1]
    auc = roc_auc_score(y_test, proba)
    print(f"Test AUC: {auc:.4f}")
    print(classification_report(y_test, (proba >= 0.5).astype(int), target_names=["Loss","Win"]))

    out = Path(args.output)
    out.parent.mkdir(parents=True, exist_ok=True)

    payload = {
        "model": model,
        "meta": {
            "created_at": datetime.now().isoformat(),
            "win_threshold": args.win_threshold,
            "train_rows": int(len(train_df)),
            "test_rows": int(len(test_df)),
            "test_auc": float(auc),
            "num_cols": num_cols,
            "cat_cols": cat_cols,
            "schema_version": "winner_v1",
        }
    }
    joblib.dump(payload, out)
    print(f"Saved: {out}")

if __name__ == "__main__":
    main()
