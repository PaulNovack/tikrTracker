#!/bin/bash
# v2/scripts/retrain_all.sh — Retrain active pipelines with the v2 trainer.
# Run overnight. Pipelines train in parallel.
# Mirrors the logic from scripts/train_and_rescore/retrain_all_active_pipelines.sh
# but lives in the v2/ directory with its own env.sh helpers.
#
# Optional pipeline list:
#   bash python_ml/v2/scripts/retrain_all.sh
#   bash python_ml/v2/scripts/retrain_all.sh A,B,C
#   bash python_ml/v2/scripts/retrain_all.sh --pipelines A,B,C
#
# Optional date range override:
#   bash python_ml/v2/scripts/retrain_all.sh --from 2024-01-01 --to 2026-08-15
#   bash python_ml/v2/scripts/retrain_all.sh A,B,C --from 2024-01-01 --to 2026-08-15
#
# If no pipeline list is provided, all active pipelines are trained.
# If no date range is provided, each pipeline keeps its default start date and
# uses today as the end date.
#
# Pipelines trained: A, B, C, D, E, F, G, H, I, J, K, L, M, N, O, P, Q, R (separate models)
# Note: H, I, D are trained as independent models here. Use retrain_all_with_hid.sh for a combined H+I+D model.

set -e

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
source "$SCRIPT_DIR/env.sh"

ALL_PIPELINES=(A B C D E F G H I J K L M N O P Q R)
REQUESTED_PIPELINES=()
REQUESTED_FROM=""
REQUESTED_TO=""

PIPELINE_ARG=""
while [[ $# -gt 0 ]]; do
    case "$1" in
        -p|--pipelines)
            if [[ $# -lt 2 ]]; then
                echo "ERROR: --pipelines requires a comma-separated pipeline list"
                exit 1
            fi
            PIPELINE_ARG="$2"
            shift 2
            ;;
        --pipelines=*)
            PIPELINE_ARG="${1#*=}"
            shift
            ;;
        -f|--from)
            if [[ $# -lt 2 ]]; then
                echo "ERROR: --from requires a YYYY-MM-DD date"
                exit 1
            fi
            REQUESTED_FROM="$2"
            shift 2
            ;;
        --from=*)
            REQUESTED_FROM="${1#*=}"
            shift
            ;;
        -t|--to)
            if [[ $# -lt 2 ]]; then
                echo "ERROR: --to requires a YYYY-MM-DD date"
                exit 1
            fi
            REQUESTED_TO="$2"
            shift 2
            ;;
        --to=*)
            REQUESTED_TO="${1#*=}"
            shift
            ;;
        -h|--help)
            echo "Usage: bash python_ml/v2/scripts/retrain_all.sh [A,B,C|--pipelines A,B,C] [--from YYYY-MM-DD] [--to YYYY-MM-DD]"
            exit 0
            ;;
        *)
            if [[ -z "$PIPELINE_ARG" && "$1" != -* ]]; then
                PIPELINE_ARG="$1"
                shift
            else
                echo "ERROR: Unknown argument: $1"
                exit 1
            fi
            ;;
    esac
done

if [[ -n "$PIPELINE_ARG" ]]; then
    IFS=',' read -r -a REQUESTED_PIPELINES <<< "$PIPELINE_ARG"
    NORMALIZED_PIPELINES=()
    for PIPELINE in "${REQUESTED_PIPELINES[@]}"; do
        PIPELINE="${PIPELINE//[[:space:]]/}"
        PIPELINE="${PIPELINE^^}"
        if [[ -n "$PIPELINE" ]]; then
            NORMALIZED_PIPELINES+=("$PIPELINE")
        fi
    done
    REQUESTED_PIPELINES=("${NORMALIZED_PIPELINES[@]}")
fi

export REQUESTED_FROM REQUESTED_TO

