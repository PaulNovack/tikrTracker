#!/bin/bash
# Retrain all active pipeline models with the v4 trainer
# Run overnight — launches B, D, E, F, H, J, K, L, N and H+I+D in parallel

set -e

cd "$(dirname "$0")/../.." || exit

source scripts/train_and_rescore/model_env_v4.sh

TODAY=$(date +%Y-%m-%d)
LOG_DIR=$(mktemp -d "${TMPDIR:-/tmp}/pipeline-v4-retrain.${TODAY}.XXXXXX")
JOB_PIDS=()
JOB_LABELS=()
JOB_LOGS=()

TRAINER=$(get_trainer_cmd)
REPO_ROOT=$(resolve_repo_root)

echo "=========================================================="
echo "  Retrain All Active Pipelines (v4 trainer)"
echo "  Trainer: $TRAINER"
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
    $TRAINER \
        --pipeline K \
        --win-threshold 2.0 \
        --actual-fill-weight 20.0 \
        --eval-on-actual-only \
        --split-mode day \
        --start 2024-01-01 \
        --end "$TODAY" \
        --test-size 0.2 \
        --train-full \
        --model-out "$(get_pipeline_model_path "K" "python_ml/models/winner_model_pipeline_k_v4.joblib")"

launch_job \
    "Early Momentum" \
    "L.log" \
    $TRAINER \
        --pipeline L \
        --win-threshold 2.0 \
        --actual-fill-weight 20.0 \
        --eval-on-actual-only \
        --split-mode day \
        --start 2024-01-01 \
        --end "$TODAY" \
        --test-size 0.2 \
        --train-full \
        --model-out "$(get_pipeline_model_path "L" "python_ml/models/winner_model_pipeline_l_v4.joblib")"

launch_job \
    "Higher-Low Breakout" \
    "J.log" \
    $TRAINER \
        --pipeline J \
        --win-threshold 2.0 \
        --actual-fill-weight 20.0 \
        --eval-on-actual-only \
        --split-mode day \
        --start 2024-01-01 \
        --end "$TODAY" \
        --test-size 0.2 \
        --train-full \
        --model-out "$(get_pipeline_model_path "J" "python_ml/models/winner_model_pipeline_j_v4.joblib")"

launch_job \
    "Pipeline H+I+D Combined" \
    "HID.log" \
    $TRAINER \
        --pipeline H,I,D \
        --win-threshold 2.0 \
        --actual-fill-weight 20.0 \
        --eval-on-actual-only \
        --split-mode day \
        --start 2024-01-01 \
        --end "$TODAY" \
        --test-size 0.2 \
        --train-full \
        --model-out "$(get_pipeline_model_path "HID" "python_ml/models/winner_model_pipeline_hid_v4.joblib")"

launch_job \
    "Risk-Off / Bear Market" \
    "F.log" \
    $TRAINER \
        --pipeline F \
        --win-threshold 2.0 \
        --actual-fill-weight 20.0 \
        --eval-on-actual-only \
        --split-mode day \
        --start 2024-01-01 \
        --end "$TODAY" \
        --test-size 0.2 \
        --train-full \
        --model-out "$(get_pipeline_model_path "F" "python_ml/models/winner_model_pipeline_f_v4.joblib")"

launch_job \
    "Trend Continuation" \
    "E.log" \
    $TRAINER \
        --pipeline E \
        --win-threshold 2.0 \
        --actual-fill-weight 20.0 \
        --eval-on-actual-only \
        --split-mode day \
        --start 2024-01-01 \
        --end "$TODAY" \
        --test-size 0.2 \
        --train-full \
        --model-out "$(get_pipeline_model_path "E" "python_ml/models/winner_model_pipeline_e_v4.joblib")"

launch_job \
    "Market Movers Momentum" \
    "N.log" \
    $TRAINER \
        --pipeline N \
        --win-threshold 2.0 \
        --actual-fill-weight 20.0 \
        --eval-on-actual-only \
        --start 2024-01-01 \
        --end "$TODAY" \
        --test-size 0.2 \
        --train-full \
        --model-out "$(get_pipeline_model_path "N" "python_ml/models/winner_model_pipeline_n_v4.joblib")"

launch_job \
    "Elite Multi-Day Momentum" \
    "B.log" \
    $TRAINER \
        --pipeline B \
        --win-threshold 2.0 \
        --actual-fill-weight 20.0 \
        --eval-on-actual-only \
        --split-mode day \
        --start 2024-01-01 \
        --end "$TODAY" \
        --test-size 0.2 \
        --train-full \
        --model-out "$(get_pipeline_model_path "B" "python_ml/models/winner_model_momentum_v4.joblib")"

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
if [[ "$FAILURES" == "0" ]]; then
    echo "  All v4 pipeline models trained successfully!"
    echo "  Update .env with V4 model paths, then set ML_VERSION=v4 to switch."
    echo "=========================================================="
    echo ""

    echo "Add to .env:"
    echo "  ML_VERSION=v4"
    for PIPELINE in K L J F E N B; do
        echo "  TRADING_ML_PIPELINE_${PIPELINE}_V4_MODEL_PATH=python_ml/models/winner_model_pipeline_$(echo $PIPELINE | tr '[:upper:]' '[:lower:]')_v4.joblib"
    done
else
    echo "  Some v4 pipelines failed — check logs at $LOG_DIR"
    echo "=========================================================="
fi
