#!/usr/bin/env bash
set -euo pipefail

# Directory where THIS script lives
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

# .env is one directory above scripts/
ENV_FILE="$SCRIPT_DIR/../.env"

if [[ ! -f "$ENV_FILE" ]]; then
  echo "ERROR: .env not found at $ENV_FILE"
  exit 1
fi

for PIPE in J; do
  KEY="TRADE_ALERT_${PIPE}_VERSION"

  ALGO_VERSION="$(grep -E "^${KEY}=" "$ENV_FILE" \
    | head -n1 \
    | cut -d '=' -f2- \
    | tr -d '"' \
    | tr -d "'")"

  if [[ -z "${ALGO_VERSION:-}" ]]; then
    echo "ERROR: $KEY not found or empty in $ENV_FILE"
    exit 1
  fi

  echo "=== Pipeline $PIPE | $KEY=$ALGO_VERSION ==="

  php artisan analyze:trade-alerts-atr-immediate \
    --algo-version="$ALGO_VERSION" \
    --pipeline="$PIPE" \
    --write-results \
    --show-details

  echo ""
done
echo "All pipeline analyses completed."