#!/bin/bash
# v2/scripts/rescore_all.sh — Rescore ALL alerts across all pipelines.
#
# By default, clears ml_scored_at on all matching alerts, then re-scores them
# using the latest trained models. This ensures every alert has an up-to-date
# win_prob after model retraining.
#
# Usage:
#   bash python_ml/v2/scripts/rescore_all.sh                          # all pipelines, all dates since 2024-01-01 (FULL rescore)
#   bash python_ml/v2/scripts/rescore_all.sh 2026-06-01 2026-06-16   # date range
#   bash python_ml/v2/scripts/rescore_all.sh 2026-06-16               # single date
#   bash python_ml/v2/scripts/rescore_all.sh 2026-06-01 2026-06-16 "A,B,K,N"  # specific pipelines
#
# Set SKIP_CLEAR=1 to only score unscored alerts (no clearing):
#   SKIP_CLEAR=1 bash python_ml/v2/scripts/rescore_all.sh

set -e

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
source "$SCRIPT_DIR/env.sh"

# --------------------------------------------------
# Config
# --------------------------------------------------
START_DATE="${1:-2024-01-01}"
END_DATE="${2:-$(date +%Y-%m-%d)}"

# All pipelines that have trained models (mirrors retrain_all.sh)
# H,I,D is a combined model trained as one; individual H,I,D use winner_model_pipeline_hid.joblib
ALL_PIPELINES="A,B,C,E,F,G,H,I,D,J,K,L,M,N,O,P,Q,R"

# If a 3rd argument is provided, use it as the pipeline list override
PIPELINES="${3:-$ALL_PIPELINES}"

SCORER="python ${PYTHON_ML_DIR}/v2/score_trade_alerts.py"

# --------------------------------------------------
# Helpers
# --------------------------------------------------
load_env_file

IFS=',' read -ra PIPE_ARRAY <<< "$PIPELINES"
PIPE_LIST=""
for P in "${PIPE_ARRAY[@]}"; do
    P_CLEAN="$(echo "$P" | xargs | tr '[:lower:]' '[:upper:]')"
    if [ -n "$P_CLEAN" ]; then
        if [ -n "$PIPE_LIST" ]; then
            PIPE_LIST="$PIPE_LIST,'$P_CLEAN'"
        else
            PIPE_LIST="'$P_CLEAN'"
        fi
    fi
done

# --------------------------------------------------
# Main
# --------------------------------------------------
echo "=========================================================="
echo "  Rescore All Alerts (v2 scorer)"
echo "  Date Range: $START_DATE → $END_DATE"
echo "  Pipelines:  $PIPELINES"
if [ "${SKIP_CLEAR:-}" = "1" ]; then
    echo "  Mode:       Unscored only (SKIP_CLEAR=1)"
else
    echo "  Mode:       FULL (clearing ml_scored_at first)"
fi
echo "  Scorer:     $SCORER"
echo "  Started:    $(date)"
echo "=========================================================="
echo ""

# Step 1: Optionally clear existing scores
if [ "${SKIP_CLEAR:-}" != "1" ]; then
    echo "Clearing ml_scored_at & ml_win_prob for matching alerts..."
    echo "  (this may take a minute for large date ranges — counting rows first)..."

    set +e
    TOTAL=$(printf "SELECT COUNT(*) FROM trade_alerts WHERE trading_date_est >= '%s' AND trading_date_est <= '%s' AND pipeline_run IN (%s)" "$START_DATE" "$END_DATE" "$PIPE_LIST" | mysql -N -u "${DB_USERNAME:-laravel}" -p"${DB_PASSWORD:-laravel}" "${DB_DATABASE:-laravelInvest}" 2>/tmp/rescore_mysql.err)
    MYSQL_RC=$?
    set -e

    if [ $MYSQL_RC -ne 0 ]; then
        echo "ERROR: MySQL COUNT query failed (exit $MYSQL_RC)."
        echo "  stderr: $(cat /tmp/rescore_mysql.err)"
        exit 1
    fi
    if [ -z "$TOTAL" ]; then
        echo "ERROR: MySQL COUNT query returned empty result."
        exit 1
    fi

    echo "  Found $TOTAL alerts to update..."

    set +e
    UPDATE_OUTPUT=$(printf "UPDATE trade_alerts SET ml_scored_at = NULL, ml_win_prob = NULL WHERE trading_date_est >= '%s' AND trading_date_est <= '%s' AND pipeline_run IN (%s)" "$START_DATE" "$END_DATE" "$PIPE_LIST" | mysql -u "${DB_USERNAME:-laravel}" -p"${DB_PASSWORD:-laravel}" "${DB_DATABASE:-laravelInvest}" 2>/dev/null)
    UPDATE_RC=$?
    set -e

    if [ $UPDATE_RC -eq 0 ]; then
        echo "✅ Scores cleared ($TOTAL alerts reset)."
    else
        echo "⚠️  Clear scores FAILED (exit code $UPDATE_RC):"
        echo "    $UPDATE_OUTPUT"
        echo "  Continuing with rescore anyway (alerts may already be cleared)..."
    fi
    echo ""
