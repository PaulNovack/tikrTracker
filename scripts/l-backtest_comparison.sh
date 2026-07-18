#!/bin/bash

# Pipeline L Backtest
# Tests the production algorithm configured in .env (TRADE_ALERT_L_VERSION)

# Get script directory and change to Laravel root
SCRIPT_DIR="$( cd "$( dirname "${BASH_SOURCE[0]}" )" && pwd )"
LARAVEL_ROOT="$(dirname "$SCRIPT_DIR")"
cd "$LARAVEL_ROOT" || exit 1

echo "Working directory: $(pwd)"
echo ""

# Default range: Jan 1, 2026 through May 3, 2026 (override with START_DATE / END_DATE)
START_DATE=${START_DATE:-2026-01-01}
END_DATE=${END_DATE:-2026-05-03}

# Default to v1600.0 unless the caller explicitly overrides it
export TRADE_ALERT_L_VERSION=${TRADE_ALERT_L_VERSION:-v1600.0}

start_epoch=$(date -d "$START_DATE" +%s)
end_epoch=$(date -d "$END_DATE" +%s)

DATES=()
for ((ts=start_epoch; ts<=end_epoch; ts+=86400)); do
  date=$(date -d "@$ts" +%F)
  weekday=$(date -d "$date" +%u)
  if [[ "$weekday" -le 5 ]]; then
    DATES+=("$date")
  fi
done


echo "════════════════════════════════════════════════════════════"
echo "  PIPELINE L BACKTEST"
echo "════════════════════════════════════════════════════════════"
echo ""
echo "📅 Trading Days: ${#DATES[@]}"
echo "⏰ Time Range: 09:40 - 15:30 EST (built-in to --backtest mode)"
echo "🗓️ Date Range: $START_DATE -> $END_DATE"
echo "🔧 Algorithm version: $TRADE_ALERT_L_VERSION"

# Parallel processing configuration
MAX_PARALLEL=${MAX_PARALLEL:-8}  # Tune based on CPU/DB capacity for 1-year runs

# Parse full table flags (default ON for this year-back script)
FULLTABLE_FLAG=${FULLTABLE_FLAG:---fulltable}
for arg in "$@"; do
  if [[ "$arg" == "--fulltable" ]]; then
    FULLTABLE_FLAG="--fulltable"
  fi
  if [[ "$arg" == "--no-fulltable" ]]; then
    FULLTABLE_FLAG=""
  fi
done
export FULLTABLE_FLAG
echo "⚡ Parallel Jobs: $MAX_PARALLEL"
echo "📋 Full Tables: ${FULLTABLE_FLAG:-off}"


echo ""
echo "════════════════════════════════════════════════════════════"
echo "  GENERATING PRODUCTION ALERTS (PARALLEL)"
echo "════════════════════════════════════════════════════════════"
echo ""

# Function to process a single date
process_date() {
    local date=$1
    echo "📊 Processing $date..."

    # Use the actual production pipeline with --backtest mode
    php artisan trade:pipeline-l stock \
        --backtest \
        --from="$date" \
        --to="$date" \
        --top=50 \
        --lookback=120 \
        --minMove=0.4 \
        --volMult=1.2 \
        --before=6 \
        --stale=6 \
        --step=5 \
        --timeFrom=09:40:00 \
        --timeTo=15:30:00 \
        ${FULLTABLE_FLAG:+"$FULLTABLE_FLAG"} \
        --no-interaction 2>&1 | sed "s/^/[$date] /"

    echo "  ✅ Completed $date"
}

export -f process_date

# Process dates in parallel using xargs
printf "%s\n" "${DATES[@]}" | xargs -P $MAX_PARALLEL -I {} bash -c 'process_date "$@"' _ {}

echo ""
echo "✅ Production alert generation complete!"
echo ""

# Extract version from .env
ALGO_VERSION=$(grep "^TRADE_ALERT_L_VERSION=" "$LARAVEL_ROOT/.env" | cut -d '=' -f2)
echo "Using algorithm version from .env: $ALGO_VERSION"
echo ""

echo "════════════════════════════════════════════════════════════"
echo "  ANALYZING BACKTEST RESULTS (Pipeline L)"
echo "════════════════════════════════════════════════════════════"
echo ""

php artisan analyze:trade-alerts-atr-immediate \
    --algo-version="$ALGO_VERSION" \
    --pipeline=L --write-results --show-details

echo ""
echo "════════════════════════════════════════════════════════════"
echo "  BACKTEST COMPLETE"
echo "════════════════════════════════════════════════════════════"
echo ""
