# TradingV2 Exhaustive Pipeline Search

> Generated 2026-08-24 — Explains how to run the exhaustive pipeline optimizer, what data it uses,
> how to tune its arguments, and how to interpret the output.

---

## Purpose

Use `trading:build-winning-pipeline-sql-exhaustive` when you want to search through the available
pipeline gates and generate a new SQL seed that represents the strongest candidate pipeline found
from the data.

This command is intended to:

1. Read both passing alerts and analyzed backtest candidates.
2. Evaluate gate combinations across one pipeline or all pipelines.
3. Rank the resulting candidates by average P&L, win rate, and sample count.
4. Emit SQL for the best result so you can review or seed it later.

It is a **search/optimization** tool, not a live trading command.

---

## Recommended Command

The command the team has been using for a 3% target is:

```bash
php artisan trading:build-winning-pipeline-sql-exhaustive ALL \
  --target-pnl=3 \
  --max-gates=4 \
  --max-variants-per-gate=3 \
  --max-evaluations=50000 \
  --min-samples=500 \
  --output=winner-all-3.sql
```

### What this means

- `ALL` tells the command to evaluate every active pipeline in `alert_versions`.
- `--target-pnl=3` treats trades with $3\%$ or better P&L as winners during the search.
- `--max-gates=4` limits how many gates can be combined in a candidate set.
- `--max-variants-per-gate=3` keeps up to three threshold variants for each gate.
- `--max-evaluations=50000` caps the number of combinations evaluated per pipeline.
- `--min-samples=500` rejects gate sets that leave too few passing rows.
- `--output=winner-all-3.sql` writes the generated SQL to that file.

---

## What Data It Uses

The command combines two sources:

- `trade_alerts` for rows that already passed the pipeline gates and have P&L data.
- `trade_alerts_backtest_candidates` for analyzed candidates, including rejected rows that were
  still evaluated and have `pnl_percent` filled in.

That means the search is not limited to winners only. It can use:

- passed alerts
- failed gate candidates
- historical rows from multiple pipelines

The command deduplicates rows by `dedupe_key` before evaluating them.

---

## How The Search Works

For each pipeline, the command:

1. Loads the active version from `alert_versions`.
2. Loads all analyzed rows for that pipeline from both source tables.
3. Loads the current gate definitions from `alert_version_gates`.
4. Builds candidate threshold variants from the observed gate values.
5. Evaluates combinations of those variants up to the configured limits.
6. Filters out combinations with fewer than `--min-samples` passing rows.
7. Ranks the best result for that pipeline.
8. After all pipelines are processed, ranks the pipelines against each other and selects the best overall.

The final SQL is generated only for the top-ranked pipeline version.

---

## How To Read The Output

You will usually see two blocks:

### Pipeline Ranking Table

This compares every pipeline that produced a viable candidate.

Example:

```text
Pipeline ranking
+----------+---------+----------+---------+----------------+-------------+
| Pipeline | Samples | Win Rate | Avg P&L | Selected Gates | Evaluations |
+----------+---------+----------+---------+----------------+-------------+
| C        | 1511    | 3.4%     | 0.36%   | 3              | 20000       |
+----------+---------+----------+---------+----------------+-------------+
```

Interpretation:

- `Samples` is the number of rows that passed the selected gate set.
- `Win Rate` is the percentage of those rows that reached the target P&L threshold.
- `Avg P&L` is the average return of the passed rows.
- `Selected Gates` is how many gates ended up in the winning gate set.
- `Evaluations` is how many combinations were tested before the search stopped.

### Best Candidate Block

This is the final winner the command wants you to seed or review.

Important fields:

- `Pipeline` — the pipeline letter selected as best overall.
- `Version string` — the generated version label for the new SQL insert.
- `Samples` — the size of the final filtered set.
- `Win rate` — the share of passing rows that meet the target threshold.
- `Average pnl` — the average P&L for the passing set.
- `Winner average pnl` — the average P&L of the rows that exceeded the target threshold.
- `Selected gates` — how many gates were kept.
- `Evaluations` — total evaluated combinations for that pipeline.

If `Samples` is `0`, the search became too restrictive and the final gate set filtered everything out.

---

## Recommended Tuning

### Quick sanity check

```bash
php artisan trading:build-winning-pipeline-sql-exhaustive C \
  --target-pnl=3 \
  --max-gates=3 \
  --max-variants-per-gate=2 \
  --max-evaluations=5000 \
  --min-samples=200 \
  --output=winner-c-3.sql
```

Use this when you want a faster test run and do not want to wait hours.

### Overnight search

```bash
php artisan trading:build-winning-pipeline-sql-exhaustive C \
  --target-pnl=3 \
  --max-gates=4 \
  --max-variants-per-gate=3 \
  --max-evaluations=50000 \
  --min-samples=500 \
  --output=winner-c-3.sql
```

Use this when you are okay with a long run and want a more serious search.

### Hard search

```bash
php artisan trading:build-winning-pipeline-sql-exhaustive ALL \
  --target-pnl=4 \
  --max-gates=6 \
  --max-variants-per-gate=3 \
  --max-evaluations=100000 \
  --min-samples=500 \
  --output=winner-all-4.sql
```

Use this when you want the command to search more aggressively and runtime is not a concern.

---

## Practical Rules Of Thumb

- If you get `Samples: 0`, the chosen gate set is too strict.
- If you get a high win rate but very low sample count, the result may be overfit.
- If average P&L is positive but win rate is low, the winners are probably carrying the result.
- If `ALL` returns one pipeline that is clearly better than the rest, that is the best seed candidate.

For long runs, the most important controls are:

- `--max-evaluations` for total search depth.
- `--max-gates` for how complex the final gate set can become.
- `--min-samples` for how selective the final pipeline must be.

---

## Runtime Expectations

The exhaustive search is expensive.

With large datasets, expect:

- quick tests: minutes
- overnight runs: hours
- high-evaluation `ALL` runs: potentially a day or longer

The runtime depends heavily on:

- the number of pipelines with analyzed rows
- the number of rows in `trade_alerts` and `trade_alerts_backtest_candidates`
- the number of gate variants generated for each gate
- the `--max-evaluations` cap

---

## Output File

When `--output=winner-all-3.sql` is provided, the command writes a SQL bundle containing:

1. An `INSERT INTO alert_versions` row.
2. A `SET @new_alert_version_id := LAST_INSERT_ID();` statement.
3. An `INSERT INTO alert_version_gates` block for the selected gates.

The file is meant for review and seeding, not direct live execution without inspection.

---

## Best Use Case

Use this command when you want to answer:

> “Given all the analyzed winners and losers we already have, what is the best pipeline gate set I can produce for a given P&L target?”

It is best used after you have enough historical data to support the search.
