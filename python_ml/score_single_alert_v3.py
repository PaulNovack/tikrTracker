#!/usr/bin/env python3
"""
score_single_alert_v3.py — Scorer for models trained with train_stock_winner_model_v3.py

Changes from v2:
  1. Adds signal-latency features at scoring time:
       • alert_age_minutes — (NOW − signal_ts_est) in minutes (key: at initial scoring time this
         reflects real-world staleness; at live/rescore time it reflects true order placement lag)
       • price_move_since_signal_pct — (1m entry bar price − signal bar price) / signal bar price × 100
  2. Derived v3 flags: alert_stale, alert_very_stale, abs_price_move_since_signal,
     stock_already_ran, stock_ran_hard (same thresholds used in training).
  3. Adds LEFT JOIN one_minute_prices sig_bar at signal_ts_est to fetch signal_price.
     signal_price itself is NOT passed to the model — only the derived % move is.

NOTE: alert_age_minutes uses datetime.now(America/New_York) so it is always in Eastern time,
matching the training SQL which uses CONVERT_TZ(filled_at, 'UTC', 'America/New_York').
Server timezone does not matter.

Use for models saved by:
  - train_stock_winner_model_v3.py
  - train_stock_winner_model_profit_weighted_v3.py
"""
import os
import argparse
from datetime import datetime, timezone
from zoneinfo import ZoneInfo
from pathlib import Path
from dotenv import load_dotenv

import numpy as np
import pandas as pd
import joblib
from sqlalchemy import create_engine, text
from sqlalchemy.engine import URL


# ---------- ENV / DB ----------

def load_parent_env() -> None:
    script_path = Path(__file__).resolve()
    env_path = script_path.parents[1] / ".env"
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


# ---------- Feature Engineering — must stay in sync with train_stock_winner_model_v3.py ----------

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


# ---------- Main ----------

