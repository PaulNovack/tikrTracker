#!/usr/bin/env python3
"""
Train ML model from upgraded pipeline backtest data.

This script is designed to train on the institutional-quality signals
from the 2026-03-02 pipeline upgrades (1.2% moves, 3.5x volume, 94+ scores).

Usage:
    # After running February 2026 backtest with upgraded filters
    python python_ml/train_from_upgraded_backtest.py --month=2026-02
    
    # Or specify exact date range
    python python_ml/train_from_upgraded_backtest.py \
        --start-date=2026-02-01 \
        --end-date=2026-02-28
"""

import argparse
import os
import sys
from pathlib import Path
from datetime import datetime

import pandas as pd
import numpy as np
from dotenv import load_dotenv
from sqlalchemy import create_engine, text
from sklearn.metrics import roc_auc_score, classification_report, confusion_matrix
from sklearn.model_selection import train_test_split
from xgboost import XGBClassifier
from sklearn.pipeline import Pipeline
from sklearn.compose import ColumnTransformer
from sklearn.impute import SimpleImputer
import joblib

# Load .env from parent directory
env_path = Path(__file__).resolve().parents[1] / ".env"
load_dotenv(dotenv_path=env_path)


def make_engine():
    """Create SQLAlchemy engine from Laravel .env"""
    host = os.getenv("DB_HOST", "127.0.0.1")
    port = os.getenv("DB_PORT", "3306")
    database = os.getenv("DB_DATABASE", "laravelInvest")
    username = os.getenv("DB_USERNAME", "laravel")
    password = os.getenv("DB_PASSWORD", "laravel")
    
    conn_str = f"mysql+pymysql://{username}:{password}@{host}:{port}/{database}"
    return create_engine(conn_str, pool_pre_ping=True)


def load_upgraded_backtest_data(engine, start_date: str, end_date: str, min_score: float = 93.0) -> pd.DataFrame:
    """
    Load training data from upgraded pipeline backtests.
    
    Filters to institutional-quality signals:
    - Entry scores >= 93 (top 7%)
    - Volume ratios >= 3.0x (institutional breakouts)
    - Analyzed trades only (have outcomes)
    - From upgraded pipeline versions (A-I except F)
    """
    
    # Updated pipeline versions (from 2026-03-02 upgrade)
    upgraded_versions = ['v90.1', 'v120.0', 'v600.0', 'v60.3', 'v400.0', 'v210.0', 'v25.2', 'v17.0']
    version_filter = ", ".join([f"'{v}'" for v in upgraded_versions])
    
    query = text(f"""
    SELECT
        -- Trade identification
        ta.id,
        ta.symbol,
        ta.trading_date_est,
        ta.entry_ts_est,
        ta.signal_type,
        ta.entry_type,
        ta.version,
        
        -- Entry-time features (no lookahead!)
        ta.score as entry_score,
        ta.vol_ratio as vol_ratio_1m,
        ta.atr_pct as alert_atr_pct,
        ta.risk_pct,
        ta.consolidation_bars,
        ta.breakout_volume_ratio,
        ta.five_min_green_bar_pct,
        ta.five_min_net_progress,
        ta.five_min_directional_changes,
        ta.rsi_14_1m as alert_rsi_14_1m,
        ta.pct_below_intraday_high,
        ta.minutes_since_high,
        ta.price_velocity_5min,
        ta.price_velocity_10min,
        ta.failed_rally_count,
        ta.avg_dollar_volume_per_minute,
        
        -- Time features
        HOUR(ta.entry_ts_est) as hour_of_day,
        DAYOFWEEK(ta.trading_date_est) as day_of_week,
        MINUTE(ta.entry_ts_est) as minute_of_hour,
        
        -- Outcome (target variable)
        ta.pnl_percent,
        ta.exit_reason,
        ta.r_multiple,
        ta.hold_time_minutes,
        ta.max_adverse_excursion,
        ta.target_hit
        
    FROM trade_alerts ta
    WHERE ta.trading_date_est >= :start_date
      AND ta.trading_date_est <= :end_date
      AND ta.analyzed = 1
      AND ta.pnl_percent IS NOT NULL
      AND ta.score >= :min_score
      AND ta.vol_ratio >= 3.0
      AND ta.version IN ({version_filter})
    ORDER BY ta.entry_ts_est ASC
    """)
    
    print(f"Loading upgraded backtest data from {start_date} to {end_date}...")
    print(f"Filtering: score >= {min_score}, vol_ratio >= 3.0x")
    print(f"Pipeline versions: {', '.join(upgraded_versions)}\n")
    
    df = pd.read_sql(query, engine, params={
        'start_date': start_date,
        'end_date': end_date,
        'min_score': min_score
    })
    
    print(f"Loaded {len(df)} trades")
    
    if len(df) == 0:
        raise ValueError("No data found! Have you run the backtest yet?")
    
    return df


