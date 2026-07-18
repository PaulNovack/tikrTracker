#!/bin/bash
# v2/scripts/train_pipeline.sh — Train a single pipeline with v2 trainer.
# Usage:
#   bash python_ml/v2/scripts/train_pipeline.sh N
#   bash python_ml/v2/scripts/train_pipeline.sh C
#   bash python_ml/v2/scripts/train_pipeline.sh E 2024-01-01 2026-06-13

set -e

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
source "$SCRIPT_DIR/env.sh"

PIPELINE="${1:-N}"
START_DATE="${2:-2024-01-01}"
END_DATE="${3:-$(date -d tomorrow +%Y-%m-%d)}"

train_pipeline "$PIPELINE" "$START_DATE" "$END_DATE"
