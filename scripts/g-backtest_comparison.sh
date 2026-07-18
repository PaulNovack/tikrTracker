#!/bin/bash

# Pipeline G Backtest
# Tests the production algorithm configured in .env (TRADE_ALERT_G_VERSION)

# Get script directory and change to Laravel root
SCRIPT_DIR="$( cd "$( dirname "${BASH_SOURCE[0]}" )" && pwd )"
LARAVEL_ROOT="$(dirname "$SCRIPT_DIR")"
cd "$LARAVEL_ROOT" || exit 1

echo "Working directory: $(pwd)"
echo ""

DATES=($(mysql -u laravel -plaravel -h 127.0.0.1 laravelInvest -sN -e "SELECT DISTINCT trading_date_est FROM one_minute_prices_full where trading_date_est > '2024-09-05' ORDER BY trading_date_est" 2>/dev/null))
if [ ${#DATES[@]} -eq 0 ]; then
    echo "ERROR: No dates found in one_minute_prices_full"
    exit 1
fi


echo "════════════════════════════════════════════════════════════"
echo "  PIPELINE G BACKTEST"
echo "════════════════════════════════════════════════════════════"
echo ""
echo "📅 Trading Days: ${#DATES[@]}"
echo "⏰ Time Range: 09:40 - 15:30 EST (built-in to --backtest mode)"
echo "🔧 Algorithm version configured in .env: TRADE_ALERT_G_VERSION"
echo ""
echo "This runs the Pipeline G algorithm configured in your .env file."
echo "  1. Generate alerts using --backtest mode"
echo ""

# Parallel processing configuration
MAX_PARALLEL=${MAX_PARALLEL:-24}  # Run 4 dates in parallel (adjust based on CPU cores)

# Default to --fulltable for backtest runs (uses one_minute_prices_full)
FULLTABLE_FLAG="--fulltable"
echo "⚡ Parallel Jobs: $MAX_PARALLEL"
echo "📋 Full Tables: on"


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
    php artisan trade:pipeline-g stock \
        --backtest \
        --fulltable \
        --from="$date" \
        --to="$date" \
        --top=25 \
        --lookback=15 \
        --stale=12 \
        --before=6 \
        --step=30 \
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
ALGO_VERSION=$(grep "^TRADE_ALERT_G_VERSION=" "$LARAVEL_ROOT/.env" | cut -d '=' -f2)
echo "Using algorithm version from .env: $ALGO_VERSION"
echo ""

echo "════════════════════════════════════════════════════════════"
echo "  ANALYZING BACKTEST RESULTS (Pipeline G)"
echo "════════════════════════════════════════════════════════════"
echo ""

php artisan analyze:trade-alerts-atr-immediate \
    --algo-version="$ALGO_VERSION" \
    --use-full-tables \
    --pipeline=G --write-results --show-details

echo ""
echo "════════════════════════════════════════════════════════════"
echo "  BACKTEST COMPLETE"
echo "════════════════════════════════════════════════════════════"
echo ""
