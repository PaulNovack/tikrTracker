#!/bin/bash

source "$(dirname "$0")/model_env.sh"
set -euo pipefail

cd "$(dirname "$0")/../.." || exit 1
# Rescore Pipeline J alerts (Higher-Low Breakout)

MODEL_PATH="$(get_pipeline_model_path "J" "python_ml/models/winner_model_pipeline_j.joblib")"
LIMIT=200000
PYTHON_BIN="${PYTHON_PATH:-python}"

if [[ ! -f "$MODEL_PATH" ]]; then
  echo "ERROR: Model not found: $MODEL_PATH"
  exit 1
fi

echo "=================================================="
echo "Rescoring Pipeline J Alerts (Higher-Low Breakout)"
echo "=================================================="
echo "Model: $MODEL_PATH"
echo "Start time: $(date)"
echo ""

echo "Clearing existing ML scores for pipeline J..."
mysql -u laravel -plaravel laravelInvest -e "UPDATE trade_alerts SET ml_scored_at = NULL, ml_win_prob = NULL, passed_ml = 0, ml_model_version = NULL WHERE pipeline_run = 'J'"

echo "Fetching trading dates for pipeline J..."
DATES=$(mysql -u laravel -plaravel laravelInvest -N -B -e "SELECT DISTINCT trading_date_est FROM trade_alerts WHERE pipeline_run = 'J' ORDER BY trading_date_est")

TOTAL=$(echo "$DATES" | wc -l)
CURRENT=0

echo "Found $TOTAL trading dates to process"
echo ""

for date in $DATES; do
  CURRENT=$((CURRENT + 1))
  echo "[$CURRENT/$TOTAL] Scoring $date..."
  "$PYTHON_BIN" python_ml/score_trade_alerts.py \
    --model-in "$MODEL_PATH" \
    --trading-date "$date" \
    --limit "$LIMIT" \
    --pipeline J
done

echo ""
echo "=================================================="
echo "Done! All Pipeline J alerts rescored."
echo "End time: $(date)"
echo "=================================================="
