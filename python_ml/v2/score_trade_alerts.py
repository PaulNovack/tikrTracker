#!/usr/bin/env python3
import os
import argparse
from pathlib import Path
from typing import Dict, Optional
from dotenv import load_dotenv
load_dotenv(dotenv_path=Path(__file__).resolve().parents[2] / ".env", override=False)

import pandas as pd
import joblib
from sqlalchemy import create_engine, text

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
    host = os.environ.get("DB_HOST", "127.0.0.1")
    port = int(os.environ.get("DB_PORT", "3306"))
    name = os.environ.get("DB_DATABASE", "laravelInvest")
    user = os.environ.get("DB_USERNAME", "laravel")
    password = os.environ.get("DB_PASSWORD", "laravel")
    url = f"mysql+pymysql://{user}:{password}@{host}:{port}/{name}"
    return create_engine(url, pool_pre_ping=True)

# ---------- SQL (same feature shape as training) ----------
# NOTE: This is a simplified scoring query:
# - uses trade_alerts + 1m at entry + 5m <= signal
# - computes RSI(14) from 1m history if trade_alerts.rsi_14_1m is NULL
SCORE_SQL = """
WITH ta_base AS (
    SELECT
        ta.id AS alert_id,
        ta.symbol,
        ta.asset_type,
        ta.trading_date_est,
        ta.signal_type,
        ta.signal_ts_est,
        ta.time_of_day,
        ta.entry_type,
        ta.entry_ts_est,
        ta.entry,
        ta.stop,

        -- alert-side pre-entry fields
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

    FROM trade_alerts ta
    WHERE ta.trading_date_est = :trading_date
      AND ta.ml_scored_at IS NULL             -- not scored yet
      AND (:pipeline IS NULL OR ta.pipeline_run IN :pipeline_list)
),
bars AS (
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
    t.*,

    -- 1-minute at entry (join via ts_est)
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

    COALESCE(t.alert_rsi_14_1m_stored, r.rsi_14_sql) AS alert_rsi_14_1m,

    -- 5-minute context near signal time
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

    -- Market context: benchmark ({{benchmark_symbol}}) at signal time
    CASE
        WHEN mkt_open.open IS NOT NULL AND mkt_open.open > 0 AND mkt_fmp.price IS NOT NULL
            THEN (mkt_fmp.price - mkt_open.open) / mkt_open.open * 100
        ELSE NULL
    END                      AS mkt_day_pct,
    mkt_fmp.ema9_above_ema21 AS mkt_5m_ema_trend,
    mkt_fmp.vwap_dist_pct    AS mkt_5m_vwap_dist,
    mkt_fmp.rsi_14           AS mkt_5m_rsi,

    -- Stock intraday % vs market
    CASE
        WHEN stk_open.open IS NOT NULL AND stk_open.open > 0 AND fmp.price IS NOT NULL
            THEN (fmp.price - stk_open.open) / stk_open.open * 100
        ELSE NULL
    END                      AS stock_intraday_pct,

    -- actual fill columns: always NULL during scoring (filled post-trade only)
    -- must be present so the model receives the same feature shape as training
    NULL AS actual_pnl_pct,
    NULL AS actual_buy_price,
    NULL AS actual_sell_price,
    0    AS has_actual_fill

FROM ta_base t
JOIN one_minute_prices_full omp
  ON omp.symbol = t.symbol
 AND omp.asset_type = t.asset_type
 AND omp.ts_est = (
            SELECT MAX(omp2.ts_est)
            FROM one_minute_prices_full omp2
            WHERE omp2.symbol = t.symbol
                AND omp2.asset_type = t.asset_type
                AND omp2.ts_est <= t.entry_ts_est
 )
LEFT JOIN rsi_calc r
  ON r.alert_id = t.alert_id
 AND r.ts_est = t.entry_ts_est
LEFT JOIN five_minute_prices_full fmp
  ON fmp.symbol = t.symbol
 AND fmp.asset_type = t.asset_type
 AND fmp.ts_est = (
      SELECT MAX(f2.ts_est)
      FROM five_minute_prices_full f2
      WHERE f2.symbol = t.symbol
        AND f2.asset_type = t.asset_type
        AND f2.ts_est <= t.signal_ts_est
 )

-- Market context: benchmark ({{benchmark_symbol}}) at signal time
LEFT JOIN five_minute_prices_full mkt_fmp
  ON mkt_fmp.symbol     = '{{benchmark_symbol}}'
 AND mkt_fmp.asset_type = 'stock'
 AND mkt_fmp.ts_est     = (
      SELECT MAX(ts_est)
      FROM five_minute_prices_full
      WHERE symbol     = '{{benchmark_symbol}}'
        AND asset_type = 'stock'
        AND ts_est    <= t.signal_ts_est
 )
LEFT JOIN five_minute_prices_full mkt_open
  ON mkt_open.symbol     = '{{benchmark_symbol}}'
 AND mkt_open.asset_type = 'stock'
 AND mkt_open.ts_est     = (
      SELECT MIN(ts_est)
      FROM five_minute_prices_full
      WHERE symbol     = '{{benchmark_symbol}}'
        AND asset_type = 'stock'
        AND DATE(ts_est) = t.trading_date_est
 )
LEFT JOIN five_minute_prices_full stk_open
  ON stk_open.symbol     = t.symbol
 AND stk_open.asset_type = t.asset_type
 AND stk_open.ts_est     = (
      SELECT MIN(ts_est)
      FROM five_minute_prices_full
      WHERE symbol     = t.symbol
        AND asset_type = t.asset_type
        AND DATE(ts_est) = t.trading_date_est
 );
"""

