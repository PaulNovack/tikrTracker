#!/bin/bash
# Rescore Pipeline M alerts only (after adding vol_ratio and targets fields)

MODEL_PATH="python_ml/models/winner_model_weighted.joblib"
LIMIT=200000

echo "Clearing existing ML scores for Pipeline M alerts..."
mysql -u laravel -plaravel laravelInvest -e "UPDATE trade_alerts SET ml_scored_at = NULL, ml_win_prob = NULL, passed_ml = 0, ml_model_version = NULL WHERE pipeline_run = 'M'"

echo "Fetching all trading dates with Pipeline M alerts..."
DATES=$(mysql -u laravel -plaravel laravelInvest -N -B -e "SELECT DISTINCT trading_date_est FROM trade_alerts WHERE pipeline_run = 'M' ORDER BY trading_date_est")

TOTAL=$(echo "$DATES" | wc -l)
CURRENT=0

for date in $DATES; do
  CURRENT=$((CURRENT + 1))
  echo "[$CURRENT/$TOTAL] Scoring Pipeline M alerts for $date..."
  python python_ml/v2/score_trade_alerts.py \
    --model-in "$MODEL_PATH" \
    --trading-date "$date" \
    --limit "$LIMIT"
done

echo "Done! All Pipeline M alerts rescored with updated vol_ratio and targets fields."
echo ""
echo "Summary:"
mysql -u laravel -plaravel laravelInvest -e "
  SELECT 
    COUNT(*) as total_alerts,
    COUNT(ml_win_prob) as scored_alerts,
    AVG(ml_win_prob) as avg_win_prob,
    MIN(ml_win_prob) as min_win_prob,
    MAX(ml_win_prob) as max_win_prob
  FROM trade_alerts 
  WHERE pipeline_run = 'M'
"
