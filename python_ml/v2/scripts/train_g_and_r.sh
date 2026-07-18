#!/bin/bash
# v2/scripts/train_g_and_r.sh — Train Pipeline G & R with XGBoost.
#
# Pipeline R has grown to ~2,200 records (up from ~500), enough for XGBoost.
# Pipeline G still has ~500-600 records but XGBoost with conservative params
# (max_depth=3, min_child_weight=8) handles small datasets well.
#
# Model paths are set in .env:
#   TRADING_ML_PIPELINE_G_MODEL_PATH  → winner_model_pipeline_XGB_G.joblib
#   TRADING_ML_PIPELINE_R_MODEL_PATH  → winner_model_pipeline_XGB_R.joblib
#
# Usage:
#   bash python_ml/v2/scripts/train_g_and_r.sh

set -e

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
source "$SCRIPT_DIR/env.sh"

TODAY=$(date +%Y-%m-%d)
TRAINER=$(get_trainer_cmd)

echo "=========================================================="
echo "  G & R XGBoost Training"
echo "  Trainer: $TRAINER"
echo "  Date: $TODAY"
echo "=========================================================="
echo ""

train_one() {
    local PIPELINE="$1"
    local START="$2"
    local LOWER
    LOWER="$(echo "$PIPELINE" | tr '[:upper:]' '[:lower:]')"

    local MODEL_OUT
    MODEL_OUT="$(get_pipeline_model_path "$PIPELINE" "python_ml/v2/models/winner_model_pipeline_XGB_${LOWER}.joblib")"
    local LOG_FILE="${V2_DIR}/training_logs/${PIPELINE}-${TODAY}.log"

    echo "----------------------------------------------------------"
      echo "  Pipeline $PIPELINE (XGBoost)"
    echo "  Date range: $START → $TODAY"
    echo "  Output: $MODEL_OUT"
    echo "  Log: $LOG_FILE"
    echo "----------------------------------------------------------"
    echo ""

    $TRAINER \
        --pipeline "$PIPELINE" \
          --win-threshold 2.0 \
          --actual-fill-weight 20.0 \
        --start "$START" \
        --end "$TODAY" \
        --test-size 0.2 \
        --top-k 10 \
        --train-full \
        --model-out "$MODEL_OUT" 2>&1 | tee "$LOG_FILE"

    echo ""
    echo "  ✅ Pipeline $PIPELINE training complete"
    echo ""

    persist_auc_p10_from_log "$LOG_FILE" "$PIPELINE"
    echo ""
}

# Pipeline G — Oversold Bounce (v210.0) — ~500-600 records
train_one "G" "2024-01-01"

# Pipeline R — Backtest-Optimized ML (v3100.0)
train_one "R" "2024-01-01"

echo "=========================================================="
echo "  G & R training complete!"
echo "=========================================================="
