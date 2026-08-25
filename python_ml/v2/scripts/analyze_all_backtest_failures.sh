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

REQUESTED_FROM=""
REQUESTED_TO=""

while [[ $# -gt 0 ]]; do
  case "$1" in
    -f|--from)
      if [[ $# -lt 2 ]]; then
        echo "ERROR: --from requires a YYYY-MM-DD date"
        exit 1
      fi
      REQUESTED_FROM="$2"
      shift 2
      ;;
    --from=*)
      REQUESTED_FROM="${1#*=}"
      shift
      ;;
    -t|--to)
      if [[ $# -lt 2 ]]; then
        echo "ERROR: --to requires a YYYY-MM-DD date"
        exit 1
      fi
      REQUESTED_TO="$2"
      shift 2
      ;;
    --to=*)
      REQUESTED_TO="${1#*=}"
      shift
      ;;
    -h|--help)
      echo "Usage: bash python_ml/v2/scripts/analyze_all_backtest_failures.sh [--from YYYY-MM-DD] [--to YYYY-MM-DD]"
      exit 0
      ;;
    *)
      echo "ERROR: Unknown argument: $1"
      exit 1
      ;;
  esac
done

if [[ -n "$REQUESTED_FROM" && -z "$REQUESTED_TO" ]]; then
  echo "ERROR: --from requires --to"
  exit 1
fi

if [[ -n "$REQUESTED_TO" && -z "$REQUESTED_FROM" ]]; then
  echo "ERROR: --to requires --from"
  exit 1
fi

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
    ${REQUESTED_FROM:+--from="$REQUESTED_FROM"} \
    ${REQUESTED_TO:+--to="$REQUESTED_TO"} \
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
