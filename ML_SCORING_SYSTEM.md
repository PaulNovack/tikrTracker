# ML Trade Alert Scoring System

## Overview

The ML scoring system automatically predicts the probability that a trade alert will be profitable (win rate ≥1%) using per-pipeline XGBoost models. When new alerts are created they are automatically queued for ML scoring without blocking alert creation.

## Architecture

### Key Scripts

- **`python_ml/train_stock_winner_model_v2.py`** — Training pipeline (66 features, market context)
- **`python_ml/score_single_alert_live.py`** — Live scoring at order entry time
- **`python_ml/score_single_alert_v2.py`** — Manual/historical rescoring

### Laravel Components

- **`app/Jobs/ScoreTradeAlertWithMl.php`** — Async job (3 retries, 60s timeout)
- **`app/Services/Trading/TradeAlertWriterV1.php`** — Auto-dispatches scoring job after alert creation

### Models (Per-Pipeline)

All stored under `python_ml/v2/models/winner_model_pipeline_{x}.joblib`. 65 numeric + 4 categorical features including full market context. H, I, D share a combined model (`winner_model_pipeline_hid.joblib`).

| Pipeline | Model File | AUC | P@10 | Source | Notes |
|---|---|---|---|---|---|
| A | winner_model_momentum.joblib | 0.651 | 0.100 (actual) | BT+actual | |
| B | winner_model_pipeline_b.joblib | 0.846 | 0.300 (BT) | BT+actual | Best AUC |
| C | winner_model_breakouts.joblib | 0.752 | 0.300 (BT) | BT+actual | |
| D | (shared with H,I) | 0.700 | 0.400 (BT) | H,I,D combined | |
| E | winner_model_pipeline_e.joblib | 0.679 | 0.700 (BT) | BT+actual | Threshold raised to 0.65 |
| F | winner_model_pipeline_f.joblib | 0.701 | 0.125 (actual) | BT+actual | |
| H | winner_model_pipeline_hid.joblib | 0.700 | 0.400 (BT) | H,I,D combined | |
| I | winner_model_pipeline_hid.joblib | 0.700 | 0.400 (BT) | H,I,D combined | |
| J | winner_model_pipeline_j.joblib | 0.628 | 0.300 (BT) | BT+actual | |
| K | winner_model_pipeline_k.joblib | 0.777 | 0.500 (actual) | BT+actual | |
| L | winner_model_pipeline_l.joblib | 0.656 | 0.100 (actual) | BT+actual | |
| N | winner_model_pipeline_n.joblib | 0.762 | 0.900 (BT) | BT+actual | Best P@10 |
| P | winner_model_pipeline_p.joblib | 0.539 | 0.500 (BT) | BT+actual | Forward-biased |

*Last retrained: June 14, 2026*

### AUC & Precision@10 Persistence

After each `retrain_all.sh` run, the post-processing section extracts Test AUC and Precision@10 from training logs and persists them to the `settings` DB table. This feeds the ML threshold analyzer (`analyze:ml-thresholds`) and the trading-settings UI.

**DB keys:**
- `trading.pipeline_auc.{a..p}` — Test AUC from holdout split
- `trading.pipeline_p10.{a..p}` — Precision@10 (prefers actual-fill, falls back to BT-simulated)

**Extraction logic** (in `python_ml/v2/scripts/retrain_all.sh` lines 220-310):
1. For each pipeline, picks the latest `{PIPELINE}-{date}_*.log` from `training_logs/`
2. Greps `Test AUC:` and `[bt_only] Precision@10=` or `Precision@10 (actual):`
3. Prefers actual-fill P@10 when available and > 0
4. H, I, D share values from the combined `HID-*.log`
5. Writes via `INSERT ... ON DUPLICATE KEY UPDATE` to `settings` table

**Manual re-persistence** (without re-running full training):
```bash
# Extract and persist metrics from today's logs only
source .env && LOG_DIR="python_ml/v2/training_logs"
# ... see retrain_all.sh lines 220-310 for the full script
```

