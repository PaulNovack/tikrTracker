#!/bin/bash
# Rescore pipeline alerts for H, I, and D using the combined HID model.
# Usage: ./rescore_all_pipelines.sh [--pipeline H,I,D]
#
# Defaults to all three pipelines. Pass --pipeline to restrict to a subset.

source "$(dirname "$0")/model_env.sh"

PIPELINES="H,I,D"
LIMIT=200000

while [[ $# -gt 0 ]]; do
  case $1 in
    --pipeline)
      PIPELINES="$2"
      shift 2
      ;;
    *)
      echo "Unknown argument: $1"
      exit 1
      ;;
  esac
done

IFS=',' read -ra PIPELINE_LIST <<< "$PIPELINES"

echo "=================================================="
echo "Rescoring Pipelines: $PIPELINES"
echo "Start time: $(date)"
echo "=================================================="
echo ""

for PIPELINE in "${PIPELINE_LIST[@]}"; do
  PIPELINE=$(echo "$PIPELINE" | xargs)  # trim whitespace
  MODEL_PATH="$(get_pipeline_model_path "$PIPELINE" "python_ml/models/winner_model_pipeline_hid.joblib")"

  echo "--------------------------------------------------"
  echo "Pipeline $PIPELINE — Model: $MODEL_PATH"
  echo "--------------------------------------------------"

  echo "Clearing existing ML scores for pipeline $PIPELINE..."
  mysql -u laravel -plaravel laravelInvest -e \
    "UPDATE trade_alerts SET ml_scored_at = NULL, ml_win_prob = NULL, passed_ml = 0, ml_model_version = NULL WHERE pipeline_run = '$PIPELINE'"

  echo "Fetching trading dates for pipeline $PIPELINE..."
  DATES=$(mysql -u laravel -plaravel laravelInvest -N -B -e \
    "SELECT DISTINCT trading_date_est FROM trade_alerts WHERE pipeline_run = '$PIPELINE' ORDER BY trading_date_est")

  TOTAL=$(echo "$DATES" | grep -c .)
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
  echo "Pipeline $PIPELINE done."
  echo ""
done

echo "=================================================="
echo "All done! Pipelines rescored: $PIPELINES"
echo "End time: $(date)"
echo "=================================================="
