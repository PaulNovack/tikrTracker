#!/usr/bin/env python3
"""
Train ML model from ACTUAL LIVE TRADING RESULTS (alpaca_orders table).

This fixes the backtest-to-live performance gap by training on:
- Real entry/exit fills (not simulated backtest prices)
- Real execution timing (not perfect hindsight)
- Real market conditions (slippage, gaps, liquidity)

Key differences from train_stock_winner_model.py:
1. Uses alpaca_orders table (actual fills) instead of trade_alerts (backtest)
2. Calculates P&L from filled_avg_price (real) not entry_price (simulated)
3. Only includes features available AT ORDER TIME (no lookahead)
4. Joins trade_alerts for signal features, but outcomes from alpaca_orders

Usage:
    python python_ml/train_from_live_trades.py \
        --start "2026-02-24" \
        --end "2026-03-02" \
        --win-threshold 0.5 \
        --model-out python_ml/models/winner_model_live.joblib
"""

import os
import argparse
from pathlib import Path
from dotenv import load_dotenv
import numpy as np
import pandas as pd
from sqlalchemy import create_engine, text
from sklearn.metrics import roc_auc_score, classification_report, confusion_matrix
from sklearn.model_selection import train_test_split
from sklearn.pipeline import Pipeline
from sklearn.compose import ColumnTransformer
from sklearn.preprocessing import StandardScaler
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


def load_live_trading_data(engine, start_date: str, end_date: str) -> pd.DataFrame:
    """
    Load training data from ACTUAL LIVE TRADES.
    
    Returns one row per completed trade with:
    - Features: Available at order time (from trade_alerts)
    - Outcome: Actual P&L from alpaca_orders fills
    """
    
    query = text("""
    WITH ranked_sells AS (
        SELECT 
            sell.id as sell_id,
            sell.symbol,
            sell.filled_avg_price,
            sell.created_at as sell_time,
            buy.id as buy_id,
            ROW_NUMBER() OVER (PARTITION BY buy.id ORDER BY sell.created_at) as sell_rank
        FROM alpaca_orders buy
        INNER JOIN alpaca_orders sell
            ON sell.symbol = buy.symbol
            AND sell.side = 'sell'
            AND sell.status = 'filled'
            AND sell.created_at > buy.created_at
            AND DATE(sell.created_at) = DATE(buy.created_at)
        WHERE buy.side = 'buy'
            AND buy.status = 'filled'
            AND DATE(buy.created_at) BETWEEN :start_date AND :end_date
    )
    SELECT
        -- Trade identification
        buy.symbol,
        buy.created_at as trade_date,
        buy.trade_alert_id,
        
        -- Entry details (ACTUAL fills, not estimates)
        buy.filled_avg_price as entry_price,
        buy.filled_qty as qty,
        
        -- Exit details (ACTUAL fills) - from first matching sell
        rs.filled_avg_price as exit_price,
        
        -- Calculate ACTUAL P&L (not backtest P&L)
        ((rs.filled_avg_price - buy.filled_avg_price) / buy.filled_avg_price) * 100 as pnl_percent,
        
        -- Signal features from trade_alerts (available at order time)
        ta.entry_type,
        ta.signal_type,
        ta.pipeline_run,
        ta.score as entry_score,
        ta.vol_ratio as vol_ratio_1m,
        ta.atr_pct,
        ta.risk_pct,
        
        -- Price action features (from 1m data at entry time)
        omp.vwap_dist_pct as entry_vwap_dist_pct,
        omp.ema9_ema21_spread,
        omp.ema9_above_ema21,
        omp.above_vwap as entry_above_vwap,
        
        -- Time features
        HOUR(buy.created_at) as hour_of_day,
        DAYOFWEEK(buy.created_at) as day_of_week
        
    FROM alpaca_orders buy
    
    -- Match with FIRST sell order after this buy
    INNER JOIN ranked_sells rs
        ON rs.buy_id = buy.id
        AND rs.sell_rank = 1
    
    -- Join trade alert for signal features
    LEFT JOIN trade_alerts ta 
        ON ta.id = buy.trade_alert_id
    
    -- Join 1m prices at entry time for market context (closest bar only, no duplicates)
    LEFT JOIN one_minute_prices omp
        ON omp.id = (
            SELECT id FROM one_minute_prices
            WHERE symbol = buy.symbol
              AND trading_date_est = DATE(buy.created_at)
              AND ts_est <= buy.created_at
            ORDER BY ts_est DESC
            LIMIT 1
        )
    
    WHERE buy.side = 'buy'
        AND buy.status = 'filled'
        AND DATE(buy.created_at) BETWEEN :start_date AND :end_date
        
    ORDER BY buy.created_at
    """)
    
    df = pd.read_sql(query, engine, params={"start_date": start_date, "end_date": end_date})
    
    print(f"✓ Loaded {len(df)} completed trades from {start_date} to {end_date}")
    print(f"  Symbols: {df['symbol'].nunique()}")
    print(f"  Date range: {df['trade_date'].min()} to {df['trade_date'].max()}")
    
    return df


