#!/bin/bash
# Rescore Momentum Model alerts (Pipelines A, B, N)

source "$(dirname "$0")/model_env.sh"

LIMIT=200000

echo "=================================================="
echo "Rescoring Momentum Alerts (Pipelines A, B, N)"
echo "=================================================="
echo "Start time: $(date)"
echo ""

for PIPELINE in A B N; do
  MODEL_PATH="$(get_pipeline_model_path "$PIPELINE" "python_ml/models/winner_model_momentum.joblib")"
  echo "--------------------------------------------------"
  echo "Pipeline $PIPELINE — Model: $MODEL_PATH"
  echo "--------------------------------------------------"

  echo "Clearing existing ML scores for pipeline $PIPELINE..."
  mysql -u laravel -plaravel laravelInvest -e "UPDATE trade_alerts SET ml_scored_at = NULL, ml_win_prob = NULL, passed_ml = 0, ml_model_version = NULL WHERE pipeline_run = '$PIPELINE'"

  echo "Fetching trading dates for pipeline $PIPELINE..."
  DATES=$(mysql -u laravel -plaravel laravelInvest -N -B -e "SELECT DISTINCT trading_date_est FROM trade_alerts WHERE pipeline_run = '$PIPELINE' ORDER BY trading_date_est")

  TOTAL=$(echo "$DATES" | wc -l)
  CURRENT=0

  echo "Found $TOTAL trading dates to process"
  echo ""

  for date in $DATES; do
    CURRENT=$((CURRENT + 1))
    echo "[$CURRENT/$TOTAL] Pipeline $PIPELINE — Scoring $date..."
    python python_ml/v2/score_trade_alerts.py \
      --model-in "$MODEL_PATH" \
      --trading-date "$date" \
      --limit "$LIMIT" \
      --pipeline "$PIPELINE"
  done

  echo ""
done

echo ""
echo "=================================================="
echo "Done! All momentum alerts rescored."
echo "End time: $(date)"
echo "=================================================="
