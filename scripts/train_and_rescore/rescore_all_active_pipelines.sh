#!/bin/bash
# Rescore all active pipelines, grouping pipelines that share the same model.
#
# Defaults:
# - Active pipelines: K,L,J,H,F,E,N,D,B (same set as retrain_all_active_pipelines.sh)
# - Limit per date: 200000
#
# Usage:
#   ./scripts/train_and_rescore/rescore_all_active_pipelines.sh
#   ./scripts/train_and_rescore/rescore_all_active_pipelines.sh --pipelines H,I,D,B
#   ./scripts/train_and_rescore/rescore_all_active_pipelines.sh --limit 50000
#   ./scripts/train_and_rescore/rescore_all_active_pipelines.sh --dry-run

set -euo pipefail

cd "$(dirname "$0")/../.." || exit 1

ENV_FILE=".env"
DEFAULT_PIPELINES="K,L,J,H,F,E,N,D,B"
PIPELINES="$DEFAULT_PIPELINES"
LIMIT=200000
DRY_RUN=0
TODAY=$(date +%Y-%m-%d)
LOG_DIR=$(mktemp -d "${TMPDIR:-/tmp}/pipeline-rescore.${TODAY}.XXXXXX")
JOB_PIDS=()
JOB_LABELS=()
JOB_LOGS=()

while [[ $# -gt 0 ]]; do
    case "$1" in
        --pipelines)
            PIPELINES="${2:-}"
            shift 2
            ;;
        --limit)
            LIMIT="${2:-}"
            shift 2
            ;;
        --dry-run)
            DRY_RUN=1
            shift
            ;;
        -h|--help)
            echo "Usage: $0 [--pipelines A,B,C] [--limit N] [--dry-run]"
            exit 0
            ;;
        *)
            echo "Unknown argument: $1"
            echo "Usage: $0 [--pipelines A,B,C] [--limit N] [--dry-run]"
            exit 1
            ;;
    esac
done

if [[ ! -f "$ENV_FILE" ]]; then
    echo "ERROR: $ENV_FILE not found in project root."
    exit 1
fi

if ! [[ "$LIMIT" =~ ^[0-9]+$ ]]; then
    echo "ERROR: --limit must be a positive integer."
    exit 1
fi

# Trim whitespace and uppercase each pipeline token.
normalize_pipeline_csv() {
    local input="$1"
    local out=""
    IFS=',' read -ra raw <<< "$input"

    for token in "${raw[@]}"; do
        local p
        p=$(echo "$token" | xargs | tr '[:lower:]' '[:upper:]')
        if [[ -z "$p" ]]; then
            continue
        fi
        if [[ -z "$out" ]]; then
            out="$p"
        else
            out="$out,$p"
        fi
    done

    echo "$out"
}

# Read TRADING_ML_PIPELINE_<X>_MODEL_PATH from .env.
get_model_path_for_pipeline() {
    local pipeline="$1"
    local key="TRADING_ML_PIPELINE_${pipeline}_MODEL_PATH"
    local line

    line=$(grep -E "^${key}=" "$ENV_FILE" | tail -n 1 || true)
    if [[ -z "$line" ]]; then
        echo ""
        return 0
    fi

    local value="${line#*=}"
    # Remove optional surrounding single or double quotes.
    value="${value%\"}"
    value="${value#\"}"
    value="${value%\'}"
    value="${value#\'}"

    echo "$value"
}

run_model_group() {
    local model_path="$1"
    local pipeline_csv="$2"

    # Build SQL IN list: 'H','I','D'
    local SQL_PIPELINE_LIST=""
    local GROUP_PIPELINES=()
    IFS=',' read -ra GROUP_PIPELINES <<< "$pipeline_csv"
    for P in "${GROUP_PIPELINES[@]}"; do
        if [[ -z "$SQL_PIPELINE_LIST" ]]; then
            SQL_PIPELINE_LIST="'$P'"
        else
            SQL_PIPELINE_LIST="$SQL_PIPELINE_LIST,'$P'"
        fi
    done

    echo "----------------------------------------------------------"
    echo "Model group pipelines: $pipeline_csv"
    echo "Model: $model_path"
    echo "----------------------------------------------------------"

    if [[ "$DRY_RUN" == "1" ]]; then
        echo "DRY RUN: would clear existing ML scores for pipelines: $pipeline_csv"
    else
        mysql -u laravel -plaravel laravelInvest -e \
            "UPDATE trade_alerts SET ml_scored_at = NULL, ml_win_prob = NULL, passed_ml = 0, ml_model_version = NULL WHERE pipeline_run IN ($SQL_PIPELINE_LIST)"
    fi

    local DATES
    DATES=$(mysql -u laravel -plaravel laravelInvest -N -B -e \
        "SELECT DISTINCT trading_date_est FROM trade_alerts WHERE pipeline_run IN ($SQL_PIPELINE_LIST) ORDER BY trading_date_est")

    local TOTAL
    TOTAL=$(echo "$DATES" | grep -c . || true)
    local CURRENT=0

    echo "Found $TOTAL trading dates to process for pipelines: $pipeline_csv"

    if [[ "$TOTAL" -eq 0 ]]; then
        echo "No dates found for this model group."
        echo ""
        return 0
    fi

    local DATE
    for DATE in $DATES; do
        CURRENT=$((CURRENT + 1))
        if [[ "$DRY_RUN" == "1" ]]; then
            echo "[$CURRENT/$TOTAL] DRY RUN: would score $DATE for pipelines $pipeline_csv"
        else
            echo "[$CURRENT/$TOTAL] Scoring $DATE for pipelines $pipeline_csv"
            python -u python_ml/score_trade_alerts.py \
                --model-in "$model_path" \
                --trading-date "$DATE" \
                --limit "$LIMIT" \
                --pipeline "$pipeline_csv"
        fi
    done

    echo ""
}

