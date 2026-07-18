#!/bin/bash

# Train ML Model with Recent Data (Last 3 Months)
# Focuses on recent market conditions to avoid training on stale patterns

set -e
cd "$(dirname "$0")/.."

echo "=================================================="
echo "ML Model Training - Recent Data (3 Months)"
echo "=================================================="
echo ""

# Calculate date ranges (last 3 months for better recency)
END_DATE=$(date +%Y-%m-%d)
START_DATE=$(date -d "90 days ago" +%Y-%m-%d)

echo "📅 Training Period:"
echo "   Start: $START_DATE"
echo "   End:   $END_DATE"
echo ""

# Model output path
MODEL_OUT="python_ml/models/winner_model_6months.joblib"
echo "📦 Output Model: $MODEL_OUT"
echo ""

# Training parameters
WIN_THRESHOLD=0.0  # Any positive P&L = win
TEST_SIZE=0.20     # 20% test set for evaluation
TOP_K=50           # Show top 50 picks

echo "⚙️  Training Parameters:"
echo "   Win threshold:  ${WIN_THRESHOLD}%"
echo "   Test split:     ${TEST_SIZE} (80% train, 20% test)"
echo "   Top-K display:  ${TOP_K}"
echo ""

# Check data availability first
echo "🔍 Checking training data availability..."
DATA_CHECK=$(php artisan tinker --execute="
\$count = DB::table('trade_alerts')
    ->whereBetween('trading_date_est', ['$START_DATE', '$END_DATE'])
    ->whereNotNull('pnl_percent')
    ->count();
echo \$count;
")

echo "   Found $DATA_CHECK trade alerts with outcomes"

if [ "$DATA_CHECK" -lt 500 ]; then
    echo ""
    echo "⚠️  WARNING: Only $DATA_CHECK training samples available."
    echo "   Recommended minimum: 500 samples"
    echo "   Consider extending date range or waiting for more trades"
    echo ""
    read -p "Continue anyway? (y/n) " -n 1 -r
    echo
    if [[ ! $REPLY =~ ^[Yy]$ ]]; then
        exit 1
    fi
fi

echo ""
echo "🚀 Starting model training..."
echo "   This may take 2-5 minutes..."
echo ""

# Run training
python python_ml/v2/v2/train_stock_winner_model_v2.py train \
    --start "$START_DATE" \
    --end "$END_DATE" \
    --table trade_alerts \
    --model-out "$MODEL_OUT" \
    --win-threshold "$WIN_THRESHOLD" \
    --test-size "$TEST_SIZE" \
    --top-k "$TOP_K"

TRAIN_EXIT_CODE=$?

if [ $TRAIN_EXIT_CODE -ne 0 ]; then
    echo ""
    echo "❌ Training failed with exit code $TRAIN_EXIT_CODE"
    exit $TRAIN_EXIT_CODE
fi

echo ""
echo "=================================================="
echo "✅ Training Complete!"
echo "=================================================="
echo ""
echo "📊 Model saved to: $MODEL_OUT"
echo ""
echo "🎯 Next Steps:"
echo "   1. Review the model metrics above (AUC, precision@k)"
echo "   2. Check feature importance rankings"
echo "   3. If satisfied, rescore all alerts:"
echo "      ./scripts/rescore_all_ml_predictions.sh"
echo ""
echo "💡 Expected Improvements:"
echo "   - Better detection of over-extended setups"
echo "   - Reduced false positives on overbought conditions"
echo "   - Improved win rate correlation with ML scores"
echo ""