**Training timeout protection:** The v2 trainer engine (`train_stock_winner_model_v2.py`) uses `read_timeout=120` / `write_timeout=120` connect_args to prevent MySQL `(2013) Lost connection` errors during large CTE queries (e.g., H,I,D's 12,000-row dataset).

## Configuration

**config/trading.php:**
```php
'ml_scoring' => [
    'enabled' => (bool) env('TRADING_ML_SCORING_ENABLED', true),
    'model_path' => env('TRADING_ML_MODEL_PATH', 'python_ml/models/winner_model_xgb.joblib'),
    'timeout_seconds' => (int) env('TRADING_ML_TIMEOUT', 60),
    'max_retries' => (int) env('TRADING_ML_MAX_RETRIES', 3),
],
```

**Pipeline-specific thresholds (.env):**
```bash
AUTO_ALPACA_ML_THRESHOLD_PIPELINE_K=0.60
AUTO_ALPACA_ML_THRESHOLD_PIPELINE_E=0.65   # Raised from 0.50 — May 2026
AUTO_ALPACA_ML_THRESHOLD_PIPELINE_N=0.60
# etc.
```

**Pipeline-specific model paths (.env):**
```bash
AUTO_ALPACA_ML_MODEL_PIPELINE_K=python_ml/models/winner_model_pipeline_k.joblib
AUTO_ALPACA_ML_MODEL_PIPELINE_N=python_ml/models/winner_model_pipeline_n.joblib
# etc.
```

## Database Schema

```sql
-- Added to: trade_alerts, trade_alerts_unfiltered
ml_win_prob          DECIMAL(10,6) NULL  -- Predicted win probability (0-1)
ml_scored_at         TIMESTAMP NULL      -- When alert was scored
ml_model_version     VARCHAR(64) NULL    -- Model version used
```

All three columns are indexed.

## Live Scoring Flow

1. Pipeline creates alert → `TradeAlertWriterV1` dispatches `ScoreTradeAlertWithMl` job
2. Job calls `score_single_alert_live.py` with the alert ID and pipeline-specific model
3. Script computes all 66 features (including market context via QQQ `asset_type='stock'` JOIN)
4. Writes `ml_win_prob`, `ml_scored_at`, `ml_model_version` back to DB

Queue workers must be running:
```bash
php artisan queue:work
```

## Historical Rescoring

Use `score_single_alert_v2.py` for rescoring — it uses the full 66-feature set with market context.
**Do NOT use the old `score_trade_alerts.py`** (lacks market context features).

```bash
# Rescore a pipeline for yesterday
./scripts/train_and_rescore/rescore_pipeline_yesterday.sh K
./scripts/train_and_rescore/rescore_pipeline_yesterday.sh F

# Rescore a specific date
./scripts/train_and_rescore/rescore_pipeline_yesterday.sh N 2026-05-09
```

## Retraining

```bash
# Retrain all active pipelines
./scripts/train_and_rescore/retrain_all_active_pipelines.sh
php artisan config:clear
```

See [ML_TRADING_SYSTEM.md](ML_TRADING_SYSTEM.md) for full training documentation.

## Known Notes

- `alert_rsi_14_1m IS NULL` warning during scoring is expected — imputed from training median
- QQQ benchmark is stored as `asset_type = 'stock'` (not `'us_equity'`) in `five_minute_prices`
- `rs_spread_vs_market` requires both `fmp` (stock 5m bar) and `stk_open` (first bar of day) JOINs

## Monitoring

**Check queue jobs:**
```bash
php artisan queue:work
php artisan queue:failed
php artisan queue:retry all
```

**Check scoring status:**
```sql
-- Count scored alerts today
SELECT COUNT(*) FROM trade_alerts
WHERE trading_date_est = CURDATE() AND ml_scored_at IS NOT NULL;

-- Average ML probability by pipeline
SELECT pipeline_run, ROUND(AVG(ml_win_prob) * 100, 1) as avg_prob, COUNT(*) as cnt
FROM trade_alerts
WHERE ml_win_prob IS NOT NULL
GROUP BY pipeline_run
ORDER BY avg_prob DESC;
```

**Check logs:**
```bash
tail -f storage/logs/laravel.log | grep "ML Scoring"
```

## Troubleshooting

**Alerts not being scored:**
1. Check queue workers running: `ps aux | grep "queue:work"`
2. Check failed jobs: `php artisan queue:failed`
3. Verify config: `php artisan config:clear`

**Low scores / unexpected values:**
1. Verify model file exists: `ls -lh python_ml/models/winner_model_pipeline_k.joblib`
2. Check `asset_type = 'stock'` (not `'us_equity'`) in benchmark JOINs
3. Rescore yesterday's alerts: `./scripts/train_and_rescore/rescore_pipeline_yesterday.sh K`

## Testing

```bash
php artisan test tests/Feature/TradeAlertMlScoringTest.php
```
