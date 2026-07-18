#!/bin/bash

export TZ="America/New_York"

# Pipeline K Backtest - Continuous Loop (Single Thread)
# Tests the production algorithm configured in .env (TRADE_ALERT_K_VERSION)
# Runs today's date in a continuous loop, one iteration at a time.

SCRIPT_DIR="$( cd "$( dirname "${BASH_SOURCE[0]}" )" && pwd )"
LARAVEL_ROOT="$( cd "$SCRIPT_DIR/../.." && pwd )"
cd "$LARAVEL_ROOT" || exit 1

# Stagger startup to avoid simultaneous MySQL overload when supervisor starts
echo "[startup] Waiting 90s before first iteration…"
sleep 90

echo "Working directory: $(pwd)"
echo ""

ITERATION=0

while true; do
    ITERATION=$((ITERATION + 1))
    TODAY=$(date +%Y-%m-%d)
    # Recompute log file each iteration so it rotates at midnight EST
    LOG_FILE="$LARAVEL_ROOT/storage/logs/backtest-k-${TODAY}.log"
    exec >> "$LOG_FILE" 2>&1
    TIME=$(date +%H:%M:%S)
    TimeFrom=$(date -d "-15 minutes" +"%H:%M:%S")
    TimeTo=$(date -d "+12 minutes" +"%H:%M:%S")
    # TODAY="2026-04-21" # For testing purposes, override today's date

    # Extract version from .env each iteration so changes are picked up
    ALGO_VERSION=$(grep "^TRADE_ALERT_K_VERSION=" "$LARAVEL_ROOT/.env" | cut -d '=' -f2)

    echo "============================================================"
    echo "  PIPELINE K BACKTEST  [Iteration $ITERATION]"
    echo "============================================================"
    echo ""
    echo "Date:      $TODAY $TIME"
    echo "TimeFrom:  $TimeFrom"
    echo "TimeTo:    $TimeTo"
    echo "Algorithm: $ALGO_VERSION"
    echo "Mode:      continuous single-thread loop"
    echo ""

    echo "Processing $TODAY..."
    php artisan trade:pipeline-k stock \
        --backtest \
        --from="$TODAY" \
        --to="$TODAY" \
        --top=50 \
        --stale=8 \
        --fill=close \
        --step=2 \
        --timeFrom="$TimeFrom" \
        --timeTo="$TimeTo" \
        --no-interaction 2>&1 | sed "s/^/[$TODAY] /"

    echo ""
    echo "Iteration $ITERATION complete — restarting loop..."
    echo ""
    sleep 30
done
