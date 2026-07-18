#!/bin/bash
# Rescore yesterday's Pipeline K alerts using v2/score_single_alert_v2.py
# Uses the full feature set including market context + rs_spread_vs_market

source "$(dirname "$0")/model_env.sh"

set -e

cd "$(dirname "$0")/../.." || exit

MODEL_PATH="$(get_pipeline_model_path "K" "python_ml/models/winner_model_pipeline_k.joblib")"
DATE="${1:-$(date -d 'yesterday' +%Y-%m-%d)}"

echo "=================================================="
echo "Rescoring Pipeline K Alerts for $DATE"
echo "Model: $MODEL_PATH"
echo "Start time: $(date)"
echo "=================================================="
echo ""

# Fetch alert IDs for the target date
ALERT_IDS=$(mysql -u laravel -plaravel laravelInvest -N -B -e \
    "SELECT id FROM trade_alerts WHERE pipeline_run = 'K' AND trading_date_est = '$DATE' ORDER BY id")

TOTAL=$(echo "$ALERT_IDS" | grep -c '[0-9]' || true)

if [ "$TOTAL" -eq 0 ]; then
    echo "No Pipeline K alerts found for $DATE"
    exit 0
fi

echo "Found $TOTAL alerts to rescore for $DATE"
echo ""

CURRENT=0
for alert_id in $ALERT_IDS; do
    CURRENT=$((CURRENT + 1))
    echo -n "[$CURRENT/$TOTAL] Alert $alert_id: "
    python python_ml/v2/v2/score_single_alert_v2.py \
        --alert-id "$alert_id" \
        --model-in "$MODEL_PATH"
done

echo ""
echo "=================================================="
echo "Done! $TOTAL Pipeline K alerts rescored for $DATE."
echo "End time: $(date)"
echo "=================================================="
