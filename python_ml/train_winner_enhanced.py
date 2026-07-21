#!/usr/bin/env python3
"""
Enhanced training script that focuses on winner patterns
Uses the existing train_stock_winner_model.py infrastructure
but with winner-focused feature engineering
"""
import sys
import os
sys.path.insert(0, os.path.dirname(__file__))

from train_stock_winner_model import (
    get_db_config_from_env, make_engine, load_training_data,
    make_label, split_by_time, build_model
)
import pandas as pd
import numpy as np
from sklearn.metrics import roc_auc_score, classification_report
import joblib

# Enhanced feature engineering
def add_winner_features(df: pd.DataFrame) -> pd.DataFrame:
    d = df.copy()
    
    # Core features from original
    if 'vwap_dist_pct' in d.columns:
        d['abs_vwap_dist_pct'] = d['vwap_dist_pct'].astype(float).abs()
    
    if 'ema9_ema21_spread' in d.columns:
        d['abs_ema_spread'] = d['ema9_ema21_spread'].astype(float).abs()
    
    if 'alert_rsi_14_1m' in d.columns:
        d['alert_rsi_centered'] = (d['alert_rsi_14_1m'].astype(float) - 50.0) / 50.0
    
    if 'fmp_rsi_14' in d.columns:
        d['fmp_rsi_centered'] = (d['fmp_rsi_14'].astype(float) - 50.0) / 50.0
    
    if 'above_vwap' in d.columns and 'ema9_above_ema21' in d.columns:
        d['trend_alignment_1m'] = (
            d['above_vwap'].fillna(0).astype(float) * d['ema9_above_ema21'].fillna(0).astype(float)
        )
    
    if 'fmp_above_vwap' in d.columns and 'fmp_ema9_above_ema21' in d.columns:
        d['trend_alignment_5m'] = (
            d['fmp_above_vwap'].fillna(0).astype(float) * d['fmp_ema9_above_ema21'].fillna(0).astype(float)
        )
    
    if 'ema9_ema21_spread' in d.columns and 'fmp_ema_spread' in d.columns:
        d['spread_1m_minus_5m'] = d['ema9_ema21_spread'].astype(float) - d['fmp_ema_spread'].astype(float)
    
    # === WINNER-FOCUSED FEATURES ===
    
    # 1. Momentum acceleration (KEY PREDICTOR)
    if 'spread_1m_minus_5m' in d.columns:
        d['momentum_acceleration'] = (d['spread_1m_minus_5m'] > 0).astype(int)
        d['momentum_strength'] = d['spread_1m_minus_5m'].fillna(0).astype(float).abs()
    
    # 2. Volatility signals (winners are MORE volatile)
    if 'alert_atr_pct' in d.columns:
        d['high_volatility'] = (d['alert_atr_pct'].astype(float) >= 0.20).astype(int)
        d['atr_squared'] = d['alert_atr_pct'].astype(float) ** 2
    
    # 3. Pullback entry (winners pull back more before entry)
    if 'pct_below_intraday_high' in d.columns:
        d['pullback_entry'] = (d['pct_below_intraday_high'].astype(float) >= 0.25).astype(int)
        if 'five_min_net_progress' in d.columns:
            d['pullback_strength'] = (
                d['pct_below_intraday_high'].fillna(0).astype(float) * 
                d['five_min_net_progress'].fillna(0).astype(float)
            )
    
    # 4. Velocity indicators
    if 'price_velocity_5min' in d.columns and 'price_velocity_10min' in d.columns:
        d['velocity_avg'] = (
            d['price_velocity_5min'].fillna(0).astype(float) + 
            d['price_velocity_10min'].fillna(0).astype(float)
        ) / 2
        d['velocity_acceleration'] = (
            d['price_velocity_5min'].fillna(0).astype(float) - 
            d['price_velocity_10min'].fillna(0).astype(float)
        )
        d['high_velocity'] = (d['velocity_avg'] >= 0.20).astype(int)
    
    # 5. ATR-adjusted momentum
    if 'alert_atr_pct' in d.columns and 'five_min_net_progress' in d.columns:
        d['atr_adjusted_progress'] = (
            d['five_min_net_progress'].fillna(0).astype(float) * 
            d['alert_atr_pct'].fillna(0).astype(float)
        )
    
    # 6. Composite winner score
    winner_components = ['momentum_acceleration', 'high_volatility', 'pullback_entry', 'high_velocity']
    if all(c in d.columns for c in winner_components):
        d['winner_score'] = sum(d[c].fillna(0).astype(int) for c in winner_components)
    
    return d


