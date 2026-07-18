#!/bin/bash

source "$(dirname "$0")/model_env.sh"

TODAY=$(date +%Y-%m-%d)
MODEL_OUT="${MODEL_OUT:-$(get_pipeline_model_path "H" "python_ml/models/winner_model_pipeline_hid.joblib")}"

# Train Pipeline H+I+D Combined Model
# H (v25.2): Quality-first, top performers + bounce candidates
# I (v17.0): Loose filters, same universe as H base
# D (v60.3): Hybrid - v17 universe + EntryScore confirmation (most architecturally similar)
# Combined: ~11,500 alerts — enough for robust training

set -e

echo "=================================================="
echo "Training Pipeline H+I+D Combined Model (v2 trainer)"
echo "=================================================="
echo "Start time: $(date)"
echo ""

# Train combined H+I+D model
python python_ml/v2/v2/train_stock_winner_model_v2.py train \
  --pipeline H,I,D \
  --win-threshold 2.0 \
  --actual-fill-weight 20.0 \
  --eval-on-actual-only \
  --start 2024-01-01 \
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
echo "1. Update .env for pipelines H, I, and D:"
echo "   TRADING_ML_PIPELINE_H_MODEL_PATH=$MODEL_OUT"
echo "   TRADING_ML_PIPELINE_I_MODEL_PATH=$MODEL_OUT"
echo "   TRADING_ML_PIPELINE_D_MODEL_PATH=$MODEL_OUT"
echo "2. Set v2 scorer for each pipeline in .env:"
echo "   TRADING_ML_SCORER_SCRIPT_PIPELINE_H=python_ml/v2/v2/score_single_alert_v2.py"
echo "   TRADING_ML_SCORER_SCRIPT_PIPELINE_I=python_ml/v2/v2/score_single_alert_v2.py"
echo "   TRADING_ML_SCORER_SCRIPT_PIPELINE_D=python_ml/v2/v2/score_single_alert_v2.py"
echo "3. Run: php artisan config:clear"
echo "4. Monitor logs for the v2 single-alert scorer output"
