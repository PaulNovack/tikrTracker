#!/bin/bash
# Rescore Pipeline M alerts (Tight Stops Clean Trend)

source "$(dirname "$0")/model_env.sh"

MODEL_PATH="$(get_pipeline_model_path "M" "python_ml/models/winner_model_pipeline_m.joblib")"
LIMIT=200000

echo "=================================================="
echo "Rescoring Pipeline M Alerts (Tight Stops Clean Trend)"
echo "=================================================="
echo "Model: $MODEL_PATH"
echo "Start time: $(date)"
echo ""

echo "Clearing existing ML scores for pipeline M..."
mysql -u laravel -plaravel laravelInvest -e "UPDATE trade_alerts SET ml_scored_at = NULL, ml_win_prob = NULL, passed_ml = 0, ml_model_version = NULL WHERE pipeline_run = 'M'"

echo "Fetching trading dates for pipeline M..."
DATES=$(mysql -u laravel -plaravel laravelInvest -N -B -e "SELECT DISTINCT trading_date_est FROM trade_alerts WHERE pipeline_run = 'M' ORDER BY trading_date_est")

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
    --pipeline M
done

echo ""
echo "=================================================="
echo "Done! All Pipeline M alerts rescored."
echo "End time: $(date)"
echo "=================================================="
