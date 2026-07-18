#!/bin/bash

export TZ="America/New_York"

# Pipeline L Backtest - Continuous Loop (Single Thread)
# Tests the production algorithm configured in .env (TRADE_ALERT_L_VERSION)
# Runs today's date in a continuous loop, one iteration at a time.

SCRIPT_DIR="$( cd "$( dirname "${BASH_SOURCE[0]}" )" && pwd )"
LARAVEL_ROOT="$( cd "$SCRIPT_DIR/../.." && pwd )"
cd "$LARAVEL_ROOT" || exit 1

# Stagger startup to avoid simultaneous MySQL overload when supervisor starts
echo "[startup] Waiting 60s before first iteration…"
sleep 60

echo "Working directory: $(pwd)"
echo ""

ITERATION=0
WINDOW_START_MINUTES=4
WINDOW_END_MINUTES=12

while true; do
    ITERATION=$((ITERATION + 1))
    TODAY=$(date +%Y-%m-%d)
    TIME=$(date +%H:%M:%S)
    TimeFrom=$(date -d "-${WINDOW_START_MINUTES} minutes" +"%H:%M:%S")
    TimeTo=$(date -d "+${WINDOW_END_MINUTES} minutes" +"%H:%M:%S")

    # Recompute log file each iteration so it rotates at midnight EST
    LOG_FILE="$LARAVEL_ROOT/storage/logs/backtest-l-${TODAY}.log"
    exec >> "$LOG_FILE" 2>&1

    # Extract version from .env each iteration so changes are picked up
    ALGO_VERSION=$(grep "^TRADE_ALERT_L_VERSION=" "$LARAVEL_ROOT/.env" | cut -d '=' -f2)

    echo "============================================================"
    echo "  PIPELINE L BACKTEST  [Iteration $ITERATION]"
    echo "============================================================"
    echo ""
    echo "Date:      $TODAY $TIME"
    echo "TimeFrom:  $TimeFrom"
    echo "TimeTo:    $TimeTo"
    echo "Algorithm: $ALGO_VERSION"
    echo "StaleMin:  (from DB via TradingSettingService)"
    echo "Mode:      continuous single-thread loop"
    echo ""

    echo "Processing $TODAY..."
    php artisan trade:pipeline-l stock \
        --backtest \
        --enforce-current-freshness \
        --from="$TODAY" \
        --to="$TODAY" \
        --top=25 \
        --lookback=120 \
        --fill=next_open \
        --step=2 \
        --timeFrom="$TimeFrom" \
        --timeTo="$TimeTo" \
        --no-interaction 2>&1 | sed "s/^/[$TODAY] /"

    echo ""
    echo "Iteration $ITERATION complete — restarting loop..."
    echo ""
    sleep 30
done
