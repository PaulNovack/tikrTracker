#!/bin/bash
# Retrain all active pipeline models with the v2 trainer
# Run overnight — launches B, D, E, F, H, J, K, L, N and H+I+D in parallel
# Scorer: v2/score_single_alert_v2.py

set -e

cd "$(dirname "$0")/../.." || exit

source scripts/train_and_rescore/model_env.sh

TODAY=$(date +%Y-%m-%d)
LOG_DIR=$(mktemp -d "${TMPDIR:-/tmp}/pipeline-retrain.${TODAY}.XXXXXX")
JOB_PIDS=()
JOB_LABELS=()
JOB_LOGS=()

echo "=========================================================="
echo "  Retrain All Active Pipelines (v2 trainer)"
echo "  Date: $TODAY"
echo "  Logs: $LOG_DIR"
echo "=========================================================="
echo ""

launch_job() {
    local LABEL=$1
    local LOG_NAME=$2
    shift 2

    local LOG_FILE="$LOG_DIR/$LOG_NAME"

    echo "----------------------------------------------------------"
    echo "  Launching $LABEL"
    echo "  Log: $LOG_FILE"
    echo "----------------------------------------------------------"

    (
        set -e
        set -o pipefail
        stdbuf -oL -eL "$@" 2>&1 | tee -a "$LOG_FILE"
    ) &

    JOB_PIDS+=("$!")
    JOB_LABELS+=("$LABEL")
    JOB_LOGS+=("$LOG_FILE")
}

launch_job \
    "Scarcity Leaders" \
    "K.log" \
    bash ./scripts/train_and_rescore/train_pipeline_k_model.sh

launch_job \
    "Early Momentum" \
    "L.log" \
    bash ./scripts/train_and_rescore/train_pipeline_l_model.sh

launch_job \
    "Higher-Low Breakout" \
    "J.log" \
    bash ./scripts/train_and_rescore/train_pipeline_j_model.sh

launch_job \
    "Pipeline H+I+D Combined" \
    "HID.log" \
    ./scripts/train_and_rescore/train_pipeline_hi_model.sh

launch_job \
    "Risk-Off / Bear Market" \
    "F.log" \
    bash ./scripts/train_and_rescore/train_pipeline_f_model.sh

launch_job \
    "Trend Continuation" \
    "E.log" \
    bash ./scripts/train_and_rescore/train_pipeline_e_model.sh

launch_job \
    "Market Movers Momentum" \
    "N.log" \
    python python_ml/v2/train_stock_winner_model_v2.py train \
        --pipeline N \
        --win-threshold 2.0 \
        --actual-fill-weight 20.0 \
        --eval-on-actual-only \
        --start 2024-01-01 \
        --end "$TODAY" \
        --test-size 0.2 \
        --train-full \
        --model-out "$(get_pipeline_model_path "N" "python_ml/models/winner_model_pipeline_n.joblib")"

launch_job \
    "Elite Multi-Day Momentum" \
    "B.log" \
    bash ./scripts/train_and_rescore/train_pipeline_b_model.sh

echo ""
echo "=========================================================="
echo "  Waiting for all training jobs to finish..."
echo "=========================================================="
echo ""

FAILURES=0
for INDEX in "${!JOB_PIDS[@]}"; do
    PID="${JOB_PIDS[$INDEX]}"
    LABEL="${JOB_LABELS[$INDEX]}"
    LOG_FILE="${JOB_LOGS[$INDEX]}"

    if wait "$PID"; then
        echo "✅ $LABEL finished"
    else
        STATUS=$?
        echo "❌ $LABEL failed with exit code $STATUS"
        echo "   Log: $LOG_FILE"
        tail -n 20 "$LOG_FILE"
        FAILURES=1
    fi
done

echo "=========================================================="
if [[ "$FAILURES" -eq 0 ]]; then
    echo "  All pipelines retrained!"
else
    echo "  One or more training jobs failed."
fi
echo "  End time: $(date)"
echo ""
echo "  Logs are in: $LOG_DIR"
echo "  Next steps:"
echo "  1. php artisan config:clear"
echo "  2. Set TRADING_ML_SCORER_SCRIPT_PIPELINE_X=python_ml/v2/v2/score_single_alert_v2.py in .env"
echo "     for each pipeline retrained"
echo "  3. Monitor tomorrow's live scoring logs for the v2 single-alert scorer"
echo "=========================================================="

if [[ "$FAILURES" -ne 0 ]]; then
    exit 1
fi
