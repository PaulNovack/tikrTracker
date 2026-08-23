#!/usr/bin/env bash
set -euo pipefail

# analyze_all_backtest_failures.sh — Run analyze:trade-alerts-atr-immediate for failed backtest candidates
# Location: python_ml/v2/scripts/
#
# Reads TRADE_ALERT_{P}_VERSION from .env for every pipeline, then runs the
# artisan analysis command against trade_alerts_backtest_candidates with
# --failed-only so only rejected backtest rows are analyzed using the live ATR
# stop-loss settings and trailing stop rules.

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

# .env is three directories above this script (python_ml/v2/scripts/ → repo root)
ENV_FILE="$SCRIPT_DIR/../../../.env"

if [[ ! -f "$ENV_FILE" ]]; then
  echo "ERROR: .env not found at $ENV_FILE"
  exit 1
fi

PIPELINES=(A B C D E F G H I J K L M N P Q R EXTERNAL)
FAILED_PIPES=()

for PIPE in "${PIPELINES[@]}"; do
  KEY="TRADE_ALERT_${PIPE}_VERSION"

  ALGO_VERSION="$(grep -E "^${KEY}=" "$ENV_FILE" \
    | head -n1 \
    | cut -d '=' -f2- \
    | tr -d '"' \
    | tr -d "'")"

  if [[ -z "${ALGO_VERSION:-}" ]]; then
    echo "WARNING: $KEY not found or empty in $ENV_FILE — skipping pipeline $PIPE"
    continue
  fi

  echo "============================================================"
  echo "=== Pipeline $PIPE  |  $KEY = $ALGO_VERSION ==="
  echo "============================================================"

  if php artisan analyze:trade-alerts-atr-immediate \
    --table=trade_alerts_backtest_candidates \
    --algo-version="$ALGO_VERSION" \
    --failed-only \
    --show-details \
    --use-full-tables; then
    echo "=== Pipeline $PIPE completed successfully ==="
  else
    echo "=== Pipeline $PIPE FAILED (exit code $?) ==="
    FAILED_PIPES+=("$PIPE")
  fi

  echo ""
done

echo "============================================================"
if [[ ${#FAILED_PIPES[@]} -eq 0 ]]; then
  echo "All backtest failure analyses completed successfully."
else
  echo "FAILED pipelines: ${FAILED_PIPES[*]}"
  exit 1
fi
