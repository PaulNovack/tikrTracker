#!/bin/bash
# Rescore any pipeline's alerts for a given date using v2/score_single_alert_v2.py
# Usage: ./rescore_pipeline_yesterday.sh <PIPELINE> [DATE]
# Example: ./rescore_pipeline_yesterday.sh F
#          ./rescore_pipeline_yesterday.sh H 2026-05-09

source "$(dirname "$0")/model_env.sh"

set -e

cd "$(dirname "$0")/../.." || exit

PIPELINE="${1:-}"
DATE="${2:-$(date -d 'yesterday' +%Y-%m-%d)}"

if [ -z "$PIPELINE" ]; then
    echo "Usage: $0 <PIPELINE> [DATE]"
    echo "Example: $0 F"
    echo "         $0 H 2026-05-09"
    exit 1
fi

PIPELINE_UPPER=$(echo "$PIPELINE" | tr '[:lower:]' '[:upper:]')
MODEL_PATH="$(get_pipeline_model_path "$PIPELINE_UPPER" "python_ml/models/winner_model_pipeline_$(echo "$PIPELINE" | tr '[:upper:]' '[:lower:]').joblib")"

if [ ! -f "$MODEL_PATH" ]; then
    echo "ERROR: Model not found: $MODEL_PATH"
    exit 1
fi

echo "=================================================="
echo "Rescoring Pipeline $PIPELINE_UPPER Alerts for $DATE"
echo "Model: $MODEL_PATH"
echo "Start time: $(date)"
echo "=================================================="
echo ""

ALERT_IDS=$(mysql -u laravel -plaravel laravelInvest -N -B -e \
    "SELECT id FROM trade_alerts WHERE pipeline_run = '$PIPELINE_UPPER' AND trading_date_est = '$DATE' ORDER BY id")

TOTAL=$(echo "$ALERT_IDS" | grep -c '[0-9]' || true)

if [ "$TOTAL" -eq 0 ]; then
    echo "No Pipeline $PIPELINE_UPPER alerts found for $DATE"
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
echo "Done! $TOTAL Pipeline $PIPELINE_UPPER alerts rescored for $DATE."
echo "End time: $(date)"
echo "=================================================="
