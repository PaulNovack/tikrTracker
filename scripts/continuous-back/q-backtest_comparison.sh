#!/bin/bash

export TZ="America/New_York"

# Pipeline Q Backtest - Continuous Loop (Single Thread)
# Tests the production algorithm configured in .env (TRADE_ALERT_Q_VERSION)
# Uses v27.0 Volume-First Strategy - wider net, tighter entry quality
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

while true; do
    ITERATION=$((ITERATION + 1))
    TODAY=$(date +%Y-%m-%d)
    LOG_FILE="$LARAVEL_ROOT/storage/logs/backtest-q-${TODAY}.log"
    exec >> "$LOG_FILE" 2>&1
    TIME=$(date +%H:%M:%S)
    TimeFrom=$(date -d "-15 minutes" +"%H:%M:%S")
    TimeTo=$(date -d "+12 minutes" +"%H:%M:%S")

    ALGO_VERSION=$(grep "^TRADE_ALERT_Q_VERSION=" "$LARAVEL_ROOT/.env" | cut -d '=' -f2)
    if [ -z "$ALGO_VERSION" ]; then
        ALGO_VERSION="v27.0"
    fi

    echo "============================================================"
    echo "  PIPELINE Q BACKTEST - VOLUME-FIRST  [Iteration $ITERATION]"
    echo "============================================================"
    echo ""
    echo "Date:      $TODAY $TIME"
    echo "TimeFrom:  $TimeFrom"
    echo "TimeTo:    $TimeTo"
    echo "Algorithm: $ALGO_VERSION"
    echo "Strategy:  Volume-First: wider scanner net + tighter entry quality"
    echo "Entry Types: VWAP_RECLAIM_STRONG, ORB_RETEST, ORB_BREAKOUT, EMA9_PULLBACK"
    echo "Mode:      continuous single-thread loop"
    echo ""

    echo "Processing $TODAY..."
    php artisan trade:pipeline-q stock \
        --backtest \
        --from="$TODAY" \
        --to="$TODAY" \
        --top=60 \
        --lookback=60 \
        --minMove=0.6 \
        --volMult=1.5 \
        --no-interaction 2>&1 | sed "s/^/[$TODAY] /"

    echo ""
    echo "Iteration $ITERATION complete — restarting loop..."
    echo ""
    sleep 60
done
