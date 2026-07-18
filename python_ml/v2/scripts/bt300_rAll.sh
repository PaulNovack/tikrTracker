#!/bin/bash
# v2/scripts/bt300_rAll.sh — Hybrid retrain: 3K+ backtest + real Alpaca fills (20x weight)
#
# Trains a single combined model on ALL pipeline data (backtest + live fills).
# Real Alpaca fills are weighted 20x so the model prioritizes real execution outcomes
# while still benefiting from the volume of backtest data for stable gradient estimates.
#
# Usage:
#   bash python_ml/v2/scripts/bt300_rAll.sh

set -e

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
source "$SCRIPT_DIR/env.sh"

TODAY=$(date +%Y-%m-%d)
TRAINER=$(get_trainer_cmd)
DATE_TAG=$(date +%Y-%m-%d_%H%M)
MODEL_OUT="${V2_DIR}/models/winner_model_hybrid_bt300_rAll-${DATE_TAG}.joblib"
LOG_FILE="${V2_DIR}/training_logs/HYBRID_BT300_RALL-$(date +%Y-%m-%d_%H%M).log"

# All active pipelines — train one combined model
ALL_PIPELINES="A,B,C,D,E,F,G,H,I,J,K,L,M,N,O,P,Q,R"

echo "=========================================================="
echo "  Hybrid Retrain: 3K+ BT Simulated + Alpaca Fills (20x)"
echo "  Trainer: $TRAINER"
echo "  Pipelines: $ALL_PIPELINES"
echo "  Model out: $MODEL_OUT"
echo "  Log: $LOG_FILE"
echo "  Date: $TODAY"
echo "=========================================================="
echo ""

$TRAINER \
    --pipeline "$ALL_PIPELINES" \
    --actual-fill-weight 20.0 \
    --win-threshold 2.0 \
    --eval-on-actual-only \
    --split-mode day \
    --start "$(date -d '90 days ago' +%Y-%m-%d)" \
    --end "$TODAY" \
    --test-size 0.2 \
    --top-k 10 \
    --limit 4000 \
    --train-full \
    --model-out "$MODEL_OUT" \
    2>&1 | tee "$LOG_FILE"

echo ""
echo "=========================================================="
echo "  Hybrid retrain complete!"
echo "  Model: $MODEL_OUT"
echo "  End time: $(date)"
echo "=========================================================="

# ── Extract AUC & Precision@10 and persist to DB ──
source "$REPO_ROOT/.env" 2>/dev/null || true
DB_USER="${DB_USERNAME:-laravel}"
DB_PASS="${DB_PASSWORD:-laravel}"
DB_NAME="${DB_DATABASE:-laravelInvest}"

AUC=$(grep -oP 'Test AUC:\s*\K[\d.]+' "$LOG_FILE" | head -1)
P10=$(grep -oP '\[actual_only\] Precision@10=\K[\d.]+' "$LOG_FILE" | head -1)

if [[ -n "$AUC" ]]; then
    mysql -N -u "$DB_USER" -p"$DB_PASS" "$DB_NAME" \
        -e "INSERT INTO settings (name, value, updated_at) VALUES ('trading.pipeline_auc.hybrid_bt300_rall', '${AUC}', NOW()) ON DUPLICATE KEY UPDATE value='${AUC}', updated_at=NOW();"
    echo "  AUC=${AUC} ✔"
fi

if [[ -n "$P10" ]]; then
    mysql -N -u "$DB_USER" -p"$DB_PASS" "$DB_NAME" \
        -e "INSERT INTO settings (name, value, updated_at) VALUES ('trading.pipeline_p10.hybrid_bt300_rall', '${P10}', NOW()) ON DUPLICATE KEY UPDATE value='${P10}', updated_at=NOW();"
    echo "  P@10=${P10} ✔"
fi

mysql -N -u "$DB_USER" -p"$DB_PASS" "$DB_NAME" \
    -e "INSERT INTO settings (name, value, updated_at) VALUES ('trading.pipeline_ml_updated.hybrid_bt300_rall', 'now', NOW()) ON DUPLICATE KEY UPDATE value='now', updated_at=NOW();"
echo "  ML Updated ✔"

echo "=========================================================="
echo "  Done."
echo "=========================================================="
