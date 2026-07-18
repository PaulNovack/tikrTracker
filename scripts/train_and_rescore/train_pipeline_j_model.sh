#!/bin/bash

source "$(dirname "$0")/model_env.sh"

START_DATE=2025-01-01
END_DATE=$(date -d tomorrow +%Y-%m-%d)
MODEL_OUT="${MODEL_OUT:-$(get_pipeline_model_path "J" "python_ml/models/winner_model_pipeline_j.joblib")}"

# Train Pipeline J Model (Higher-Low Breakout)
# Morning momentum (09:40-10:10) higher-low breakout pattern
# Expected: 2000+ alerts (if strategy viable)

set -e

echo "=================================================="
echo "Training Pipeline J Model (Higher-Low Breakout)"
echo "=================================================="
echo "Start time: $(date)"
echo ""

# Train Pipeline J specific model
# Fix: win-threshold 1.0→2.0 (removes noise labels), actual-fill-weight 5x→20x (honors real data),
# eval-on-actual-only gives honest metrics on live-fill performance
python python_ml/v2/v2/train_stock_winner_model_v2.py train \
  --pipeline J \
  --win-threshold 2.0 \
  --actual-fill-weight 20.0 \
  --eval-on-actual-only \
  --split-mode day \
  --start "$START_DATE" \
  --end "$END_DATE" \
  --test-size 0.2 \
  --top-k 10 \
  --train-full \
  --model-out "$MODEL_OUT"

echo ""
echo "=================================================="
echo "Training complete!"
echo "End time: $(date)"
echo "Model saved: $MODEL_OUT"
echo "=================================================="
echo ""
echo "Next steps:"
echo "1. Update .env:"
echo "   TRADING_ML_PIPELINE_J_MODEL_PATH=$MODEL_OUT"
echo "2. Run: php artisan config:clear"
echo "3. Monitor logs for 'Using Pipeline J specific model'"
