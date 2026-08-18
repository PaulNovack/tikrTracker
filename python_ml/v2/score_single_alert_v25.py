#!/usr/bin/env python3
"""
score_single_alert_v25.py

Standalone v2.5 single-alert scorer.

This adds the pipeline_h entry-quality and room-to-run fields to the v2 scoring
path without changing the existing v2 scorer.
"""

from __future__ import annotations

import argparse
import os
import sys
from pathlib import Path

import joblib
import pandas as pd
from dotenv import load_dotenv
from sqlalchemy import create_engine, text
from sqlalchemy.engine import URL

ROOT_DIR = Path(__file__).resolve().parents[2]
if str(ROOT_DIR) not in sys.path:
    sys.path.insert(0, str(ROOT_DIR))

from python_ml.shared.features import add_derived_features, coerce_feature_columns  # noqa: E402


FEATURE_COLUMNS = [
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
    "move_30m_pct",
    "rvol_5m",
    "atr_pct_5m",
    "notional_last5m",
    "spy_move_30m_pct",
    "hod",
    "room_to_hod_pct",
    "room_to_hod_atr",
    "above_vwap_entry_pct",
    "entry_body_pct",
    "entry_close_position",
    "entry_volume_ratio",
    "entry_notional_1m",
    "risk_pct",
    "suggested_trailing_stop_pct",
    "avg_dollar_volume_per_minute",
    "sentiment_score_1_100",
    "entry_spread_strength",
    "entry_vwap_dist_score",
    "entry_atr_score",
    "entry_vol_score",
    "entry_candle_score",
    "entry_time_bonus",
    "vwap_dist_pct",
    "above_vwap",
    "ema9_ema21_spread",
    "ema9_above_ema21",
    "omp_atr_pct",
    "fmp_vwap_dist_pct",
    "fmp_above_vwap",
    "fmp_ema_spread",
    "fmp_ema9_above_ema21",
    "fmp_atr_pct",
    "fmp_rsi_14",
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
    "mkt_day_pct",
    "mkt_5m_ema_trend",
    "mkt_5m_vwap_dist",
    "mkt_5m_rsi",
    "mkt_is_green",
    "mkt_is_strong",
    "mkt_is_weak",
    "mkt_trending_up",
    "rs_spread_vs_market",
    "minutes_since_open",
]

CATEGORICAL_FEATURES = ["signal_type", "entry_type", "time_of_day", "pipeline_run"]


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
    host = os.environ.get("DB_HOST", "127.0.0.1")
    port = int(os.environ.get("DB_PORT", "3306"))
    name = os.environ.get("DB_DATABASE", "laravelInvest")
    user = os.environ.get("DB_USERNAME", "laravel")
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


