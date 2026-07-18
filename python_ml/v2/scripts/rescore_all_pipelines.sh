#!/bin/bash
# v2/scripts/rescore_all_pipelines.sh — Rescore all active pipelines by model group.
#
# Groups pipelines that share the same trained model (e.g., H,I,D share one .joblib),
# clears their ml_scored_at, then re-scores each trading date in date-ascending order.
#
# Mirrors: scripts/train_and_rescore/rescore_all_active_pipelines.sh
# but uses the v2 scorer at python_ml/v2/score_trade_alerts.py.
#
# Usage:
#   bash python_ml/v2/scripts/rescore_all_pipelines.sh
#   bash python_ml/v2/scripts/rescore_all_pipelines.sh --pipelines K,L,J
#   bash python_ml/v2/scripts/rescore_all_pipelines.sh --limit 50000
#   bash python_ml/v2/scripts/rescore_all_pipelines.sh --dry-run

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
source "$SCRIPT_DIR/env.sh"

load_env_file

DEFAULT_PIPELINES="A,B,C,D,E,F,G,H,I,J,K,L,M,N,O,P,Q,R,S"
PIPELINES="$DEFAULT_PIPELINES"
LIMIT=200000
DRY_RUN=0
FULL_UPDATE=0
LOOKBACK_MONTHS=6
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
        --full-update)
            FULL_UPDATE=1
            shift
            ;;
        --lookback-months)
            LOOKBACK_MONTHS="${2:-}"
            shift 2
            ;;
        -h|--help)
            echo "Usage: $0 [--pipelines A,B,C] [--limit N] [--lookback-months N] [--full-update] [--dry-run]"
            echo "  --full-update   Score all historical data instead of just the last LOOKBACK_MONTHS months"
            exit 0
            ;;
        *)
            echo "Unknown argument: $1"
            echo "Usage: $0 [--pipelines A,B,C] [--limit N] [--lookback-months N] [--full-update] [--dry-run]"
            exit 1
            ;;
    esac
done

if ! [[ "$LIMIT" =~ ^[0-9]+$ ]]; then
    echo "ERROR: --limit must be a positive integer."
    exit 1
fi

if ! [[ "$LOOKBACK_MONTHS" =~ ^[0-9]+$ ]]; then
    echo "ERROR: --lookback-months must be a positive integer."
    exit 1
fi

if [[ "$FULL_UPDATE" == "1" ]]; then
    DATE_CONDITION=""
else
    DATE_CONDITION="AND trading_date_est >= DATE_SUB(CURDATE(), INTERVAL $LOOKBACK_MONTHS MONTH)"
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

    line=$(grep -E "^${key}=" "$REPO_ROOT/.env" | tail -n 1 || true)
    if [[ -z "$line" ]]; then
        echo ""
        return 0
    fi

    local value="${line#*=}"
    value="${value%\"}"
    value="${value#\"}"
    value="${value%\'}"
    value="${value#\'}"

    echo "$value"
}