def add_derived_features(df: pd.DataFrame) -> pd.DataFrame:
    """Add engineered features that are safe (no lookahead)"""
    
    # Risk metrics
    df["risk_pct"] = df["atr_pct"] * 2.5  # Typical stop distance
    
    # Time buckets (market phases)
    df["is_morning"] = (df["hour_of_day"] >= 10) & (df["hour_of_day"] < 12)
    df["is_afternoon"] = (df["hour_of_day"] >= 12) & (df["hour_of_day"] < 15)
    df["is_late"] = df["hour_of_day"] >= 15
    
    # Volume strength
    df["vol_strength"] = df["vol_ratio_1m"].fillna(1.0)
    df["high_volume"] = (df["vol_strength"] >= 2.0).astype(int)
    
    # VWAP position
    df["near_vwap"] = (df["entry_vwap_dist_pct"].abs() < 0.5).astype(int)
    
    # Entry quality score
    df["entry_quality"] = (
        (df["entry_score"].fillna(0) / 100) +  # Normalize score
        (df["vol_strength"].clip(0, 5) / 5) +  # Cap volume bonus
        (df["near_vwap"] * 0.5)  # VWAP bonus
    ) / 2.5
    
    return df


def train_live_model(df: pd.DataFrame, win_threshold: float):
    """Train XGBoost on actual live trading results with time-based train/test split."""

    # Define target
    df["is_win"] = (df["pnl_percent"] >= win_threshold).astype(int)

    # Feature columns (only those available at order time!)
    feature_cols = [
        "entry_score",
        "vol_ratio_1m",
        "atr_pct",
        "entry_vwap_dist_pct",
        "ema9_ema21_spread",
        "ema9_above_ema21",
        "entry_above_vwap",
        "hour_of_day",
        "day_of_week",
        "risk_pct",
        "is_morning",
        "is_afternoon",
        "is_late",
        "vol_strength",
        "high_volume",
        "near_vwap",
        "entry_quality",
    ]

    # Drop rows with missing critical features
    df_clean = df[feature_cols + ["is_win", "pnl_percent", "symbol", "trade_date"]].dropna()

    # --- Time-based train/test split (last 20% of dates = test set) ---
    # IMPORTANT: Do NOT use random shuffle — financial data must be split by time
    # to avoid future leakage (training on Monday, testing on the prior Friday).
    df_clean = df_clean.sort_values("trade_date").reset_index(drop=True)
    split_idx = int(len(df_clean) * 0.80)
    df_train = df_clean.iloc[:split_idx]
    df_test = df_clean.iloc[split_idx:]

    print(f"\n✓ Train/test split (time-based, 80/20):")
    print(f"  Train: {len(df_train)} trades  ({df_train['trade_date'].iloc[0].date()} → {df_train['trade_date'].iloc[-1].date()})")
    print(f"  Test:  {len(df_test)} trades  ({df_test['trade_date'].iloc[0].date()} → {df_test['trade_date'].iloc[-1].date()})")
    print(f"  Train win rate: {df_train['is_win'].mean()*100:.1f}%")
    print(f"  Test win rate:  {df_test['is_win'].mean()*100:.1f}%")

    if len(df_test) < 10:
        print("\n⚠️  WARNING: Test set has fewer than 10 samples — metrics will be unreliable.")
        print("   Collect more live trades before trusting this model.")

    X_train = df_train[feature_cols]
    y_train = df_train["is_win"]
    X_test = df_test[feature_cols]
    y_test = df_test["is_win"]

    # Calculate class weights from training set only
    n_wins = int(y_train.sum())
    n_losses = int(len(y_train) - n_wins)
    scale_pos_weight = n_losses / n_wins if n_wins > 0 else 1.0

    print(f"\n  Class weight: {scale_pos_weight:.2f} (train: {n_losses} losses vs {n_wins} wins)")

    # Build pipeline
    preprocessor = ColumnTransformer(
        transformers=[("num", "passthrough", feature_cols)],
        remainder="drop",
    )

    model = Pipeline([
        ("pre", preprocessor),
        ("clf", XGBClassifier(
            n_estimators=100,
            max_depth=3,          # Shallower tree — less overfitting on small datasets
            learning_rate=0.05,   # Slower learning — more robust
            min_child_weight=5,   # Require at least 5 samples per leaf
            subsample=0.8,        # Row sampling — reduces overfitting
            colsample_bytree=0.8, # Feature sampling — reduces overfitting
            reg_alpha=0.1,        # L1 regularization
            reg_lambda=1.0,       # L2 regularization
            scale_pos_weight=scale_pos_weight,
            random_state=42,
            eval_metric="logloss",
        )),
    ])

    model.fit(X_train, y_train)

    # --- Evaluate on HELD-OUT TEST set ---
    y_test_pred = model.predict(X_test)
    y_test_prob = model.predict_proba(X_test)[:, 1]

    # Also capture train metrics for overfitting comparison
    y_train_prob = model.predict_proba(X_train)[:, 1]
    train_auc = roc_auc_score(y_train, y_train_prob)
    test_auc = roc_auc_score(y_test, y_test_prob) if y_test.nunique() > 1 else float("nan")
    test_acc = (y_test == y_test_pred).mean()

    print("\n" + "=" * 60)
    print("RESULTS (held-out test set)")
    print("=" * 60)
    print(f"\n  Train AUC: {train_auc:.3f}  (overfitting check — should be close to test)")
    print(f"  Test  AUC: {test_auc:.3f}  ← the number that matters")
    print(f"  Test  Acc: {test_acc:.3f}")

    if train_auc - test_auc > 0.15:
        print("\n⚠️  Train AUC >> Test AUC — model is overfitting. Consider more data or simpler model.")

    cm = confusion_matrix(y_test, y_test_pred)
    print(f"\nConfusion Matrix (test):")
    print(f"  True Negatives:  {cm[0, 0]}")
    print(f"  False Positives: {cm[0, 1]}")
    print(f"  False Negatives: {cm[1, 0]}")
    print(f"  True Positives:  {cm[1, 1]}")

    print("\nClassification Report (test):")
    print(classification_report(y_test, y_test_pred, target_names=["Loss", "Win"]))

    # Top predictions on test set
    df_test_scored = df_test.copy()
    df_test_scored["ml_prob"] = y_test_prob
    df_test_scored = df_test_scored.sort_values("ml_prob", ascending=False)

    print("\n" + "=" * 60)
    print("TOP 10 PREDICTIONS on TEST SET (Highest ML Confidence)")
    print("=" * 60)
    for _, row in df_test_scored.head(10).iterrows():
        outcome = "WIN" if row["is_win"] else "LOSS"
        print(f"{row['symbol']:6s} ML:{row['ml_prob']*100:5.1f}% → {outcome:4s} (P&L: {row['pnl_percent']:+.2f}%)")

    # Feature importance
    feature_importance = model.named_steps["clf"].feature_importances_
    importance_df = pd.DataFrame({
        "feature": feature_cols,
        "importance": feature_importance,
    }).sort_values("importance", ascending=False)

    print("\n" + "=" * 60)
    print("FEATURE IMPORTANCE")
    print("=" * 60)
    for _, row in importance_df.head(10).iterrows():
        print(f"{row['feature']:20s} {row['importance']:.4f}")

    metrics = {
        "train_auc": float(train_auc),
        "test_auc": float(test_auc),
        "test_accuracy": float(test_acc),
        "n_train": len(df_train),
        "n_test": len(df_test),
        "n_wins_train": n_wins,
        "n_losses_train": n_losses,
        "train_win_rate": float(y_train.mean()),
        "test_win_rate": float(y_test.mean()),
        "scale_pos_weight": float(scale_pos_weight),
        "feature_importance": importance_df.to_dict("records"),
    }

    return model, metrics, feature_cols


