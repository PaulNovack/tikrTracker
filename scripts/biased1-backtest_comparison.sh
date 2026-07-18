#!/bin/bash

# Biased Pipeline 1 Backtest
# Tests the biased scanner that finds known 10%+ intraday gainers for ML training

# Get script directory and change to Laravel root
SCRIPT_DIR="$( cd "$( dirname "${BASH_SOURCE[0]}" )" && pwd )"
LARAVEL_ROOT="$(dirname "$SCRIPT_DIR")"
cd "$LARAVEL_ROOT" || exit 1

echo "Working directory: $(pwd)"
echo ""

DATES=(
'2026-01-26'
'2026-01-27'
'2026-01-28'
'2026-01-29'
'2026-01-30'
)
'2025-08-07'
'2026-01-09'
'2026-01-12'
)

echo "════════════════════════════════════════════════════════════"
echo "  BIASED PIPELINE 1 BACKTEST (ML Training Dataset)"
echo "════════════════════════════════════════════════════════════"
echo ""
echo "📅 Trading Days: ${#DATES[@]}"
echo "⏰ Time Range: 09:40 - 15:30 EST (built-in to --backtest mode)"
echo "🔧 Scanner: FiveMinuteBiasedSignalScannerV1_0 (Look-Ahead)"
echo "🎯 Target: Stocks with 10%+ intraday gains"
echo "⚠️  WARNING: This is a BIASED dataset for ML training only!"

echo ""
echo "════════════════════════════════════════════════════════════"
echo "  GENERATING BIASED ALERTS (Known Winners)"
echo "════════════════════════════════════════════════════════════"
# Parallel processing configuration
MAX_PARALLEL=${MAX_PARALLEL:-4}  # Run 4 dates in parallel (adjust based on CPU cores)

# Parse --fulltable flag
FULLTABLE_FLAG=${FULLTABLE_FLAG:-""}
for arg in "$@"; do
  if [[ "$arg" == "--fulltable" ]]; then
    FULLTABLE_FLAG="--fulltable"
  fi
done
export FULLTABLE_FLAG
echo "⚡ Parallel Jobs: $MAX_PARALLEL"
echo "📋 Full Tables: ${FULLTABLE_FLAG:-off}"


echo ""
echo "════════════════════════════════════════════════════════════"
echo "  GENERATING BIASED ALERTS (PARALLEL)"
echo "════════════════════════════════════════════════════════════"
echo ""

# Function to process a single date
process_date() {
    local date=$1
    echo "📊 Processing $date..."
    
    # Use biased pipeline to find 10%+ gainers
    php artisan trade:biased-pipeline-1 stock \
        --backtest \
        --from="$date" \
        --to="$date" \
        --minMove=10 \
        --top=25 \
        --step=5 \
        ${FULLTABLE_FLAG:+"$FULLTABLE_FLAG"} \
        --no-interaction 2>&1 | sed "s/^/[$date] /"
    
    echo "  ✅ Completed $date"
}

export -f process_date

# Process dates in parallel using xargs
printf "%s\n" "${DATES[@]}" | xargs -P $MAX_PARALLEL -I {} bash -c 'process_date "$@"' _ {}

echo ""
echo "✅ Biased alert generation complete!"
echo ""

ALGO_VERSION="v1.0-biased"
echo "Using biased scanner version: $ALGO_VERSION"
echo ""

echo "════════════════════════════════════════════════════════════"
echo "  ANALYZING BACKTEST RESULTS (Biased Pipeline 1)"
echo "════════════════════════════════════════════════════════════"
echo ""

php artisan analyze:trade-alerts-atr-immediate \
    --algo-version="$ALGO_VERSION" \
    --pipeline=BIASED1 \
    --fixed-stop-pct=1.0 \
    --write-results --show-details

echo ""
echo "════════════════════════════════════════════════════════════"
echo "  BACKTEST COMPLETE"
echo "════════════════════════════════════════════════════════════"
echo ""
echo "📊 Results saved to database with pipeline_run='BIASED1'"
echo "💡 These are KNOWN 10%+ gainers for ML training"
echo "⚠️  Do not use for live trading decisions!"
echo ""
