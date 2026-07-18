#!/bin/bash
# v2/scripts/retrain_all.sh — Retrain all active pipelines with v2 trainer.
# Run overnight. Each pipeline trains in parallel.
# Mirrors the logic from scripts/train_and_rescore/retrain_all_active_pipelines.sh
# but lives in the v2/ directory with its own env.sh helpers.
#
# Pipelines trained: A, B, C, D, E, F, G, H, I, J, K, L, M, N, O, P, Q, R (separate models)
# Note: H, I, D are trained as independent models here. Use retrain_all_with_hid.sh for a combined H+I+D model.
#
# Usage:
#   bash python_ml/v2/scripts/retrain_all.sh

set -e

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
source "$SCRIPT_DIR/env.sh"

TODAY=$(date +%Y-%m-%d)
JOB_PIDS=()
JOB_LABELS=()

TRAINER=$(get_trainer_cmd)

echo "=========================================================="
echo "  Retrain All Active Pipelines (v2 trainer)"
echo "  Trainer: $TRAINER"
echo "  Date: $TODAY"
echo "=========================================================="
echo ""

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
launch_job "Momentum Continuation" "A-$(date +%Y-%m-%d_%H).log" \
    $TRAINER \
        --pipeline A --win-threshold 2.0 --actual-fill-weight 20.0 \
        --eval-on-actual-only --split-mode day \
        --start 2024-01-01 --end "$TODAY" \
        --test-size 0.2 --train-full \
        --model-out "$(get_pipeline_model_path "A" "python_ml/v2/models/winner_model_momentum.joblib")"

# C — Hybrid Big-Move Breakout (v600.0)
launch_job "Hybrid Big-Move Breakout" "C-$(date +%Y-%m-%d_%H).log" \
    $TRAINER \
        --pipeline C --win-threshold 2.0 --actual-fill-weight 20.0 \
        --eval-on-actual-only --split-mode day \
        --start 2024-01-01 --end "$TODAY" \
        --test-size 0.2 --train-full \
        --model-out "$(get_pipeline_model_path "C" "python_ml/v2/models/winner_model_breakouts.joblib")"

