#!/bin/bash

source "$(dirname "$0")/model_env.sh"

START_DATE=2025-01-01
END_DATE=$(date -d tomorrow +%Y-%m-%d)
MODEL_OUT="${MODEL_OUT:-$(get_pipeline_model_path "P" "python_ml/models/winner_model_pipeline_p.joblib")}"

# Train Pipeline P Model (Forward-Looking 2-Hour Runner)
# Uses forward-looking v2100.0 entry finder for research backtesting only

set -e

echo "=================================================="
echo "Training Pipeline P Model (Forward-Looking 2-Hour Runner)"
echo "=================================================="
echo "Start time: $(date)"
echo ""

python python_ml/v2/v2/train_stock_winner_model_v2.py train \
  --pipeline P \
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
echo "   TRADING_ML_PIPELINE_P_MODEL_PATH=$MODEL_OUT"
echo "2. Run: php artisan config:clear"
