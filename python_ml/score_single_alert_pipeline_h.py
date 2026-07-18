#!/usr/bin/env python3
"""
score_single_alert_pipeline_h.py — Pipeline H (v25.2) per-alert ML scorer.

Mirrors the structure of score_single_alert_v2.py but:
  1. SELECTs all v25.2-specific feature columns from trade_alerts.
  2. Applies both base + Pipeline H derived features before scoring.
  3. Defaults the model path to python_ml/models/winner_model_pipeline_h.joblib.

Usage:
  python python_ml/score_single_alert_pipeline_h.py \
    --alert-id 12345 \
    --model-in python_ml/models/winner_model_pipeline_h.joblib \
    --recent-losses 0

The model is loaded from a joblib dict created by train_stock_winner_model_pipeline_h.py.
After scoring, it writes ml_win_prob, ml_scored_at, and ml_model_version back to the row.
"""
import os
import argparse
from pathlib import Path

import numpy as np
import pandas as pd
import joblib
from dotenv import load_dotenv
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


# ---------- Feature Engineering ----------
# These must stay in sync with train_stock_winner_model_pipeline_h.py.

def add_derived_features(df: pd.DataFrame) -> pd.DataFrame:
    """Base derived features (shared with the generic v2 scorer)."""
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

    if "move_30m_pct" in d.columns and "spy_move_30m_pct" in d.columns:
        d["rs_30m_vs_spy"] = d["move_30m_pct"].astype(float) - d["spy_move_30m_pct"].astype(float)

    if "move_30m_pct" in d.columns and "rvol_5m" in d.columns:
        d["move_rvol_combo"] = d["move_30m_pct"].astype(float) * d["rvol_5m"].astype(float)

    if "rvol_5m" in d.columns and "atr_pct_5m" in d.columns:
        d["rvol_atr_combo"] = d["rvol_5m"].astype(float) * d["atr_pct_5m"].astype(float)

    if "risk_per_share" in d.columns and "alert_atr" in d.columns:
        atr_safe = d["alert_atr"].astype(float).replace(0, np.nan)
        d["stop_distance_atr"] = d["risk_per_share"].astype(float) / atr_safe

    if "suggested_trailing_stop_pct" in d.columns and "risk_pct" in d.columns:
        risk_safe = d["risk_pct"].astype(float).replace(0, np.nan)
        d["trail_vs_risk"] = d["suggested_trailing_stop_pct"].astype(float) / risk_safe

    if "above_vwap_entry_pct" in d.columns:
        d["abs_above_vwap_entry_pct"] = d["above_vwap_entry_pct"].astype(float).abs()
        d["vwap_chase_risk"] = (d["above_vwap_entry_pct"].astype(float) > 0.8).astype(float)

    if "entry_type" in d.columns:
        d["is_vwap_reclaim_strong"] = (d["entry_type"] == "VWAP_RECLAIM_STRONG").astype(float)
        d["is_orb_retest"]          = (d["entry_type"] == "ORB_RETEST").astype(float)
        d["is_ema9_pullback"]       = (d["entry_type"] == "EMA9_PULLBACK").astype(float)

    return d


# ---------- Main ----------

