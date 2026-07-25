#!/bin/bash
# v2/scripts/retrain_realpicks.sh
#
# Train a SINGLE combined model on ALL pipelines, using ONLY trade_alerts
# records that have real Alpaca fills (actual fills matched via alpaca_orders).
# Saves the model as a fixed-name file (no date tag).
#
# This is similar to retrain_combined_alpaca_only.sh but outputs to a
# stable filename: winner_model_pipeline_realpicks.joblib
#
# Usage:
#   bash python_ml/v2/scripts/retrain_realpicks.sh

set -e

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
source "$SCRIPT_DIR/env.sh"

TODAY=$(date +%Y-%m-%d)
TRAINER=$(get_trainer_cmd)
MODEL_OUT="${V2_DIR}/models/winner_model_pipeline_realpicks.joblib"
LOG_FILE="${V2_DIR}/training_logs/REALPICKS-$(date +%Y-%m-%d_%H%M).log"

# All active pipelines — train one combined model on real picks only
ALL_PIPELINES="A,B,C,D,E,F,G,H,I,J,K,L,M,N,O,P,Q,R,MANUAL"

echo "=========================================================="
echo "  Retrain Real Picks Model (Alpaca Fills Only)"
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
echo "  Real Picks training complete!"
echo "  Model: $MODEL_OUT"
echo "  End time: $(date)"
echo "=========================================================="

# ─────────────────────────────────────────────────────────────────────────
# Extract AUC & Precision@10 and persist to DB for visibility.
# Stored under a synthetic "realpicks" pipeline key.
# ─────────────────────────────────────────────────────────────────────────
MYSQL_CMD=$(get_mysql_cmd)

AUC=$(grep -oP 'Test AUC:\s*\K[\d.]+' "$LOG_FILE" | head -1)
P10=$(grep -oP '\[actual_only\] Precision@10=\K[\d.]+' "$LOG_FILE" | head -1)

if [[ -n "$AUC" ]]; then
    $MYSQL_CMD \
        -e "INSERT INTO settings (name, value, updated_at) VALUES ('trading.pipeline_auc.realpicks', '${AUC}', NOW()) ON DUPLICATE KEY UPDATE value='${AUC}', updated_at=NOW();"
    echo "  AUC=${AUC} ✔"
fi

if [[ -n "$P10" ]]; then
    $MYSQL_CMD \
        -e "INSERT INTO settings (name, value, updated_at) VALUES ('trading.pipeline_p10.realpicks', '${P10}', NOW()) ON DUPLICATE KEY UPDATE value='${P10}', updated_at=NOW();"
    echo "  P@10=${P10} ✔"
fi

$MYSQL_CMD \
    -e "INSERT INTO settings (name, value, updated_at) VALUES ('trading.pipeline_ml_updated.realpicks', 'now', NOW()) ON DUPLICATE KEY UPDATE value='now', updated_at=NOW();"
echo "  ML Updated ✔"

echo "=========================================================="
echo "  Done."
echo "=========================================================="

cd "$REPO_ROOT" && php artisan ml:ingest-calibration
