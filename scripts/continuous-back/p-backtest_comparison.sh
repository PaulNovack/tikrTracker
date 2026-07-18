#!/bin/bash

export TZ="America/New_York"

# Pipeline P Backtest - Continuous Loop (Single Thread)
# Tests the production algorithm configured in .env (TRADE_ALERT_P_VERSION)
# Uses v140.0 Institutional Follow-Through Strategy
# Runs today's date in a continuous loop, one iteration at a time.

SCRIPT_DIR="$( cd "$( dirname "${BASH_SOURCE[0]}" )" && pwd )"
LARAVEL_ROOT="$( cd "$SCRIPT_DIR/../.." && pwd )"
cd "$LARAVEL_ROOT" || exit 1

# Stagger startup to avoid simultaneous MySQL overload when supervisor starts
echo "[startup] Waiting 120s before first iteration…"
sleep 120

echo "Working directory: $(pwd)"
echo ""

ITERATION=0

while true; do
    ITERATION=$((ITERATION + 1))
    TODAY=$(date +%Y-%m-%d)
    LOG_FILE="$LARAVEL_ROOT/storage/logs/backtest-p-${TODAY}.log"
    exec >> "$LOG_FILE" 2>&1
    TIME=$(date +%H:%M:%S)
    TimeFrom=$(date -d "-15 minutes" +"%H:%M:%S")
    TimeTo=$(date -d "+12 minutes" +"%H:%M:%S")

    ALGO_VERSION=$(grep "^TRADE_ALERT_P_VERSION=" "$LARAVEL_ROOT/.env" | cut -d '=' -f2)
    if [ -z "$ALGO_VERSION" ]; then
        ALGO_VERSION="v140.0"
    fi

    echo "============================================================"
    echo "  PIPELINE P BACKTEST - INSTITUTIONAL FOLLOW-THROUGH  [Iteration $ITERATION]"
    echo "============================================================"
    echo ""
    echo "Date:      $TODAY $TIME"
    echo "TimeFrom:  $TimeFrom"
    echo "TimeTo:    $TimeTo"
    echo "Algorithm: $ALGO_VERSION"
    echo "Strategy:  Multi-day consistency + institutional liquidity"
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
    php artisan trade:pipeline-p stock \
        --backtest \
        --from="$TODAY" \
        --to="$TODAY" \
        --top=200 \
        --lookback=90 \
        --minMove=1.5 \
        --volMult=1.5 \
        --stale=10 \
        --step=2 \
        --timeFrom="$TimeFrom" \
        --timeTo="$TimeTo" \
        --no-interaction 2>&1 | sed "s/^/[$TODAY] /"

    echo ""
    echo "Iteration $ITERATION complete — restarting loop..."
    echo ""
    sleep 30
done