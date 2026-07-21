#!/usr/bin/env python3
"""
Score a single trade alert with ML model and update database.
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
    env_path = script_path.parents[2] / ".env"
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

# ---------- Feature Engineering (same as training) ----------
def add_derived_features(df: pd.DataFrame) -> pd.DataFrame:
    """Add same derived features used during training."""
    d = df.copy()
    
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
        d["trend_alignment_1m"] = d["above_vwap"].fillna(0).astype(float) * d["ema9_above_ema21"].fillna(0).astype(float)

    if "fmp_above_vwap" in d.columns and "fmp_ema9_above_ema21" in d.columns:
        d["trend_alignment_5m"] = d["fmp_above_vwap"].fillna(0).astype(float) * d["fmp_ema9_above_ema21"].fillna(0).astype(float)

    if "ema9_ema21_spread" in d.columns and "fmp_ema_spread" in d.columns:
        d["spread_1m_minus_5m"] = d["ema9_ema21_spread"].astype(float) - d["fmp_ema_spread"].astype(float)
    
    # ========== OVER-EXTENSION DETECTION FEATURES (2026-03-03) ==========
    
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

# ---------- Main ----------
def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("--model-in", required=True, help="Path to trained model (.joblib)")
    parser.add_argument("--alert-id", required=True, type=int, help="Alert ID to score")
    parser.add_argument("--table", default="trade_alerts", help="Table name (trade_alerts or trade_alerts_unfiltered)")
    args = parser.parse_args()

    # Load model
    payload = joblib.load(args.model_in)
    # Model is saved as {"model": ..., "meta": ...}
    if isinstance(payload, dict) and "model" in payload:
        model = payload["model"]
        model_meta = payload.get("meta", {})
    else:
        model = payload
        model_meta = {}
    
    model_version = Path(args.model_in).stem
    
    # Connect to database
    engine = make_engine()
    
    # Fetch alert with context data
    query = f"""
    SELECT
        ta.id AS alert_id,
        ta.symbol,
        ta.asset_type,
        ta.entry_ts_est,
        ta.signal_ts_est,
        ta.entry,
        ta.stop,
        
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
        
    FROM {args.table} ta
    LEFT JOIN one_minute_prices omp 
        ON omp.symbol = ta.symbol
        AND omp.asset_type = ta.asset_type
        AND omp.ts_est = ta.entry_ts_est
    LEFT JOIN five_minute_prices fmp
        ON fmp.symbol = ta.symbol
        AND fmp.asset_type = ta.asset_type
        AND fmp.ts_est = (
            SELECT MAX(ts_est) 
            FROM five_minute_prices 
            WHERE symbol = ta.symbol 
              AND asset_type = ta.asset_type 
              AND ts_est <= ta.signal_ts_est
        )
    WHERE ta.id = :alert_id
    """
    
    with engine.connect() as conn:
        df = pd.read_sql(text(query), conn, params={"alert_id": args.alert_id})
    
    if df.empty:
        print(f"No data found for alert {args.alert_id}")
        return
    
    # Add derived features
    df = add_derived_features(df)
    
    # Force numeric columns to be float (handles NULL values correctly)
    # These columns might be stored as Object type if all values are NULL
    numeric_column_names = [
        'entry', 'stop', 'alert_score', 'alert_vol_ratio', 'alert_atr', 'alert_atr_pct',
        'alert_rsi_14_1m', 'five_min_directional_changes', 'five_min_green_bar_pct',
        'five_min_net_progress', 'consolidation_bars', 'breakout_volume_ratio',
        'pct_below_intraday_high', 'minutes_since_high', 'price_velocity_5min',
        'price_velocity_10min', 'failed_rally_count',
        'omp_entry_price', 'omp_open', 'omp_high', 'omp_low', 'omp_volume',
        'vwap', 'vwap_dist_pct', 'above_vwap', 'ema9', 'ema21', 'ema9_ema21_spread',
        'ema9_above_ema21', 'omp_atr', 'omp_atr_pct',
        'fmp_price', 'fmp_open', 'fmp_high', 'fmp_low', 'fmp_volume', 'fmp_vwap',
        'fmp_vwap_dist_pct', 'fmp_above_vwap', 'fmp_ema9', 'fmp_ema21',
        'fmp_ema_spread', 'fmp_ema9_above_ema21', 'fmp_atr', 'fmp_atr_pct', 'fmp_rsi_14',
        # Derived features
        'abs_vwap_dist_pct', 'abs_ema_spread', 'alert_rsi_centered', 'fmp_rsi_centered',
        'trend_alignment_1m', 'trend_alignment_5m',
    ]
    
    for col in numeric_column_names:
        if col in df.columns:
            df[col] = pd.to_numeric(df[col], errors='coerce')
    
    # Get feature columns (same as training)
    drop_cols = {
        "alert_id", "symbol", "asset_type", "entry_ts_est", "signal_ts_est",
    }
    feature_cols = [c for c in df.columns if c not in drop_cols]
    numeric_cols = [c for c in feature_cols if pd.api.types.is_numeric_dtype(df[c])]
    
    # Filter features to match what model was trained on
    # If model has feature_names_ attribute, use only those features
    if hasattr(model, 'feature_names_in_'):
        model_features = list(model.feature_names_in_)
        numeric_cols = [c for c in numeric_cols if c in model_features]
    elif hasattr(model, 'get_booster') and hasattr(model.get_booster(), 'feature_names'):
        # XGBoost models
        model_features = model.get_booster().feature_names
        numeric_cols = [c for c in numeric_cols if c in model_features]
    
    # Prepare features (reorder to match model's expected order if possible)
    if hasattr(model, 'feature_names_in_'):
        X = df.reindex(columns=model.feature_names_in_)
    else:
        X = df[numeric_cols]
    
    # Predict
    try:
        prob = model.predict_proba(X)[0, 1]
        
        # Update database
        update_query = f"""
        UPDATE {args.table}
        SET ml_win_prob = :prob,
            ml_scored_at = NOW(),
        passed_ml = 1,
            ml_model_version = :model_version
        WHERE id = :alert_id
        """
        
        with engine.connect() as conn:
            conn.execute(
                text(update_query),
                {
                    "prob": float(prob),
                    "model_version": model_version,
                    "alert_id": args.alert_id
                }
            )
            conn.commit()
        
        print(f"Scored alert {args.alert_id}: ml_win_prob = {prob:.6f}")
        
    except Exception as e:
        print(f"Error scoring alert {args.alert_id}: {str(e)}")
        raise

if __name__ == "__main__":
    main()