SELECTED_PIPELINES=()
if [[ ${#REQUESTED_PIPELINES[@]} -eq 0 ]]; then
    SELECTED_PIPELINES=("${ALL_PIPELINES[@]}")
else
    SELECTED_PIPELINES=("${REQUESTED_PIPELINES[@]}")
fi

should_run_pipeline() {
    local PIPELINE="$1"

    if [[ ${#REQUESTED_PIPELINES[@]} -eq 0 ]]; then
        return 0
    fi

    for REQUESTED in "${REQUESTED_PIPELINES[@]}"; do
        if [[ "$REQUESTED" == "$PIPELINE" ]]; then
            return 0
        fi
    done

    return 1
}

TODAY=$(date +%Y-%m-%d)
JOB_PIDS=()
JOB_LABELS=()

TRAINER=$(get_trainer_cmd)

echo "=========================================================="
echo "  Retrain All Active Pipelines (v2 trainer)"
echo "  Trainer: $TRAINER"
echo "  Date: $TODAY"
if [[ ${#REQUESTED_PIPELINES[@]} -gt 0 ]]; then
    echo "  Pipelines: ${SELECTED_PIPELINES[*]}"
else
    echo "  Pipelines: all active"
fi
if [[ -n "$REQUESTED_FROM" || -n "$REQUESTED_TO" ]]; then
    echo "  Date range override: from=${REQUESTED_FROM:-default} to=${REQUESTED_TO:-today}"
else
    echo "  Date range override: default pipeline start dates → today"
fi
echo "=========================================================="
echo ""

resolve_start_date() {
    local DEFAULT_START="$1"
    if [[ -n "$REQUESTED_FROM" ]]; then
        echo "$REQUESTED_FROM"
    else
        echo "$DEFAULT_START"
    fi
}

resolve_end_date() {
    if [[ -n "$REQUESTED_TO" ]]; then
        echo "$REQUESTED_TO"
    else
        echo "$TODAY"
    fi
}

launch_job() {
    local LABEL=$1
    local LOG_NAME=$2
    shift 2

    local LOG_FILE="${SCRIPT_DIR}/../training_logs/${LOG_NAME}"

    echo "----------------------------------------------------------"
    echo "  Launching $LABEL"
    echo "  Log: $LOG_FILE"
    echo "----------------------------------------------------------"
    echo ""

    (
        set -e
        set -o pipefail
        stdbuf -oL -eL "$@" 2>&1 | tee -a "$LOG_FILE"
    ) &

    JOB_PIDS+=("$!")
    JOB_LABELS+=("$LABEL")
}

# A — Momentum Continuation (v90.1)
if should_run_pipeline "A"; then
    launch_job "Momentum Continuation" "A-$(date +%Y-%m-%d_%H).log" \
        $TRAINER \
            --pipeline A --win-threshold 1.5 --actual-fill-weight 1.0 \
            --eval-on-actual-only --split-mode day \
            --start "$(resolve_start_date "2024-01-01")" --end "$(resolve_end_date)" \
            --test-size 0.2 --train-full \
            --model-out "$(get_pipeline_model_path "A" "python_ml/v2/models/winner_model_A.joblib")"
fi

# C — Hybrid Big-Move Breakout (v600.0)
if should_run_pipeline "C"; then
    launch_job "Hybrid Big-Move Breakout" "C-$(date +%Y-%m-%d_%H).log" \
        $TRAINER \
            --pipeline C --win-threshold 1.5 --actual-fill-weight 1.0 \
            --eval-on-actual-only --split-mode day \
            --start "$(resolve_start_date "2024-01-01")" --end "$(resolve_end_date)" \
            --test-size 0.2 --train-full \
            --model-out "$(get_pipeline_model_path "C" "python_ml/v2/models/winner_model_C.joblib")"
fi

# B — Elite Multi-Day Momentum
# Queries DB for the earliest analyzed B alert to auto-determine start date.
if should_run_pipeline "B"; then
    MYSQL_CMD=$(get_mysql_cmd)
    DEFAULT_START_DATE=$(
        $MYSQL_CMD -e "
            SELECT DATE(MIN(entry_ts_est))
            FROM trade_alerts
            WHERE pipeline_run='B'
              AND analyzed = 1
              AND pnl_percent IS NOT NULL
        " 2>/dev/null | tail -n1
    )
    DEFAULT_START_DATE=${DEFAULT_START_DATE:-$(date -d '12 months ago' +%Y-%m-%d)}
    PIPELINE_START=$(resolve_start_date "$DEFAULT_START_DATE")
    PIPELINE_END=$(resolve_end_date)

    launch_job "Elite Multi-Day Momentum" "B-$(date +%Y-%m-%d_%H).log" \
        $TRAINER \
            --pipeline B --win-threshold 1.5 --actual-fill-weight 1.0 \
            --eval-on-actual-only --start "$PIPELINE_START" --end "$PIPELINE_END" \
            --test-size 0.2 --train-full \
            --model-out "$(get_pipeline_model_path "B" "python_ml/v2/models/winner_model_B.joblib")"
fi

# E — Trend Continuation
if should_run_pipeline "E"; then
    launch_job "Trend Continuation" "E-$(date +%Y-%m-%d_%H).log" \
        $TRAINER \
            --pipeline E --win-threshold 1.5 --actual-fill-weight 1.0 \
            --eval-on-actual-only --split-mode day \
            --start "$(resolve_start_date "2024-01-01")" --end "$(resolve_end_date)" \
            --test-size 0.2 --train-full \
            --model-out "$(get_pipeline_model_path "E" "python_ml/v2/models/winner_model_E.joblib")"
fi

# F — Risk-Off / Bear Market
if should_run_pipeline "F"; then
    launch_job "Risk-Off / Bear Market" "F-$(date +%Y-%m-%d_%H).log" \
        $TRAINER \
            --pipeline F --win-threshold 1.5 --actual-fill-weight 1.0 \
            --eval-on-actual-only --split-mode day \
            --start "$(resolve_start_date "2024-01-01")" --end "$(resolve_end_date)" \
            --test-size 0.2 --train-full \
            --model-out "$(get_pipeline_model_path "F" "python_ml/v2/models/winner_model_F.joblib")"
fi

# H — High-Momentum Breakout (separate model)
if should_run_pipeline "H"; then
    launch_job "High-Momentum Breakout" "H-$(date +%Y-%m-%d_%H).log" \
        $TRAINER \
            --pipeline H --win-threshold 1.5 --actual-fill-weight 1.0 \
            --eval-on-actual-only --split-mode day \
            --start "$(resolve_start_date "2024-01-01")" --end "$(resolve_end_date)" \
            --test-size 0.2 --train-full \
            --model-out "$(get_pipeline_model_path "H" "python_ml/v2/models/winner_model_H.joblib")"
fi

# I — Intraday Reversal (separate model)
if should_run_pipeline "I"; then
    launch_job "Intraday Reversal" "I-$(date +%Y-%m-%d_%H).log" \
        $TRAINER \
            --pipeline I --win-threshold 1.5 --actual-fill-weight 1.0 \
            --eval-on-actual-only --split-mode day \
            --start "$(resolve_start_date "2024-01-01")" --end "$(resolve_end_date)" \
            --test-size 0.2 --train-full \
            --model-out "$(get_pipeline_model_path "I" "python_ml/v2/models/winner_model_I.joblib")"
fi

# D — Day Range Breakout (separate model)
if should_run_pipeline "D"; then
    launch_job "Day Range Breakout" "D-$(date +%Y-%m-%d_%H).log" \
        $TRAINER \
            --pipeline D --win-threshold 1.5 --actual-fill-weight 1.0 \
            --eval-on-actual-only --split-mode day \
            --start "$(resolve_start_date "2024-01-01")" --end "$(resolve_end_date)" \
            --test-size 0.2 --train-full \
            --model-out "$(get_pipeline_model_path "D" "python_ml/v2/models/winner_model_D.joblib")"
fi

# J — Higher-Low Breakout (Recent 4% Movers)
if should_run_pipeline "J"; then
    launch_job "Higher-Low Breakout" "J-$(date +%Y-%m-%d_%H).log" \
        $TRAINER \
            --pipeline J --win-threshold 1.5 --actual-fill-weight 1.0 \
            --eval-on-actual-only --split-mode day \
            --start "$(resolve_start_date "2025-01-01")" --end "$(resolve_end_date)" \
            --test-size 0.2 --top-k 10 --train-full \
            --model-out "$(get_pipeline_model_path "J" "python_ml/v2/models/winner_model_J.joblib")"
fi

# K — Scarcity Leaders
if should_run_pipeline "K"; then
    launch_job "Scarcity Leaders" "K-$(date +%Y-%m-%d_%H).log" \
        $TRAINER \
            --pipeline K --win-threshold 1.5 --actual-fill-weight 1.0 \
            --eval-on-actual-only --split-mode day \
            --start "$(resolve_start_date "2024-01-01")" --end "$(resolve_end_date)" \
            --test-size 0.2 --train-full \
            --model-out "$(get_pipeline_model_path "K" "python_ml/v2/models/winner_model_K.joblib")"
fi

# L — Early Momentum
if should_run_pipeline "L"; then
    launch_job "Early Momentum" "L-$(date +%Y-%m-%d_%H).log" \
        $TRAINER \
            --pipeline L --win-threshold 1.5 --actual-fill-weight 1.0 \
            --eval-on-actual-only --split-mode day \
            --start "$(resolve_start_date "2024-01-01")" --end "$(resolve_end_date)" \
            --test-size 0.2 --train-full \
            --model-out "$(get_pipeline_model_path "L" "python_ml/v2/models/winner_model_L.joblib")"
fi

# N — Market Movers Momentum
if should_run_pipeline "N"; then
    launch_job "Market Movers Momentum" "N-$(date +%Y-%m-%d_%H).log" \
        $TRAINER \
            --pipeline N --win-threshold 1.5 --actual-fill-weight 1.0 \
            --eval-on-actual-only --start "$(resolve_start_date "2024-01-01")" --end "$(resolve_end_date)" \
            --test-size 0.2 --train-full \
            --model-out "$(get_pipeline_model_path "N" "python_ml/v2/models/winner_model_M.joblib")"
fi

# G — Oversold Bounce (v210.0) — small dataset, use LR baseline to avoid overfitting
if should_run_pipeline "G"; then
    launch_job "Oversold Bounce" "G-$(date +%Y-%m-%d_%H).log" \
        $TRAINER \
            --pipeline G --win-threshold 1.5 --actual-fill-weight 1.0 \
            --baseline \
            --eval-on-actual-only --split-mode day \
            --start "$(resolve_start_date "2024-01-01")" --end "$(resolve_end_date)" \
            --test-size 0.2 --train-full \
            --model-out "$(get_pipeline_model_path "G" "python_ml/v2/models/winner_model_G.joblib")"
fi

# M — Tight Stops Clean Trend (v1400.0)
if should_run_pipeline "M"; then
    launch_job "Tight Stops Clean Trend" "M-$(date +%Y-%m-%d_%H).log" \
        $TRAINER \
            --pipeline M --win-threshold 1.5 --actual-fill-weight 1.0 \
            --eval-on-actual-only --split-mode day \
            --start "$(resolve_start_date "2024-01-01")" --end "$(resolve_end_date)" \
            --test-size 0.2 --train-full \
            --model-out "$(get_pipeline_model_path "M" "python_ml/v2/models/winner_model_M.joblib")"
fi

# O — Opening Range Breakout (v1500.0)
if should_run_pipeline "O"; then
    launch_job "Opening Range Breakout" "O-$(date +%Y-%m-%d_%H).log" \
        $TRAINER \
            --pipeline O --win-threshold 1.5 --actual-fill-weight 1.0 \
            --eval-on-actual-only --split-mode day \
            --start "$(resolve_start_date "2024-01-01")" --end "$(resolve_end_date)" \
            --test-size 0.2 --train-full \
            --model-out "$(get_pipeline_model_path "O" "python_ml/v2/models/winner_model_O.joblib")"
fi

# P — Oversold Bounce (v210.1) — research only, no model path yet
if should_run_pipeline "P"; then
    launch_job "Forward-Looking 2H Runner" "P-$(date +%Y-%m-%d_%H).log" \
        $TRAINER \
            --pipeline P --win-threshold 1.5 --actual-fill-weight 1.0 \
            --eval-on-actual-only --split-mode day \
            --start "$(resolve_start_date "2024-01-01")" --end "$(resolve_end_date)" \
            --test-size 0.2 --train-full \
            --model-out "$(get_pipeline_model_path "P" "python_ml/v2/models/winner_model_P.joblib")"
fi

# Q — Volume-First (v27.0)
if should_run_pipeline "Q"; then
    launch_job "Volume-First" "Q-$(date +%Y-%m-%d_%H).log" \
        $TRAINER \
            --pipeline Q --win-threshold 1.5 --actual-fill-weight 1.0 \
            --eval-on-actual-only --split-mode day \
            --start "$(resolve_start_date "2025-06-01")" --end "$(resolve_end_date)" \
            --test-size 0.2 --top-k 10 --train-full \
            --model-out "$(get_pipeline_model_path "Q" "python_ml/v2/models/winner_model_Q.joblib")"
fi

# R — Backtest-Optimized ML (v3100.0) — small dataset, use LR baseline to avoid overfitting
if should_run_pipeline "R"; then
    launch_job "Backtest-Optimized ML" "R-$(date +%Y-%m-%d_%H).log" \
        $TRAINER \
            --pipeline R --win-threshold 1.5 --actual-fill-weight 1.0 \
            --baseline \
            --eval-on-actual-only --split-mode day \
            --start "$(resolve_start_date "2024-01-01")" --end "$(resolve_end_date)" \
            --test-size 0.2 --train-full \
            --model-out "$(get_pipeline_model_path "R" "python_ml/v2/models/winner_model_R.joblib")"
fi

echo ""
echo "=========================================================="
echo "  Waiting for all training jobs to finish..."
echo "=========================================================="
echo ""

FAILURES=0
for INDEX in "${!JOB_PIDS[@]}"; do
    PID="${JOB_PIDS[$INDEX]}"; LABEL="${JOB_LABELS[$INDEX]}"
    if wait "$PID"; then echo "✅ $LABEL finished"
    else STATUS=$?; echo "❌ $LABEL failed ($STATUS)"; FAILURES=1; fi
done

echo "=========================================================="
if [[ "$FAILURES" == "0" ]]; then echo "  All v2 pipeline models trained successfully!"
else echo "  Some v2 pipelines failed — check logs at ${SCRIPT_DIR}/../training_logs"; fi
echo "=========================================================="

# ─────────────────────────────────────────────────────────────────────────
# Extract AUC and Precision@10 from each training log and persist to the DB
# as pipeline-quality markers visible on the /trading-settings pipelines tab.
# Uses BT-only P@10 as default; actual-fill P@10 is preferred when available.
echo ""
echo "=========================================================="
echo "  Extracting AUC & P@10 from training logs..."
echo "=========================================================="

MYSQL_CMD=$(get_mysql_cmd)

LOG_DIR="${SCRIPT_DIR}/../training_logs"
PIPELINE_LETTERS="${SELECTED_PIPELINES[*]}"

persist_setting() {
    local name="$1"
    local value="$2"
    echo "      → Writing ${name}=${value} to DB..."
    $MYSQL_CMD \
        -e "INSERT INTO settings (name, value, updated_at) VALUES ('${name}', '${value}', NOW()) ON DUPLICATE KEY UPDATE value='${value}', updated_at=NOW();"
}

for LET in $PIPELINE_LETTERS; do
    PATTERN="${LOG_DIR}/${LET}-$(date +%Y-%m-%d)_*.log"
    LATEST=$(ls -t $PATTERN 2>/dev/null | head -1)
    LOW=$(echo "$LET" | tr '[:upper:]' '[:lower:]')

    if [[ -z "$LATEST" ]]; then continue; fi

    AUC=$(grep -oP 'Test AUC:\s*\K(?:nan|[-+]?(?:\d*\.\d+|\d+))' "$LATEST" | head -1)
    P10=$(grep -oP '(?:Precision@10(?: \((?:actual|bt_only)\))?:|\[bt_only\] Precision@10=)\s*\K(?:nan|[-+]?(?:\d*\.\d+|\d+))' "$LATEST" | head -1)

    if [[ -n "$AUC" ]]; then
        if persist_setting "trading.pipeline_auc.${LOW}" "$AUC"; then
            echo "   ${LET}: AUC=${AUC} ✔"
        else
            echo "   ${LET}: AUC=${AUC} ✗ (DB write failed)"
        fi
    fi

    if [[ -n "$P10" ]]; then
        if persist_setting "trading.pipeline_p10.${LOW}" "$P10"; then
            echo "     P@10=${P10} ✔"
        else
            echo "     P@10=${P10} ✗ (DB write failed)"
        fi
    fi

    # Persist dedicated ML updated timestamp (feeds the "ML Updated" column on /trading-settings)
    persist_setting "trading.pipeline_ml_updated.${LOW}" "$(date -u +%Y-%m-%dT%H:%M:%SZ)"
done

echo "=========================================================="
echo "  All training complete. Running calibration ingestion..."
cd "$REPO_ROOT" && php artisan ml:ingest-calibration
echo "=========================================================="
