#!/bin/bash

source "$(dirname "$0")/model_env.sh"

set -e

SCRIPT_DIR="$( cd "$( dirname "${BASH_SOURCE[0]}" )" && pwd )"
LARAVEL_ROOT="$(dirname "$(dirname "$SCRIPT_DIR")")"
cd "$LARAVEL_ROOT" || exit 1

TODAY=$(date +%Y-%m-%d)
MODEL_OUT="${MODEL_OUT:-$(get_pipeline_model_path "E" "python_ml/models/winner_model_pipeline_e.joblib")}" 
DB_USER=${DB_USERNAME:-laravel}
DB_PASS=${DB_PASSWORD:-laravel}
DB_NAME=${DB_DATABASE:-laravelInvest}

DEFAULT_START_DATE=$(mysql -N -u "$DB_USER" -p"$DB_PASS" "$DB_NAME" -e "
SELECT DATE(MIN(entry_ts_est))
FROM trade_alerts
WHERE pipeline_run='E'
  AND analyzed = 1
  AND pnl_percent IS NOT NULL
")
DEFAULT_START_DATE=${DEFAULT_START_DATE:-$(date -d "12 months ago" +%Y-%m-%d)}
START_DATE=${START_DATE:-$DEFAULT_START_DATE}

# Train Pipeline E Model (Multi-Day Pattern Continuation)
# Trend continuation with healthy pullbacks (20-60% retracement)
# Expected: 2000+ alerts

echo "=================================================="
echo "Training Pipeline E Model (Multi-Day Continuation)"
echo "=================================================="
echo "Start time: $(date)"
echo "Training window: $START_DATE -> $TODAY"
echo "Training scope: all available E alerts from the earliest analyzed row"
echo ""

# Train Pipeline E specific model
python python_ml/v2/v2/train_stock_winner_model_v2.py train \
  --pipeline E \
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
echo "=================================================="
echo ""
echo "Next steps:"
echo "1. Update .env:"
echo "   TRADING_ML_PIPELINE_E_MODEL_PATH=$MODEL_OUT"
echo "2. Run: php artisan config:clear"
echo "3. Monitor logs for 'Using Pipeline E specific model'"