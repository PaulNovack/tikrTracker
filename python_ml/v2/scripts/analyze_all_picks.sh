#!/usr/bin/env bash
set -euo pipefail

# analyze_all_picks.sh — Run analyze:trade-alerts-atr-immediate for all pipelines
# Location: python_ml/v2/scripts/
#
# Reads TRADE_ALERT_{P}_VERSION from .env for every pipeline, then runs the
# artisan analysis command for each. Skips pipelines without a version entry.

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
      echo "Usage: bash python_ml/v2/scripts/analyze_all_picks.sh [--from YYYY-MM-DD] [--to YYYY-MM-DD]"
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

ATR_SETTINGS_RAW="$(php artisan tinker --execute="echo implode('|', [\App\Services\TradingSettingService::getStopLossAtrMultiplier(), \App\Services\TradingSettingService::getStopLossAtrMinPct(), \App\Services\TradingSettingService::getStopLossAtrMaxPct()]);" --no-interaction 2>/dev/null | tail -n1)" || {
  echo "WARNING: Unable to read live DB ATR settings; proceeding with pipeline analysis only."
  ATR_SETTINGS_RAW=""
}

if [[ -n "$ATR_SETTINGS_RAW" ]]; then
  IFS='|' read -r ATR_MULTIPLIER ATR_MIN_PCT ATR_MAX_PCT <<< "$ATR_SETTINGS_RAW"
  echo "Live DB ATR settings: multiplier=${ATR_MULTIPLIER}x, min=${ATR_MIN_PCT}%, max=${ATR_MAX_PCT}%"
  echo ""
fi

# All pipelines that have a TRADE_ALERT_*_VERSION in .env
PIPELINES=(A B C D E F G H I J K L M N O P Q R EXTERNAL)

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
    --algo-version="$ALGO_VERSION" \
    --pipeline="$PIPE" \
    ${REQUESTED_FROM:+--from="$REQUESTED_FROM"} \
    ${REQUESTED_TO:+--to="$REQUESTED_TO"} \
    --win-threshold=1.5 \
    --write-results \
    --show-details \
    --use-full-tables \
    --only-unanalyzed; then
    echo "=== Pipeline $PIPE completed successfully ==="
  else
    echo "=== Pipeline $PIPE FAILED (exit code $?) ==="
    FAILED_PIPES+=("$PIPE")
  fi

  echo ""
done

echo "============================================================"
if [[ ${#FAILED_PIPES[@]} -eq 0 ]]; then
  echo "All pipeline analyses completed successfully."
else
  echo "FAILED pipelines: ${FAILED_PIPES[*]}"
  exit 1
fi
