#!/bin/bash

source "$(dirname "$0")/model_env.sh"

TODAY=$(date +%Y-%m-%d)
MODEL_OUT="${MODEL_OUT:-$(get_pipeline_model_path "M" "python_ml/models/winner_model_pipeline_m.joblib")}"

# Train Pipeline M Model (Tight Stops Clean Trend)
# Low-choppiness smooth trends suitable for tight stops
# Expected: 3000+ alerts

set -e

echo "=================================================="
echo "Training Pipeline M Model (Tight Stops Clean Trend)"
echo "=================================================="
echo "Start time: $(date)"
echo ""

# Train Pipeline M specific model
python python_ml/v2/v2/train_stock_winner_model_v2.py train \
  --pipeline M \
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
echo "   TRADING_ML_PIPELINE_M_MODEL_PATH=$MODEL_OUT"
echo "2. Run: php artisan config:clear"
echo "3. Monitor logs for 'Using Pipeline M specific model'"
