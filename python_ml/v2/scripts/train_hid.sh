#!/bin/bash
# v2/scripts/train_hid.sh — Train H+I+D pipeline separately.
# Runs the combined H,I,D training as a standalone job.
# Mirrors the H+I+D section from retrain_all.sh.
#
# Usage:
#   bash python_ml/v2/scripts/train_hid.sh
#   bash python_ml/v2/scripts/train_hid.sh 2024-06-01 2026-06-16

set -e

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
source "$SCRIPT_DIR/env.sh"

START_DATE="${1:-2024-01-01}"
END_DATE="${2:-$(date -d tomorrow +%Y-%m-%d)}"

TRAINER=$(get_trainer_cmd)

LOG_FILE="${SCRIPT_DIR}/../training_logs/HID-$(date +%Y-%m-%d_%H).log"

echo "=========================================================="
echo "  Train Pipeline H+I+D (Standalone)"
echo "  Trainer: $TRAINER"
echo "  Date Range: $START_DATE → $END_DATE"
echo "  Log: $LOG_FILE"
echo "=========================================================="
echo ""

{
    set -e
    set -o pipefail

    $TRAINER \
        --pipeline H,I,D \
        --win-threshold 2.0 \
        --actual-fill-weight 20.0 \
        --eval-on-actual-only \
        --start "$START_DATE" \
        --end "$END_DATE" \
        --test-size 0.2 \
        --train-full \
        --limit 12000 \
        --model-out "$(get_pipeline_model_path "H" "python_ml/v2/models/winner_model_pipeline_hid.joblib")"

} 2>&1 | tee "$LOG_FILE"

RC=${PIPESTATUS[0]}
if [ $RC -eq 0 ]; then
    echo ""
    echo "✅ H,I,D training completed successfully."
    echo "   Log saved to: $LOG_FILE"
else
    echo ""
    echo "❌ H,I,D training FAILED with exit code $RC."
    echo "   Check log: $LOG_FILE"
    exit $RC
fi
