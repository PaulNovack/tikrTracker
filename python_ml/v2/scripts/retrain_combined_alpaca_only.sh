#!/bin/bash
# v2/scripts/retrain_combined_alpaca_only.sh
#
# Train a SINGLE combined model on ALL pipelines, using ONLY trade_alerts
# records that have real Alpaca fills (actual fills matched via alpaca_orders).
#
# This replaces the per-pipeline approach (retrain_all_with_hid.sh) with a
# unified model that sees all signals together, trained exclusively on live
# execution data rather than backtest simulations.
#
# Usage:
#   bash python_ml/v2/scripts/retrain_combined_alpaca_only.sh

set -e

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
source "$SCRIPT_DIR/env.sh"

TODAY=$(date +%Y-%m-%d)
TRAINER=$(get_trainer_cmd)
DATE_TAG=$(date +%Y-%m-%d_%H%M)
MODEL_OUT="${V2_DIR}/models/winner_model_combined_alpaca-${DATE_TAG}.joblib"
LOG_FILE="${V2_DIR}/training_logs/COMBINED_ALPACA-$(date +%Y-%m-%d_%H%M).log"

# All active pipelines — train one combined model
ALL_PIPELINES="A,B,C,D,E,F,G,H,I,J,K,L,M,N,O,P,Q,R"

echo "=========================================================="
echo "  Retrain Combined Alpaca-Only Model"
echo "  Trainer: $TRAINER"
echo "  Pipelines: $ALL_PIPELINES"
echo "  Model out: $MODEL_OUT"
echo "  Log: $LOG_FILE"
echo "  Date: $TODAY"
echo "=========================================================="
echo ""

$TRAINER \
    --pipeline "$ALL_PIPELINES" \
    --actual-fills-only \
    --win-threshold 2.0 \
    --actual-fill-weight 1.0 \
    --eval-on-actual-only \
    --split-mode day \
    --start 2024-01-01 \
    --end "$TODAY" \
    --test-size 0.2 \
    --top-k 10 \
    --limit 50000 \
    --train-full \
    --model-out "$MODEL_OUT" \
    2>&1 | tee "$LOG_FILE"

echo ""
echo "=========================================================="
echo "  Combined Alpaca-Only training complete!"
echo "  Model: $MODEL_OUT"
echo "  End time: $(date)"
echo "=========================================================="

# ─────────────────────────────────────────────────────────────────────────
# Extract AUC & Precision@10 and persist to DB for visibility on
# the /trading-settings pipelines tab (stored as a synthetic "ALL" pipeline).
# ─────────────────────────────────────────────────────────────────────────
source "$REPO_ROOT/.env" 2>/dev/null || true
DB_USER="${DB_USERNAME:-laravel}"
DB_PASS="${DB_PASSWORD:-laravel}"
DB_NAME="${DB_DATABASE:-laravelInvest}"

AUC=$(grep -oP 'Test AUC:\s*\K[\d.]+' "$LOG_FILE" | head -1)
P10=$(grep -oP '\[actual_only\] Precision@10=\K[\d.]+' "$LOG_FILE" | head -1)

if [[ -n "$AUC" ]]; then
    mysql -N -u "$DB_USER" -p"$DB_PASS" "$DB_NAME" \
        -e "INSERT INTO settings (name, value, updated_at) VALUES ('trading.pipeline_auc.combined_alpaca', '${AUC}', NOW()) ON DUPLICATE KEY UPDATE value='${AUC}', updated_at=NOW();"
    echo "  AUC=${AUC} ✔"
fi

if [[ -n "$P10" ]]; then
    mysql -N -u "$DB_USER" -p"$DB_PASS" "$DB_NAME" \
        -e "INSERT INTO settings (name, value, updated_at) VALUES ('trading.pipeline_p10.combined_alpaca', '${P10}', NOW()) ON DUPLICATE KEY UPDATE value='${P10}', updated_at=NOW();"
    echo "  P@10=${P10} ✔"
fi

mysql -N -u "$DB_USER" -p"$DB_PASS" "$DB_NAME" \
    -e "INSERT INTO settings (name, value, updated_at) VALUES ('trading.pipeline_ml_updated.combined_alpaca', 'now', NOW()) ON DUPLICATE KEY UPDATE value='now', updated_at=NOW();"
echo "  ML Updated ✔"

echo "=========================================================="
echo "  Done."
echo "=========================================================="
