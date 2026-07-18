#!/bin/bash
# v2/scripts/score.sh — Score a single alert with the v2 scorer.
# Usage:
#   bash python_ml/v2/scripts/score.sh 12345
#   bash python_ml/v2/scripts/score.sh 12345 path/to/model.joblib trade_alerts

set -e

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
source "$SCRIPT_DIR/env.sh"

score_alert "$@"
