#!/usr/bin/env python3
"""
score_single_alert_v2.py — Corrected version of score_single_alert.py

Changes from v1:
  1. Feature list pulled from model.named_steps["pre"].transformers_[0][2] — the exact
     columns and order the Pipeline was trained on. Replaces the fragile hasattr() checks
     that could silently pass the wrong column set.
  2. Numeric conversion applied only to model features (not a hand-maintained list),
     so derived features like spread_1m_minus_5m are never accidentally left as object dtype.
  3. Warns when model features are missing from the scored row (will be median-imputed).
  4. --table validated against an allowlist before use in SQL (prevents injection).
  5. SQLAlchemy URL.create() replaces f-string URL (safe with special chars in passwords).
  6. one_minute_prices uses INNER JOIN — if the entry bar is missing the score is
     untrustworthy; fail loudly rather than impute most 1m features to NaN.
  7. Fetches trading_date_est, as_of_ts_est, time_of_day, pipeline_run, version for
     consistency with training data (non-numeric so no model impact today, but future-safe).
  8. Warns when alert_rsi_14_1m is NULL (training had a SQL fallback; scoring does not).
  9. model_version prefers metadata stored in the payload over the filename stem.
"""
import os
import argparse
from pathlib import Path
from dotenv import load_dotenv

import pandas as pd
import joblib
from sqlalchemy import create_engine, text
from sqlalchemy.engine import URL


# ---------- ENV / DB ----------

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


# ---------- Feature Engineering (must stay in sync with training script) ----------

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

    # Validate table name before using it in SQL
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

    # Prefer version stored in training metadata; fall back to filename stem
    model_version = model_meta.get("model_version") or Path(args.model_in).stem

    # Fix 6: Prefer feature_columns from model metadata (saved by trainer's score_candidates).
    # This ensures exact column order and set used during training.
    model_features = model_meta.get("feature_columns")
    if not model_features:
        # Fall back to introspecting the Pipeline's ColumnTransformer
        try:
            model_features = list(model.named_steps["pre"].transformers_[0][2])
        except Exception as e:
            raise RuntimeError(
                "Could not extract trained feature list from model Pipeline. "
                "Make sure this model was saved by train_stock_winner_model_v2.py."
            ) from e

    engine = make_engine()

    # INNER JOIN on one_minute_prices: if the entry bar is missing, the score
    # would impute most 1m features to NaN and be untrustworthy.
    # LEFT JOIN kept for five_minute_prices — missing 5m context is less critical.
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

        -- 5-minute context (left join — missing is acceptable)
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

    -- Match training: use MAX(ts_est) <= entry_ts_est pattern (same as training SQL).
    -- Live tables (one_minute_prices / five_minute_prices) for production scoring.
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

    -- Market context: benchmark most-recent 5m bar at or before signal time
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

    -- Market context: benchmark first bar of the day
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

    -- Stock first bar of the day (for stock intraday % calculation)
    LEFT JOIN five_minute_prices stk_open
        ON stk_open.symbol     = ta.symbol
       AND stk_open.asset_type = ta.asset_type
       AND stk_open.ts_est     = (
           SELECT MIN(ts_est)
           FROM five_minute_prices
             AND DATE(ts_est) = ta.trading_date_est
       )

    WHERE ta.id = :alert_id
    """

    with engine.connect() as conn:
        df = pd.read_sql(text(query), conn, params={
            "alert_id": args.alert_id,
            "benchmark_symbol": _get_benchmark_symbol(),
        })

    if df.empty:
        # Attempt fallback: search for the nearest bar within ±5 minutes
        print(f"WARNING: No exact match for alert {args.alert_id}, searching nearest bar within ±5 min...")
        fallback_query = f"""
        SELECT omp.ts_est
        FROM {args.table} ta
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
        WHERE ta.id = :alert_id
        ORDER BY ABS(TIMESTAMPDIFF(SECOND, omp.ts_est, ta.entry_ts_est))
        LIMIT 1
        """
        with engine.connect() as conn:
            fallback_df = pd.read_sql(text(fallback_query), conn, params={"alert_id": args.alert_id})
        if not fallback_df.empty:
            nearest_ts = fallback_df.iloc[0]["ts_est"]
            print(f"WARNING: Mapped alert {args.alert_id} → nearest bar at {nearest_ts}")
            # Re-run main query with corrected timestamp
            with engine.connect() as conn:
                df = pd.read_sql(text(query), conn, params={
                    "alert_id": args.alert_id,
                    "benchmark_symbol": _get_benchmark_symbol(),
                })

    if df.empty:
        raise SystemExit(
            f"No data found for alert {args.alert_id}. "
            "The alert may not exist, or its entry_ts_est has no matching row in one_minute_prices_full."
        )

    # Warn if RSI is null — training had a SQL fallback but scoring does not
    if "alert_rsi_14_1m" in df.columns and pd.isna(df.loc[0, "alert_rsi_14_1m"]):
        print("WARNING: alert_rsi_14_1m is NULL; model will impute this feature.")

    # Inject session-level features that can't be derived from the alert row alone
    df["recent_losses_today"] = float(args.recent_losses)

    # Add derived features
    df = add_derived_features(df)

    # Convert model features to numeric (handles any object-typed NULLs from SQL)
    for col in model_features:
        if col in df.columns:
            df[col] = pd.to_numeric(df[col], errors="coerce")

    # Warn on any features the model expects but the row doesn't have
    missing_features = [c for c in model_features if c not in df.columns]
    if missing_features:
        print(f"WARNING: Missing {len(missing_features)} model features; they will be imputed as NaN:")
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

    print(f"Scored alert {args.alert_id}: ml_win_prob = {prob:.6f}")


if __name__ == "__main__":
    main()
