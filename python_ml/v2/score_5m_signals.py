#!/usr/bin/env python3
"""
Score 5-minute signals with ML model before entry finding.
This is a lightweight pre-filter to reject low-probability setups early.

Usage:
    python python_ml/v2/score_5m_signals.py --signals-json '["AAPL", "MSFT"]' --model python_ml/models/winner_model_xgb.joblib
    
Returns JSON with ML probabilities for each signal.
"""
import os
import sys
import json
import argparse
from pathlib import Path
from dotenv import load_dotenv

import pandas as pd
import numpy as np
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

# Features used in training (simplified for 5m signal scoring)
FEATURE_COLS = [
    'score', 'vol_ratio', 'risk_pct', 'atr_pct',
    'entry_hour', 'entry_minute',
    'five_min_directional_changes', 'five_min_green_bar_pct', 
    'five_min_net_progress', 'consolidation_bars', 'breakout_volume_ratio'
]

def score_signals(signals_data: list, model_path: str) -> dict:
    """
    Score 5m signals with ML model.
    
    Args:
        signals_data: List of dicts with signal metadata
        model_path: Path to trained model
        
    Returns:
        Dict mapping symbol -> ml_win_prob
    """
    try:
        # Load model
        model_path_obj = Path(model_path)
        if not model_path_obj.exists():
            return {"error": f"Model not found: {model_path}"}
        
        model_data = joblib.load(model_path)
        model = model_data.get("model")
        if model is None:
            return {"error": "Model object not found in saved file"}
        
        # Convert signals to DataFrame
        df = pd.DataFrame(signals_data)
        
        # Extract features from signal metadata
        features = []
        for _, sig in df.iterrows():
            meta = sig.get('meta', {})
            signal_ts = sig.get('signal_ts_est', '')
            
            # Parse hour/minute from timestamp
            try:
                from datetime import datetime
                dt = datetime.strptime(signal_ts, '%Y-%m-%d %H:%M:%S')
                entry_hour = dt.hour
                entry_minute = dt.minute
            except:
                entry_hour = 10  # Default
                entry_minute = 0
            
            feat_dict = {
                'score': sig.get('score', 50.0),
                'vol_ratio': sig.get('vol_ratio', 1.0),
                'risk_pct': 1.0,  # Estimate for 5m signal
                'atr_pct': meta.get('atr_pct', 0.5),
                'entry_hour': entry_hour,
                'entry_minute': entry_minute,
                'five_min_directional_changes': meta.get('five_min_directional_changes', 2),
                'five_min_green_bar_pct': meta.get('five_min_green_bar_pct', 50.0),
                'five_min_net_progress': meta.get('five_min_net_progress', 0.0),
                'consolidation_bars': meta.get('consolidation_bars_5m', 3),
                'breakout_volume_ratio': sig.get('vol_ratio', 1.0),
            }
            features.append(feat_dict)
        
        X = pd.DataFrame(features)
        
        # Ensure all required columns exist
        for col in FEATURE_COLS:
            if col not in X.columns:
                X[col] = 0.0
        
        # Predict probabilities
        X_model = X[FEATURE_COLS].fillna(0)
        probs = model.predict_proba(X_model)[:, 1]  # Probability of class 1 (winner)
        
        # Create result mapping
        result = {}
        for i, sig in df.iterrows():
            symbol = sig.get('symbol')
            result[symbol] = float(probs[i])
        
        return result
        
    except Exception as e:
        return {"error": str(e)}

def main():
    parser = argparse.ArgumentParser(description="Score 5m signals with ML model")
    parser.add_argument("--signals-json", required=True, help="JSON array of signal objects")
    parser.add_argument("--model", required=True, help="Path to trained model .joblib file")
    
    args = parser.parse_args()
    
    try:
        # Parse signals JSON
        signals = json.loads(args.signals_json)
        
        # Score signals
        scores = score_signals(signals, args.model)
        
        # Output as JSON
        print(json.dumps(scores, indent=2))
        
    except Exception as e:
        print(json.dumps({"error": str(e)}), file=sys.stderr)
        sys.exit(1)

if __name__ == "__main__":
    main()
