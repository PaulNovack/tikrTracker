#!/usr/bin/env python3
"""
score_single_alert_live.py — Re-scores an alert using the CURRENT 1-minute bar.

Unlike score_single_alert_v2.py which uses entry_ts_est (the historical bar at
signal detection time), this script accepts --current-ts to join one_minute_prices
at the actual time of order entry. This gives an accurate ML score based on what
the market looks like RIGHT NOW rather than 5-15 minutes ago.

Usage:
    python score_single_alert_live.py \
        --alert-id 12345 \
        --table trade_alerts \
        --model-in python_ml/models/winner_model_pipeline_k.joblib \
        --current-ts "2026-05-11 12:18:00"
"""
import os
import argparse
from pathlib import Path

import pandas as pd
import joblib
from sqlalchemy import create_engine, text
from sqlalchemy.engine import URL
from dotenv import load_dotenv


def load_parent_env() -> None:
    script_path = Path(__file__).resolve()
    env_path = script_path.parents[2] / ".env"
    if env_path.exists():
        load_dotenv(dotenv_path=env_path, override=False)


def _get_benchmark_symbol() -> str:
    load_parent_env()
    return os.environ.get("TRADING_MARKET_BENCHMARK_SYMBOL", "QQQ")


def make_engine():
    load_parent_env()
    host     = os.environ.get("DB_HOST", "127.0.0.1")
    port     = int(os.environ.get("DB_PORT", "3306"))
    name     = os.environ.get("DB_DATABASE", "laravelInvest")
    user     = os.environ.get("DB_USERNAME", "laravel")
    password = os.environ.get("DB_PASSWORD", "laravel")
    url = URL.create(
        "mysql+pymysql",
        username=user,
        password=password,
        host=host,
        port=port,
        database=name,
    )
    return create_engine(url, pool_pre_ping=True)


def add_derived_features(df: pd.DataFrame) -> pd.DataFrame:
    """Must stay in sync with training script and score_single_alert_v2.py."""
    if "omp_entry_price" in df.columns and "fmp_price" in df.columns:
        df["spread_1m_minus_5m"] = df["omp_entry_price"].astype(float) - df["fmp_price"].astype(float)

    if "omp_entry_price" in df.columns and "vwap" in df.columns:
        vwap = df["vwap"].astype(float)
        price = df["omp_entry_price"].astype(float)
        df["price_above_vwap"] = (price > vwap).astype(float)

    if "omp_atr" in df.columns and "omp_entry_price" in df.columns:
        atr   = df["omp_atr"].astype(float)
        price = df["omp_entry_price"].astype(float)
        df["atr_sweet_spot"] = ((atr / price.replace(0, float("nan"))) * 100).between(0.5, 2.0).astype(float)

    if "alert_vol_ratio" in df.columns:
        df["vol_ratio_extreme"]  = (df["alert_vol_ratio"].astype(float) > 5.0).astype(float)
        df["vol_ratio_moderate"] = (
            (df["alert_vol_ratio"].astype(float) >= 2.0) & (df["alert_vol_ratio"].astype(float) <= 4.0)
        ).astype(float)

    # Market context derived features
    if "mkt_day_pct" in df.columns:
        mkt = df["mkt_day_pct"].astype(float)
        df["mkt_is_green"]  = (mkt > 0.0).astype(float)
        df["mkt_is_strong"] = (mkt > 0.5).astype(float)
        df["mkt_is_weak"]   = (mkt < -0.3).astype(float)
        if "mkt_5m_ema_trend" in df.columns:
            df["mkt_trending_up"] = (
                (df["mkt_5m_ema_trend"].fillna(0).astype(float) > 0) & (mkt > 0.0)
            ).astype(float)
        if "stock_intraday_pct" in df.columns:
            df["rs_spread_vs_market"] = df["stock_intraday_pct"].astype(float) - mkt

    if "entry_ts_est" in df.columns:
        ts = pd.to_datetime(df["entry_ts_est"])
        df["minutes_since_open"] = (ts.dt.hour - 9) * 60 + ts.dt.minute - 30
        df["minutes_since_open"] = df["minutes_since_open"].clip(lower=0).astype(float)

    return df