def main():
    parser = argparse.ArgumentParser(description="Pipeline H (v25.2) per-alert ML scorer")
    parser.add_argument("--model-in",      default="python_ml/models/winner_model_pipeline_h.joblib",
                        help="Path to trained Pipeline H model (.joblib)")
    parser.add_argument("--alert-id",      required=True, type=int,
                        help="Alert ID to score")
    parser.add_argument("--table",         default="trade_alerts",
                        help="Table name (trade_alerts or trade_alerts_unfiltered)")
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

    # Prefer version stored in training metadata; fall back to filename stem
    model_version = model_meta.get("model_version") or Path(args.model_in).stem

    # Ensure this was trained for Pipeline H
    if model_meta.get("pipeline") and model_meta["pipeline"] != "H":
        raise SystemExit(
            f"This scorer is for Pipeline H only. "
            f"Loaded model is for pipeline={model_meta['pipeline']}."
        )

    # Prefer feature_columns from model metadata (saved by trainer).
    # This ensures exact column order and set used during training.
    model_features = model_meta.get("feature_columns")
    if not model_features:
        try:
            model_features = list(model.named_steps["pre"].transformers_[0][2])
        except Exception as e:
            raise RuntimeError(
                "Could not extract trained feature list from model Pipeline. "
                "Make sure this model was saved by train_stock_winner_model_pipeline_h.py."
            ) from e

    benchmark_symbol = _get_benchmark_symbol()
    engine           = make_engine()

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

        -- Base alert features
        ta.score                        AS alert_score,
        ta.vol_ratio                    AS alert_vol_ratio,
        ta.atr                          AS alert_atr,
        ta.atr_pct                      AS alert_atr_pct,
        ta.daily_trend_5d_pct,
        ta.range_position_60m,
        ta.rsi_14_1m                    AS alert_rsi_14_1m,
        ta.five_min_directional_changes,
        ta.five_min_green_bar_pct,
        ta.five_min_net_progress,
        ta.risk_pct,
        ta.risk_per_share,
        ta.suggested_trailing_stop_pct,

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

        -- v25.2 VWAP reclaim specific
        ta.vwap_reclaim_strength_pct,
        ta.vwap_reclaim_wick_below_pct,

        -- v25.2 ORB retest specific
        ta.or_retest_depth_pct,
        ta.or_hold_close_pct,
        ta.or_break_distance_pct,
        ta.bars_since_or_break,

        -- v25.2 EMA9 pullback specific
        ta.ema9_pullback_depth_pct,
        ta.ema9_reclaim_pct,

        -- 1-minute features AT ENTRY (INNER JOIN — fail fast if missing)
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

        -- 5-minute context at signal time (LEFT JOIN — missing is acceptable)
        fmp.price          AS fmp_price,
        fmp.vwap_dist_pct  AS fmp_vwap_dist_pct,
        fmp.above_vwap     AS fmp_above_vwap,
        fmp.ema9_ema21_spread AS fmp_ema_spread,
        fmp.ema9_above_ema21  AS fmp_ema9_above_ema21,
        fmp.atr_pct        AS fmp_atr_pct,
        fmp.rsi_14         AS fmp_rsi_14,

        -- Market context: benchmark at signal time
        CASE
            WHEN mkt_open.open IS NOT NULL AND mkt_open.open > 0 AND mkt_fmp.price IS NOT NULL
            THEN (mkt_fmp.price - mkt_open.open) / mkt_open.open * 100
            ELSE NULL
        END                       AS mkt_day_pct,
        mkt_fmp.ema9_above_ema21  AS mkt_5m_ema_trend,
        mkt_fmp.vwap_dist_pct     AS mkt_5m_vwap_dist,
        mkt_fmp.rsi_14            AS mkt_5m_rsi,

        -- Stock intraday % (for RS spread vs market)
        CASE
            WHEN stk_open.open IS NOT NULL AND stk_open.open > 0 AND fmp.price IS NOT NULL
            THEN (fmp.price - stk_open.open) / stk_open.open * 100
            ELSE NULL
        END                       AS stock_intraday_pct

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

    WHERE ta.id = :alert_id
    """

    with engine.connect() as conn:
        df = pd.read_sql(
            text(query),
            conn,
            params={"alert_id": args.alert_id, "benchmark_symbol": benchmark_symbol},
        )

    if df.empty:
        raise SystemExit(
            f"No data found for alert {args.alert_id}. "
            "The alert may not exist, it may not be a Pipeline H alert, "
            "or its entry_ts_est has no matching row in one_minute_prices."
        )

    if "alert_rsi_14_1m" in df.columns and pd.isna(df.loc[0, "alert_rsi_14_1m"]):
        print("WARNING: alert_rsi_14_1m is NULL; model will impute this feature.")

    # Inject session-level features that can't be derived from the alert row alone
    df["recent_losses_today"] = float(args.recent_losses)

    # Apply feature engineering
    df = add_derived_features(df)
    df = add_h_derived_features(df)

    # Convert model features to numeric (handles any object-typed NULLs from SQL)
    for col in model_features:
        if col in df.columns:
            df[col] = pd.to_numeric(df[col], errors="coerce")

    missing_features = [c for c in model_features if c not in df.columns]
    if missing_features:
        print(f"WARNING: Missing {len(missing_features)} model features (will be imputed as NaN):")
        print(missing_features)

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

    print(f"Scored alert {args.alert_id} [Pipeline H]: ml_win_prob = {prob:.6f}")


if __name__ == "__main__":
    main()
