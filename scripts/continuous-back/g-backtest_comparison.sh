#!/bin/bash

export TZ="America/New_York"
# Pipeline G Backtest - Continuous Loop (Single Thread)
# v35.0 Quote-Triggered Quality-First: partial 5m candles from 1m data
# Tests the production algorithm configured in .env (TRADE_ALERT_G_VERSION)
# Runs today's date in a continuous loop, fast step=1 for near-real-time detection

SCRIPT_DIR="$( cd "$( dirname "${BASH_SOURCE[0]}" )" && pwd )"
LARAVEL_ROOT="$( cd "$SCRIPT_DIR/../.." && pwd )"
cd "$LARAVEL_ROOT" || exit 1

# Stagger startup to avoid simultaneous MySQL overload when supervisor starts
echo "[startup] Waiting 30s before first iteration…"
sleep 30

echo "Working directory: $(pwd)"
echo ""

ITERATION=0

# Determine the full date range available in one_minute_prices_full
FROM_DATE=$(php artisan tinker --execute="echo DB::table('one_minute_prices_full')->min('trading_date_est');" 2>/dev/null)
TO_DATE=$(date +%Y-%m-%d)
if [ -z "$FROM_DATE" ]; then
    FROM_DATE="2025-01-01"
fi

echo "Date range: $FROM_DATE → $TO_DATE"
echo ""

# Pre-build the rolling date array once at startup
DATES=()
CURRENT="$FROM_DATE"
while [[ "$CURRENT" < "$TO_DATE" ]] || [[ "$CURRENT" == "$TO_DATE" ]]; do
    DATES+=("$CURRENT")
    CURRENT=$(date -d "$CURRENT + 1 day" +%Y-%m-%d)
done
TOTAL_DAYS=${#DATES[@]}
echo "Total days to backfill: $TOTAL_DAYS"

while true; do
    ITERATION=$((ITERATION + 1))
    DATE_INDEX=$(( (ITERATION - 1) % TOTAL_DAYS ))
    BACKTEST_DATE="${DATES[$DATE_INDEX]}"
    TIME=$(date +%H:%M:%S)

    LOG_FILE="$LARAVEL_ROOT/storage/logs/backtest-g-${BACKTEST_DATE}.log"

    ALGO_VERSION=$(grep "^TRADE_ALERT_G_VERSION=" "$LARAVEL_ROOT/.env" | cut -d '=' -f2)
    if [ -z "$ALGO_VERSION" ]; then
        ALGO_VERSION="v35.0"
    fi

    {
        echo "============================================================"
        echo "  PIPELINE G BACKTEST  [Iteration $ITERATION, Day $((DATE_INDEX+1))/$TOTAL_DAYS]"
        echo "============================================================"
        echo ""
        echo "Date:      $BACKTEST_DATE $TIME"
        echo "Algorithm: $ALGO_VERSION (partial 5m candles from 1m data)"
        echo "Window:    09:40:00 → 15:30:00 (full market day)"
        echo "Strategy:  Quote-Triggered Quality-First — detects momentum mid-bar"
        echo "Mode:      continuous single-thread loop across all available days"
        echo ""

        echo "Processing $BACKTEST_DATE..."
        php artisan trade:pipeline-g stock \
            --backtest \
            --fulltable \
            --from="$BACKTEST_DATE" \
            --to="$BACKTEST_DATE" \
            --top=25 \
            --lookback=30 \
            --minMove=0.4 \
            --volMult=1.2 \
            --before=6 \
            --no-interaction 2>&1 | sed "s/^/[$BACKTEST_DATE] /"

        echo ""
        echo "Iteration $ITERATION complete (day $BACKTEST_DATE) — next day..."
        echo ""
    } >> "$LOG_FILE" 2>&1

    sleep 5
done
