#!/usr/bin/env bash

# TradingV2 backtest runner.
#
# Run a date range:
#   ./scripts/runallPipelines.sh --from="2026-08-01 09:30:00" --to="2026-08-15 16:00:00"
#
# Run only one pipeline letter without affecting the others:
#   ./scripts/runallPipelines.sh --from="2026-08-01 09:30:00" --to="2026-08-15 16:00:00" --pipeline=A
#
# Optional flags:
#   --step=5            Scan every 5 minutes
#   --fulltable         Use full-table scans
#   --write             Write generated alerts to the database
#   --use-entry-finder  Use the entry-finder path

set -euo pipefail

SCRIPT_DIR="$( cd "$( dirname "${BASH_SOURCE[0]}" )" && pwd )"
LARAVEL_ROOT="$(dirname "$SCRIPT_DIR")"
cd "$LARAVEL_ROOT" || exit 1

FROM="${V2_BACKTEST_FROM:-}"
TO="${V2_BACKTEST_TO:-}"
STEP="${V2_BACKTEST_STEP:-5}"
FULLTABLE_FLAG=""
WRITE_FLAG="${V2_BACKTEST_WRITE:---write}"
USE_ENTRY_FINDER_FLAG="${V2_BACKTEST_USE_ENTRY_FINDER:-}"
PIPELINE_FLAG="${V2_BACKTEST_PIPELINE:-}"

for arg in "$@"; do
	case "$arg" in
		--from=*)
			FROM="${arg#*=}"
			;;
		--to=*)
			TO="${arg#*=}"
			;;
		--step=*)
			STEP="${arg#*=}"
			;;
		--pipeline=*)
			PIPELINE_FLAG="${arg#*=}"
			;;
		--fulltable|--full-table)
			FULLTABLE_FLAG="--fulltable"
			;;
		--no-fulltable|--no-full-table)
			FULLTABLE_FLAG=""
			;;
		--write)
			WRITE_FLAG="--write"
			;;
		--no-write)
			WRITE_FLAG=""
			;;
		--use-entry-finder)
			USE_ENTRY_FINDER_FLAG="--use-entry-finder"
			;;
	esac
done

if [[ -z "$FROM" || -z "$TO" ]]; then
	cat <<'EOF'
Usage: ./scripts/runallPipelines.sh --from="YYYY-MM-DD HH:MM:SS" --to="YYYY-MM-DD HH:MM:SS" [--step=5] [--fulltable] [--write] [--pipeline=A] [--use-entry-finder]

Or set V2_BACKTEST_FROM / V2_BACKTEST_TO / V2_BACKTEST_STEP / V2_BACKTEST_PIPELINE / V2_BACKTEST_WRITE in the environment.
EOF
	exit 1
fi

echo "Working directory: $(pwd)"
echo ""
echo "════════════════════════════════════════════════════════════"
echo "  TRADING V2 BACKTEST"
echo "════════════════════════════════════════════════════════════"
echo ""
echo "📅 Window: $FROM -> $TO"
echo "⏰ Step: ${STEP}m"
echo "📋 Full Tables: ${FULLTABLE_FLAG:-off}"
echo "💾 Write Alerts: ${WRITE_FLAG:--write off}"
echo "🔎 Pipeline Filter: ${PIPELINE_FLAG:-ALL}"
echo ""

CMD=(php artisan trading:v2-backtest --from="$FROM" --to="$TO" --step="$STEP")

if [[ -n "$FULLTABLE_FLAG" ]]; then
	CMD+=("$FULLTABLE_FLAG")
fi

if [[ -n "$WRITE_FLAG" ]]; then
	CMD+=("$WRITE_FLAG")
fi

if [[ -n "$USE_ENTRY_FINDER_FLAG" ]]; then
	CMD+=("$USE_ENTRY_FINDER_FLAG")
fi

if [[ -n "$PIPELINE_FLAG" ]]; then
	CMD+=("--pipeline=$PIPELINE_FLAG")
fi

"${CMD[@]}"

echo ""
echo "All v2 backtests have completed."