def add_winner_features(df: pd.DataFrame) -> pd.DataFrame:
    """
    Add winner-focused features (same as train_winner_enhanced.py)
    """
    d = df.copy()
    
    # 1. Momentum acceleration (KEY PREDICTOR from previous model)
    if 'price_velocity_5min' in d.columns and 'price_velocity_10min' in d.columns:
        d['momentum_acceleration'] = (
            (d['price_velocity_5min'].fillna(0) - d['price_velocity_10min'].fillna(0)) > 0
        ).astype(int)
        d['momentum_strength'] = (
            d['price_velocity_5min'].fillna(0) - d['price_velocity_10min'].fillna(0)
        ).abs()
        d['velocity_avg'] = (
            d['price_velocity_5min'].fillna(0) + d['price_velocity_10min'].fillna(0)
        ) / 2
    
    # 2. Volatility signals (winners are MORE volatile)
    if 'alert_atr_pct' in d.columns:
        d['high_volatility'] = (d['alert_atr_pct'].fillna(0) >= 0.20).astype(int)
        d['atr_squared'] = d['alert_atr_pct'].fillna(0) ** 2
    
    # 3. Pullback entry patterns
    if 'pct_below_intraday_high' in d.columns:
        d['pullback_entry'] = (d['pct_below_intraday_high'].fillna(0) >= 0.25).astype(int)
        if 'five_min_net_progress' in d.columns:
            d['pullback_strength'] = (
                d['pct_below_intraday_high'].fillna(0) * 
                d['five_min_net_progress'].fillna(0)
            )
    
    # 4. Volume quality indicators
    if 'vol_ratio_1m' in d.columns and 'breakout_volume_ratio' in d.columns:
        d['volume_quality'] = d['vol_ratio_1m'].fillna(0) * d['breakout_volume_ratio'].fillna(0)
        d['extreme_volume'] = (d['vol_ratio_1m'].fillna(0) >= 5.0).astype(int)
    
    # 5. Score quality
    if 'entry_score' in d.columns:
        d['elite_score'] = (d['entry_score'].fillna(0) >= 96).astype(int)
        d['score_squared'] = (d['entry_score'].fillna(0) / 100.0) ** 2
    
    # 6. Pattern quality
    if 'five_min_green_bar_pct' in d.columns and 'five_min_directional_changes' in d.columns:
        d['clean_trend'] = (
            (d['five_min_green_bar_pct'].fillna(0) >= 0.60) & 
            (d['five_min_directional_changes'].fillna(0) <= 5)
        ).astype(int)
    
    # 7. Timing features
    if 'hour_of_day' in d.columns:
        d['is_power_hour'] = ((d['hour_of_day'] >= 9) & (d['hour_of_day'] <= 11)).astype(int)
        d['is_afternoon'] = (d['hour_of_day'] >= 13).astype(int)
    
    # 8. Risk-reward setup
    if 'risk_pct' in d.columns and 'alert_atr_pct' in d.columns:
        d['risk_to_atr'] = d['risk_pct'].fillna(0) / (d['alert_atr_pct'].fillna(0) + 0.01)
    
    return d


