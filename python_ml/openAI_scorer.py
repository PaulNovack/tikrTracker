#!/usr/bin/env python3
import os, argparse, json
from pathlib import Path
from datetime import datetime
import pandas as pd
from dotenv import load_dotenv
from sqlalchemy import create_engine, text
import joblib

env_path = Path(__file__).resolve().parents[1] / ".env"
load_dotenv(env_path)

def make_engine():
    host = os.getenv("DB_HOST","127.0.0.1")
    port = os.getenv("DB_PORT","3306")
    db   = os.getenv("DB_DATABASE","laravelInvest")
    user = os.getenv("DB_USERNAME","laravel")
    pw   = os.getenv("DB_PASSWORD","laravel")
    return create_engine(f"mysql+pymysql://{user}:{pw}@{host}:{port}/{db}", pool_pre_ping=True)

SCORE_SQL = """
SELECT
  ta.id,
  ta.symbol,
  ta.asset_type,
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
  fmp.rsi_14              AS fmp_rsi_14
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
WHERE ta.id = :id
LIMIT 1
"""

UPDATE_SQL = """
UPDATE trade_alerts
SET ml_win_prob = :p,
    ml_scored_at = NOW(),
    ml_model_version = :ver
WHERE id = :id
"""

def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--id", type=int, required=True)
    ap.add_argument("--model", required=True, help="path to joblib payload")
    ap.add_argument("--write", action="store_true")
    args = ap.parse_args()

    payload = joblib.load(args.model)
    model = payload["model"]
    meta = payload["meta"]
    ver = meta.get("schema_version","winner_v1")

    engine = make_engine()
    df = pd.read_sql(text(SCORE_SQL), engine, params={"id": args.id})
    if df.empty:
        raise SystemExit("Trade alert not found.")

    X = df[meta["num_cols"] + meta["cat_cols"]]
    p = float(model.predict_proba(X)[:, 1][0])

    out = {"id": args.id, "symbol": df["symbol"].iloc[0], "win_prob": p, "model_version": ver}
    print(json.dumps(out))

    if args.write:
        with engine.begin() as conn:
            conn.execute(text(UPDATE_SQL), {"id": args.id, "p": p, "ver": ver})

if __name__ == "__main__":
    main()
