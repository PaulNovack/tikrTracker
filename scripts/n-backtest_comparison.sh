#!/bin/bash

# Pipeline N Backtest - Market Movers Momentum Strategy
# Tests the production algorithm configured in .env (TRADE_ALERT_N_VERSION)

# Get script directory and change to Laravel root
SCRIPT_DIR="$( cd "$( dirname "${BASH_SOURCE[0]}" )" && pwd )"
LARAVEL_ROOT="$(dirname "$SCRIPT_DIR")"
cd "$LARAVEL_ROOT" || exit 1

echo "Working directory: $(pwd)"
echo ""

DATES=(
'2026-05-08'
'2026-05-07'
'2026-05-06'
'2026-05-05'
'2026-05-04'
'2026-05-01'
'2026-04-30'
'2026-04-29'
'2026-04-28'
'2026-04-27'
'2026-04-24'
'2026-04-23'
'2026-04-22'
'2026-04-21'
'2026-04-20'
'2026-04-17'
'2026-04-16'
'2026-04-15'
'2026-04-14'
'2026-04-13'
'2026-04-10'
'2026-04-09'
'2026-04-08'
'2026-04-07'
'2026-04-06'
'2026-04-02'
'2026-04-01'

)


echo "════════════════════════════════════════════════════════════"
echo "  PIPELINE N BACKTEST - MARKET MOVERS MOMENTUM"
echo "════════════════════════════════════════════════════════════"
echo ""
echo "📅 Trading Days: ${#DATES[@]}"
echo "⏰ Time Range: 09:40 - 15:30 EST (built-in to --backtest mode)"
echo "🔧 Algorithm version configured in .env: TRADE_ALERT_N_VERSION"
echo "📊 Strategy: Two-bar momentum on 4%+ intraday movers"
echo ""
echo "This runs the Pipeline N algorithm configured in your .env file."
echo "  1. Auto-populate market_movers data for backtest dates if missing"
echo "  2. Generate alerts using --backtest mode"
echo ""

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
echo "  VERIFYING MARKET MOVERS DATA"
echo "════════════════════════════════════════════════════════════"
echo ""

# Get first and last date from DATES array
FIRST_DATE=${DATES[0]}
LAST_DATE=${DATES[-1]}

if [ -n "$FIRST_DATE" ] && [ -n "$LAST_DATE" ]; then
    echo "📋 Checking market_movers coverage for $FIRST_DATE to $LAST_DATE..."
    echo ""
    
    # Verify and auto-populate missing dates
    php artisan market-movers:verify \
        --from="$FIRST_DATE" \
        --to="$LAST_DATE" \
        --auto-populate \
        --no-interaction
    
    echo ""
    echo "✅ Market movers data ready"
    echo ""
else
    echo "⚠️  No dates specified in DATES array - skipping verification"
    echo ""
fi

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
    php artisan trade:pipeline-n stock \
        --backtest \
        --from="$date" \
        --to="$date" \
        --before=6 \
        --stale=12 \
        --step=3 \
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
ALGO_VERSION=$(grep "^TRADE_ALERT_N_VERSION=" "$LARAVEL_ROOT/.env" | cut -d '=' -f2)
if [ -z "$ALGO_VERSION" ]; then
    ALGO_VERSION="v1200.0"  # Default fallback
fi
echo "Using algorithm version from .env: $ALGO_VERSION"
echo ""

echo "════════════════════════════════════════════════════════════"
echo "  ANALYZING BACKTEST RESULTS (Pipeline N)"
echo "════════════════════════════════════════════════════════════"
echo ""

php artisan analyze:trade-alerts-atr-immediate \
    --algo-version="$ALGO_VERSION" \
    --pipeline=N --write-results --show-details

echo ""
echo "════════════════════════════════════════════════════════════"
echo "  BACKTEST COMPLETE"
echo "════════════════════════════════════════════════════════════"
echo ""
