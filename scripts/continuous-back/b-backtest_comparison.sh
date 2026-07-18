#!/bin/bash

export TZ="America/New_York"
# Pipeline B Backtest - Continuous Loop (Single Thread)
# Tests the production algorithm configured in .env (TRADE_ALERT_B_VERSION)
# Runs today's date in a continuous loop, one iteration at a time.

SCRIPT_DIR="$( cd "$( dirname "${BASH_SOURCE[0]}" )" && pwd )"
LARAVEL_ROOT="$( cd "$SCRIPT_DIR/../.." && pwd )"
cd "$LARAVEL_ROOT" || exit 1

echo "Working directory: $(pwd)"
echo ""

ITERATION=0

while true; do
    ITERATION=$((ITERATION + 1))
    TODAY=$(date +%Y-%m-%d)
    # Recompute log file each iteration so it rotates at midnight EST
    LOG_FILE="$LARAVEL_ROOT/storage/logs/backtest-b-${TODAY}.log"
    exec >> "$LOG_FILE" 2>&1
    TimeFrom=$(date -d "-11 minutes" +"%H:%M:%S")
    TimeTo=$(date -d "+2 minutes" +"%H:%M:%S")
    # Extract version from .env each iteration so changes are picked up
    ALGO_VERSION=$(grep "^TRADE_ALERT_B_VERSION=" "$LARAVEL_ROOT/.env" | cut -d '=' -f2)

    echo "============================================================"
    echo "  PIPELINE B BACKTEST  [Iteration $ITERATION]"
    echo "============================================================"
    echo ""
    echo "Date:      $TODAY"
    echo "Algorithm: $ALGO_VERSION"
    echo "Mode:      continuous single-thread loop"
    echo ""

    echo "Processing $TODAY..."
    php artisan trade:pipeline-b stock \
        --backtest \
        --from="$TODAY" \
        --to="$TODAY" \
        --top=50 \
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