UPDATE_SQL = """
UPDATE trade_alerts
SET ml_win_prob = :p,
    ml_scored_at = NOW(),
    ml_model_version = :mv
WHERE id = :id
"""


def _resolve_model_path_for_pipeline(pipeline: str, explicit_model_in: Optional[str]) -> str:
    """Resolve model path from .env per pipeline, with explicit fallback for compatibility."""
    load_parent_env()
    key = f"TRADING_ML_PIPELINE_{pipeline.upper()}_MODEL_PATH"
    env_model = (os.environ.get(key, "") or "").strip()
    if env_model:
        return env_model

    if explicit_model_in:
        return explicit_model_in

    global_model = (os.environ.get("TRADING_ML_MODEL_PATH", "") or "").strip()
    if global_model:
        return global_model

    raise SystemExit(
        f"No model path configured for pipeline {pipeline}. "
        f"Set {key} in .env (or pass --model-in as fallback)."
    )


def _load_model_payload(model_path: str, cache: Dict[str, dict]) -> dict:
    if model_path not in cache:
        cache[model_path] = joblib.load(model_path)
    return cache[model_path]

# ---------- feature engineering (must match training) ----------
def add_derived_features(df: pd.DataFrame) -> pd.DataFrame:
    d = df.copy()

    def numeric_or_zero(series: pd.Series) -> pd.Series:
        return pd.to_numeric(series, errors="coerce").fillna(0.0)
    
    # Base features
    if "vwap_dist_pct" in d.columns:
        d["abs_vwap_dist_pct"] = d["vwap_dist_pct"].astype(float).abs()
    if "ema9_ema21_spread" in d.columns:
        d["abs_ema_spread"] = d["ema9_ema21_spread"].astype(float).abs()

    if "alert_rsi_14_1m" in d.columns:
        d["alert_rsi_centered"] = (d["alert_rsi_14_1m"].astype(float) - 50.0) / 50.0
    if "fmp_rsi_14" in d.columns:
        d["fmp_rsi_centered"] = (d["fmp_rsi_14"].astype(float) - 50.0) / 50.0

    if "above_vwap" in d.columns and "ema9_above_ema21" in d.columns:
        d["trend_alignment_1m"] = numeric_or_zero(d["above_vwap"]) * numeric_or_zero(d["ema9_above_ema21"])

    if "fmp_above_vwap" in d.columns and "fmp_ema9_above_ema21" in d.columns:
        d["trend_alignment_5m"] = numeric_or_zero(d["fmp_above_vwap"]) * numeric_or_zero(d["fmp_ema9_above_ema21"])

    if "ema9_ema21_spread" in d.columns and "fmp_ema_spread" in d.columns:
        d["spread_1m_minus_5m"] = d["ema9_ema21_spread"].astype(float) - d["fmp_ema_spread"].astype(float)
    
    # ========== OVER-EXTENSION DETECTION FEATURES (2026-03-03) ==========
    # Help model identify setups that are too extended and prone to reversal
    
    # 1. Overbought/Oversold RSI flags
    if "alert_rsi_14_1m" in d.columns:
        d["rsi_1m_overbought"] = (d["alert_rsi_14_1m"].astype(float) > 70).astype(float)
        d["rsi_1m_oversold"] = (d["alert_rsi_14_1m"].astype(float) < 30).astype(float)
        d["rsi_1m_extreme"] = ((d["alert_rsi_14_1m"].astype(float) > 75) | 
                               (d["alert_rsi_14_1m"].astype(float) < 25)).astype(float)
    
    if "fmp_rsi_14" in d.columns:
        d["rsi_5m_overbought"] = (d["fmp_rsi_14"].astype(float) > 70).astype(float)
        d["rsi_5m_oversold"] = (d["fmp_rsi_14"].astype(float) < 30).astype(float)
    
    # 2. VWAP extension flags
    if "vwap_dist_pct" in d.columns:
        d["vwap_extended"] = (d["vwap_dist_pct"].astype(float).abs() > 1.0).astype(float)
        d["vwap_very_extended"] = (d["vwap_dist_pct"].astype(float).abs() > 2.0).astype(float)
    
    # 3. Volume ratio flags
    if "alert_vol_ratio" in d.columns:
        d["vol_ratio_extreme"] = (d["alert_vol_ratio"].astype(float) > 5.0).astype(float)
        d["vol_ratio_moderate"] = ((d["alert_vol_ratio"].astype(float) >= 2.0) & 
                                   (d["alert_vol_ratio"].astype(float) <= 4.0)).astype(float)
    
    # 4. ATR flags
    if "alert_atr_pct" in d.columns:
        d["atr_too_low"] = (d["alert_atr_pct"].astype(float) < 0.3).astype(float)
        d["atr_too_high"] = (d["alert_atr_pct"].astype(float) > 2.0).astype(float)
        d["atr_sweet_spot"] = ((d["alert_atr_pct"].astype(float) >= 0.5) & 
                               (d["alert_atr_pct"].astype(float) <= 1.2)).astype(float)
    
    # 5. Green bar percentage flags
    if "five_min_green_bar_pct" in d.columns:
        d["green_bars_high"] = (d["five_min_green_bar_pct"].astype(float) > 75).astype(float)
        d["green_bars_balanced"] = ((d["five_min_green_bar_pct"].astype(float) >= 50) & 
                                    (d["five_min_green_bar_pct"].astype(float) <= 70)).astype(float)
    
    # 6. Directional changes (choppiness)
    if "five_min_directional_changes" in d.columns:
        d["choppy"] = (d["five_min_directional_changes"].astype(float) > 6).astype(float)
        d["clean_trend"] = (d["five_min_directional_changes"].astype(float) <= 4).astype(float)
    
    # 7. Distance from intraday high
    if "pct_below_intraday_high" in d.columns:
        d["near_high"] = (d["pct_below_intraday_high"].astype(float) < 0.5).astype(float)
        d["off_highs"] = (d["pct_below_intraday_high"].astype(float) > 2.0).astype(float)
    
    # 8. Composite scores
    warning_cols = [c for c in d.columns if c in [
        'rsi_1m_overbought', 'rsi_5m_overbought', 'vwap_extended', 
        'vol_ratio_extreme', 'green_bars_high', 'near_high', 'rsi_1m_extreme'
    ]]
    if warning_cols:
        d["overextension_score"] = d[warning_cols].sum(axis=1).astype(float)
    
    healthy_cols = [c for c in d.columns if c in [
        'vol_ratio_moderate', 'atr_sweet_spot', 'green_bars_balanced', 
        'clean_trend', 'off_highs'
    ]]
    if healthy_cols:
        d["healthy_setup_score"] = d[healthy_cols].sum(axis=1).astype(float)
    
    return d

