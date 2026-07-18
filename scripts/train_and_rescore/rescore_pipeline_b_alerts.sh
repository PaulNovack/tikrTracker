#!/bin/bash

source "$(dirname "$0")/model_env.sh"

echo "=================================================="
echo "Rescoring Pipeline B Alerts with Dedicated Model"
echo "=================================================="

MODEL_PATH="$(get_pipeline_model_path "B" "python_ml/models/winner_model_pipeline_b.joblib")"
LIMIT=200000

echo "Model: $MODEL_PATH"
echo "Start time: $(date)"
echo ""

cd "$(dirname "$0")/../.." || exit

echo "Clearing existing ML scores for Pipeline B..."
mysql -u laravel -plaravel laravelInvest -e "UPDATE trade_alerts SET ml_scored_at = NULL, ml_win_prob = NULL, passed_ml = 0, ml_model_version = NULL WHERE pipeline_run = 'B'"

echo "Fetching distinct trading dates for Pipeline B..."
DATES=$(mysql -u laravel -plaravel laravelInvest -N -B -e "SELECT DISTINCT trading_date_est FROM trade_alerts WHERE pipeline_run = 'B' ORDER BY trading_date_est")

TOTAL=$(echo "$DATES" | wc -l)
CURRENT=0

echo "Found $TOTAL trading dates to process"
echo ""

for DATE in $DATES; do
    CURRENT=$((CURRENT + 1))
    echo "[$CURRENT/$TOTAL] Scoring $DATE..."
    python python_ml/v2/score_trade_alerts.py \
        --model-in "$MODEL_PATH" \
        --trading-date "$DATE" \
        --limit "$LIMIT" \
        --pipeline B
done

echo ""
echo "=================================================="
echo "Rescoring complete!"
echo "End time: $(date)"
echo "=================================================="
