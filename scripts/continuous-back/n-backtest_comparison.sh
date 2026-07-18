#!/bin/bash

export TZ="America/New_York"
# Pipeline N Backtest - Market Movers Momentum Strategy - Continuous Loop (Single Thread)
# Tests the production algorithm configured in .env (TRADE_ALERT_N_VERSION)
# Runs today's date in a continuous loop, one iteration at a time.

SCRIPT_DIR="$( cd "$( dirname "${BASH_SOURCE[0]}" )" && pwd )"
LARAVEL_ROOT="$( cd "$SCRIPT_DIR/../.." && pwd )"
cd "$LARAVEL_ROOT" || exit 1

# Stagger startup to avoid simultaneous MySQL overload when supervisor starts
echo "[startup] Waiting 105s before first iteration…"
sleep 105

echo "Working directory: $(pwd)"
echo ""

ITERATION=0

while true; do
    ITERATION=$((ITERATION + 1))
    TODAY=$(date +%Y-%m-%d)
    # Recompute log file each iteration so it rotates at midnight EST
    LOG_FILE="$LARAVEL_ROOT/storage/logs/backtest-n-${TODAY}.log"
    exec >> "$LOG_FILE" 2>&1
    TimeFrom=$(date -d "-15 minutes" +"%H:%M:%S")
    TimeTo=$(date -d "+12 minutes" +"%H:%M:%S")
    # TODAY="2026-04-23" # For testing purposes, override today's date

    # Extract version from .env each iteration so changes are picked up
    ALGO_VERSION=$(grep "^TRADE_ALERT_N_VERSION=" "$LARAVEL_ROOT/.env" | cut -d '=' -f2)
    if [ -z "$ALGO_VERSION" ]; then
        ALGO_VERSION="v1200.0"
    fi

    echo "============================================================"
    echo "  PIPELINE N BACKTEST - MARKET MOVERS MOMENTUM  [Iteration $ITERATION]"
    echo "============================================================"
    echo ""
    echo "Date:      $TODAY"
    echo "Algorithm: $ALGO_VERSION"
    echo "Strategy:  Two-bar momentum on 4%+ intraday movers"
    echo "Mode:      continuous single-thread loop"
    echo ""

    echo "Checking market_movers coverage for $TODAY..."
    php artisan market-movers:verify \
        --from="$TODAY" \
        --to="$TODAY" \
        --auto-populate \
        --no-interaction

    echo ""
    echo "Processing $TODAY..."
    php artisan trade:pipeline-n stock \
        --backtest \
        --from="$TODAY" \
        --to="$TODAY" \
        --stale=8 \
        --step=2 \
        --timeFrom="$TimeFrom" \
        --timeTo="$TimeTo" \
        --no-interaction 2>&1 | sed "s/^/[$TODAY] /"

    echo ""
    echo "Iteration $ITERATION complete — restarting loop..."
    echo ""
    sleep 30
done
