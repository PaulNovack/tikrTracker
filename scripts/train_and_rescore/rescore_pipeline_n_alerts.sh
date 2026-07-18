#!/bin/bash
# Rescore Pipeline N alerts (Market Movers Momentum)

source "$(dirname "$0")/model_env.sh"

MODEL_PATH="$(get_pipeline_model_path "N" "python_ml/models/winner_model_pipeline_n.joblib")"
LIMIT=200000

echo "=================================================="
echo "Rescoring Pipeline N Alerts (Market Movers Momentum)"
echo "=================================================="
echo "Model: $MODEL_PATH"
echo "Start time: $(date)"
echo ""

echo "Clearing existing ML scores for pipeline N..."
mysql -u laravel -plaravel laravelInvest -e "UPDATE trade_alerts SET ml_scored_at = NULL, ml_win_prob = NULL, passed_ml = 0, ml_model_version = NULL WHERE pipeline_run = 'N'"

echo "Fetching trading dates for pipeline N..."
DATES=$(mysql -u laravel -plaravel laravelInvest -N -B -e "SELECT DISTINCT trading_date_est FROM trade_alerts WHERE pipeline_run = 'N' ORDER BY trading_date_est")

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
    --pipeline N
done

echo ""
echo "=================================================="
echo "Done! All Pipeline N alerts rescored."
echo "End time: $(date)"
echo "=================================================="
