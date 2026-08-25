# TradingV2 Pipeline Optimization

> Generated 2026-08-24 — Explains how to run the greedy and exhaustive pipeline optimizers,
> what data they use, how to tune their arguments, how to interpret the old/new output,
> and how to choose the right search strategy for a given tuning goal.

---

## Purpose

Use the pipeline optimizer commands when you want to search through the available gates and
generate a new SQL seed that represents the strongest candidate pipeline found from the data.

There are two related commands:

- `trading:build-winning-pipeline-sql` uses a greedy add-one-gate search.
- `trading:build-winning-pipeline-sql-exhaustive` uses a bounded exhaustive subset search.

Both commands are intended to:

1. Read both passing alerts and analyzed backtest candidates.
2. Evaluate gate combinations across one pipeline or all pipelines.
3. Rank the resulting candidates by average P&L, win rate, and sample count.
4. Emit SQL for the best result so you can review or seed it later.

These are **search/optimization** tools, not live trading commands.

---

## Which Command Should I Use?

### Use the greedy command when:

- You want a faster first pass.
- You want a simple explanation of which gates were kept.
- You expect only a small number of gate additions to help.
- You want to push iterations higher without exploding runtime.

### Use the exhaustive command when:

- You want to search more combinations of gates and threshold variants.
- You suspect the best answer is not reachable by a simple greedy path.
- You want to compare gate sets more thoroughly before seeding.
- You are willing to spend more time for a potentially better result.

### Practical rule

If you are asking “should I add more gates or more iterations?”, the answer is usually:

- more iterations helps the greedy command only up to the point where the search stops finding better candidates
- more gates helps only if the data supports the extra filtering without shrinking samples too far
- the exhaustive command is the better fit if your goal is “find the best gate set,” not “find the quickest acceptable gate set”

---

## Recommended Commands

### Greedy search

This is the command to start with if you want a quick optimization run:

```bash
php artisan trading:build-winning-pipeline-sql A \
  --min-samples=2000 \
  --iterations=250 \
  --target-pnl=4.0 \
  --output=winner-250it-4-0.sql
```

Use a higher `--iterations` value when you want to give the greedy search more chances to improve the gate set.

### Exhaustive search

This is the command to use when you want the broadest practical search:

```bash
php artisan trading:build-winning-pipeline-sql-exhaustive A \
  --min-samples=2000 \
  --target-pnl=4.0 \
  --max-gates=4 \
  --max-variants-per-gate=4 \
  --max-evaluations=100000 \
  --output=winner-a-exhaustive.sql
```

### What this means

- `A` tells the command to optimize Pipeline A only.
- `ALL` tells the command to evaluate every active pipeline in `alert_versions`.
- `--target-pnl=4.0` treats trades with $4\%$ or better P&L as winners during the search.
- `--min-samples=2000` rejects gate sets that leave too few passing rows.
- `--iterations=250` gives the greedy search more passes to improve the current candidate.
- `--max-gates=4` limits how many gates can be combined in a candidate set.
- `--max-variants-per-gate=4` keeps up to four threshold variants for each gate.
- `--max-evaluations=100000` caps the number of combinations evaluated per pipeline.
- `--output=winner-a-exhaustive.sql` writes the generated SQL to that file.

### When to change the defaults

- Raise `--iterations` on the greedy command if you want to keep the search simple but explore longer.
- Raise `--max-gates` on the exhaustive command if you think the best result needs a larger filter set.
- Raise `--max-variants-per-gate` when the data has several plausible threshold cutoffs and you want the command to consider more of them.
- Raise `--max-evaluations` when you have enough time to spend on a deeper search.
- Raise `--min-samples` when you want to avoid overfitting and prefer a more conservative gate set.
- Lower `--min-samples` only if you are explicitly willing to accept a tighter but less stable result.

---

## What Data It Uses

The optimizer combines two sources:

- `trade_alerts` for rows that already passed the pipeline gates and have P&L data.
- `trade_alerts_backtest_candidates` for analyzed candidates, including rejected rows that were
  still evaluated and have `pnl_percent` filled in.

That means the search is not limited to winners only. It can use:

- passed alerts
- failed gate candidates
- historical rows from multiple pipelines

The commands deduplicate rows by `dedupe_key` before evaluating them.

---

## How The Search Works

For each pipeline, the commands:

1. Loads the active version from `alert_versions`.
2. Loads all analyzed rows for that pipeline from both source tables.
3. Loads the current gate definitions from `alert_version_gates`.
4. Builds candidate threshold variants from the observed gate values.
5. Evaluates combinations of those variants up to the configured limits.
6. Filters out combinations with fewer than `--min-samples` passing rows.
7. Ranks the best result for that pipeline.
8. After all pipelines are processed, ranks the pipelines against each other and selects the best overall.

The greedy command does this by adding one gate at a time if that gate improves the current best
result. The exhaustive command does this by testing bounded combinations of gate variants and
keeping the best-scoring set it can find within the evaluation limits.

The final SQL is generated only for the top-ranked pipeline version.

---

## How To Read The Output

You will usually see two blocks, and now both commands print the same old/new comparison style:

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

The old/new comparison block is the most important part for quick judgment:

