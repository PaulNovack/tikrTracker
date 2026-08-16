#!/bin/bash

# Pipeline A Backtest
# Tests the production algorithm configured in .env (TRADE_ALERT_A_VERSION)

# Get script directory and change to Laravel root
SCRIPT_DIR="$( cd "$( dirname "${BASH_SOURCE[0]}" )" && pwd )"
LARAVEL_ROOT="$(dirname "$SCRIPT_DIR")"
cd "$LARAVEL_ROOT" || exit 1

echo "Working directory: $(pwd)"
echo ""

DATES=(
'2026-01-02'
'2026-01-05'
'2026-01-06'
'2026-01-07'
'2026-01-08'
'2026-01-09'
'2026-01-12'
'2026-01-13'
'2026-01-14'
'2026-01-15'
'2026-01-16'
'2026-01-20'
'2026-01-21'
'2026-01-22'
'2026-01-23'
'2026-01-26'
'2026-01-27'
'2026-01-28'
'2026-01-29'
'2026-01-30'
'2026-02-02'
'2026-02-03'
'2026-02-04'
'2026-02-05'
'2026-02-06'
'2026-02-09'
'2026-02-10'
'2026-02-11'
'2026-02-12'
'2026-02-13'
'2026-02-17'
'2026-02-18'
'2026-02-19'
'2026-02-20'
'2026-02-23'
'2026-02-24'
'2026-02-25'
'2026-02-26'
'2026-02-27'
'2026-03-02'
'2026-03-03'
'2026-03-04'
'2026-03-05'
'2026-03-06'
'2026-03-09'
'2026-03-10'
'2026-03-11'
'2026-03-12'
'2026-03-13'
'2026-03-16'
'2026-03-17'
'2026-03-18'
'2026-03-19'
'2026-03-20'
'2026-03-23'
'2026-03-24'
'2026-03-25'
'2026-03-26'
'2026-03-27'
'2026-03-30'
'2026-03-31'
'2026-04-01'
'2026-04-02'
'2026-04-06'
'2026-04-07'
'2026-04-08'
'2026-04-09'
'2026-04-10'
'2026-04-13'
'2026-04-14'
'2026-04-15'
'2026-04-16'
'2026-04-17'
'2026-04-20'
'2026-04-21'
'2026-04-22'
'2026-04-23'
'2026-04-24'
'2026-04-27'
'2026-04-28'
'2026-04-29'
'2026-04-30'
'2026-05-01'
'2026-05-04'
'2026-05-05'
'2026-05-06'
'2026-05-07'
'2026-05-08'
'2026-05-11'
'2026-05-12'
'2026-05-13'
'2026-05-14'
'2026-05-15'
'2026-05-18'
'2026-05-19'
'2026-05-20'
'2026-05-21'
'2026-05-22'
'2026-05-26'
'2026-05-27'
'2026-05-28'
'2026-05-29'
'2026-06-01'
'2026-06-02'
'2026-06-03'
'2026-06-04'
'2026-06-05'
'2026-06-08'
'2026-06-09'
'2026-06-10'
'2026-06-11'
'2026-06-12'
'2026-06-15'
'2026-06-16'
'2026-06-17'
'2026-06-18'
'2026-06-22'
'2026-06-23'
'2026-06-24'
'2026-06-25'
'2026-06-26'
'2026-06-29'
'2026-06-30'
'2026-07-01'
'2026-07-02'
'2026-07-06'
'2026-07-07'
'2026-07-08'
'2026-07-09'
'2026-07-10'
'2026-07-13'
'2026-07-14'
'2026-07-15'
'2026-07-16'
'2026-07-17'
'2026-07-20'
'2026-07-21'
'2026-07-22'
'2026-07-23'
'2026-07-24'
'2026-07-27'
'2026-07-28'
'2026-07-29'
'2026-07-30'
'2026-07-31'
'2026-08-03'
'2026-08-04'
)

echo "════════════════════════════════════════════════════════════"
echo "  PIPELINE A BACKTEST"
echo "════════════════════════════════════════════════════════════"
echo ""
echo "📅 Trading Days: ${#DATES[@]}"
echo "⏰ Time Range: 09:40 - 15:30 EST (built-in to --backtest mode)"
echo "🔧 Algorithm version configured in .env: TRADE_ALERT_A_VERSION"

# Parallel processing configuration
MAX_PARALLEL=${MAX_PARALLEL:-10}  # Run 4 dates in parallel (adjust based on CPU cores)

# Parse full-table flags
FULLTABLE_FLAG=${FULLTABLE_FLAG:-""}
for arg in "$@"; do
  case "$arg" in
    --fulltable|--full-table)
      FULLTABLE_FLAG="--fulltable"
      ;;
    --no-fulltable|--no-full-table)
      FULLTABLE_FLAG=""
      ;;
  esac
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
  TRADING_PIPELINE_A_USE_REDIS=false php artisan trade:pipeline-a stock \
        --backtest \
        --from="$date" \
        --to="$date" \
        --top=50 \
        --before=6 \
        --stale=12 \
        --step=10 \
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
ALGO_VERSION=$(grep "^TRADE_ALERT_A_VERSION=" "$LARAVEL_ROOT/.env" | cut -d '=' -f2)
echo "Using algorithm version from .env: $ALGO_VERSION"
echo ""

echo "════════════════════════════════════════════════════════════"
echo "  ANALYZING BACKTEST RESULTS (Pipeline A)"
echo "════════════════════════════════════════════════════════════"
echo ""

php artisan analyze:trade-alerts-atr-immediate \
    --algo-version="$ALGO_VERSION" \
    --pipeline=A --write-results --show-details

echo ""
echo "════════════════════════════════════════════════════════════"
echo "  BACKTEST COMPLETE"
echo "════════════════════════════════════════════════════════════"
echo ""

