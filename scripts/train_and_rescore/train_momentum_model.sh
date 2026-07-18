#!/bin/bash

source "$(dirname "$0")/model_env.sh"

TODAY=$(date +%Y-%m-%d)
MODEL_OUT="${MODEL_OUT:-$(get_pipeline_model_path "A" "python_ml/models/winner_model_momentum.joblib")}"

# Train Momentum Continuation Model (Pipelines A, B, N)
# Multi-day runners with continuation bias
# Expected: 9000+ combined alerts

set -e

echo "=================================================="
echo "Training Momentum Model (Pipelines A, B, N)"
echo "=================================================="
echo "Start time: $(date)"
echo ""

# Train combined momentum model
python python_ml/v2/v2/train_stock_winner_model_v2.py train \
  --pipeline A,B,N \
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
echo "1. Update .env for pipelines A, B, N:"
echo "   TRADING_ML_PIPELINE_A_MODEL_PATH=$MODEL_OUT"
echo "   TRADING_ML_PIPELINE_B_MODEL_PATH=$MODEL_OUT"
echo "   TRADING_ML_PIPELINE_N_MODEL_PATH=$MODEL_OUT"
echo "2. Run: php artisan config:clear"
echo "3. Monitor logs for 'Using Pipeline X specific model'"
