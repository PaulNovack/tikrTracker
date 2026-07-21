#!/usr/bin/env python3
"""
Train ML model from historical backtest data (trade_alerts table).

Uses large dataset from backtests but carefully avoids lookahead bias by:
1. Only using features available at entry time
2. Filtering to analyzed trades only
3. Using recent data (last 3-6 months for market relevance)
4. Proper train/test split with temporal ordering
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
from sklearn.calibration import CalibratedClassifierCV
from xgboost import XGBClassifier
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


def load_backtest_data(engine, version: str, start_date: str, end_date: str) -> pd.DataFrame:
    """
    Load training data from analyzed backtest trades.
    
    CRITICAL: Only uses features available at entry time!
    """
    
    query = text("""
    SELECT
        -- Trade identification
        ta.id,
        ta.symbol,
        ta.trading_date_est,
        ta.entry_ts_est,
        ta.signal_type,
        ta.entry_type,
        
        -- Features AVAILABLE AT ENTRY TIME (no lookahead!)
        ta.score as entry_score,
        ta.vol_ratio as vol_ratio_1m,
        ta.atr_pct,
        ta.risk_pct,
        ta.consolidation_bars,
        ta.breakout_volume_ratio,
        ta.five_min_green_bar_pct,
        ta.five_min_net_progress,
        ta.five_min_directional_changes,
        ta.rsi_14_1m,
        
        -- Time features
        HOUR(ta.entry_ts_est) as hour_of_day,
        DAYOFWEEK(ta.trading_date_est) as day_of_week,
        
        -- Outcome (for training target)
        ta.pnl_percent,
        ta.exit_reason,
        ta.hold_time_minutes
        
    FROM trade_alerts ta
    
    WHERE ta.analyzed = 1
        AND ta.version = :version
        AND ta.trading_date_est BETWEEN :start_date AND :end_date
        AND ta.pnl_percent IS NOT NULL
        AND ta.exit_price IS NOT NULL
        
    ORDER BY ta.trading_date_est, ta.entry_ts_est
    """)
    
    print(f"\nLoading backtest data (version={version})...")
    df = pd.read_sql(query, engine, params={
        "version": version,
        "start_date": start_date,
        "end_date": end_date
    })
    
    print(f"✓ Loaded {len(df):,} analyzed trades from {start_date} to {end_date}")
    print(f"  Symbols: {df['symbol'].nunique()}")
    print(f"  Date range: {df['trading_date_est'].min()} to {df['trading_date_est'].max()}")
    
    return df


def engineer_features(df: pd.DataFrame) -> pd.DataFrame:
    """Add derived features (only using data available at entry time)"""
    
    # Fill missing values
    df = df.fillna({
        'entry_score': 0,
        'vol_ratio_1m': 1.0,
        'atr_pct': 1.5,
        'risk_pct': 2.0,
        'consolidation_bars': 0,
        'breakout_volume_ratio': 1.0,
        'five_min_green_bar_pct': 50.0,
        'five_min_net_progress': 0.0,
        'five_min_directional_changes': 0,
        'rsi_14_1m': 50.0
    })
    
    # Time-based features
    df["is_morning"] = (df["hour_of_day"] >= 10) & (df["hour_of_day"] < 12)
    df["is_midday"] = (df["hour_of_day"] >= 12) & (df["hour_of_day"] < 14)
    df["is_afternoon"] = df["hour_of_day"] >= 14
    
    # Volume strength
    df["high_volume"] = (df["vol_ratio_1m"] >= 2.5).astype(int)
    df["vol_strength"] = df["vol_ratio_1m"].clip(0, 10)  # Cap outliers
    
    # Quality indicators
    df["strong_consolidation"] = (df["consolidation_bars"] >= 10).astype(int)
    df["strong_breakout"] = (df["breakout_volume_ratio"] >= 2.0).astype(int)
    df["bullish_5min"] = (df["five_min_green_bar_pct"] >= 60).astype(int)
    
    # RSI zones
    df["rsi_oversold"] = (df["rsi_14_1m"] < 30).astype(int)
    df["rsi_neutral"] = ((df["rsi_14_1m"] >= 30) & (df["rsi_14_1m"] <= 70)).astype(int)
    df["rsi_overbought"] = (df["rsi_14_1m"] > 70).astype(int)
    
    # Signal type dummies
    df["is_long_breakout"] = (df["signal_type"] == "long_breakout").astype(int)
    df["is_short_fade"] = (df["signal_type"] == "short_fade").astype(int)
    
    return df


def train_model(df: pd.DataFrame, win_threshold: float, test_size: float = 0.2):
    """Train XGBoost on backtest data with temporal train/test split"""
    
    # Define target
    df["is_win"] = (df["pnl_percent"] >= win_threshold).astype(int)
    
    # Feature columns (only those available at entry time!)
    feature_cols = [
        # Core signals
        "entry_score",
        "vol_ratio_1m",
        "vol_strength",
        "atr_pct",
        "risk_pct",
        
        # Pattern quality
        "consolidation_bars",
        "breakout_volume_ratio",
        "strong_consolidation",
        "strong_breakout",
        
        # 5-minute context
        "five_min_green_bar_pct",
        "five_min_net_progress",
        "five_min_directional_changes",
        "bullish_5min",
        
        # RSI
        "rsi_14_1m",
        "rsi_oversold",
        "rsi_neutral",
        "rsi_overbought",
        
        # Time
        "hour_of_day",
        "day_of_week",
        "is_morning",
        "is_midday",
        "is_afternoon",
        
        # Signal type
        "is_long_breakout",
        "is_short_fade",
        
        # Volume
        "high_volume"
    ]
    
    # Remove any rows with NaN in features or target
    df_clean = df[feature_cols + ["is_win", "trading_date_est"]].dropna()
    
    # Temporal split: Train on earlier dates, test on later dates
    df_sorted = df_clean.sort_values("trading_date_est")
    split_idx = int(len(df_sorted) * (1 - test_size))
    
    train_df = df_sorted.iloc[:split_idx]
    test_df = df_sorted.iloc[split_idx:]
    
    X_train = train_df[feature_cols]
    y_train = train_df["is_win"]
    X_test = test_df[feature_cols]
    y_test = test_df["is_win"]
    
    train_date = train_df["trading_date_est"].iloc[-1] if len(train_df) > 0 else None
    
    print(f"\n✓ Training on {len(X_train):,} trades (through {train_date})")
    print(f"  Winners: {y_train.sum()} ({y_train.mean()*100:.1f}%)")
    print(f"  Losers: {len(y_train) - y_train.sum()}")
    
    print(f"\n✓ Testing on {len(X_test):,} trades (after {train_date})")
    print(f"  Winners: {y_test.sum()} ({y_test.mean()*100:.1f}%)")
    print(f"  Losers: {len(y_test) - y_test.sum()}")
    
    # Calculate class weights for imbalanced data
    n_neg = (y_train == 0).sum()
    n_pos = (y_train == 1).sum()
    scale_pos_weight = n_neg / n_pos if n_pos > 0 else 1.0
    
    print(f"  Class weight: {scale_pos_weight:.2f} (balancing {n_neg} losses vs {n_pos} wins)")
    
    # Train XGBoost
    model = XGBClassifier(
        n_estimators=100,
        max_depth=4,
        learning_rate=0.1,
        scale_pos_weight=scale_pos_weight,
        random_state=42,
        eval_metric="logloss"
    )
    
    model.fit(X_train, y_train)
    
    # Calibrate probabilities (using CV on training set)
    calibrated_model = CalibratedClassifierCV(model, method="isotonic", cv=3)
    calibrated_model.fit(X_train, y_train)
    
    # Evaluate on test set
    y_pred = calibrated_model.predict(X_test)
    y_proba = calibrated_model.predict_proba(X_test)[:, 1]
    
    # Calculate discrimination (avg probability difference)
    avg_win_prob = y_proba[y_test == 1].mean() if (y_test == 1).sum() > 0 else 0
    avg_loss_prob = y_proba[y_test == 0].mean() if (y_test == 0).sum() > 0 else 0
    discrimination = (avg_win_prob - avg_loss_prob) * 100
    
    print("\n" + "=" * 60)
    print("TEST SET RESULTS (Out-of-sample)")
    print("=" * 60)
    print(f"\nAccuracy: {(y_pred == y_test).mean():.3f}")
    if len(np.unique(y_test)) > 1:
        print(f"AUC: {roc_auc_score(y_test, y_proba):.3f}")
    
    print(f"\nModel Discrimination: {discrimination:.1f}%")
    print(f"  Winners predict: {avg_win_prob*100:.1f}%")
    print(f"  Losers predict: {avg_loss_prob*100:.1f}%")
    if discrimination < 10:
        print("  ⚠️  WARNING: Low discrimination (<10%) - model may not be useful")
    elif discrimination < 15:
        print("  ⚠️  Moderate discrimination (10-15%) - proceed with caution")
    else:
        print("  ✓ Good discrimination (>15%) - model shows predictive power")
    
    print("\nConfusion Matrix:")
    tn, fp, fn, tp = confusion_matrix(y_test, y_pred).ravel()
    print(f"  True Negatives:  {tn}")
    print(f"  False Positives: {fp}")
    print(f"  False Negatives: {fn}")
    print(f"  True Positives:  {tp}")
    
    print("\nClassification Report:")
    print(classification_report(y_test, y_pred, target_names=["Loss", "Win"]))
    
    # Show top predictions from test set
    test_with_proba = test_df.copy()
    test_with_proba["ml_prob"] = y_proba
    test_with_proba["pnl_percent"] = df.loc[test_df.index, "pnl_percent"]
    test_with_proba["symbol"] = df.loc[test_df.index, "symbol"]
    
    print("\n" + "=" * 60)
    print("TOP 10 TEST PREDICTIONS (Highest ML Confidence)")
    print("=" * 60)
    top_preds = test_with_proba.nlargest(10, "ml_prob")
    for _, row in top_preds.iterrows():
        outcome = "WIN" if row["pnl_percent"] >= win_threshold else "LOSS"
        print(f"{row['symbol']:<6s} ML: {row['ml_prob']*100:4.1f}% → {outcome:4s} (P&L: {row['pnl_percent']:+.2f}%)")
    
    # Feature importance
    print("\n" + "=" * 60)
    print("FEATURE IMPORTANCE")
    print("=" * 60)
    importances = pd.Series(model.feature_importances_, index=feature_cols)
    for feat, imp in importances.nlargest(15).items():
        print(f"{feat:30s} {imp:.4f}")
    
    return calibrated_model, feature_cols


def main():
    parser = argparse.ArgumentParser(description="Train ML model from backtest data")
    parser.add_argument("--version", default="v60.3", help="Pipeline version to use")
    parser.add_argument("--start", required=True, help="Start date (YYYY-MM-DD)")
    parser.add_argument("--end", required=True, help="End date (YYYY-MM-DD)")
    parser.add_argument("--win-threshold", type=float, default=0.5, help="Win threshold %")
    parser.add_argument("--test-size", type=float, default=0.2, help="Test set size (0-1)")
    parser.add_argument("--model-out", default="python_ml/models/winner_model_backtest.joblib", 
                       help="Output model path")
    
    args = parser.parse_args()
    
    print("=" * 60)
    print("TRAINING FROM BACKTEST DATA")
    print("=" * 60)
    print(f"Version: {args.version}")
    print(f"Date range: {args.start} to {args.end}")
    print(f"Win threshold: {args.win_threshold}%")
    print(f"Test size: {args.test_size*100:.0f}%")
    print(f"Output: {args.model_out}")
    
    # Load data
    engine = make_engine()
    df = load_backtest_data(engine, args.version, args.start, args.end)
    
    if len(df) < 100:
        print(f"\n❌ ERROR: Only {len(df)} trades found. Need at least 100 for training.")
        sys.exit(1)
    
    # Engineer features
    df = engineer_features(df)
    
    # Train model
    model, feature_cols = train_model(df, args.win_threshold, args.test_size)
    
    # Save model
    model_path = Path(args.model_out)
    model_path.parent.mkdir(parents=True, exist_ok=True)
    
    joblib.dump({
        "model": model,
        "features": feature_cols,
        "version": args.version,
        "trained_at": datetime.now().isoformat(),
        "train_start": args.start,
        "train_end": args.end,
        "win_threshold": args.win_threshold,
        "n_trades": len(df)
    }, model_path)
    
    print(f"\n✓ Model saved to {args.model_out}")
    print("\nNext steps:")
    print(f"1. Update .env: TRADING_ML_MODEL_PATH={args.model_out}")
    print("2. Run: php artisan config:clear")
    print("3. Test on tomorrow's trades")
    print("4. Compare predicted vs actual win rate")


if __name__ == "__main__":
    main()
