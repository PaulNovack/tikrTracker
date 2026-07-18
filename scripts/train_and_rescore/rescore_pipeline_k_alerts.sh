#!/bin/bash

source "$(dirname "$0")/model_env.sh"
# Rescore Pipeline K alerts (Scarcity Leaders)

MODEL_PATH="$(get_pipeline_model_path "K" "python_ml/models/winner_model_pipeline_k.joblib")"
LIMIT=200000

echo "=================================================="
echo "Rescoring Pipeline K Alerts (Scarcity Leaders)"
echo "=================================================="
echo "Model: $MODEL_PATH"
echo "Start time: $(date)"
echo ""

echo "Clearing existing ML scores for pipeline K..."
mysql -u laravel -plaravel laravelInvest -e "UPDATE trade_alerts SET ml_scored_at = NULL, ml_win_prob = NULL, passed_ml = 0, ml_model_version = NULL WHERE pipeline_run = 'K'"

echo "Fetching trading dates for pipeline K..."
DATES=$(mysql -u laravel -plaravel laravelInvest -N -B -e "SELECT DISTINCT trading_date_est FROM trade_alerts WHERE pipeline_run = 'K' ORDER BY trading_date_est")

TOTAL=$(echo "$DATES" | wc -l)
CURRENT=0

echo "Found $TOTAL trading dates to process"
echo ""

for date in $DATES; do
  CURRENT=$((CURRENT + 1))
  echo "[$CURRENT/$TOTAL] Scoring $date..."
  python python_ml/v2/score_trade_alerts.py \
    --model-in "$MODEL_PATH" \
    --trading-date "$date" \
    --limit "$LIMIT" \
    --pipeline K
done

echo ""
echo "=================================================="
echo "Done! All Pipeline K alerts rescored."
echo "End time: $(date)"
echo "=================================================="
