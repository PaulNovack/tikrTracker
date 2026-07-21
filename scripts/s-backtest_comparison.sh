#!/bin/bash

# Pipeline S Backtest — VwapRevRealtime
# Strategy: Real-time VWAP reversal detection with 15-gate candidate scanner
# Uses VWAP bounce/reclaim patterns for mean-reversion entries
# Uses the same trading:realtime-backtest infrastructure as Pipeline R

# Get script directory and change to Laravel root
SCRIPT_DIR="$( cd "$( dirname "${BASH_SOURCE[0]}" )" && pwd )"
LARAVEL_ROOT="$(dirname "$SCRIPT_DIR")"
cd "$LARAVEL_ROOT" || exit 1

echo "Working directory: $(pwd)"
echo ""

# Default date range (override with --from / --to)
FROM_DATE="${FROM_DATE:-2025-06-18}"
TO_DATE="${TO_DATE:-2026-01-01}"

# Parse --from / --to / other args
EXTRA_ARGS=()
while [[ $# -gt 0 ]]; do
    case "$1" in
        --from)
            FROM_DATE="${2:-}"
            shift 2
            ;;
        --to)
            TO_DATE="${2:-}"
            shift 2
            ;;
        *)
            EXTRA_ARGS+=("$1")
            shift
            ;;
    esac
done

echo "────────────────────────────────────────────────────────────"
echo "  PIPELINE S BACKTEST — VwapRevRealtime"
echo "────────────────────────────────────────────────────────────"
echo ""
echo "📅 Range: $FROM_DATE → $TO_DATE"
echo "🔧 Algorithm version configured in .env: TRADE_ALERT_S_VERSION"

echo ""
echo "════════════════════════════════════════════════════════════"
echo "  RUNNING REAL-TIME BACKTEST (Pipeline S)"
echo "════════════════════════════════════════════════════════════"
echo ""

# Pipeline S uses the dedicated pipeline-s command (VWAP Reversal finder)
php -d max_execution_time=0 artisan trade:pipeline-s \
    --from="$FROM_DATE" \
    --to="$TO_DATE" \
    --full-table \
    --skip-liquidity-filter \
    --interval=15 \
    "${EXTRA_ARGS[@]}"

echo ""
echo "════════════════════════════════════════════════════════════"
echo "  PIPELINE S BACKTEST COMPLETE"
echo "════════════════════════════════════════════════════════════"
echo ""
echo "View results at: http://127.0.0.1:8080/backtest-results?pipeline=S"
echo ""
echo "💡 Next Steps:"
echo "  • Check win rate, avg P&L, and profit factor"
echo "  • Train ML model on S-specific dataset once sufficient data is collected"
echo "  • Enable live scoring when ready"
echo ""