def _build_query(table_name: str) -> str:
    return f"""
    SELECT
        ta.id AS alert_id,
        ta.symbol,
        ta.asset_type,
        ta.trading_date_est,
        ta.as_of_ts_est,
        ta.signal_ts_est,
        ta.signal_type,
        ta.time_of_day,
        ta.entry_type,
        ta.entry_ts_est,
        ta.entry,
        ta.stop,
        ta.pipeline_run,
        ta.version,

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
        ta.move_30m_pct,
        ta.rvol_5m,
        ta.atr_pct_5m,
        ta.notional_last5m,
        ta.spy_move_30m_pct,
        ta.hod,
        ta.room_to_hod_pct,
        ta.room_to_hod_atr,
        ta.above_vwap_entry_pct,
        ta.entry_body_pct,
        ta.entry_close_position,
        ta.entry_volume_ratio,
        ta.entry_notional_1m,
        ta.risk_pct,
        ta.suggested_trailing_stop_pct,
        ta.avg_dollar_volume_per_minute,
        ta.sentiment_score_1_100,
        ta.entry_spread_strength,
        ta.entry_vwap_dist_score,
        ta.entry_atr_score,
        ta.entry_vol_score,
        ta.entry_candle_score,
        ta.entry_time_bonus,

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
        END AS mkt_day_pct,
        mkt_fmp.ema9_above_ema21 AS mkt_5m_ema_trend,
        mkt_fmp.vwap_dist_pct AS mkt_5m_vwap_dist,
        mkt_fmp.rsi_14 AS mkt_5m_rsi,

        CASE
            WHEN stk_open.open IS NOT NULL AND stk_open.open > 0 AND fmp.price IS NOT NULL
            THEN (fmp.price - stk_open.open) / stk_open.open * 100
            ELSE NULL
        END AS stock_intraday_pct

    FROM {table_name} ta

    JOIN one_minute_prices omp
        ON omp.symbol     = ta.symbol
       AND omp.asset_type = ta.asset_type
       AND omp.ts_est = (
           SELECT MAX(omp2.ts_est)
           FROM one_minute_prices omp2
           WHERE omp2.symbol     = ta.symbol
             AND omp2.asset_type = ta.asset_type
             AND omp2.ts_est    <= ta.entry_ts_est
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

    LEFT JOIN five_minute_prices mkt_fmp
        ON mkt_fmp.symbol     = :benchmark_symbol
       AND mkt_fmp.asset_type = 'stock'
       AND mkt_fmp.ts_est     = (
           SELECT MAX(ts_est)
           FROM five_minute_prices
           WHERE symbol     = :benchmark_symbol
             AND asset_type = 'stock'
             AND ts_est    <= ta.signal_ts_est
       )

    LEFT JOIN five_minute_prices mkt_open
        ON mkt_open.symbol     = :benchmark_symbol
       AND mkt_open.asset_type = 'stock'
       AND mkt_open.ts_est     = (
           SELECT MIN(ts_est)
           FROM five_minute_prices
           WHERE symbol     = :benchmark_symbol
             AND asset_type = 'stock'
             AND DATE(ts_est) = ta.trading_date_est
       )

    LEFT JOIN five_minute_prices stk_open
        ON stk_open.symbol     = ta.symbol
       AND stk_open.asset_type = ta.asset_type
       AND stk_open.ts_est     = (
           SELECT MIN(ts_est)
           FROM five_minute_prices
           WHERE symbol     = ta.symbol
             AND asset_type = ta.asset_type
             AND DATE(ts_est) = ta.trading_date_est
       )

    WHERE ta.id = :alert_id
    """


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("--model-in", required=True, type=str, help="Path to trained model (.joblib)")
    parser.add_argument("--alert-id", required=True, type=int, help="Alert ID to score")
    parser.add_argument("--table", default="trade_alerts", choices=["trade_alerts", "trade_alerts_unfiltered"], help="Table name (trade_alerts or trade_alerts_unfiltered)")
    parser.add_argument("--recent-losses", type=int, default=0, help="Count of losing trades completed today before this entry (default: 0)")
    args = parser.parse_args()

    payload = joblib.load(args.model_in)
    if isinstance(payload, dict) and "model" in payload:
        model = payload["model"]
        model_meta = payload.get("meta", {})
    else:
        model = payload
        model_meta = {}

    model_version = model_meta.get("model_version") or Path(args.model_in).stem
    model_features = model_meta.get("feature_columns")
    if not model_features:
        try:
            model_features = list(model.named_steps["pre"].transformers_[0][2])
        except Exception as exc:
            raise RuntimeError("Could not extract trained feature list from model Pipeline.") from exc

    if args.table not in {"trade_alerts", "trade_alerts_unfiltered"}:
        raise SystemExit("--table must be one of: trade_alerts, trade_alerts_unfiltered")

    engine = make_engine()
    query = _build_query(args.table)

    with engine.connect() as conn:
        df = pd.read_sql(text(query), conn, params={"alert_id": args.alert_id, "benchmark_symbol": _get_benchmark_symbol()})

    if df.empty:
        raise SystemExit(
            f"No data found for alert {args.alert_id}. The alert may not exist, or its entry_ts_est has no matching row."
        )

    if "alert_rsi_14_1m" in df.columns and pd.isna(df.loc[0, "alert_rsi_14_1m"]):
        print("WARNING: alert_rsi_14_1m is NULL; model will impute this feature.")

    df["recent_losses_today"] = float(args.recent_losses)
    df = add_derived_features(df)
    df = coerce_feature_columns(df, FEATURE_COLUMNS, CATEGORICAL_FEATURES)

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
    except Exception as exc:
        raise RuntimeError(f"Model prediction failed for alert {args.alert_id}: {exc}") from exc

    update_query = f"""
    UPDATE {args.table}
    SET ml_win_prob      = :prob,
        ml_scored_at     = NOW(),
        passed_ml        = 1,
        ml_model_version = :model_version
    WHERE id = :alert_id
    """

    with engine.connect() as conn:
        conn.execute(
            text(update_query),
            {"prob": float(prob), "model_version": model_version, "alert_id": args.alert_id},
        )
        conn.commit()

    print(f"Scored alert {args.alert_id}: ml_win_prob = {prob:.6f}")


if __name__ == "__main__":
    main()