launch_job() {
    local label="$1"
    local log_name="$2"
    local model_path="$3"
    local pipeline_csv="$4"

    local log_file="$LOG_DIR/$log_name"

    echo "----------------------------------------------------------"
    echo "  Launching $label"
    echo "  Log: $log_file"
    echo "----------------------------------------------------------"

    (
        set -e
        set -o pipefail
        run_model_group "$model_path" "$pipeline_csv"
    ) > "$log_file" 2>&1 &

    JOB_PIDS+=("$!")
    JOB_LABELS+=("$label")
    JOB_LOGS+=("$log_file")
}

PIPELINES=$(normalize_pipeline_csv "$PIPELINES")
if [[ -z "$PIPELINES" ]]; then
    echo "ERROR: No valid pipelines were provided."
    exit 1
fi

declare -A MODEL_TO_PIPELINES
declare -A MODEL_EXISTS

IFS=',' read -ra PIPELINE_LIST <<< "$PIPELINES"

echo "=========================================================="
echo "Rescore All Active Pipelines (Shared-Model Aware)"
echo "Start time: $(date)"
echo "Pipelines requested: $PIPELINES"
echo "Limit per date: $LIMIT"
if [[ "$DRY_RUN" == "1" ]]; then
    echo "Mode: DRY RUN (no DB updates, no scoring calls)"
fi
echo "=========================================================="
echo ""

for PIPELINE in "${PIPELINE_LIST[@]}"; do
    MODEL_PATH=$(get_model_path_for_pipeline "$PIPELINE")

    if [[ -z "$MODEL_PATH" ]]; then
        echo "WARN: No model path configured for pipeline $PIPELINE in $ENV_FILE. Skipping."
        continue
    fi

    if [[ -z "${MODEL_TO_PIPELINES[$MODEL_PATH]+x}" ]]; then
        MODEL_TO_PIPELINES[$MODEL_PATH]="$PIPELINE"
    else
        MODEL_TO_PIPELINES[$MODEL_PATH]="${MODEL_TO_PIPELINES[$MODEL_PATH]},$PIPELINE"
    fi

    if [[ -f "$MODEL_PATH" ]]; then
        MODEL_EXISTS[$MODEL_PATH]=1
    else
        MODEL_EXISTS[$MODEL_PATH]=0
    fi
done

if [[ "${#MODEL_TO_PIPELINES[@]}" -eq 0 ]]; then
    echo "ERROR: No valid pipeline->model mappings found. Nothing to do."
    exit 1
fi

echo "Resolved model groups:"
for MODEL_PATH in "${!MODEL_TO_PIPELINES[@]}"; do
    STATUS="missing"
    if [[ "${MODEL_EXISTS[$MODEL_PATH]}" == "1" ]]; then
        STATUS="ok"
    fi
    echo "  - $MODEL_PATH -> ${MODEL_TO_PIPELINES[$MODEL_PATH]} ($STATUS)"
done
echo ""

for MODEL_PATH in "${!MODEL_TO_PIPELINES[@]}"; do
    PIPELINE_CSV="${MODEL_TO_PIPELINES[$MODEL_PATH]}"

    if [[ "${MODEL_EXISTS[$MODEL_PATH]}" != "1" ]]; then
        echo "WARN: Model file not found for pipelines $PIPELINE_CSV: $MODEL_PATH"
        echo "Skipping this model group."
        echo ""
        continue
    fi

    launch_job \
        "Pipelines $PIPELINE_CSV" \
        "$(echo "$PIPELINE_CSV" | tr ',' '_').log" \
        "$MODEL_PATH" \
        "$PIPELINE_CSV"
done


if [[ "${#JOB_PIDS[@]}" -eq 0 ]]; then
    echo "ERROR: No valid model groups were launched. Nothing to do."
    exit 1
fi

echo ""
echo "=========================================================="
echo "Waiting for all model groups to finish..."
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
    echo "All requested model groups processed."
else
    echo "One or more model groups failed."
fi
echo "End time: $(date)"
echo "=========================================================="

if [[ "$FAILURES" -ne 0 ]]; then
    exit 1
fi
