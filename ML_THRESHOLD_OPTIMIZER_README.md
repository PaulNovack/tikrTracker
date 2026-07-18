# ML Threshold Optimizer (DB-Backed)

This project now supports optimizing and applying per-pipeline ML thresholds directly to database settings.

## Main Command

```bash
php artisan analyze:ml-thresholds --days=60 --min-trades=20 --top=3 --no-interaction
```

## Max Picks Mode (80%+ Win Rate Guard)

Use `--max-picks` to choose the threshold that yields the most picks while still meeting the minimum win-rate guard.

```bash
php artisan analyze:ml-thresholds --days=60 --min-trades=20 --top=3 --max-picks --min-win-rate=80 --dry-run --no-interaction
```

## Use '--max-picks' and 75 percent threshold

```bash
php artisan analyze:ml-thresholds --days=60 --min-trades=2 --top=5 --max_picks --min_win_rate=75 --no-interaction
```

When running this command shape, any pipeline with estimated PnL/day below `0.5%` is automatically forced to the disable threshold (`0.99`).

## What It Does

1. Loads analyzed alerts (`trade_alerts`) for the selected date window.
2. Sweeps ML thresholds (`ml_win_prob`) using the configured range/step.
3. Scores each threshold per pipeline using the selected metric.
4. Selects the best threshold per pipeline (subject to `--min-trades`).
5. Writes results to DB settings (default behavior).

When `--max-picks` is enabled, selection changes to:

1. Keep only threshold candidates that meet `--min-win-rate` (default `80`).
2. Select the candidate with the highest trade count.
3. Tie-break by higher win rate, then score, then lower threshold.
4. If no candidate meets the win-rate floor, force-disable that pipeline using `--disable-threshold` (default `0.99`).
5. If estimated PnL/day is below `--min-pnl-per-day` (default `0.5`), force-disable that pipeline using `--disable-threshold`.

## DB Write Behavior

By default, the command runs in **APPLY** mode and updates settings keys like:

- `trading.pipeline_a.ml_threshold`
- `trading.pipeline_b.ml_threshold`
- ...
- `trading.pipeline_o.ml_threshold`

These thresholds are now used at runtime for order gating.

## Dry Run (No Writes)

Use `--dry-run` to preview recommendations without updating DB:

```bash
php artisan analyze:ml-thresholds --days=60 --min-trades=20 --top=3 --dry-run --no-interaction
```

## Useful Options

- `--days=60` lookback window (when `--from/--to` not provided)
- `--from=YYYY-mm-dd --to=YYYY-mm-dd` explicit date range
- `--pipelines=A,B,L` restrict to selected pipelines
- `--min-trades=20` minimum sample size required per candidate threshold
- `--metric=expectancy|win_rate|total_pnl` optimization objective
- `--max-picks` maximize picks while staying above `--min-win-rate`
- `--min-win-rate=80` minimum win rate guardrail for selection
- `--min-pnl-per-day=0.5` minimum estimated PnL/day required or pipeline is forced off
- `--disable-threshold=0.99` threshold used to effectively turn off weak pipelines
- `--top=3` show top N candidates per pipeline

## Output Sections

The command prints:

1. Pipeline summary table (`Current`, `Suggested`, `Trades`, `Win Rate`, `Avg PnL`, `Total PnL`, `Score`)
2. Recommended DB settings (`trading.pipeline_{x}.ml_threshold`)
3. Top-N threshold candidates per pipeline
4. Estimated activity table using suggested thresholds:
	- `Est Trades (Window)`
	- `Est Trades/Day`
	- `Est Win Rate`
	- `Est PnL (Window)`
	- `Est PnL/Day`
5. Aggregate estimates:
	- total estimated trades/day
	- estimated overall weighted win rate
	- total estimated PnL (window)
	- total estimated PnL/day

## Trading Settings UI

Per-pipeline ML thresholds are editable in:

- `/trading-settings`
- **ML Thresholds** tab

Changes made in UI are stored in DB and used by runtime services.

## Notes

- If a pipeline has insufficient qualifying rows, it may not receive an updated threshold.
- The command output includes both current and suggested values for visibility.
- In `--max-picks` mode, summary `Score` shows `MAX_PICKS` for qualifying pipelines.
- Pipelines that cannot satisfy the win-rate floor or the minimum estimated PnL/day gate are marked `FORCED_OFF` and assigned the disable threshold.
- Run `php artisan config:clear` only if you changed config files; DB setting updates do not require worker restarts.
