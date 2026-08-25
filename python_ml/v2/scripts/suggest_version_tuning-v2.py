#!/usr/bin/env python3
"""Suggest tuning changes for a TradingV2 alert version.

Prerequisite:
  Run `python_ml/v2/scripts/analyze_all_picks.sh` first, or otherwise make sure
  `analyze:trade-alerts-atr-immediate` has already populated recent `trade_alerts`
  rows for the pipeline/version you want to tune.

This v2 entrypoint reuses the core tuning logic from `suggest_version_tuning.py`,
but adds a clearer preflight note when the alert sample is empty so it is easier
to see that the tuning step depends on the analysis step having run first.
"""

from __future__ import annotations

import sys

import suggest_version_tuning as base


def main() -> int:
    args = base.parse_args()
    base.load_environment()
    engine = base.make_engine()

    # Keep the win-classification threshold aligned with the analysis command.
    base.WIN_THRESHOLD = getattr(args, "win_threshold", 1.5)

    want_all = args.all or (args.version_id is None and not (args.pipeline and args.version_string))

    if want_all:
        versions = base.fetch_all_active_versions(engine)
        if not versions:
            print("No active alert versions found.")
            print("Hint: run analyze_all_picks.sh first so the tuning step has alert rows to inspect.")
            return 0

        print("=" * 70)
        print(f"Analyzing ALL {len(versions)} active alert versions")
        print("=" * 70)
        print()

        summaries: list[dict[str, object]] = []
        for version in versions:
            print("=" * 70)
            print(f"  {version.pipeline_letter} / {version.version_string} (id={version.id})")
            print("=" * 70)
            analyzed = base.analyze_one_version(engine, version, args)
            if analyzed is not None:
                summaries.append({"pipeline": version.pipeline_letter, "version": version.version_string, "trades": analyzed})

        if summaries:
            print()
            print("=" * 70)
            print("SUMMARY — analyzed alert counts per pipeline")
            print("=" * 70)
            print(base.pd.DataFrame(summaries).to_string(index=False))
            print()
            print("Recommendations above are SUGGESTIONS only — nothing was written.")
            print("Re-run a specific pipeline with --apply to persist changes.")

        return 0

    try:
        version = base.fetch_version(engine, args)
    except ValueError as exc:
        print(f"ERROR: {exc}")
        return 1

    alerts = base.fetch_alerts(engine, version, args)
    if alerts.empty:
        print(f"No scored trade_alerts found for {version.pipeline_letter}/{version.version_string} and filters.")
        print("Hint: run python_ml/v2/scripts/analyze_all_picks.sh first (or the matching analyze command)")
        print("so trade_alerts contains fresh outcomes for the tuning pass.")
        return 0

    analyzed = base.analyze_one_version(engine, version, args)

    return 0 if analyzed is not None else 1


if __name__ == "__main__":
    raise SystemExit(main())