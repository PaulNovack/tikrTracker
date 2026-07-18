#!/bin/bash

source "$(dirname "$0")/model_env.sh"

set -e

SCRIPT_DIR="$( cd "$( dirname "${BASH_SOURCE[0]}" )" && pwd )"
LARAVEL_ROOT="$(dirname "$(dirname "$SCRIPT_DIR")")"
cd "$LARAVEL_ROOT" || exit 1

TODAY=$(date +%Y-%m-%d)
MODEL_OUT="${MODEL_OUT:-$(get_pipeline_model_path "B" "python_ml/models/winner_model_pipeline_b.joblib")}" 
DB_USER=${DB_USERNAME:-laravel}
DB_PASS=${DB_PASSWORD:-laravel}
DB_NAME=${DB_DATABASE:-laravelInvest}

DEFAULT_START_DATE=$(mysql -N -u "$DB_USER" -p"$DB_PASS" "$DB_NAME" -e "
SELECT DATE(MIN(entry_ts_est))
FROM trade_alerts
WHERE pipeline_run='B'
  AND analyzed = 1
  AND pnl_percent IS NOT NULL
")
DEFAULT_START_DATE=${DEFAULT_START_DATE:-$(date -d "12 months ago" +%Y-%m-%d)}
START_DATE=${START_DATE:-$DEFAULT_START_DATE}

echo "=================================================="
echo "Training Pipeline B Model (Elite Multi-Day Momentum)"
echo "=================================================="
echo "Start time: $(date)"
echo "Training window: $START_DATE -> $TODAY"
echo "Training scope: all available B alerts from the earliest analyzed row"
echo ""

python python_ml/v2/v2/train_stock_winner_model_v2.py train \
  --pipeline B \
  --win-threshold 2.0 \
  --actual-fill-weight 20.0 \
  --eval-on-actual-only \
  --start "$START_DATE" \
  --end "$TODAY" \
  --test-size 0.2 \
  --train-full \
  --model-out "$MODEL_OUT"

echo ""
echo "=================================================="
echo "Training complete!"
echo "End time: $(date)"
echo "Model saved: $MODEL_OUT"
echo ""
echo "Next steps:"
echo "1. Ensure .env points to this model path if changed:"
echo "   TRADING_ML_PIPELINE_B_MODEL_PATH=$MODEL_OUT"
echo "2. Run: php artisan config:clear"
echo "3. Run: ./scripts/train_and_rescore/rescore_pipeline_b_alerts.sh"
echo "=================================================="