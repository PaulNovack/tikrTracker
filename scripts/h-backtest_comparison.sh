#!/bin/bash

# Pipeline H Backtest
# Tests the production algorithm configured in .env (TRADE_ALERT_H_VERSION)

# Get script directory and change to Laravel root
SCRIPT_DIR="$( cd "$( dirname "${BASH_SOURCE[0]}" )" && pwd )"
LARAVEL_ROOT="$(dirname "$SCRIPT_DIR")"
cd "$LARAVEL_ROOT" || exit 1

echo "Working directory: $(pwd)"
echo ""

DATES=(
'2024-07-01'
'2024-07-02'
'2024-07-03'
'2024-07-05'
'2024-07-08'
'2024-07-09'
'2024-07-10'
'2024-07-11'
'2024-07-12'
'2024-07-15'
'2024-07-16'
'2024-07-17'
'2024-07-18'
'2024-07-19'
'2024-07-22'
'2024-07-23'
'2024-07-24'
'2024-07-25'
'2024-07-26'
'2024-07-29'
'2024-07-30'
'2024-07-31'
'2024-08-01'
'2024-08-02'
'2024-08-05'
'2024-08-06'
'2024-08-07'
'2024-08-08'
'2024-08-09'
'2024-08-12'
'2024-08-13'
'2024-08-14'
'2024-08-15'
'2024-08-16'
'2024-08-19'
'2024-08-20'
'2024-08-21'
'2024-08-22'
'2024-08-23'
'2024-08-26'
'2024-08-27'
'2024-08-28'
'2024-08-29'
'2024-08-30'
'2024-09-03'
'2024-09-04'
'2024-09-05'
'2024-09-06'
'2024-09-09'
'2024-09-10'
'2024-09-11'
'2024-09-12'
'2024-09-13'
'2024-09-16'
'2024-09-17'
'2024-09-18'
'2024-09-19'
'2024-09-20'
'2024-09-23'
'2024-09-24'
'2024-09-25'
'2024-09-26'
'2024-09-27'
'2024-09-30'
'2024-10-01'
'2024-10-02'
'2024-10-03'
'2024-10-04'
'2024-10-07'
'2024-10-08'
'2024-10-09'
'2024-10-10'
'2024-10-11'
'2024-10-14'
'2024-10-15'
'2024-10-16'
'2024-10-17'
'2024-10-18'
'2024-10-21'
'2024-10-22'
'2024-10-23'
'2024-10-24'
'2024-10-25'
'2024-10-28'
'2024-10-29'
'2024-10-30'
'2024-10-31'
'2024-11-01'
'2024-11-04'
'2024-11-05'
'2024-11-06'
'2024-11-07'
'2024-11-08'
'2024-11-11'
'2024-11-12'
'2024-11-13'
'2024-11-14'
'2024-11-15'
'2024-11-18'
'2024-11-19'
'2024-11-20'
'2024-11-21'
'2024-11-22'
'2024-11-25'
'2024-11-26'
'2024-11-27'
'2024-11-29'
'2024-12-02'
'2024-12-03'
'2024-12-04'
'2024-12-05'
'2024-12-06'
'2024-12-09'
'2024-12-10'
'2024-12-11'
'2024-12-12'
'2024-12-13'
'2024-12-16'
'2024-12-17'
'2024-12-18'
'2024-12-19'
'2024-12-20'
'2024-12-23'
'2024-12-24'
'2024-12-26'
'2024-12-27'
'2024-12-30'
'2024-12-31'
'2025-01-02'

)


echo "════════════════════════════════════════════════════════════"
echo "  PIPELINE H BACKTEST"
echo "════════════════════════════════════════════════════════════"
echo ""
echo "📅 Trading Days: ${#DATES[@]}"
echo "⏰ Time Range: 09:35 - 15:55 EST (entry finder allows 9:35-11:15 and 14:00-15:55)"
echo "🔧 Algorithm version configured in .env: TRADE_ALERT_H_VERSION"

# Parallel processing configuration
MAX_PARALLEL=${MAX_PARALLEL:-12}  # Run 4 dates in parallel (adjust based on CPU cores)

# Parse --fulltable flag
FULLTABLE_FLAG=${FULLTABLE_FLAG:-""}
for arg in "$@"; do
  if [[ "$arg" == "--fulltable" ]]; then
    FULLTABLE_FLAG="--fulltable"
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
    
    # Mirrors production scheduler exactly:
    #   trade:pipeline-h stock --top=30 --lookback=60 --stale=8
    # V25.2: Gap-down reversal / VWAP reclaim / EMA9 pullback scanner
    # timeFrom=09:35 covers the earliest allowed entry window (entry finder gate: 9:35-11:15 + 14:00-15:55)
    # step=5 approximates the production everyMinute() schedule without excessive runtime
    php artisan trade:pipeline-h stock \
        --backtest \
        --from="$date" \
        --to="$date" \
        --top=50 \
        --lookback=60 \
        --stale=8 \
        --step=5 \
        --timeFrom=09:35:00 \
        --timeTo=15:55:00 \
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
ALGO_VERSION=$(grep "^TRADE_ALERT_H_VERSION=" "$LARAVEL_ROOT/.env" | cut -d '=' -f2)
echo "Using algorithm version from .env: $ALGO_VERSION"
echo ""

echo "════════════════════════════════════════════════════════════"
echo "  ANALYZING BACKTEST RESULTS (Pipeline H)"
echo "════════════════════════════════════════════════════════════"
echo ""

php artisan analyze:trade-alerts-atr-immediate \
    --algo-version="$ALGO_VERSION" \
    --pipeline=H --write-results --show-details

echo ""
echo "════════════════════════════════════════════════════════════"
echo "  BACKTEST COMPLETE"
echo "════════════════════════════════════════════════════════════"
echo ""