def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--model-in", help="Optional fallback model path. .env per-pipeline paths take precedence.")
    ap.add_argument("--trading-date", required=True, help="YYYY-MM-DD (EST)")
    ap.add_argument("--limit", type=int, default=10)
    ap.add_argument("--model-version", help="Model version string (defaults to filename stem or model metadata)")
    ap.add_argument("--pipeline", help="Comma-separated pipeline letters (e.g., 'A,B,N' or 'C,D')")
    args = ap.parse_args()

    engine = make_engine()

    # Parse pipeline filter
    pipeline_arg = args.pipeline.strip() if args.pipeline and args.pipeline.strip() else None

    pipeline_list = ("__UNUSED_PIPELINE_FILTER__",)
    if pipeline_arg:
        selected_pipelines = [p.strip().upper() for p in pipeline_arg.split(',') if p.strip()]
        if selected_pipelines:
            pipeline_list = tuple(selected_pipelines)

    with engine.connect() as conn:
        params = {
            "trading_date": args.trading_date,
            "pipeline": pipeline_arg,
            "pipeline_list": pipeline_list,
        }
        df = pd.read_sql(text(SCORE_SQL.format(benchmark_symbol=_get_benchmark_symbol())), conn, params=params)

    if df.empty:
        print("No unscored alerts found.")
        return

    model_cache: Dict[str, dict] = {}
    scored_groups = []

    for pipeline_name, pipeline_df in df.groupby("pipeline_run", dropna=False):
        if pd.isna(pipeline_name):
            raise SystemExit("Encountered rows with NULL pipeline_run; cannot resolve model from .env")

        pipeline = str(pipeline_name).strip().upper()
        model_path = _resolve_model_path_for_pipeline(pipeline, args.model_in)
        payload = _load_model_payload(model_path, model_cache)
        model = payload["model"]

        if args.model_version:
            model_version = args.model_version
        else:
            model_version = str(payload.get("meta", {}).get("metrics", {}).get("model_version", Path(model_path).stem))

        derived = add_derived_features(pipeline_df)
        numeric_cols = payload.get("meta", {}).get("feature_columns")
        if not numeric_cols:
            numeric_cols = model.named_steps["pre"].transformers_[0][2]

        X = derived.reindex(columns=numeric_cols)
        probabilities = model.predict_proba(X)[:, 1]

        scored = pipeline_df[["alert_id", "symbol", "entry_ts_est", "signal_type", "entry_type"]].copy()
        scored["pipeline_run"] = pipeline
        scored["model_path"] = model_path
        scored["ml_model_version"] = model_version
        scored["ml_win_prob"] = probabilities
        scored_groups.append(scored)

    final_scored = pd.concat(scored_groups, ignore_index=True).sort_values("ml_win_prob", ascending=False)

    with engine.begin() as conn:
        for row in final_scored.itertuples(index=False):
            conn.execute(
                text(UPDATE_SQL),
                {"p": float(row.ml_win_prob), "mv": str(row.ml_model_version), "id": int(row.alert_id)}
            )

    print(final_scored.head(args.limit).to_string(index=False))

if __name__ == "__main__":
    main()
