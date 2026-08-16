# Backtest, Analyze, and Tune Workflow

This guide shows the normal v2 workflow for generating alerts, analyzing them, and then suggesting or applying tuning changes.

## 1. Run a TradingV2 backtest

Use the TradingV2 backtest command to generate alerts for a date range.

```bash
php artisan trading:v2-backtest --pipeline=A --from="2026-01-02 09:30:00" --to="2026-08-15 16:00:00" --step=5 --fulltable --write --no-log-rejections
```

Notes:
- `--pipeline=A` limits the run to Pipeline A.
- Remove `--pipeline=A` if you want all active pipelines.
- `--fulltable` uses the full 1-minute and 5-minute tables.
- `--write` writes generated alerts to the database.
- `--step=5` scans at 5-minute intervals.

If you want the shell wrapper instead of calling Artisan directly, use:

```bash
./scripts/runallPipelines.sh --from="2026-01-02 09:30:00" --to="2026-08-15 16:00:00" --pipeline=A --fulltable --write
```

## 2. Run the all-picks analysis first

The tuning script depends on recent alert rows already existing in `trade_alerts`. Run the analysis step first so the database has outcomes to inspect.

```bash
bash python_ml/v2/scripts/analyze_all_picks.sh
```

What it does:
- Runs `analyze:trade-alerts-atr-immediate` for every pipeline that has a `TRADE_ALERT_*_VERSION` in `.env`.
- Uses the live database ATR settings.
- Produces the alert analysis output that the tuning step expects.

If you only need one pipeline, use the matching Artisan analyze command instead of the all-picks shell script.

## 3. Run the tuning script

After analysis has populated alerts, run the tuning script to suggest stricter or looser settings.

Suggest-only mode:

```bash
/var/www/html/laravel-invest/.venv/bin/python python_ml/v2/scripts/suggest_version_tuning-v2.py --pipeline=A
```

Apply changes to the database:

```bash
/var/www/html/laravel-invest/.venv/bin/python python_ml/v2/scripts/suggest_version_tuning-v2.py --pipeline=A --apply
```

Useful options:
- `--version-id 231` tunes one exact alert version.
- `--pipeline A --version v90.1` tunes a specific pipeline/version pair.
- `--from` and `--to` narrow the alert sample.
- `--days 600` uses a lookback window when dates are not supplied.
- `--win-threshold 1.5` controls what counts as a win.
- `--all` analyzes every active version.

## Recommended order

1. Run the backtest.
2. Run `analyze_all_picks.sh`.
3. Run `suggest_version_tuning-v2.py` in suggest-only mode.
4. Review the recommendations.
5. Re-run with `--apply` only if you want to write the changes.

## Important notes

- The tuning script only works well if the alert table already has enough rows to analyze.
- If the sample is too small, it may return no recommendation.
- The v2 tuning script includes a note that `analyze_all_picks.sh` should run first.
- `--write` on the backtest and `--apply` on tuning are separate steps: the first writes alerts, the second writes gate and threshold updates.

## View or delete old alerts for Pipeline A

You can use these SQL statements to inspect or clean up old Pipeline A alerts that do not have matching Alpaca orders.

View old alerts:

```sql
SELECT ta.*
FROM trade_alerts AS ta
WHERE ta.pipeline_run = 'A'
  AND NOT EXISTS (
      SELECT 1
      FROM alpaca_orders AS ao
      WHERE ao.trade_alert_id = ta.id
  );
```

Delete old alerts:

```sql
DELETE ta
FROM trade_alerts AS ta
WHERE ta.pipeline_run = 'A'
  AND NOT EXISTS (
      SELECT 1
      FROM alpaca_orders AS ao
      WHERE ao.trade_alert_id = ta.id
  );
```

These are useful when you want to see which Pipeline A alerts were never turned into orders, or remove those old records before running another backtest.