run_model_group() {
    local model_path="$1"
    local pipeline_csv="$2"

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

    local SCOPE_LABEL
    if [[ "$FULL_UPDATE" == "1" ]]; then
        SCOPE_LABEL="all historical data"
    else
        SCOPE_LABEL="last $LOOKBACK_MONTHS months"
    fi

    if [[ "$DRY_RUN" == "1" ]]; then
        echo "DRY RUN: would clear existing ML scores ($SCOPE_LABEL) for pipelines: $pipeline_csv"
    else
        echo "Clearing existing ML scores ($SCOPE_LABEL)..."
        mysql -u "${DB_USERNAME:-laravel}" -p"${DB_PASSWORD:-laravel}" "${DB_DATABASE:-laravelInvest}" \
            -e "UPDATE trade_alerts SET ml_scored_at = NULL, ml_win_prob = NULL WHERE pipeline_run IN ($SQL_PIPELINE_LIST) $DATE_CONDITION" 2>/dev/null
        echo "Scores cleared."
    fi

    echo "Finding trading dates ($SCOPE_LABEL)..."
    local DATES
    DATES=$(mysql -u "${DB_USERNAME:-laravel}" -p"${DB_PASSWORD:-laravel}" "${DB_DATABASE:-laravelInvest}" -N -B \
        -e "SELECT DISTINCT trading_date_est FROM trade_alerts WHERE pipeline_run IN ($SQL_PIPELINE_LIST) $DATE_CONDITION ORDER BY trading_date_est")

    if [[ -z "$DATES" ]]; then
        echo "No dates found for this model group."
        echo ""
        return 0
    fi

    local TOTAL
    TOTAL=$(echo "$DATES" | grep -c . || true)
    local CURRENT=0

    echo "Found $TOTAL trading dates to process for pipelines: $pipeline_csv"

    local DATE
    for DATE in $DATES; do
        CURRENT=$((CURRENT + 1))
        if [[ "$DRY_RUN" == "1" ]]; then
            echo "[$CURRENT/$TOTAL] DRY RUN: would score $DATE for pipelines $pipeline_csv"
        else
            echo "[$CURRENT/$TOTAL] Scoring $DATE for pipelines $pipeline_csv"
            set +e  # don't let scorer failure kill the subshell
            ${PYTHON_PATH:-python3} "$PYTHON_ML_DIR/v2/score_trade_alerts.py" \
                --model-in "$model_path" \
                --trading-date "$DATE" \
                --limit "$LIMIT" \
                --pipeline "$pipeline_csv" || echo "  WARN: scoring failed for $DATE, continuing..."
            set -e  # restore subshell error handling
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
echo "Rescore All Active Pipelines (Shared-Model Aware, v2 scorer)"
echo "Start time: $(date)"
echo "Pipelines requested: $PIPELINES"
echo "Limit per date: $LIMIT"
if [[ "$FULL_UPDATE" == "1" ]]; then
    echo "Scope: full history (--full-update)"
else
    echo "Scope: last $LOOKBACK_MONTHS months"
fi
if [[ "$DRY_RUN" == "1" ]]; then
    echo "Mode: DRY RUN (no DB updates, no scoring calls)"
fi
echo "=========================================================="
echo ""

for PIPELINE in "${PIPELINE_LIST[@]}"; do
    MODEL_PATH=$(get_model_path_for_pipeline "$PIPELINE")

    if [[ -z "$MODEL_PATH" ]]; then
        echo "WARN: No model path configured for pipeline $PIPELINE in .env. Skipping."
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
echo "Launching scoring jobs (logs in $LOG_DIR/)..."
echo ""

for MODEL_PATH in "${!MODEL_TO_PIPELINES[@]}"; do
    if [[ "${MODEL_EXISTS[$MODEL_PATH]}" == "0" ]]; then
        echo "SKIPPING model group for ${MODEL_TO_PIPELINES[$MODEL_PATH]}: model file not found ($MODEL_PATH)"
        continue
    fi

    pipeline_csv="${MODEL_TO_PIPELINES[$MODEL_PATH]}"
    label="model-group-$(echo "$pipeline_csv" | tr ',' '-')"
    log_name="rescore_$(echo "$pipeline_csv" | tr ',' '_').log"

    launch_job "$label" "$log_name" "$MODEL_PATH" "$pipeline_csv"
done

echo ""
echo "=========================================================="
echo "All scoring jobs launched. Live output (Ctrl-C to stop viewing, jobs continue):"
echo "=========================================================="
echo ""

# Tail all logs in real-time so user can see progress
tail -f "$LOG_DIR"/*.log &
TAIL_PID=$!

# Wait for all jobs
FAILURES=0
for INDEX in "${!JOB_PIDS[@]}"; do
    PID="${JOB_PIDS[$INDEX]}"
    LABEL="${JOB_LABELS[$INDEX]}"
    LOG_FILE="${JOB_LOGS[$INDEX]}"

    if wait "$PID"; then
        echo "✅ $LABEL finished (log: $LOG_FILE)"
    else
        echo "❌ $LABEL FAILED (log: $LOG_FILE)"
        FAILURES=$((FAILURES + 1))
    fi
done

# Kill the tail process
kill "$TAIL_PID" 2>/dev/null || true

echo ""
echo "=========================================================="
echo "Rescore Complete"
echo "Passed: $(( ${#JOB_PIDS[@]} - FAILURES )) / ${#JOB_PIDS[@]}"
echo "Failed: $FAILURES"
echo "Logs:   $LOG_DIR/"
echo "Finished: $(date)"
echo "=========================================================="

if [[ "$FAILURES" -gt 0 ]]; then
    exit 1
fi
