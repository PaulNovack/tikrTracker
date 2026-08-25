#!/usr/bin/env bash
set -euo pipefail

# analyze_all_picks.sh — Run analyze:trade-alerts-atr-immediate for all pipelines
# Location: python_ml/v2/scripts/
#
# Reads TRADE_ALERT_{P}_VERSION from .env for every pipeline, then runs the
# artisan analysis command for each. Skips pipelines without a version entry.
#
# Usage examples:
#   bash python_ml/v2/scripts/analyze_all_picks.sh
#   bash python_ml/v2/scripts/analyze_all_picks.sh 2026-06-01 2026-06-16
#   bash python_ml/v2/scripts/analyze_all_picks.sh 2026-06-16
#   bash python_ml/v2/scripts/analyze_all_picks.sh 2026-06-01 2026-06-16 "A,B,K,N"
#   bash python_ml/v2/scripts/analyze_all_picks.sh --pipeline N
#   bash python_ml/v2/scripts/analyze_all_picks.sh --pipeline N --from 2026-06-01 --to 2026-06-16
#   bash python_ml/v2/scripts/analyze_all_picks.sh --pipeline N --from=2026-06-01 --to=2026-06-16
#   bash python_ml/v2/scripts/analyze_all_picks.sh --only-unanalyzed
#   SKIP_CLEAR=1 bash python_ml/v2/scripts/analyze_all_picks.sh
#   SKIP_CLEAR=1 bash python_ml/v2/scripts/analyze_all_picks.sh --pipeline N

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

REQUESTED_FROM=""
REQUESTED_TO=""
REQUESTED_PIPELINE=""
REQUESTED_ONLY_UNANALYZED=""
POSITIONAL_PIPELINES=""
POSITIONAL_DATE_COUNT=0

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
    -p|--pipeline)
      if [[ $# -lt 2 ]]; then
        echo "ERROR: --pipeline requires a pipeline letter, for example N"
        exit 1
      fi
      REQUESTED_PIPELINE="${2# }"
      shift 2
      ;;
    --pipeline=*)
      REQUESTED_PIPELINE="${1#*=}"
      shift
      ;;
    --only-unanalyzed)
      REQUESTED_ONLY_UNANALYZED=1
      shift
      ;;
    -h|--help)
      echo "Usage: bash python_ml/v2/scripts/analyze_all_picks.sh [--from YYYY-MM-DD] [--to YYYY-MM-DD] [--pipeline N] [--only-unanalyzed]"
      echo "   or: bash python_ml/v2/scripts/analyze_all_picks.sh [--from YYYY-MM-DD] [--to YYYY-MM-DD] [A,B,K,N] [--only-unanalyzed]"
      exit 0
      ;;
    *)
      if [[ -z "$POSITIONAL_PIPELINES" && "$1" =~ ^[0-9]{4}-[0-9]{2}-[0-9]{2}$ ]]; then
        if [[ -z "$REQUESTED_FROM" ]]; then
          REQUESTED_FROM="$1"
        elif [[ -z "$REQUESTED_TO" ]]; then
          REQUESTED_TO="$1"
        else
          echo "ERROR: Too many positional dates. Use at most two YYYY-MM-DD arguments."
          exit 1
        fi

        POSITIONAL_DATE_COUNT=$((POSITIONAL_DATE_COUNT + 1))
        shift
        continue
      fi

      if [[ -z "$POSITIONAL_PIPELINES" && -z "$1" ]]; then
        shift
        continue
      fi

      if [[ -z "$POSITIONAL_PIPELINES" && "$1" =~ ^[A-Za-z0-9_,]+$ ]]; then
        POSITIONAL_PIPELINES="$1"
        shift
        continue
      fi

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

if [[ $POSITIONAL_DATE_COUNT -eq 1 && -n "$REQUESTED_FROM" && -z "$REQUESTED_TO" ]]; then
  REQUESTED_TO="$REQUESTED_FROM"
fi

if [[ -n "$REQUESTED_PIPELINE" && -n "$POSITIONAL_PIPELINES" ]]; then
  echo "ERROR: Use either --pipeline or a positional pipeline list, not both."
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
if [[ -n "$REQUESTED_PIPELINE" ]]; then
  PIPELINES=("$(echo "$REQUESTED_PIPELINE" | xargs | tr '[:lower:]' '[:upper:]')")
elif [[ -n "$POSITIONAL_PIPELINES" ]]; then
  PIPELINES="$POSITIONAL_PIPELINES"
else
  PIPELINES=(A B C D E F G H I J K L M N O P Q R EXTERNAL)
fi

if [[ -n "$REQUESTED_PIPELINE" ]]; then
  PIPELINES=("$(echo "$REQUESTED_PIPELINE" | xargs | tr '[:lower:]' '[:upper:]')")
fi

# If a single positional pipeline was given, use it as-is; if a comma-separated
# list was provided, keep the existing multi-pipeline behavior.
if [[ -n "$POSITIONAL_PIPELINES" ]]; then
  PIPELINES="$POSITIONAL_PIPELINES"
fi

ONLY_UNANALYZED_FLAG=()
if [[ -n "$REQUESTED_ONLY_UNANALYZED" ]]; then
  ONLY_UNANALYZED_FLAG=(--only-unanalyzed)
fi

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
    "${ONLY_UNANALYZED_FLAG[@]}"; then
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
