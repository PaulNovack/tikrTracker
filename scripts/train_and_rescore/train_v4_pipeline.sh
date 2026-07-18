#!/bin/bash
# Train a single pipeline model with the v4 trainer.
# Usage: ML_VERSION=v4 bash train_v4_pipeline.sh J 2024-01-01

set -e

cd "$(dirname "$0")/../.." || exit

source scripts/train_and_rescore/model_env_v4.sh

PIPELINE="${1:-N}"
START_DATE="${2:-2024-01-01}"
END_DATE="${3:-$(date -d tomorrow +%Y-%m-%d)}"

TRAINER=$(get_trainer_cmd)
MODEL_OUT="python_ml/models/winner_model_pipeline_$(echo "$PIPELINE" | tr '[:upper:]' '[:lower:]')_v4.joblib"

echo "=================================================="
echo "Training Pipeline $PIPELINE v4 Model"
echo "Trainer: $TRAINER"
echo "Date range: $START_DATE → $END_DATE"
echo "Output: $MODEL_OUT"
echo "Start time: $(date)"
echo "=================================================="
echo ""

$TRAINER \
  --pipeline "$PIPELINE" \
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
echo "v4 training complete!"
echo "Model: $MODEL_OUT"
echo "End time: $(date)"
echo "=================================================="