def build_model(numeric_features: list) -> Pipeline:
    """
    Build XGBoost pipeline optimized for institutional-quality signals
    """
    preprocessor = ColumnTransformer(
        transformers=[
            ('num', SimpleImputer(strategy='median'), numeric_features)
        ]
    )
    
    # XGBoost with balanced settings
    xgb_clf = XGBClassifier(
        n_estimators=300,
        max_depth=6,
        learning_rate=0.05,
        subsample=0.8,
        colsample_bytree=0.8,
        min_child_weight=3,
        gamma=0.1,
        scale_pos_weight=1.0,  # Will be adjusted based on class balance
        random_state=42,
        n_jobs=-1,
        tree_method='hist'
    )
    
    pipeline = Pipeline([
        ('pre', preprocessor),
        ('clf', xgb_clf)
    ])
    
    return pipeline


def make_labels(df: pd.DataFrame, win_threshold: float = 1.0) -> pd.Series:
    """Create binary labels: 1 if pnl_percent >= threshold, else 0"""
    return (df['pnl_percent'].astype(float) >= win_threshold).astype(int)


def split_by_time(df: pd.DataFrame, test_size: float = 0.2) -> tuple:
    """Split by time to preserve temporal ordering"""
    split_idx = int(len(df) * (1 - test_size))
    train_df = df.iloc[:split_idx].copy()
    test_df = df.iloc[split_idx:].copy()
    return train_df, test_df


def evaluate_model(model, X_test, y_test, test_df):
    """Comprehensive model evaluation"""
    y_pred_proba = model.predict_proba(X_test)[:, 1]
    y_pred = model.predict(X_test)
    
    auc = roc_auc_score(y_test, y_pred_proba)
    
    print("\n" + "="*60)
    print("MODEL PERFORMANCE ON INSTITUTIONAL-QUALITY SIGNALS")
    print("="*60)
    print(f"\nTest AUC: {auc:.4f}")
    
    # Win rate at different thresholds
    print("\n" + "-"*60)
    print("Win Rate at Different ML Probability Thresholds:")
    print("-"*60)
    print(f"{'Threshold':<12} {'Win Rate':<12} {'Trades':<10} {'Recall':<10}")
    print("-"*60)
    
    for threshold in [0.3, 0.4, 0.45, 0.5, 0.55, 0.6, 0.65, 0.7]:
        y_thresh = (y_pred_proba >= threshold).astype(int)
        if y_thresh.sum() > 0:
            win_rate = (y_test[y_thresh == 1]).sum() / y_thresh.sum()
            recall = (y_test[y_thresh == 1]).sum() / y_test.sum()
            selected = y_thresh.sum()
            print(f"{threshold:<12.2f} {win_rate:<12.1%} {selected:<10} {recall:<10.1%}")
    
    print("\n" + "-"*60)
    print("Classification Report:")
    print("-"*60)
    print(classification_report(y_test, y_pred, target_names=["Loss", "Win"]))
    
    # Discrimination (difference in win rates between top and bottom)
    top_20_pct = y_pred_proba >= np.percentile(y_pred_proba, 80)
    bottom_20_pct = y_pred_proba <= np.percentile(y_pred_proba, 20)
    
    if top_20_pct.sum() > 0 and bottom_20_pct.sum() > 0:
        top_win_rate = y_test[top_20_pct].mean()
        bottom_win_rate = y_test[bottom_20_pct].mean()
        discrimination = top_win_rate - bottom_win_rate
        
        print(f"\nDiscrimination Analysis:")
        print(f"  Top 20% win rate:    {top_win_rate:.1%}")
        print(f"  Bottom 20% win rate: {bottom_win_rate:.1%}")
        print(f"  Discrimination:      {discrimination:.1%}")
        
        if discrimination < 0.10:
            print("  ⚠️  WARNING: Low discrimination (<10%) - model may not be useful")
        elif discrimination < 0.20:
            print("  ℹ️  FAIR: Moderate discrimination (10-20%)")
        else:
            print("  ✅ GOOD: Strong discrimination (>20%)")
    
    return auc


