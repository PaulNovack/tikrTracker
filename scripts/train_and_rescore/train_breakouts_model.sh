#!/bin/bash
# Train Breakout/Range Model (Pipelines C, D)
# Institutional breakouts with range expansion
# Expected: 6000+ combined alerts

source "$(dirname "$0")/model_env.sh"

set -e

TODAY=$(date +%Y-%m-%d)
MODEL_OUT="${MODEL_OUT:-$(get_pipeline_model_path "C" "python_ml/models/winner_model_breakouts.joblib")}"

if [[ -z "$TODAY" ]]; then
  echo "ERROR: Failed to compute today's date for --end parameter."
  exit 1
fi

echo "=================================================="
echo "Training Breakouts Model (Pipelines C, D)"
echo "=================================================="
echo "Start time: $(date)"
echo ""

# Train combined breakout model
python python_ml/v2/v2/train_stock_winner_model_v2.py train \
  --pipeline C,D \
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
echo "1. Update .env for pipelines C, D:"
echo "   TRADING_ML_PIPELINE_C_MODEL_PATH=$MODEL_OUT"
echo "   TRADING_ML_PIPELINE_D_MODEL_PATH=$MODEL_OUT"
echo "2. Run: php artisan config:clear"
echo "3. Monitor logs for 'Using Pipeline X specific model'"