# B — Elite Multi-Day Momentum
# Queries DB for the earliest analyzed B alert to auto-determine start date.
launch_job "Elite Multi-Day Momentum" "B-$(date +%Y-%m-%d_%H).log" bash -c "
    DB_USER=\"\${DB_USERNAME:-laravel}\"
    DB_PASS=\"\${DB_PASSWORD:-laravel}\"
    DB_NAME=\"\${DB_DATABASE:-laravelInvest}\"
    DEFAULT_START_DATE=\$(mysql -N -u \"\$DB_USER\" -p\"\$DB_PASS\" \"\$DB_NAME\" -e \"
        SELECT DATE(MIN(entry_ts_est))
        FROM trade_alerts
        WHERE pipeline_run='B'
          AND analyzed = 1
          AND pnl_percent IS NOT NULL
    \" 2>/dev/null)
    DEFAULT_START_DATE=\${DEFAULT_START_DATE:-\$(date -d '12 months ago' +%Y-%m-%d)}
    $TRAINER \
        --pipeline B --win-threshold 2.0 --actual-fill-weight 20.0 \
        --eval-on-actual-only --start \"\$DEFAULT_START_DATE\" --end \"$TODAY\" \
        --test-size 0.2 --train-full \
        --model-out \"$(get_pipeline_model_path "B" "python_ml/v2/models/winner_model_pipeline_b.joblib")\"
"

# E — Trend Continuation
launch_job "Trend Continuation" "E-$(date +%Y-%m-%d_%H).log" \
    $TRAINER \
        --pipeline E --win-threshold 2.0 --actual-fill-weight 20.0 \
        --eval-on-actual-only --split-mode day \
        --start 2024-01-01 --end "$TODAY" \
        --test-size 0.2 --train-full \
        --model-out "$(get_pipeline_model_path "E" "python_ml/v2/models/winner_model_pipeline_e.joblib")"

# F — Risk-Off / Bear Market
launch_job "Risk-Off / Bear Market" "F-$(date +%Y-%m-%d_%H).log" \
    $TRAINER \
        --pipeline F --win-threshold 2.0 --actual-fill-weight 20.0 \
        --eval-on-actual-only --split-mode day \
        --start 2024-01-01 --end "$TODAY" \
        --test-size 0.2 --train-full \
        --model-out "$(get_pipeline_model_path "F" "python_ml/v2/models/winner_model_pipeline_f.joblib")"

# H — High-Momentum Breakout (separate model)
launch_job "High-Momentum Breakout" "H-$(date +%Y-%m-%d_%H).log" \
    $TRAINER \
        --pipeline H --win-threshold 2.0 --actual-fill-weight 20.0 \
        --eval-on-actual-only --split-mode day \
        --start 2024-01-01 --end "$TODAY" \
        --test-size 0.2 --train-full \
        --model-out "$(get_pipeline_model_path "H" "python_ml/v2/models/winner_model_pipeline_h.joblib")"

# I — Intraday Reversal (separate model)
launch_job "Intraday Reversal" "I-$(date +%Y-%m-%d_%H).log" \
    $TRAINER \
        --pipeline I --win-threshold 2.0 --actual-fill-weight 20.0 \
        --eval-on-actual-only --split-mode day \
        --start 2024-01-01 --end "$TODAY" \
        --test-size 0.2 --train-full \
        --model-out "$(get_pipeline_model_path "I" "python_ml/v2/models/winner_model_pipeline_i.joblib")"

# D — Day Range Breakout (separate model)
launch_job "Day Range Breakout" "D-$(date +%Y-%m-%d_%H).log" \
    $TRAINER \
        --pipeline D --win-threshold 2.0 --actual-fill-weight 20.0 \
        --eval-on-actual-only --split-mode day \
        --start 2024-01-01 --end "$TODAY" \
        --test-size 0.2 --train-full \
        --model-out "$(get_pipeline_model_path "D" "python_ml/v2/models/winner_model_pipeline_d.joblib")"

# J — Higher-Low Breakout (Recent 4% Movers)
launch_job "Higher-Low Breakout" "J-$(date +%Y-%m-%d_%H).log" \
    $TRAINER \
        --pipeline J --win-threshold 2.0 --actual-fill-weight 20.0 \
        --eval-on-actual-only --split-mode day \
        --start 2025-01-01 --end "$TODAY" \
        --test-size 0.2 --top-k 10 --train-full \
        --model-out "$(get_pipeline_model_path "J" "python_ml/v2/models/winner_model_pipeline_j.joblib")"

# K — Scarcity Leaders
launch_job "Scarcity Leaders" "K-$(date +%Y-%m-%d_%H).log" \
    $TRAINER \
        --pipeline K --win-threshold 2.0 --actual-fill-weight 20.0 \
        --eval-on-actual-only --split-mode day \
        --start 2024-01-01 --end "$TODAY" \
        --test-size 0.2 --train-full \
        --model-out "$(get_pipeline_model_path "K" "python_ml/v2/models/winner_model_pipeline_k.joblib")"

# L — Early Momentum
launch_job "Early Momentum" "L-$(date +%Y-%m-%d_%H).log" \
    $TRAINER \
        --pipeline L --win-threshold 2.0 --actual-fill-weight 20.0 \
        --eval-on-actual-only --split-mode day \
        --start 2024-01-01 --end "$TODAY" \
        --test-size 0.2 --train-full \
        --model-out "$(get_pipeline_model_path "L" "python_ml/v2/models/winner_model_pipeline_l.joblib")"

# N — Market Movers Momentum
launch_job "Market Movers Momentum" "N-$(date +%Y-%m-%d_%H).log" \
    $TRAINER \
        --pipeline N --win-threshold 2.0 --actual-fill-weight 20.0 \
        --eval-on-actual-only --start 2024-01-01 --end "$TODAY" \
        --test-size 0.2 --train-full \
        --model-out "$(get_pipeline_model_path "N" "python_ml/v2/models/winner_model_pipeline_n.joblib")"

# G — Oversold Bounce (v210.0) — small dataset, use LR baseline to avoid overfitting
launch_job "Oversold Bounce" "G-$(date +%Y-%m-%d_%H).log" \
    $TRAINER \
        --pipeline G --win-threshold 2.0 --actual-fill-weight 20.0 \
        --baseline \
        --eval-on-actual-only --split-mode day \
        --start 2024-01-01 --end "$TODAY" \
        --test-size 0.2 --train-full \
        --model-out "$(get_pipeline_model_path "G" "python_ml/v2/models/winner_model_pipeline_g.joblib")"

# M — Tight Stops Clean Trend (v1400.0)
launch_job "Tight Stops Clean Trend" "M-$(date +%Y-%m-%d_%H).log" \
    $TRAINER \
        --pipeline M --win-threshold 2.0 --actual-fill-weight 20.0 \
        --eval-on-actual-only --split-mode day \
        --start 2024-01-01 --end "$TODAY" \
        --test-size 0.2 --train-full \
        --model-out "$(get_pipeline_model_path "M" "python_ml/v2/models/winner_model_pipeline_m.joblib")"

# O — Opening Range Breakout (v1500.0)
launch_job "Opening Range Breakout" "O-$(date +%Y-%m-%d_%H).log" \
    $TRAINER \
        --pipeline O --win-threshold 2.0 --actual-fill-weight 20.0 \
        --eval-on-actual-only --split-mode day \
        --start 2024-01-01 --end "$TODAY" \
        --test-size 0.2 --train-full \
        --model-out "$(get_pipeline_model_path "O" "python_ml/v2/models/winner_model_pipeline_o.joblib")"

# P — Oversold Bounce (v210.1) — research only, no model path yet
launch_job "Forward-Looking 2H Runner" "P-$(date +%Y-%m-%d_%H).log" \
    $TRAINER \
        --pipeline P --win-threshold 2.0 --actual-fill-weight 20.0 \
        --eval-on-actual-only --split-mode day \
        --start 2024-01-01 --end "$TODAY" \
        --test-size 0.2 --train-full \
        --model-out "$(get_pipeline_model_path "P" "python_ml/v2/models/winner_model_pipeline_p.joblib")"

# Q — Volume-First (v27.0)
launch_job "Volume-First" "Q-$(date +%Y-%m-%d_%H).log" \
    $TRAINER \
        --pipeline Q --win-threshold 2.0 --actual-fill-weight 20.0 \
        --eval-on-actual-only --split-mode day \
        --start 2025-06-01 --end "$TODAY" \
        --test-size 0.2 --top-k 10 --train-full \
        --model-out "$(get_pipeline_model_path "Q" "python_ml/v2/models/winner_model_pipeline_q.joblib")"

# R — Backtest-Optimized ML (v3100.0) — small dataset, use LR baseline to avoid overfitting
launch_job "Backtest-Optimized ML" "R-$(date +%Y-%m-%d_%H).log" \
    $TRAINER \
        --pipeline R --win-threshold 2.0 --actual-fill-weight 20.0 \
        --baseline \
        --eval-on-actual-only --split-mode day \
        --start 2024-01-01 --end "$TODAY" \
        --test-size 0.2 --train-full \
        --model-out "$(get_pipeline_model_path "R" "python_ml/v2/models/winner_model_pipeline_R.joblib")"

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

# Source .env for DB credentials
source "$REPO_ROOT/.env" 2>/dev/null || true
DB_USER="${DB_USERNAME:-laravel}"
DB_PASS="${DB_PASSWORD:-laravel}"
DB_NAME="${DB_DATABASE:-laravelInvest}"

LOG_DIR="${SCRIPT_DIR}/../training_logs"
PIPELINE_LETTERS="A B C D E F G H I J K L M N O P Q R"

persist_setting() {
    local name="$1"
    local value="$2"
    echo "      → Writing ${name}=${value} to DB..."
    mysql -N -u "$DB_USER" -p"$DB_PASS" "$DB_NAME" \
        -e "INSERT INTO settings (name, value, updated_at) VALUES ('${name}', '${value}', NOW()) ON DUPLICATE KEY UPDATE value='${value}', updated_at=NOW();"
}

for LET in $PIPELINE_LETTERS; do
    PATTERN="${LOG_DIR}/${LET}-$(date +%Y-%m-%d)_*.log"
    LATEST=$(ls -t $PATTERN 2>/dev/null | head -1)
    LOW=$(echo "$LET" | tr '[:upper:]' '[:lower:]')

    if [[ -z "$LATEST" ]]; then continue; fi

    AUC=$(grep -oP 'Test AUC:\s*\K[\d.]+' "$LATEST" | head -1)
    P10_BT=$(grep -oP '\[bt_only\] Precision@10=\K[\d.]+' "$LATEST" | head -1)
    P10_ACTUAL=$(grep -oP 'Precision@10 \(actual\):\s*\K[\d.]+' "$LATEST" | head -1)

    # Prefer actual-fill P@10 when available and > 0
    if [[ -n "$P10_ACTUAL" && "$P10_ACTUAL" != "0.000" ]]; then
        P10="$P10_ACTUAL"
        P10_SRC="actual"
    else
        P10="$P10_BT"
        P10_SRC="${P10_BT:+BT}"
    fi

    if [[ -n "$AUC" ]]; then
        if persist_setting "trading.pipeline_auc.${LOW}" "$AUC"; then
            echo "   ${LET}: AUC=${AUC} ✔"
        else
            echo "   ${LET}: AUC=${AUC} ✗ (DB write failed)"
        fi
    fi

    if [[ -n "$P10" ]]; then
        if persist_setting "trading.pipeline_p10.${LOW}" "$P10"; then
            echo "     P@10=${P10} (${P10_SRC}) ✔"
        else
            echo "     P@10=${P10} (${P10_SRC}) ✗ (DB write failed)"
        fi
    fi

    # Persist dedicated ML updated timestamp (feeds the "ML Updated" column on /trading-settings)
    persist_setting "trading.pipeline_ml_updated.${LOW}" "$(date -u +%Y-%m-%dT%H:%M:%SZ)"
done

echo "=========================================================="