def analyze_feature_importance(model, feature_names):
    """Analyze and display feature importance"""
    xgb_model = model.named_steps['clf']
    importances = xgb_model.feature_importances_
    
    # Get actual features used (preprocessor may have dropped some)
    try:
        actual_features = model.named_steps['pre'].get_feature_names_out()
        # Strip the 'num__' prefix added by ColumnTransformer
        actual_features = [f.replace('num__', '') for f in actual_features]
    except:
        # Fallback: assume same length (may fail if features were dropped)
        actual_features = feature_names[:len(importances)]
    
    feature_imp = pd.DataFrame({
        'feature': actual_features,
        'importance': importances
    }).sort_values('importance', ascending=False)
    
    print("\n" + "="*60)
    print("TOP 20 MOST IMPORTANT FEATURES")
    print("="*60)
    for idx, row in feature_imp.head(20).iterrows():
        print(f"{row['feature']:<40} {row['importance']:>6.1%}")
    
    # Highlight winner-specific features
    winner_features = [
        'momentum_acceleration', 'momentum_strength', 'high_volatility',
        'pullback_entry', 'pullback_strength', 'volume_quality',
        'extreme_volume', 'elite_score', 'clean_trend', 'risk_to_atr'
    ]
    
    winner_imp = feature_imp[feature_imp['feature'].isin(winner_features)]
    if len(winner_imp) > 0:
        print("\n" + "-"*60)
        print("WINNER-SPECIFIC FEATURES:")
        print("-"*60)
        for idx, row in winner_imp.iterrows():
            print(f"{row['feature']:<40} {row['importance']:>6.1%}")


