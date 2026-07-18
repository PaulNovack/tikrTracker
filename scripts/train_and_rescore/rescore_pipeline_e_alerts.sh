#!/bin/bash
# Rescore Pipeline E alerts (Multi-Day Pattern Continuation)

source "$(dirname "$0")/model_env.sh"

MODEL_PATH="$(get_pipeline_model_path "E" "python_ml/models/winner_model_pipeline_e.joblib")"
LIMIT=200000

echo "=================================================="
echo "Rescoring Pipeline E Alerts (Multi-Day Continuation)"
echo "=================================================="
echo "Model: $MODEL_PATH"
echo "Start time: $(date)"
echo ""

echo "Clearing existing ML scores for pipeline E..."
mysql -u laravel -plaravel laravelInvest -e "UPDATE trade_alerts SET ml_scored_at = NULL, ml_win_prob = NULL, passed_ml = 0, ml_model_version = NULL WHERE pipeline_run = 'E'"

echo "Fetching trading dates for pipeline E..."
DATES=$(mysql -u laravel -plaravel laravelInvest -N -B -e "SELECT DISTINCT trading_date_est FROM trade_alerts WHERE pipeline_run = 'E' ORDER BY trading_date_est")

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
    --pipeline E
done

echo ""
echo "=================================================="
echo "Done! All Pipeline E alerts rescored."
echo "End time: $(date)"
echo "=================================================="