def main():
    parser = argparse.ArgumentParser(description="Train ML model from live trading results")
    parser.add_argument("--start", required=True, help="Start date YYYY-MM-DD")
    parser.add_argument("--end", required=True, help="End date YYYY-MM-DD")
    parser.add_argument("--win-threshold", type=float, default=0.5, help="Win threshold percent (default: 0.5)")
    parser.add_argument("--model-out", default="python_ml/models/winner_model_live.joblib", help="Output model path")
    
    args = parser.parse_args()
    
    print("="*60)
    print("TRAINING FROM LIVE TRADES")
    print("="*60)
    print(f"Date range: {args.start} to {args.end}")
    print(f"Win threshold: {args.win_threshold}%")
    print(f"Output: {args.model_out}")
    print()
    
    # Load data
    engine = make_engine()
    df = load_live_trading_data(engine, args.start, args.end)
    
    if df.empty:
        print("❌ No completed trades found in date range!")
        print("   Make sure you have buy+sell pairs in alpaca_orders table")
        return 1
    
    # Engineer features
    df = add_derived_features(df)
    
    # Train model
    model, metrics, feature_cols = train_live_model(df, args.win_threshold)
    
    # Save model
    os.makedirs(os.path.dirname(args.model_out), exist_ok=True)
    
    payload = {
        "model": model,
        "meta": {
            "win_threshold_pct": args.win_threshold,
            "data_source": "alpaca_orders (live trades)",
            "date_range": f"{args.start} to {args.end}",
            "metrics": metrics,
            "feature_cols": feature_cols,
            "model_version": "live_v1.0"
        }
    }
    
    joblib.dump(payload, args.model_out)
    
    print(f"\n✓ Model saved to {args.model_out}")
    print(f"  Train AUC: {metrics['train_auc']:.3f}  |  Test AUC: {metrics['test_auc']:.3f}")
    print("\nNext steps:")
    print("1. Update .env: TRADING_ML_MODEL_PATH=python_ml/models/winner_model_live.joblib")
    print("2. Test on tomorrow's trades")
    print("3. Compare predicted vs actual win rate")
    
    return 0


if __name__ == "__main__":
    exit(main())
