#!/bin/bash

# Rescore All ML Predictions Script
# This script clears existing ML scores and rescoring all trade_alerts
# using the configured per-pipeline models from config/.env.

set -e  # Exit on error

cd "$(dirname "$0")/.."

echo "=================================================="
echo "ML Prediction Rescoring Script"
echo "=================================================="
echo ""

# Step 0: Build and validate pipeline -> model mapping from config
echo "Step 0/5: Validating per-pipeline model configuration..."

PIPELINE_MODEL_LINES=$(php artisan tinker --execute="
  \$pipelines = DB::table('trade_alerts')
    ->whereNotNull('pipeline_run')
    ->selectRaw('UPPER(TRIM(pipeline_run)) as pipeline_run')
    ->distinct()
    ->orderBy('pipeline_run')
    ->pluck('pipeline_run');

  foreach (\$pipelines as \$pipeline) {
      \$key = 'trading.ml_scoring.pipeline_' . strtolower(\$pipeline) . '_model_path';
      \$modelPath = config(\$key);
      echo \$pipeline . '|' . (\$modelPath ?? '') . PHP_EOL;
  }
")

if [ -z "$PIPELINE_MODEL_LINES" ]; then
    echo "No pipelines found in trade_alerts. Nothing to score."
    exit 0
fi

declare -a PIPELINE_MODEL_MAP=()
MISSING_CONFIG=0
MISSING_FILES=0

while IFS='|' read -r PIPELINE MODEL_PATH; do
    [ -z "$PIPELINE" ] && continue

    if [ -z "$MODEL_PATH" ]; then
        echo "WARN: Skipping pipeline $PIPELINE — no model config (trading.ml_scoring.pipeline_${PIPELINE,,}_model_path)"
        MISSING_CONFIG=$((MISSING_CONFIG + 1))
        continue
    fi

    if [ ! -f "$MODEL_PATH" ]; then
        echo "WARN: Skipping pipeline $PIPELINE — model file not found: $MODEL_PATH"
        MISSING_FILES=$((MISSING_FILES + 1))
        continue
    fi

    PIPELINE_MODEL_MAP+=("$PIPELINE|$MODEL_PATH")
done <<< "$PIPELINE_MODEL_LINES"

if [ "$MISSING_CONFIG" -eq 1 ] || [ "$MISSING_FILES" -eq 1 ]; then
    echo ""
    echo "⚠️  Some pipelines are missing model configs or files and will be SKIPPED."
    echo "   Missing configs: $MISSING_CONFIG pipelines"
    echo "   Missing files:   $MISSING_FILES pipelines"
    echo ""
fi

echo "Validated pipeline model mapping:"
for ENTRY in "${PIPELINE_MODEL_MAP[@]}"; do
    PIPELINE="${ENTRY%%|*}"
    MODEL_PATH="${ENTRY#*|}"
    MODEL_VERSION=$(basename "$MODEL_PATH" .joblib)
    echo "  Pipeline $PIPELINE -> $MODEL_PATH (version: $MODEL_VERSION)"
done
echo ""

# Step 1: Clear existing ML scores
echo "Step 1/5: Clearing existing ML scores..."
php artisan tinker --execute="
  \$cleared = DB::table('trade_alerts')->whereNotNull('ml_win_prob')->count();
  DB::table('trade_alerts')->update(['ml_win_prob' => null, 'ml_scored_at' => null, 'ml_model_version' => null]);
  echo \"Cleared ML scores from \$cleared alerts\n\";
"
echo ""

# Step 2: Get date range from database
echo "Step 2/5: Getting date range from trade_alerts..."
DATE_RANGE=$(php artisan tinker --execute="
  \$result = DB::table('trade_alerts')
    ->selectRaw('MIN(trading_date_est) as min_date, MAX(trading_date_est) as max_date, COUNT(*) as total')
    ->first();
  echo \$result->min_date . '|' . \$result->max_date . '|' . \$result->total;
")

MIN_DATE=$(echo "$DATE_RANGE" | cut -d'|' -f1)
MAX_DATE=$(echo "$DATE_RANGE" | cut -d'|' -f2)
TOTAL_ALERTS=$(echo "$DATE_RANGE" | cut -d'|' -f3)

echo "Date range: $MIN_DATE to $MAX_DATE"
echo "Total alerts: $TOTAL_ALERTS"
echo ""

# Step 3: Rescore all alerts by date and pipeline
echo "Step 3/5: Rescoring all alerts by date and pipeline..."
echo "This may take a few minutes..."
echo ""

CURRENT_DATE="$MIN_DATE"
COUNT=0

while [[ "$CURRENT_DATE" < "$MAX_DATE" ]] || [[ "$CURRENT_DATE" == "$MAX_DATE" ]]; do
    COUNT=$((COUNT + 1))
    echo "[$COUNT] Scoring $CURRENT_DATE..."

    for ENTRY in "${PIPELINE_MODEL_MAP[@]}"; do
        PIPELINE="${ENTRY%%|*}"
        MODEL_PATH="${ENTRY#*|}"
        MODEL_VERSION=$(basename "$MODEL_PATH" .joblib)

        echo "  - Pipeline $PIPELINE using $MODEL_VERSION"
        python python_ml/v2/score_trade_alerts.py \
            --model-in "$MODEL_PATH" \
            --trading-date "$CURRENT_DATE" \
            --model-version "$MODEL_VERSION" \
            --pipeline "$PIPELINE" \
            --limit 10000 2>&1 | grep -E "(Scored|No alerts|Error)" || true
    done
    
    # Increment date by 1 day
    CURRENT_DATE=$(date -d "$CURRENT_DATE + 1 day" +%Y-%m-%d)
done

echo ""

# Step 4: Verify completion
echo "Step 4/5: Verifying rescoring completion..."
php artisan tinker --execute="
  \$totals = DB::table('trade_alerts')
    ->selectRaw('
      COUNT(*) as total,
      COUNT(ml_win_prob) as scored,
      SUM(CASE WHEN ml_model_version IS NULL OR TRIM(ml_model_version) = \"\" THEN 1 ELSE 0 END) as missing_model_version,
      MIN(ml_scored_at) as first_scored,
      MAX(ml_scored_at) as last_scored
    ')
    ->first();

  \$byModel = DB::table('trade_alerts')
    ->selectRaw('COALESCE(NULLIF(TRIM(ml_model_version), \"\"), \"(NULL/EMPTY)\") as model_version, COUNT(*) as cnt')
    ->whereNotNull('ml_win_prob')
    ->groupBy('model_version')
    ->orderByDesc('cnt')
    ->get();
  
  echo \"\n📊 Rescoring Summary:\n\";
  echo \"   Total alerts:          \" . \$totals->total . \"\n\";
  echo \"   Scored alerts:         \" . \$totals->scored . \"\n\";
  echo \"   Missing model version: \" . \$totals->missing_model_version . \"\n\";
  echo \"   Coverage:              \" . number_format((\$totals->scored / \$totals->total) * 100, 1) . \"%\n\";
  echo \"   First scored:          \" . \$totals->first_scored . \"\n\";
  echo \"   Last scored:           \" . \$totals->last_scored . \"\n\";
  echo \"\n   Scored alerts by model version:\n\";
  foreach (\$byModel as \$row) {
    echo \"   - \" . \$row->model_version . \" => \" . \$row->cnt . \"\n\";
  }
  echo \"\n\";
  
  if (\$totals->scored == \$totals->total && (int) \$totals->missing_model_version === 0) {
    echo \"✅ All alerts successfully rescored!\n\";
  } else {
    echo \"⚠️  Some alerts are unscored or missing model version. Check output above.\n\";
  }
"

echo ""
echo "Step 5/5: Done."

echo ""
echo "=================================================="
echo "Rescoring complete!"
echo "=================================================="
echo ""
echo "💡 Remember to hard refresh your browser (Ctrl+Shift+R) to see updated ML predictions"
echo ""
