#!/bin/bash
# Rescore Pipeline L alerts (Early Momentum V1600)

source "$(dirname "$0")/model_env.sh"

MODEL_PATH=${MODEL_PATH:-$(get_pipeline_model_path "L" "python_ml/models/winner_model_pipeline_l.joblib")}
LIMIT=200000

if [[ ! -f "$MODEL_PATH" ]]; then
  LATEST_MODEL=$(ls -1t python_ml/models/winner_model_pipeline_l*.joblib 2>/dev/null | head -n 1)
  if [[ -n "$LATEST_MODEL" ]]; then
    MODEL_PATH="$LATEST_MODEL"
  fi
fi

if [[ ! -f "$MODEL_PATH" ]]; then
  echo "ERROR: Could not find a Pipeline L model artifact."
  echo "Tried: $MODEL_PATH"
  echo "Run training first: ./scripts/train_and_rescore/train_pipeline_l_model.sh"
  exit 1
fi

echo "=================================================="
echo "Rescoring Pipeline L Alerts (Early Momentum V1600)"
echo "=================================================="
echo "Model: $MODEL_PATH"
echo "Start time: $(date)"
echo ""

echo "Clearing existing ML scores for pipeline L..."
mysql -u laravel -plaravel laravelInvest -e "UPDATE trade_alerts SET ml_scored_at = NULL, ml_win_prob = NULL, passed_ml = 0, ml_model_version = NULL WHERE pipeline_run = 'L'"

echo "Fetching trading dates for pipeline L..."
DATES=$(mysql -u laravel -plaravel laravelInvest -N -B -e "SELECT DISTINCT trading_date_est FROM trade_alerts WHERE pipeline_run = 'L' ORDER BY trading_date_est")

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
    --pipeline L
done

echo ""
echo "=================================================="
echo "Done! All Pipeline L alerts rescored."
echo "End time: $(date)"
echo "=================================================="