- `Original samples` / `Samples` shows how much volume was lost or preserved.
- `Original win rate` / `Win rate` shows whether the optimizer improved hit rate.
- `Original average pnl` / `Average pnl` shows whether the filter set improved quality.
- `Delta samples` shows the change in sample size.
- `Delta win rate` shows the change in win rate in percentage points.
- `Delta average pnl` shows the change in average P&L in percentage points.
- `Result: improved over the original gate set.` means the optimized average P&L beat the baseline.
- `Result: worse than the original gate set on average pnl.` means the new set regressed.
- `Result: no change versus the original gate set.` means the optimizer did not move the baseline.

Important fields:

- `Pipeline` — the pipeline letter selected as best overall.
- `Version string` — the generated version label for the new SQL insert.
- `Original samples` — the baseline sample count before optimization.
- `Original win rate` — the baseline win rate before optimization.
- `Original average pnl` — the baseline average P&L before optimization.
- `Samples` — the size of the final filtered set.
- `Win rate` — the share of passing rows that meet the target threshold.
- `Average pnl` — the average P&L for the passing set.
- `Winner average pnl` — the average P&L of the rows that exceeded the target threshold.
- `Selected gates` — how many gates were kept.
- `Delta samples` — the difference between the optimized and original sample counts.
- `Delta win rate` — the difference between the optimized and original win rates.
- `Delta average pnl` — the difference between the optimized and original average P&L.
- `Evaluations` — total evaluated combinations for that pipeline.

If `Samples` is `0`, the search became too restrictive and the final gate set filtered everything out.

---

## Recommended Tuning

### Quick sanity check with the greedy command

```bash
php artisan trading:build-winning-pipeline-sql A \
  --target-pnl=4.0 \
  --min-samples=2000 \
  --iterations=50 \
  --output=winner-a-quick.sql
```

Use this when you want a fast baseline and want to see whether the greedy search can already find a better gate set.

### Quick sanity check with the exhaustive command

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

### Greedy vs exhaustive in practice

- Start with the greedy command if you are exploring a new pipeline and want a quick signal.
- Move to the exhaustive command if the greedy command improves metrics but still seems to leave room on the table.
- If the greedy command already reaches a good sample size and strong average P&L, use that result unless the exhaustive search clearly beats it.
- If the exhaustive command produces a much smaller sample set with only a tiny metric gain, the greedy result may be the safer seed.

---

## Practical Rules Of Thumb

- If you get `Samples: 0`, the chosen gate set is too strict.
- If you get a high win rate but very low sample count, the result may be overfit.
- If average P&L is positive but win rate is low, the winners are probably carrying the result.
- If `ALL` returns one pipeline that is clearly better than the rest, that is the best seed candidate.
- If `Delta samples` is strongly negative but the P&L metrics barely improved, you may be over-filtering.
- If `Delta win rate` is positive but `Delta average pnl` is flat or negative, the gate set may be selecting more frequent but lower-quality trades.
- If the exhaustive command finds a better result with the same or similar sample count, it is usually the better seed.

For long runs, the most important controls are:

- `--max-evaluations` for total search depth.
- `--max-gates` for how complex the final gate set can become.
- `--min-samples` for how selective the final pipeline must be.
- `--iterations` for how long the greedy command keeps trying to improve the current set.

---

## Runtime Expectations

The exhaustive search is expensive, and the greedy search is lighter but still data-heavy.

With large datasets, expect:

- greedy quick tests: minutes
- exhaustive quick tests: minutes to tens of minutes
- overnight runs: hours
- high-evaluation `ALL` runs: potentially a day or longer

The runtime depends heavily on:

- the number of pipelines with analyzed rows
- the number of rows in `trade_alerts` and `trade_alerts_backtest_candidates`
- the number of gate variants generated for each gate
- the `--max-evaluations` cap
- the `--iterations` setting on the greedy command

---

## Output File

When `--output=winner-all-3.sql` is provided, the command writes a SQL bundle containing:

1. An `INSERT INTO alert_versions` row.
2. A `SET @new_alert_version_id := LAST_INSERT_ID();` statement.
3. An `INSERT INTO alert_version_gates` block for the selected gates.

The file is meant for review and seeding, not direct live execution without inspection.

Always compare the generated SQL against the old pipeline definition before applying it. The most
important things to inspect are:

- whether the selected gates still match the intended strategy
- whether the threshold changes are small, sensible adjustments or huge jumps
- whether the old/new summary shows a real improvement rather than a tiny overfit gain

---

## Best Use Case

Use these commands when you want to answer:

> “Given all the analyzed winners and losers we already have, what is the best pipeline gate set I can produce for a given P&L target?”

It is best used after you have enough historical data to support the search.

### Suggested workflow

1. Run `bash python_ml/v2/scripts/analyze_all_picks.sh` so the database has fresh analyzed rows.
2. Run the greedy command for a fast first pass.
3. If the greedy result looks promising but not ideal, run the exhaustive command.
4. Compare `Original ...`, `Delta ...`, and `Result ...` lines before deciding whether to seed the SQL.
5. Prefer the version that improves average P&L without collapsing sample size.

### What “better” usually means

The best result is not always the one with the highest win rate.

Usually, a good seed has:

- a higher average P&L than the baseline
- a win rate that is also higher or at least not worse by much
- a sample count that is still large enough to be useful
- a small, understandable gate change set
