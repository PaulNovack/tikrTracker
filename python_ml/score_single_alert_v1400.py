#!/usr/bin/env python3
"""
Score a single Pipeline M v1400.0 alert with the v1400-specific ML model.

This script uses the same SQL and feature engineering as train_stock_winner_model_v1400.py
to ensure scoring consistency with training.

Example usage:
    python python_ml/score_single_alert_v1400.py \
      --model-in python_ml/models/winner_model_v1400.joblib \
      --alert-id 12345 \
      --table trade_alerts
"""
import os
import argparse
from pathlib import Path
from dotenv import load_dotenv

import pandas as pd
import joblib
from sqlalchemy import create_engine, text

# ---------- ENV / DB ----------
def load_parent_env() -> None:
    script_path = Path(__file__).resolve()
    env_path = script_path.parents[1] / ".env"
    if env_path.exists():
        load_dotenv(dotenv_path=env_path, override=False)

def make_engine():
    load_parent_env()
    host = os.environ.get("DB_HOST", "127.0.0.1")
    port = int(os.environ.get("DB_PORT", "3306"))
    name = os.environ.get("DB_DATABASE", "laravelInvest")
    user = os.environ.get("DB_USERNAME", "laravel")
    password = os.environ.get("DB_PASSWORD", "laravel")
    url = f"mysql+pymysql://{user}:{password}@{host}:{port}/{name}"
    return create_engine(url, pool_pre_ping=True)

# ---------- Feature Engineering (v1400-specific) ----------
def add_derived_features_v1400(df: pd.DataFrame) -> pd.DataFrame:
    """Add all standard features plus v1400-specific derived features.
    
    This MUST match the feature engineering in train_stock_winner_model_v1400.py
    to ensure scoring consistency.
    """
    d = df.copy()

    # Standard features from base model
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

    # ========== v1400-SPECIFIC FEATURES ==========
    
    # 1. TREND SMOOTHNESS: Inverse of directional changes (lower = smoother)
    if "five_min_directional_changes" in d.columns:
        # Normalize: 0-10 changes → smoothness 1.0-0.0
        d["trend_smoothness"] = 1.0 - (d["five_min_directional_changes"].astype(float) / 10.0).clip(0, 1)
        d["clean_trend"] = (d["five_min_directional_changes"].astype(float) <= 3).astype(float)
        d["choppy"] = (d["five_min_directional_changes"].astype(float) > 6).astype(float)
    
    # 2. MAX DRAWDOWN PCT: Already loaded from scanner (scanner_max_drawdown_pct)
    # No derivation needed - this is the actual max pullback
    
    # 3. RISK SCORE: Already loaded from scanner (scanner_risk_score)
    # This is Trend % ÷ Max Drawdown % - higher = better risk-adjusted returns
    
    # 4. BARS ABOVE EMA9: Trend persistence (from setup_bars query)
    if "bars_above_ema9_count" in d.columns and "total_bars" in d.columns:
        d["bars_above_ema9_pct"] = (
            d["bars_above_ema9_count"].astype(float) / d["total_bars"].astype(float).clip(lower=1)
        ) * 100.0
        d["strong_trend_persistence"] = (d["bars_above_ema9_pct"].astype(float) > 80).astype(float)
    
    # 5. AVG BAR BODY SIZE: Already loaded (avg_bar_body_size)
    # Clean bars have large bodies, wicks indicate indecision
    if "avg_bar_body_size" in d.columns:
        d["clean_bars"] = (d["avg_bar_body_size"].astype(float) > 0.10).astype(float)
    
    # 6. CHOPPINESS INDEX: Calculated from directional changes and net progress
    if "five_min_directional_changes" in d.columns and "five_min_net_progress" in d.columns:
        # Choppiness = (directional changes / net progress) × 100
        # Lower = cleaner trend (more progress per direction change)
        net_prog = d["five_min_net_progress"].astype(float).clip(lower=0.01)  # Avoid div by zero
        d["choppiness_index"] = (d["five_min_directional_changes"].astype(float) / net_prog) * 10.0
        d["choppiness_index"] = d["choppiness_index"].clip(0, 100)  # Cap at 100
        d["low_choppiness"] = (d["choppiness_index"].astype(float) < 30).astype(float)
    
    # 7. CONSECUTIVE GREEN BARS: Already loaded (max_consecutive_green_bars)
    if "max_consecutive_green_bars" in d.columns:
        d["smooth_momentum"] = (d["max_consecutive_green_bars"].astype(float) >= 5).astype(float)
        d["very_smooth_momentum"] = (d["max_consecutive_green_bars"].astype(float) >= 8).astype(float)
    
    # v1400 COMPOSITE SCORES
    
    # Quality score: Sum of positive v1400 characteristics
    quality_cols = [c for c in d.columns if c in [
        'clean_trend', 'low_choppiness', 'strong_trend_persistence',
        'smooth_momentum', 'clean_bars'
    ]]
    if quality_cols:
        d["v1400_quality_score"] = d[quality_cols].sum(axis=1).astype(float)
    
    # Risk score boost: Combine scanner risk score with quality
    if "scanner_risk_score" in d.columns and "v1400_quality_score" in d.columns:
        d["combined_risk_quality"] = (
            d["scanner_risk_score"].astype(float) * (1 + d["v1400_quality_score"].astype(float) * 0.1)
        )
    
    # Ideal v1400 setup: High risk score + low choppiness + smooth momentum
    ideal_flags = [c for c in d.columns if c in [
        'low_choppiness', 'smooth_momentum', 'strong_trend_persistence'
    ]]
    if ideal_flags and "scanner_risk_score" in d.columns:
        d["ideal_v1400_setup"] = (
            (d[ideal_flags].sum(axis=1) >= 2).astype(float) *
            (d["scanner_risk_score"].astype(float) > 2.0).astype(float)
        )
    
    # Standard over-extension detection (from base model)
    if "alert_rsi_14_1m" in d.columns:
        d["rsi_1m_overbought"] = (d["alert_rsi_14_1m"].astype(float) > 70).astype(float)
        d["rsi_1m_extreme"] = ((d["alert_rsi_14_1m"].astype(float) > 75) | 
                               (d["alert_rsi_14_1m"].astype(float) < 25)).astype(float)
    
    if "vwap_dist_pct" in d.columns:
        d["vwap_extended"] = (d["vwap_dist_pct"].astype(float).abs() > 1.0).astype(float)
    
    if "alert_vol_ratio" in d.columns:
        d["vol_ratio_moderate"] = ((d["alert_vol_ratio"].astype(float) >= 2.0) & 
                                   (d["alert_vol_ratio"].astype(float) <= 4.0)).astype(float)
    
    if "alert_atr_pct" in d.columns:
        d["atr_sweet_spot"] = ((d["alert_atr_pct"].astype(float) >= 0.3) & 
                               (d["alert_atr_pct"].astype(float) <= 0.8)).astype(float)
    
    if "five_min_green_bar_pct" in d.columns:
        d["green_bars_balanced"] = ((d["five_min_green_bar_pct"].astype(float) >= 50) & 
                                    (d["five_min_green_bar_pct"].astype(float) <= 70)).astype(float)

    return d

