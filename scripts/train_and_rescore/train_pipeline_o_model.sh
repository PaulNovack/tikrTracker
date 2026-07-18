#!/bin/bash

source "$(dirname "$0")/model_env.sh"

TODAY=$(date +%Y-%m-%d)
MODEL_OUT="${MODEL_OUT:-$(get_pipeline_model_path "O" "python_ml/models/winner_model_pipeline_o.joblib")}"

# Train Pipeline O Model (Opening Range Breakout)
# 30-minute opening range breakouts on market movers (2%+ gainers)
# Expected: 3000+ alerts

set -e

echo "=================================================="
echo "Training Pipeline O Model (Opening Range Breakout)"
echo "=================================================="
echo "Start time: $(date)"
echo ""

# Train Pipeline O specific model
python python_ml/v2/v2/train_stock_winner_model_v2.py train \
  --pipeline O \
  --win-threshold 2.0 \
  --start 2025-01-01 \
  --end "$TODAY" \
  --test-size 0.2 \
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
echo "   TRADING_ML_PIPELINE_O_MODEL_PATH=$MODEL_OUT"
echo "2. Run: php artisan config:clear"
echo "3. Monitor logs for 'Using Pipeline O specific model'"
