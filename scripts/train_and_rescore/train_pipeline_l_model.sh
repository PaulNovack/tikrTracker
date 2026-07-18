#!/bin/bash
# Train Pipeline L Model (Early Momentum V1600)
# Early momentum entries with relaxed filters (sister strategy to H)

source "$(dirname "$0")/model_env.sh"

set -e

SCRIPT_DIR="$( cd "$( dirname "${BASH_SOURCE[0]}" )" && pwd )"
LARAVEL_ROOT="$(dirname "$(dirname "$SCRIPT_DIR")")"
cd "$LARAVEL_ROOT" || exit 1

TODAY=$(date +%Y-%m-%d)
PIPELINE_L_VERSION=${PIPELINE_L_VERSION:-v1600.0}
DB_USER=${DB_USERNAME:-laravel}
DB_PASS=${DB_PASSWORD:-laravel}
DB_NAME=${DB_DATABASE:-laravelInvest}

DEFAULT_START_DATE=$(mysql -N -u "$DB_USER" -p"$DB_PASS" "$DB_NAME" -e "
SELECT DATE(MIN(entry_ts_est))
FROM trade_alerts
WHERE pipeline_run='L'
  AND analyzed = 1
  AND pnl_percent IS NOT NULL
")
DEFAULT_START_DATE=${DEFAULT_START_DATE:-$(date -d "12 months ago" +%Y-%m-%d)}
START_DATE=${START_DATE:-$DEFAULT_START_DATE}
END_DATE=${END_DATE:-$TODAY}
MODEL_SUFFIX=${PIPELINE_L_VERSION//./_}
MODEL_OUT=${MODEL_OUT:-$(get_pipeline_model_path "L" "python_ml/models/winner_model_pipeline_l_${MODEL_SUFFIX}.joblib")}
RUN_ANALYZE=${RUN_ANALYZE:-1}
USE_FULL_TABLES=${USE_FULL_TABLES:-0}
MIN_LABELED_ROWS=${MIN_LABELED_ROWS:-200}
MIN_WINS=${MIN_WINS:-40}
MIN_LOSSES=${MIN_LOSSES:-40}
FORCE_SMALL_DATA=${FORCE_SMALL_DATA:-0}
ALGO_VERSION=$(grep "^TRADE_ALERT_L_VERSION=" "$LARAVEL_ROOT/.env" | cut -d '=' -f2)
ALGO_VERSION=${ALGO_VERSION:-$PIPELINE_L_VERSION}

echo "=================================================="
echo "Training Pipeline L Model (Early Momentum V1600)"
echo "=================================================="
echo "Start time: $(date)"
echo "Training window: $START_DATE -> $END_DATE"
echo "Training scope: all available L alerts from the earliest analyzed row"
echo "Pipeline L version target: $PIPELINE_L_VERSION"
echo ""

if [[ "$RUN_ANALYZE" == "1" ]]; then
  ANALYZE_FULL_TABLES_FLAG=""
  if [[ "$USE_FULL_TABLES" == "1" ]]; then
    ANALYZE_FULL_TABLES_FLAG="--use-full-tables"
  fi

  echo "Running analyze step (analyze:trade-alerts-atr-immediate)..."
  php artisan analyze:trade-alerts-atr-immediate \
    --algo-version="$ALGO_VERSION" \
    --pipeline=L \
    $ANALYZE_FULL_TABLES_FLAG \
    --write-results \
    --show-details
  echo ""
else
  echo "Skipping analyze step (RUN_ANALYZE=$RUN_ANALYZE)"
  echo ""
fi

echo "Running preflight data-quality check..."
read -r LABELED_ROWS WIN_ROWS LOSS_ROWS <<< "$(mysql -N -u "$DB_USER" -p"$DB_PASS" "$DB_NAME" -e "
SELECT
  COUNT(*) AS labeled_rows,
  SUM(CASE WHEN pnl_percent >= 1.0 THEN 1 ELSE 0 END) AS win_rows,
  SUM(CASE WHEN pnl_percent < 1.0 THEN 1 ELSE 0 END) AS loss_rows
FROM trade_alerts
WHERE pipeline_run='L'
  AND entry_ts_est >= '${START_DATE} 00:00:00'
  AND entry_ts_est <  DATE_ADD('${END_DATE} 00:00:00', INTERVAL 1 DAY)
  AND analyzed = 1
  AND pnl_percent IS NOT NULL
")"

echo "Labeled rows: ${LABELED_ROWS:-0} (wins: ${WIN_ROWS:-0}, losses: ${LOSS_ROWS:-0})"

if [[ "${FORCE_SMALL_DATA}" != "1" ]]; then
  if [[ "${LABELED_ROWS:-0}" -lt "$MIN_LABELED_ROWS" || "${WIN_ROWS:-0}" -lt "$MIN_WINS" || "${LOSS_ROWS:-0}" -lt "$MIN_LOSSES" ]]; then
    echo ""
    echo "Training aborted: not enough labeled data for stable Pipeline L model quality."
    echo "Required minimums: rows>=$MIN_LABELED_ROWS, wins>=$MIN_WINS, losses>=$MIN_LOSSES"
    echo "Current values:   rows=${LABELED_ROWS:-0}, wins=${WIN_ROWS:-0}, losses=${LOSS_ROWS:-0}"
    echo ""
    echo "If you want an interim exploratory model anyway, rerun with: FORCE_SMALL_DATA=1 ./scripts/train_and_rescore/train_pipeline_l_model.sh"
    exit 1
  fi
fi

# Train Pipeline L specific model
# Fix: actual-fill-weight 20x (honors real data over BT-simulated),
# eval-on-actual-only gives honest metrics on live-fill performance
python python_ml/v2/v2/train_stock_winner_model_v2.py train \
  --pipeline L \
  --win-threshold 2.0 \
  --actual-fill-weight 20.0 \
  --eval-on-actual-only \
  --start "$START_DATE" \
  --end "$END_DATE" \
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
echo "   TRADING_ML_PIPELINE_L_MODEL_PATH=$MODEL_OUT"
echo "2. Run: php artisan config:clear"
echo "3. Monitor logs for 'Using Pipeline L specific model'"