# ---------- Main ----------
def main():
    parser = argparse.ArgumentParser(
        description="Score a single v1400 alert with v1400-specific ML model"
    )
    parser.add_argument("--model-in", required=True, help="Path to v1400 model (.joblib)")
    parser.add_argument("--alert-id", required=True, type=int, help="Alert ID to score")
    parser.add_argument("--table", default="trade_alerts", help="Table name")
    args = parser.parse_args()

    # Load model
    payload = joblib.load(args.model_in)
    if isinstance(payload, dict) and "model" in payload:
        model = payload["model"]
    else:
        model = payload
    
    model_version = Path(args.model_in).stem
    
    # Connect to database
    engine = make_engine()
    
    # Fetch alert with v1400-specific context data
    # This SQL MUST match train_stock_winner_model_v1400.py
    query = f"""
    WITH ta_base AS (
        SELECT
            ta.id AS alert_id,
            ta.symbol,
            ta.asset_type,
            ta.entry_ts_est,
            ta.signal_ts_est,
            ta.entry,
            ta.stop,
            
            -- Standard alert features
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
            
            -- v1400-specific scanner features
            ta.trend_pct AS scanner_trend_pct,
            ta.max_drawdown_pct AS scanner_max_drawdown_pct,
            ta.risk_score AS scanner_risk_score,
            
            ta.pipeline_run,
            ta.version
        FROM {args.table} ta
        WHERE ta.id = :alert_id
    ),
    -- v1400 SPECIFIC: Compute features from 1-minute bars during setup
    setup_bars AS (
        SELECT
            t.alert_id,
            -- Count bars where price is above EMA9 (trend persistence)
            SUM(CASE WHEN omp.price > omp.ema9 THEN 1 ELSE 0 END) AS bars_above_ema9_count,
            COUNT(*) AS total_bars,
            -- Average bar body size (clean bars have large bodies vs wicks)
            AVG(ABS(omp.close - omp.open)) AS avg_bar_body_size,
            -- Consecutive green bars (max streak)
            MAX(green_streak) AS max_consecutive_green_bars
        FROM ta_base t
        JOIN one_minute_prices omp
          ON omp.symbol = t.symbol
         AND omp.asset_type = t.asset_type
         -- Look at bars in the 2-hour setup window before entry
         AND omp.ts_est BETWEEN (t.entry_ts_est - INTERVAL 120 MINUTE) AND t.entry_ts_est
        LEFT JOIN LATERAL (
            -- Count consecutive green bars ending at current bar
            SELECT COUNT(*) AS green_streak
            FROM one_minute_prices omp2
            WHERE omp2.symbol = t.symbol
              AND omp2.asset_type = t.asset_type
              AND omp2.ts_est <= omp.ts_est
              AND omp2.ts_est >= (omp.ts_est - INTERVAL 30 MINUTE)
              AND omp2.close > omp2.open
            ORDER BY omp2.ts_est DESC
        ) AS streaks ON TRUE
        GROUP BY t.alert_id
    )
    
    SELECT
        t.alert_id,
        t.symbol,
        t.asset_type,
        t.entry_ts_est,
        t.signal_ts_est,
        t.entry,
        t.stop,
        
        -- Standard alert features
        t.alert_score,
        t.alert_vol_ratio,
        t.alert_atr,
        t.alert_atr_pct,
        t.daily_trend_5d_pct,
        t.range_position_60m,
        t.alert_rsi_14_1m,
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
        
        -- v1400-specific scanner features
        t.scanner_trend_pct,
        t.scanner_max_drawdown_pct,
        t.scanner_risk_score,
        
        -- v1400-specific computed features from setup bars
        sb.bars_above_ema9_count,
        sb.total_bars,
        sb.avg_bar_body_size,
        sb.max_consecutive_green_bars,
        
        t.pipeline_run,
        t.version,
        
        -- 1-minute features AT ENTRY
        omp.price AS omp_entry_price,
        omp.open AS omp_open,
        omp.high AS omp_high,
        omp.low AS omp_low,
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
        
        -- 5-minute context
        fmp.price AS fmp_price,
        fmp.open AS fmp_open,
        fmp.high AS fmp_high,
        fmp.low AS fmp_low,
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
    
    LEFT JOIN setup_bars sb
      ON sb.alert_id = t.alert_id
    
    LEFT JOIN five_minute_prices fmp
      ON fmp.symbol = t.symbol
     AND fmp.asset_type = t.asset_type
     AND fmp.ts_est = (
          SELECT MAX(f2.ts_est)
          FROM five_minute_prices f2
          WHERE f2.symbol = t.symbol
            AND f2.asset_type = t.asset_type
            AND f2.ts_est <= t.signal_ts_est
     )
    """
    
    with engine.connect() as conn:
        df = pd.read_sql(text(query), conn, params={"alert_id": args.alert_id})
    
    if df.empty:
        print(f"[v1400] No data found for alert {args.alert_id}")
        return
    
    # Verify this is a v1400 alert
    pipeline_run = df.iloc[0].get('pipeline_run', '')
    version = df.iloc[0].get('version', '')
    
    if pipeline_run != 'M' or version != 'v1400.0':
        print(f"[v1400] WARNING: Alert {args.alert_id} is {pipeline_run} {version}, not Pipeline M v1400.0")
        print(f"[v1400] Consider using score_single_alert.py for non-v1400 alerts")
    
    # Add v1400-specific derived features
    df = add_derived_features_v1400(df)
    
    # Force numeric columns
    numeric_column_names = [
        'entry', 'stop', 'alert_score', 'alert_vol_ratio', 'alert_atr', 'alert_atr_pct',
        'daily_trend_5d_pct', 'range_position_60m', 'alert_rsi_14_1m',
        'five_min_directional_changes', 'five_min_green_bar_pct', 'five_min_net_progress',
        'consolidation_bars', 'breakout_volume_ratio',
        'pct_below_intraday_high', 'minutes_since_high',
        'price_velocity_5min', 'price_velocity_10min', 'failed_rally_count',
        # v1400 scanner features
        'scanner_trend_pct', 'scanner_max_drawdown_pct', 'scanner_risk_score',
        # v1400 computed features
        'bars_above_ema9_count', 'total_bars', 'avg_bar_body_size', 'max_consecutive_green_bars',
        # 1m price context
        'omp_entry_price', 'omp_open', 'omp_high', 'omp_low', 'omp_volume',
        'vwap', 'vwap_dist_pct', 'above_vwap',
        'ema9', 'ema21', 'ema9_ema21_spread', 'ema9_above_ema21',
        'omp_atr', 'omp_atr_pct',
        # 5m price context
        'fmp_price', 'fmp_open', 'fmp_high', 'fmp_low', 'fmp_volume',
        'fmp_vwap', 'fmp_vwap_dist_pct', 'fmp_above_vwap',
        'fmp_ema9', 'fmp_ema21', 'fmp_ema_spread', 'fmp_ema9_above_ema21',
        'fmp_atr', 'fmp_atr_pct', 'fmp_rsi_14',
    ]
    
    for col in numeric_column_names:
        if col in df.columns:
            df[col] = pd.to_numeric(df[col], errors='coerce')
    
    # Define v1400 feature columns (must match training script)
    numeric_features = [
        # Standard features
        "alert_score", "alert_vol_ratio", "alert_atr", "alert_atr_pct",
        "daily_trend_5d_pct", "range_position_60m", "alert_rsi_14_1m",
        "five_min_directional_changes", "five_min_green_bar_pct", "five_min_net_progress",
        "consolidation_bars", "breakout_volume_ratio",
        "pct_below_intraday_high", "minutes_since_high",
        "price_velocity_5min", "price_velocity_10min", "failed_rally_count",
        
        # v1400 scanner features
        "scanner_trend_pct", "scanner_max_drawdown_pct", "scanner_risk_score",
        
        # v1400 computed features
        "bars_above_ema9_count", "total_bars", "avg_bar_body_size", "max_consecutive_green_bars",
        
        # 1m price context
        "omp_entry_price", "omp_open", "omp_high", "omp_low", "omp_volume",
        "vwap", "vwap_dist_pct", "above_vwap",
        "ema9", "ema21", "ema9_ema21_spread", "ema9_above_ema21",
        "omp_atr", "omp_atr_pct",
        
        # 5m price context
        "fmp_price", "fmp_open", "fmp_high", "fmp_low", "fmp_volume",
        "fmp_vwap", "fmp_vwap_dist_pct", "fmp_above_vwap",
        "fmp_ema9", "fmp_ema21", "fmp_ema_spread", "fmp_ema9_above_ema21",
        "fmp_atr", "fmp_atr_pct", "fmp_rsi_14",
        
        # Derived features
        "abs_vwap_dist_pct", "abs_ema_spread",
        "alert_rsi_centered", "fmp_rsi_centered",
        "trend_alignment_1m", "trend_alignment_5m",
        
        # v1400 derived features
        "trend_smoothness", "bars_above_ema9_pct", "choppiness_index",
        "v1400_quality_score", "combined_risk_quality",
        "clean_trend", "low_choppiness", "strong_trend_persistence",
        "smooth_momentum", "very_smooth_momentum", "clean_bars",
        "ideal_v1400_setup",
        
        # Standard flags
        "rsi_1m_overbought", "rsi_1m_extreme", "vwap_extended",
        "vol_ratio_moderate", "atr_sweet_spot", "green_bars_balanced", "choppy",
    ]
    
    # Filter to existing columns
    numeric_features = [c for c in numeric_features if c in df.columns]
    
    # Convert to numeric
    for col in numeric_features:
        df[col] = pd.to_numeric(df[col], errors="coerce")
    
    # Prepare features (reorder to match model's expected order if possible)
    if hasattr(model, 'feature_names_in_'):
        X = df.reindex(columns=model.feature_names_in_)
    else:
        X = df[numeric_features]
    
    # Predict
    try:
        prob = model.predict_proba(X)[0, 1]
        
        print(f"[v1400] Alert {args.alert_id} ({df.iloc[0]['symbol']})")
        print(f"[v1400] Pipeline: {pipeline_run} {version}")
        print(f"[v1400] ML Win Probability: {prob*100:.2f}%")
        print(f"[v1400] Scanner Risk Score: {df.iloc[0].get('scanner_risk_score', 'N/A')}")
        print(f"[v1400] Trend Smoothness: {df.iloc[0].get('trend_smoothness', 'N/A')}")
        print(f"[v1400] Choppiness Index: {df.iloc[0].get('choppiness_index', 'N/A')}")
        
        # Update database
        update_query = f"""
        UPDATE {args.table}
        SET ml_win_prob      = :prob,
        passed_ml        = 1,
            ml_scored_at = NOW(),
            ml_model_version = :model_version
        WHERE id = :alert_id
        """
        
        with engine.connect() as conn:
            result = conn.execute(
                text(update_query),
                {
                    "prob": float(prob),
                    "model_version": model_version,
                    "alert_id": args.alert_id
                }
            )
            conn.commit()
        
        print(f"[v1400] ✅ Alert {args.alert_id} scored and updated in database")
        
    except Exception as e:
        print(f"[v1400] ❌ Error scoring alert {args.alert_id}: {e}")
        raise

if __name__ == "__main__":
    main()
