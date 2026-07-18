#!/bin/bash
# v2/scripts/start_daemon.sh — Start the v2 ML scoring daemon.
# Usage:
#   bash python_ml/v2/scripts/start_daemon.sh

set -e

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
source "$SCRIPT_DIR/env.sh"

DAEMON_CMD=$(get_daemon_cmd)
SOCKET="${ML_DAEMON_SOCKET:-storage/ml-scoring.sock}"

# Collect available model paths for pre-warming
MODELS=""
for LETTER in A B C D E F G H I J K L M N O P Q; do
    VAR="TRADING_ML_PIPELINE_${LETTER}_MODEL_PATH"
    load_env_file
    VAL="${!VAR:-}"
    if [[ -n "$VAL" && -f "$VAL" ]]; then
        MODELS="$MODELS$VAL,"
    fi
done
MODELS="${MODELS%,}"

echo "=================================================="
echo "  Starting v2 ML Scoring Daemon"
echo "  Socket: $SOCKET"
echo "  Command: $DAEMON_CMD"
echo "  Models: ${MODELS:0:120}..."
echo "=================================================="

exec $DAEMON_CMD \
    --socket "$SOCKET" \
    --models "$MODELS"