def main():
    parser = argparse.ArgumentParser(description='Train ML model from upgraded backtest data')
    parser.add_argument('--start-date', type=str, help='Start date (YYYY-MM-DD)')
    parser.add_argument('--end-date', type=str, help='End date (YYYY-MM-DD)')
    parser.add_argument('--month', type=str, help='Month shorthand (YYYY-MM), e.g. 2026-02')
    parser.add_argument('--min-score', type=float, default=93.0, help='Minimum entry score (default: 93)')
    parser.add_argument('--win-threshold', type=float, default=1.0, help='Win threshold in % (default: 1.0)')
    parser.add_argument('--test-size', type=float, default=0.2, help='Test set size (default: 0.2)')
    parser.add_argument('--output', type=str, default='models/winner_model_upgraded.joblib', help='Output path')
    
    args = parser.parse_args()
    
    # Handle month shorthand
    if args.month:
        year, month = args.month.split('-')
        args.start_date = f"{year}-{month}-01"
        # Last day of month (simplified)
        if month == '02':
            args.end_date = f"{year}-{month}-28"
        elif month in ['04', '06', '09', '11']:
            args.end_date = f"{year}-{month}-30"
        else:
            args.end_date = f"{year}-{month}-31"
    
    if not args.start_date or not args.end_date:
        print("Error: Must specify either --month or both --start-date and --end-date")
        sys.exit(1)
    
    print("="*60)
    print("TRAINING ML MODEL FROM UPGRADED PIPELINE BACKTEST")
    print("="*60)
    print(f"Date Range: {args.start_date} to {args.end_date}")
    print(f"Min Score: {args.min_score}")
    print(f"Win Threshold: {args.win_threshold}%")
    print(f"Test Size: {args.test_size * 100:.0f}%\n")
    
    # Load data
    engine = make_engine()
    df = load_upgraded_backtest_data(engine, args.start_date, args.end_date, args.min_score)
    
    # Add winner-focused features
    df = add_winner_features(df)
    
    # Define feature set
    # Note: entry_score now standardized 0-100 across all pipelines (2026-03-02)
    base_features = [
        'entry_score', 'vol_ratio_1m', 'alert_atr_pct', 'risk_pct',
        'consolidation_bars', 'breakout_volume_ratio',
        'five_min_green_bar_pct', 'five_min_net_progress', 'five_min_directional_changes',
        'alert_rsi_14_1m', 'pct_below_intraday_high', 'minutes_since_high',
        'price_velocity_5min', 'price_velocity_10min', 'failed_rally_count',
        'avg_dollar_volume_per_minute', 'hour_of_day', 'day_of_week', 'minute_of_hour'
    ]
    
    winner_features = [
        'momentum_acceleration', 'momentum_strength', 'velocity_avg',
        'high_volatility', 'atr_squared',
        'pullback_entry', 'pullback_strength',
        'volume_quality', 'extreme_volume',
        'elite_score', 'score_squared',
        'clean_trend', 'is_power_hour', 'is_afternoon',
        'risk_to_atr'
    ]
    
    all_features = base_features + winner_features
    numeric_features = [f for f in all_features if f in df.columns]
    
    print(f"\nUsing {len(numeric_features)} features:")
    print(f"  - {len([f for f in base_features if f in df.columns])} base features")
    print(f"  - {len([f for f in winner_features if f in df.columns])} winner-specific features")
    
    # Create labels
    y = make_labels(df, args.win_threshold)
    wins = y.sum()
    losses = len(y) - wins
    win_rate = wins / len(y)
    
    print(f"\nClass Distribution:")
    print(f"  Wins:   {wins:>5} ({win_rate:>6.1%})")
    print(f"  Losses: {losses:>5} ({(1-win_rate):>6.1%})")
    
    if win_rate < 0.30:
        print("\n⚠️  WARNING: Win rate < 30%. Data quality may still be insufficient.")
        print("   Consider waiting for more live trading data with upgraded filters.")
    
    # Time-based split
    train_df, test_df = split_by_time(df, args.test_size)
    y_train = make_labels(train_df, args.win_threshold)
    y_test = make_labels(test_df, args.win_threshold)
    
    X_train = train_df.reindex(columns=numeric_features)
    X_test = test_df.reindex(columns=numeric_features)
    
    print(f"\nTrain: {len(train_df)} trades ({y_train.mean():.1%} win rate)")
    print(f"Test:  {len(test_df)} trades ({y_test.mean():.1%} win rate)")
    
    # Build model with class weight adjustment
    model = build_model(numeric_features)
    
    # Adjust scale_pos_weight for class imbalance
    scale_pos_weight = losses / wins if wins > 0 else 1.0
    model.named_steps['clf'].set_params(scale_pos_weight=scale_pos_weight)
    
    print(f"\nTraining XGBoost with scale_pos_weight={scale_pos_weight:.2f}...")
    model.fit(X_train, y_train)
    
    # Evaluate
    auc = evaluate_model(model, X_test, y_test, test_df)
    
    # Feature importance
    analyze_feature_importance(model, numeric_features)
    
    # Save model
    payload = {
        "model": model,
        "meta": {
            "features": numeric_features,
            "train_size": len(train_df),
            "test_size": len(test_df),
            "date_range": f"{args.start_date} to {args.end_date}",
            "min_score": args.min_score,
            "metrics": {
                "test_auc": float(auc),
                "train_win_rate": float(y_train.mean()),
                "test_win_rate": float(y_test.mean()),
                "model_version": "winner_upgraded_v1.0",
                "win_threshold": args.win_threshold,
                "trained_on": datetime.now().isoformat(),
            }
        }
    }
    
    joblib.dump(payload, args.output)
    
    print("\n" + "="*60)
    print(f"✅ Model saved to: {args.output}")
    print("="*60)
    
    print("\nNext Steps:")
    print(f"1. Update .env: TRADING_ML_MODEL_PATH={args.output}")
    print("2. Restart trading: sudo supervisorctl restart laravel-invest-worker:*")
    print("3. Monitor performance for 1-2 weeks")
    print("4. Retrain with live data: python python_ml/train_from_upgraded_backtest.py --month=2026-03\n")


if __name__ == '__main__':
    main()