def main():
    parser = argparse.ArgumentParser(description="Re-score alert at actual entry time (live 1-min bar)")
    parser.add_argument("--alert-id",   required=True, type=int)
    parser.add_argument("--model-in",   required=True)
    parser.add_argument("--current-ts", required=True,
                        help="Current EST timestamp YYYY-MM-DD HH:MM:SS — used to find the live 1-min bar")
    parser.add_argument("--table",      default="trade_alerts")
    parser.add_argument("--recent-losses", type=int, default=0,
                        help="Count of losing trades completed today before this entry (default: 0)")
    args = parser.parse_args()

    allowed_tables = {"trade_alerts", "trade_alerts_unfiltered"}
    if args.table not in allowed_tables:
        raise SystemExit(f"--table must be one of: {', '.join(sorted(allowed_tables))}")

    payload = joblib.load(args.model_in)
    if isinstance(payload, dict) and "model" in payload:
        model      = payload["model"]
        model_meta = payload.get("meta", {})
    else:
        model      = payload
        model_meta = {}

    model_version = model_meta.get("model_version") or Path(args.model_in).stem

    # Prefer feature_columns from model metadata (saved by trainer).
    # This ensures exact column order and set used during training.
    model_features = model_meta.get("feature_columns")
    if not model_features:
        try:
            model_features = list(model.named_steps["pre"].transformers_[0][2])
        except Exception as e:
            raise RuntimeError(
                "Could not extract trained feature list from model Pipeline."
            ) from e

    engine = make_engine()

    # Join one_minute_prices at the CLOSEST bar <= current-ts (the live bar)
    # rather than at entry_ts_est (the historical detection bar).
    # Five-minute context still uses signal_ts_est for the most recent 5m bar.
    query = f"""
    SELECT
        ta.id AS alert_id,
        ta.symbol,
        ta.asset_type,
        ta.trading_date_est,
        ta.as_of_ts_est,
        ta.signal_ts_est,
        ta.time_of_day,
        ta.entry_type,
        ta.entry_ts_est,
        ta.entry,
        ta.stop,
        ta.pipeline_run,
        ta.version,

        -- Alert-level features (unchanged — pattern quality at detection time)
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

        -- 1-minute features at CURRENT TIME (the bar closest to now, not entry_ts_est)
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

        -- 5-minute context (left join — missing acceptable)
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

        -- Market context: benchmark at signal time
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

    FROM {args.table} ta

    -- INNER JOIN on the most recent 1-min bar <= current-ts (live bar, not historical entry bar)
    JOIN one_minute_prices omp
        ON omp.symbol     = ta.symbol
       AND omp.asset_type = ta.asset_type
       AND omp.ts_est = (
           SELECT MAX(ts_est)
           FROM one_minute_prices
           WHERE symbol     = ta.symbol
             AND asset_type = ta.asset_type
             AND ts_est    <= :current_ts
       )

    LEFT JOIN five_minute_prices fmp
        ON fmp.symbol     = ta.symbol
       AND fmp.asset_type = ta.asset_type
       AND fmp.ts_est = (
           SELECT MAX(ts_est)
           FROM five_minute_prices
           WHERE symbol     = ta.symbol
             AND asset_type = ta.asset_type
             AND ts_est    <= ta.signal_ts_est
       )

    -- Market context: benchmark most-recent 5m bar at or before signal time
    LEFT JOIN five_minute_prices mkt_fmp
        ON mkt_fmp.symbol     = :benchmark_symbol
       AND mkt_fmp.asset_type = 'stock'
       AND mkt_fmp.ts_est     = (
           SELECT MAX(ts_est)
           FROM five_minute_prices
           WHERE symbol     = :benchmark_symbol
             AND asset_type = 'stock'
             AND ts_est    <= :current_ts
       )

    -- Market context: benchmark first bar of the day
    LEFT JOIN five_minute_prices mkt_open
        ON mkt_open.symbol     = :benchmark_symbol
       AND mkt_open.asset_type = 'stock'
       AND mkt_open.ts_est     = (
           SELECT MIN(ts_est)
           FROM five_minute_prices
           WHERE symbol     = :benchmark_symbol
             AND asset_type = 'stock'
             AND DATE(ts_est) = DATE(:current_ts)
       )

    -- Stock first bar of the day (for stock intraday % calculation)
    LEFT JOIN five_minute_prices stk_open
        ON stk_open.symbol     = ta.symbol
       AND stk_open.asset_type = ta.asset_type
       AND stk_open.ts_est     = (
           SELECT MIN(ts_est)
           FROM five_minute_prices
           WHERE symbol     = ta.symbol
             AND asset_type = ta.asset_type
             AND DATE(ts_est) = DATE(:current_ts)
       )

    WHERE ta.id = :alert_id
    """

    with engine.connect() as conn:
        df = pd.read_sql(text(query), conn, params={
            "alert_id":        args.alert_id,
            "current_ts":      args.current_ts,
            "benchmark_symbol": _get_benchmark_symbol(),
        })

    if df.empty:
        raise SystemExit(
            f"No live 1-min bar found for alert {args.alert_id} at or before {args.current_ts}. "
            "Price data may be missing or current-ts is outside market hours."
        )

    if "alert_rsi_14_1m" in df.columns and pd.isna(df.loc[0, "alert_rsi_14_1m"]):
        print("WARNING: alert_rsi_14_1m is NULL; model will impute this feature.")

    # Inject session-level features that can't be derived from the alert row alone
    df["recent_losses_today"] = float(args.recent_losses)

    df = add_derived_features(df)

    for col in model_features:
        if col in df.columns:
            df[col] = pd.to_numeric(df[col], errors="coerce")

    missing_features = [c for c in model_features if c not in df.columns]
    if missing_features:
        print(f"WARNING: Missing {len(missing_features)} model features; they will be imputed as NaN:")
        print(missing_features)

    X = df.reindex(columns=model_features)

    try:
        prob = model.predict_proba(X)[0, 1]
    except Exception as e:
        raise RuntimeError(f"Model prediction failed for alert {args.alert_id}: {e}") from e

    print(f"Live-scored alert {args.alert_id} at {args.current_ts}: ml_win_prob = {prob:.6f}")


if __name__ == "__main__":
    main()
