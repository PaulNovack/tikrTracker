#!/bin/bash
# Pipeline L end-to-end workflow:
# 1) Train pipeline-L model (includes analyze by default)
# 2) Rescore pipeline-L alerts

set -euo pipefail

USE_FULL_TABLES=${USE_FULL_TABLES:-0}

for arg in "$@"; do
	case "$arg" in
		--use-full-tables)
			USE_FULL_TABLES=1
			;;
		--no-full-tables)
			USE_FULL_TABLES=0
			;;
		*)
			echo "Unknown option: $arg"
			echo "Usage: $0 [--use-full-tables|--no-full-tables]"
			exit 1
			;;
	esac
done

SCRIPT_DIR="$( cd "$( dirname "${BASH_SOURCE[0]}" )" && pwd )"
LARAVEL_ROOT="$(dirname "$(dirname "$SCRIPT_DIR")")"
cd "$LARAVEL_ROOT" || exit 1

ALGO_VERSION=$(grep "^TRADE_ALERT_L_VERSION=" "$LARAVEL_ROOT/.env" | cut -d '=' -f2)

echo "=================================================="
echo "Pipeline L Train -> Rescore"
echo "=================================================="
echo "Working directory: $(pwd)"
echo "Pipeline version: $ALGO_VERSION"
echo "Use _full price tables: $([[ "$USE_FULL_TABLES" == "1" ]] && echo YES || echo NO)"
echo "Start time: $(date)"
echo ""

echo "[1/2] Train Pipeline L model (runs analyze first by default)"
USE_FULL_TABLES="$USE_FULL_TABLES" "$SCRIPT_DIR/train_pipeline_l_model.sh"

echo ""
echo "[2/2] Rescore Pipeline L alerts"
"$SCRIPT_DIR/rescore_pipeline_l_alerts.sh"

echo ""
echo "=================================================="
echo "Pipeline L workflow complete"
echo "End time: $(date)"
echo "=================================================="