if __name__ == "__main__":
    print("=== Winner-Focused Model Training ===\n")
    
    cfg = get_db_config_from_env()
    engine = make_engine(cfg)
    
    # Load data
    print("Loading training data (Jan-Feb 2026)...")
    df = load_training_data(engine, "2026-01-01", "2026-02-26", "trade_alerts")
    print(f"Loaded {len(df)} records\n")
    
    # Add enhanced features
    print("Engineering winner-focused features...")
    df = add_winner_features(df)
    
    # Define feature list (expand base features with new ones)
    base_features = [
        'spread_1m_minus_5m', 'alert_atr', 'alert_atr_pct', 'alert_score',
        'alert_vol_ratio', 'alert_rsi_14_1m', 'alert_rsi_centered',
        'omp_entry_price', 'omp_open', 'omp_high', 'omp_low', 'omp_volume',
        'vwap', 'vwap_dist_pct', 'abs_vwap_dist_pct', 'above_vwap',
        'ema9', 'ema21', 'ema9_ema21_spread', 'abs_ema_spread',
        'ema9_above_ema21', 'omp_atr', 'omp_atr_pct',
        'fmp_price', 'fmp_open', 'fmp_high', 'fmp_low', 'fmp_volume',
        'fmp_vwap', 'fmp_vwap_dist_pct', 'fmp_above_vwap',
        'fmp_ema9', 'fmp_ema21', 'fmp_ema_spread', 'fmp_ema9_above_ema21',
        'fmp_atr', 'fmp_atr_pct', 'fmp_rsi_14', 'fmp_rsi_centered',
        'five_min_directional_changes', 'five_min_green_bar_pct',
        'five_min_net_progress',
        'pct_below_intraday_high', 'minutes_since_high',
        'price_velocity_5min', 'price_velocity_10min', 'failed_rally_count',
        'trend_alignment_1m', 'trend_alignment_5m', 'entry', 'stop'
    ]
    
    # Add winner-focused features
    winner_features = [
        'momentum_acceleration', 'momentum_strength',
        'high_volatility', 'atr_squared',
        'pullback_entry', 'pullback_strength',
        'velocity_avg', 'velocity_acceleration', 'high_velocity',
        'atr_adjusted_progress', 'winner_score'
    ]
    
    all_features = base_features + winner_features
    numeric_features = [f for f in all_features if f in df.columns]
    
    print(f"Using {len(numeric_features)} features ({len(winner_features)} winner-specific)\n")
    
    # Create labels
    y = make_label(df, 1.0)
    print(f"Class distribution: {y.sum()} wins ({100*y.mean():.1f}%), {(~y.astype(bool)).sum()} losses\n")
    
    # Time-based split
    train_df, test_df = split_by_time(df, test_size=0.2)
    y_train = make_label(train_df, 1.0)
    y_test = make_label(test_df, 1.0)
    
    X_train = train_df.reindex(columns=numeric_features)
    X_test = test_df.reindex(columns=numeric_features)
    
    print(f"Train: {len(train_df)} records, Test: {len(test_df)} records\n")
    
    # Build and train model
    print("Training enhanced XGBoost model...")
    model = build_model(numeric_features, use_baseline=False)
    model.fit(X_train, y_train)
    
    # Evaluate
    y_pred_proba = model.predict_proba(X_test)[:, 1]
    y_pred = model.predict(X_test)
    
    auc = roc_auc_score(y_test, y_pred_proba)
    
    print("\n=== Model Performance ===")
    print(f"Test AUC: {auc:.4f}\n")
    
    # Precision at different thresholds
    print("Performance at different ML probability thresholds:")
    for threshold in [0.3, 0.35, 0.4, 0.45, 0.5, 0.6, 0.7]:
        y_thresh = (y_pred_proba >= threshold).astype(int)
        if y_thresh.sum() > 0:
            precision = (y_test[y_thresh == 1]).sum() / y_thresh.sum()
            recall = (y_test[y_thresh == 1]).sum() / y_test.sum()
            selected = y_thresh.sum()
            print(f"  {threshold:.2f}: Precision={precision:.3f} (win rate), Recall={recall:.3f}, Selected={selected} trades")
    
    print(f"\nClassification Report:")
    print(classification_report(y_test, y_pred, target_names=["Loss", "Win"]))
    
    # Feature importance
    xgb_model = model.named_steps['clf']
    importances = xgb_model.feature_importances_
    
    # Features are transformed by 'pre' step (ColumnTransformer)
    used_features = model.named_steps['pre'].get_feature_names_out()
    
    feature_imp = pd.DataFrame({
        'feature': used_features,
        'importance': importances
    }).sort_values('importance', ascending=False)
    
    print("\n=== TOP 20 FEATURES ===")
    print(feature_imp.head(20).to_string(index=False))
    
    print("\n=== WINNER-SPECIFIC FEATURES ===")
    winner_imp = feature_imp[feature_imp['feature'].isin(winner_features)]
    print(winner_imp.to_string(index=False))
    
    # Save model
    output_path = "models/winner_model_enhanced.joblib"
    payload = {
        "model": model,
        "meta": {
            "features": numeric_features,
            "train_size": len(train_df),
            "test_size": len(test_df),
            "metrics": {
                "test_auc": float(auc),
                "model_version": "winner_enhanced_v1.0",
                "win_threshold": 1.0,
            }
        }
    }
    
    joblib.dump(payload, output_path)
    print(f"\nSaved enhanced model to: {output_path}")