fi

# Step 2: Find dates with alerts to score
echo "Finding trading dates with alerts..."
set +e
DATES=$(printf "SELECT DISTINCT trading_date_est FROM trade_alerts WHERE trading_date_est >= '%s' AND trading_date_est <= '%s' AND pipeline_run IN (%s) AND ml_scored_at IS NULL ORDER BY trading_date_est" "$START_DATE" "$END_DATE" "$PIPE_LIST" | mysql -N -u "${DB_USERNAME:-laravel}" -p"${DB_PASSWORD:-laravel}" "${DB_DATABASE:-laravelInvest}" 2>/dev/null)
MYSQL_RC=$?
set -e

if [ $MYSQL_RC -ne 0 ]; then
    echo "ERROR: MySQL query failed (exit $MYSQL_RC)."
    echo "  Output: $DATES"
    exit 1
fi

if [ -z "$DATES" ]; then
    echo "No alerts to score in the date range."
    exit 0
fi

DATE_COUNT=$(echo "$DATES" | wc -l)
echo "Found $DATE_COUNT trading dates with alerts to score."
echo ""

TOTAL_DATES=0
TOTAL_ALERTS=0
FAILED_DATES=0

for TRADING_DATE in $DATES; do
    TOTAL_DATES=$((TOTAL_DATES + 1))

    # Count alerts for this date
    BEFORE=$(printf "SELECT COUNT(*) FROM trade_alerts WHERE trading_date_est = '%s' AND pipeline_run IN (%s) AND ml_scored_at IS NULL" "$TRADING_DATE" "$PIPE_LIST" | mysql -N -u "${DB_USERNAME:-laravel}" -p"${DB_PASSWORD:-laravel}" "${DB_DATABASE:-laravelInvest}" 2>/dev/null) || BEFORE=""

    if [ "${BEFORE:-0}" -eq 0 ] 2>/dev/null; then
        continue
    fi

    echo "----------------------------------------------------------"
    echo "[$TOTAL_DATES/$DATE_COUNT] $TRADING_DATE — $BEFORE alerts"
    echo "----------------------------------------------------------"

    set +e
    $SCORER \
        --trading-date "$TRADING_DATE" \
        --pipeline "$PIPELINES" \
        --limit 500 2>&1
    RC=$?
    set -e

    AFTER=$(printf "SELECT COUNT(*) FROM trade_alerts WHERE trading_date_est = '%s' AND pipeline_run IN (%s) AND ml_scored_at IS NULL" "$TRADING_DATE" "$PIPE_LIST" | mysql -N -u "${DB_USERNAME:-laravel}" -p"${DB_PASSWORD:-laravel}" "${DB_DATABASE:-laravelInvest}" 2>/dev/null) || AFTER=""

    SCORED=$((BEFORE - AFTER))
    TOTAL_ALERTS=$((TOTAL_ALERTS + SCORED))

    if [ $RC -eq 0 ]; then
        echo "  ✅ Scored $SCORED / $BEFORE alerts"
    else
        FAILED_DATES=$((FAILED_DATES + 1))
        echo "  ❌ FAILED (exit code $RC)"
    fi
    echo ""
done

echo "=========================================================="
echo "  Rescore Complete"
echo "  Dates processed:  $TOTAL_DATES"
echo "  Alerts scored:    $TOTAL_ALERTS"
echo "  Failed dates:     $FAILED_DATES"
echo "  Finished:         $(date)"
echo "=========================================================="
