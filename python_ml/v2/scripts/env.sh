#!/bin/bash
# v2/scripts/env.sh — Self-contained env helpers for v2 scripts.
# Resolves paths relative to the v2/ directory.
#
# Usage: source "$(dirname "$0")/env.sh"

set -e

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
V2_DIR="$(cd "$SCRIPT_DIR/.." && pwd)"
PYTHON_ML_DIR="$(cd "$V2_DIR/.." && pwd)"
REPO_ROOT="$(cd "$PYTHON_ML_DIR/.." && pwd)"

load_env_file() {
  local env_file="$REPO_ROOT/.env"
  if [[ -f "$env_file" ]]; then
    set -a
    # shellcheck disable=SC1090
    source "$env_file"
    set +a
  fi
}

get_pipeline_model_path() {
  local pipeline="$1"
  local fallback="$2"
  local upper
  upper="$(echo "$pipeline" | tr '[:lower:]' '[:upper:]')"
  local var="TRADING_ML_PIPELINE_${upper}_MODEL_PATH"

  load_env_file
  if [[ -n "${!var:-}" ]]; then
    echo "${!var}"
  else
    echo "$fallback"
  fi
}

get_trainer_cmd() {
  echo "python ${PYTHON_ML_DIR}/v2/train_stock_winner_model_v2.py train"
}

get_scorer_cmd() {
  echo "python ${PYTHON_ML_DIR}/v2/score_single_alert_v2.py"
}

get_daemon_cmd() {
  echo "python ${PYTHON_ML_DIR}/v2/scoring_daemon.py"
}

score_alert() {
  local ALERT_ID="$1"
  local MODEL_IN="${2:-$(get_pipeline_model_path "GLOBAL" "python_ml/v2/models/winner_model_xgb.joblib")}"
  local TABLE="${3:-trade_alerts}"

  python "${PYTHON_ML_DIR}/v2/score_single_alert_v2.py" \
    --alert-id "$ALERT_ID" \
    --model-in "$MODEL_IN" \
    --table "$TABLE"
}

train_pipeline() {
  local PIPELINE="$1"
  local START_DATE="${2:-2024-01-01}"
  local END_DATE="${3:-$(date -d tomorrow +%Y-%m-%d)}"
  local LOWER
  LOWER="$(echo "$PIPELINE" | tr '[:upper:]' '[:lower:]')"

  local MODEL_OUT
  MODEL_OUT="$(get_pipeline_model_path "$PIPELINE" "python_ml/v2/models/winner_model_pipeline_${LOWER}.joblib")"
  local TRAINER
  TRAINER=$(get_trainer_cmd)

  # Log file for later AUC / P@10 extraction
  local LOG_FILE="${V2_DIR}/training_logs/${PIPELINE}-$(date +%Y-%m-%d_%H%M).log"

  echo "=================================================="
  echo "Training Pipeline $PIPELINE v2 Model"
  echo "Date range: $START_DATE → $END_DATE"
  echo "Output: $MODEL_OUT"
  echo "Log: $LOG_FILE"
  echo "Start time: $(date)"
  echo "=================================================="
  echo ""

  $TRAINER \
    --pipeline "$PIPELINE" \
    --win-threshold 2.0 \
    --actual-fill-weight 20.0 \
    --eval-on-actual-only \
    --split-mode day \
    --start "$START_DATE" \
    --end "$END_DATE" \
    --test-size 0.2 \
    --top-k 10 \
    --train-full \
    --model-out "$MODEL_OUT" 2>&1 | tee "$LOG_FILE"

  echo ""
  echo "=================================================="
  echo "v2 training complete!"
  echo "Model: $MODEL_OUT"
  echo "End time: $(date)"
  echo "=================================================="

  # ── Persist AUC & Precision@10 to the DB settings table ──
  persist_auc_p10_from_log "$LOG_FILE" "$PIPELINE"
}

# ─────────────────────────────────────────────────────────────────────────
# Extract AUC and Precision@10 from a training log and persist to the DB
# as pipeline-quality markers visible on the /trading-settings pipelines tab.
# ─────────────────────────────────────────────────────────────────────────
persist_auc_p10_from_log() {
  local LOG_FILE="$1"
  local PIPELINE="$2"
  local LOW
  LOW="$(echo "$PIPELINE" | tr '[:upper:]' '[:lower:]')"

  # Source .env for DB credentials
  source "$REPO_ROOT/.env" 2>/dev/null || true
  local DB_USER="${DB_USERNAME:-laravel}"
  local DB_PASS="${DB_PASSWORD:-laravel}"
  local DB_NAME="${DB_DATABASE:-laravelInvest}"

  local AUC
  AUC=$(grep -oP 'Test AUC:\s*\K[\d.]+' "$LOG_FILE" | head -1)
  local P10_BT
  P10_BT=$(grep -oP '\[bt_only\] Precision@10=\K[\d.]+' "$LOG_FILE" | head -1)
  local P10_ACTUAL
  P10_ACTUAL=$(grep -oP 'Precision@10 \(actual\):\s*\K[\d.]+' "$LOG_FILE" | head -1)

  # Prefer actual-fill P@10 when available and > 0
  local P10
  local P10_SRC
  if [[ -n "$P10_ACTUAL" && "$P10_ACTUAL" != "0.000" ]]; then
    P10="$P10_ACTUAL"
    P10_SRC="actual"
  else
    P10="$P10_BT"
    P10_SRC="${P10_BT:+BT}"
  fi

  local PERSIST_OK=true

  if [[ -n "$AUC" ]]; then
    echo "      → Writing trading.pipeline_auc.${LOW}=${AUC} to DB..."
    if mysql -N -u "$DB_USER" -p"$DB_PASS" "$DB_NAME" \
      -e "INSERT INTO settings (name, value, updated_at) VALUES ('trading.pipeline_auc.${LOW}', '${AUC}', NOW()) ON DUPLICATE KEY UPDATE value='${AUC}', updated_at=NOW();"; then
      echo "   AUC=${AUC} ✔"
    else
      echo "   AUC=${AUC} ✗ (DB write failed)"
      PERSIST_OK=false
    fi
  fi

  if [[ -n "$P10" ]]; then
    echo "      → Writing trading.pipeline_p10.${LOW}=${P10} to DB..."
    if mysql -N -u "$DB_USER" -p"$DB_PASS" "$DB_NAME" \
      -e "INSERT INTO settings (name, value, updated_at) VALUES ('trading.pipeline_p10.${LOW}', '${P10}', NOW()) ON DUPLICATE KEY UPDATE value='${P10}', updated_at=NOW();"; then
      echo "   P@10=${P10} (${P10_SRC}) ✔"
    else
      echo "   P@10=${P10} (${P10_SRC}) ✗ (DB write failed)"
      PERSIST_OK=false
    fi
  fi

  # Persist dedicated ML updated timestamp
  echo "      → Writing trading.pipeline_ml_updated.${LOW}=now to DB..."
  if mysql -N -u "$DB_USER" -p"$DB_PASS" "$DB_NAME" \
    -e "INSERT INTO settings (name, value, updated_at) VALUES ('trading.pipeline_ml_updated.${LOW}', 'now', NOW()) ON DUPLICATE KEY UPDATE value='now', updated_at=NOW();"; then
    echo "   ML Updated ✔"
  else
    echo "   ML Updated ✗ (DB write failed)"
    PERSIST_OK=false
  fi

  if $PERSIST_OK; then
    echo "   ✅ Settings persisted for pipeline ${PIPELINE}"
  else
    echo "   ⚠️ Some settings failed to persist for pipeline ${PIPELINE}"
  fi
}