def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("--model-in",  required=True, type=str,
                        help="Path to trained model (.joblib)")
    parser.add_argument("--alert-id",  required=True, type=int,
                        help="Alert ID to score")
    parser.add_argument("--table",     default="trade_alerts",
                        help="Table name (trade_alerts or trade_alerts_unfiltered)")
    parser.add_argument("--recent-losses", type=int, default=0,
                        help="Count of losing trades completed today before this entry (default: 0)")
    args = parser.parse_args()

    # Validate table name before use in SQL (injection prevention)
    allowed_tables = {"trade_alerts", "trade_alerts_unfiltered"}
    if args.table not in allowed_tables:
        raise SystemExit(f"--table must be one of: {', '.join(sorted(allowed_tables))}")

    # Load model
    payload = joblib.load(args.model_in)
    if isinstance(payload, dict) and "model" in payload:
        model      = payload["model"]
        model_meta = payload.get("meta", {})
    else:
        model      = payload
        model_meta = {}

    model_version = model_meta.get("model_version") or Path(args.model_in).stem

    # Extract exact trained feature list from the sklearn Pipeline
    try:
        model_features = list(model.named_steps["pre"].transformers_[0][2])
    except Exception as e:
        raise RuntimeError(
            "Could not extract trained feature list from model Pipeline. "
            "Make sure this model was saved by train_stock_winner_model_v3.py (or profit_weighted_v3)."
        ) from e

    engine = make_engine()

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

        -- Alert-level features
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

        -- 1-minute features AT ENTRY (inner join — fail fast if missing)
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

        -- 5-minute context (left join)
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
        END                          AS stock_intraday_pct,

        -- V3: signal bar price (used to compute price_move_since_signal_pct in Python)
        sig_bar.price AS signal_price

    FROM {args.table} ta

    LEFT JOIN one_minute_prices omp
        ON omp.symbol     = ta.symbol
       AND omp.asset_type = ta.asset_type
       AND omp.ts_est = (
           SELECT omp2.ts_est
           FROM one_minute_prices omp2
           WHERE omp2.symbol     = ta.symbol
             AND omp2.asset_type = ta.asset_type
             AND ABS(TIMESTAMPDIFF(SECOND, omp2.ts_est, ta.entry_ts_est)) <= 300
           ORDER BY ABS(TIMESTAMPDIFF(SECOND, omp2.ts_est, ta.entry_ts_est))
           LIMIT 1
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

    -- V3: 1m bar at or just before signal_ts_est (to measure price at signal detection)
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

    WHERE ta.id = :alert_id
    """

    with engine.connect() as conn:
        df = pd.read_sql(text(query), conn, params={
            "alert_id": args.alert_id,
            "benchmark_symbol": _get_benchmark_symbol(),
        })

    if df.empty:
        raise SystemExit(
            f"No data found for alert {args.alert_id}. "
            "The alert may not exist, or its entry_ts_est has no matching row in one_minute_prices."
        )

    # Warn if RSI is null
    if "alert_rsi_14_1m" in df.columns and pd.isna(df.loc[0, "alert_rsi_14_1m"]):
        print("WARNING: alert_rsi_14_1m is NULL; model will impute this feature.")

    # ---- V3: compute signal-latency features ----
    # alert_age_minutes: from signal detection to NOW in Eastern time.
    # signal_ts_est is stored as EST/EDT wall-clock time (no TZ info).
    # datetime.now(EST) gives the current Eastern time as a naive-equivalent,
    # matching the training SQL which uses CONVERT_TZ(filled_at, 'UTC', 'America/New_York').
    if "signal_ts_est" in df.columns and pd.notna(df.loc[0, "signal_ts_est"]):
        signal_ts_raw = df.loc[0, "signal_ts_est"]
        signal_ts     = pd.to_datetime(signal_ts_raw)
        now_est       = datetime.now(ZoneInfo("America/New_York")).replace(tzinfo=None)
        age_minutes   = (now_est - signal_ts.to_pydatetime()).total_seconds() / 60.0
        df["alert_age_minutes"] = age_minutes
    else:
        print("WARNING: signal_ts_est is NULL; alert_age_minutes will be imputed as NaN.")
        df["alert_age_minutes"] = float("nan")

    # price_move_since_signal_pct: (entry bar price - signal bar price) / signal bar price × 100
    if "signal_price" in df.columns and "omp_entry_price" in df.columns:
        sig_p   = pd.to_numeric(df["signal_price"], errors="coerce").iloc[0]
        entry_p = pd.to_numeric(df["omp_entry_price"], errors="coerce").iloc[0]
        if sig_p and sig_p > 0 and not np.isnan(entry_p):
            df["price_move_since_signal_pct"] = (entry_p - sig_p) / sig_p * 100.0
        else:
            if sig_p is None or (isinstance(sig_p, float) and np.isnan(sig_p)):
                print("WARNING: signal_price is NULL; price_move_since_signal_pct will be imputed as NaN.")
            df["price_move_since_signal_pct"] = float("nan")
    else:
        df["price_move_since_signal_pct"] = float("nan")

    # Inject session-level features
    df["recent_losses_today"] = float(args.recent_losses)

    # Add derived features (includes V3 staleness flags)
    df = add_derived_features(df)

    # Convert model features to numeric
    for col in model_features:
        if col in df.columns:
            df[col] = pd.to_numeric(df[col], errors="coerce")

    # Warn on missing features
    missing_features = [c for c in model_features if c not in df.columns]
    if missing_features:
        print(f"WARNING: Missing {len(missing_features)} model features; they will be imputed as NaN:")
        print(missing_features)

    # Log the v3 features that were computed
    v3_cols = ["alert_age_minutes", "price_move_since_signal_pct", "alert_stale", "alert_very_stale",
               "stock_already_ran", "stock_ran_hard"]
    for col in v3_cols:
        if col in df.columns:
            val = df.loc[0, col]
            print(f"[v3] {col} = {val:.4f}" if not pd.isna(val) else f"[v3] {col} = NaN")

    # Reindex to exact column order the Pipeline was trained on
    X = df.reindex(columns=model_features)

    try:
        prob = model.predict_proba(X)[0, 1]
    except Exception as e:
        raise RuntimeError(f"Model prediction failed for alert {args.alert_id}: {e}") from e

    update_query = f"""
    UPDATE {args.table}
    SET ml_win_prob      = :prob,
        ml_scored_at     = NOW(),
        passed_ml = 1,
        ml_model_version = :model_version
    WHERE id = :alert_id
    """

    with engine.connect() as conn:
        conn.execute(
            text(update_query),
            {
                "prob":          float(prob),
                "model_version": model_version,
                "alert_id":      args.alert_id,
            },
        )
        conn.commit()

    print(f"Scored alert {args.alert_id}: ml_win_prob = {prob:.6f}")


if __name__ == "__main__":
    main()
