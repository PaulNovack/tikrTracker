#!/bin/bash

# Pipeline P Backtest — Institutional Follow-Through (v140.0)
# Strategy: Multi-day green consistency + $5+ price + sustained volume + 3 entry types
# Entry Types: VWAP_HOLD_CONTINUATION, HIGHER_LOW_BREAK, INSTITUTIONAL_ACCUMULATION
# Runs dates in parallel.

# Get script directory and change to Laravel root
SCRIPT_DIR="$( cd "$( dirname "${BASH_SOURCE[0]}" )" && pwd )"
LARAVEL_ROOT="$(dirname "$SCRIPT_DIR")"
cd "$LARAVEL_ROOT" || exit 1

echo "Working directory: $(pwd)"
echo ""

# Generate all trading dates from earliest available data to today
echo "📡 Detecting earliest available data..."
EARLIEST=$(php artisan tinker --execute="echo DB::select('SELECT MIN(trading_date_est) as d FROM five_minute_prices_full')[0]->d ?? '2024-07-01';" --no-interaction 2>/dev/null | tail -1)
echo "  Earliest data: $EARLIEST"

DATES=$(python3 -c "
from datetime import date, timedelta
d = date.fromisoformat('${EARLIEST}')
end = date.today()
result = []
while d <= end:
    if d.weekday() < 5:  # Mon-Fri only
        result.append(d.strftime('%Y-%m-%d'))
    d += timedelta(days=1)
for dt in reversed(result):  # newest first
    print(dt)
")

echo "────────────────────────────────────────────────────────────"
echo "  PIPELINE P BACKTEST — Institutional Follow-Through (v140.0)"
echo "────────────────────────────────────────────────────────────"
echo ""
echo "📅 Trading Days: $(echo "$DATES" | wc -l)"
echo "⏰ Time Range: 09:40 - 15:30 EST (built-in to --backtest mode)"
echo "🔧 Version: v140.0"
echo "🎯 Strategy: Multi-day consistency + institutional liquidity + VWAP/structure entries"
echo ""

# Parallel processing configuration
MAX_PARALLEL=${MAX_PARALLEL:-16}

echo "⚡ Parallel Jobs: $MAX_PARALLEL"
echo "📋 Using five_minute_prices_full / one_minute_prices_full (always --fulltable)"

echo ""
echo "════════════════════════════════════════════════════════════"
echo "  GENERATING ALERTS (PARALLEL)"
echo "════════════════════════════════════════════════════════════"
echo ""

# Function to process a single date
process_date() {
    local date=$1
    echo "📊 Processing $date..."

    php artisan trade:pipeline-p stock \
        --backtest \
        --from="$date" \
        --to="$date" \
        --top=200 \
        --lookback=90 \
        --minMove=1.5 \
        --volMult=1.5 \
        --fulltable \
        --no-interaction 2>&1 | sed "s/^/[$date] /"

    echo "  ✅ Completed $date"
}

export -f process_date

# Process dates in parallel using xargs
printf "%s\n" $DATES | xargs -P $MAX_PARALLEL -I {} bash -c 'process_date "$@"' _ {}

echo ""
echo "✅ Alert generation complete!"
echo ""

echo "════════════════════════════════════════════════════════════"
echo "  ANALYZING BACKTEST RESULTS (Pipeline P)"
echo "════════════════════════════════════════════════════════════"
echo ""

php artisan analyze:trade-alerts-atr-immediate \
    --algo-version="v140.0" \
    --pipeline=P --write-results --show-details

echo ""
echo "════════════════════════════════════════════════════════════"
echo "  PIPELINE P BACKTEST COMPLETE"
echo "════════════════════════════════════════════════════════════"
echo ""
echo "View results at: http://127.0.0.1:8080/backtest-results?pipeline=P"
echo ""
