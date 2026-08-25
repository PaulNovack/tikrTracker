#!/usr/bin/env python3
"""Suggest existing and newly discovered gate settings for a TradingV2 version.

v3 keeps the current v2 tuning behavior for existing gates, then adds a
new-gate discovery pass that looks at computed alert features which are not
currently configured as gates. It stays suggest-only by default and supports
`--apply` to write changes back to the database.

This version is designed to work from the data already emitted by the current
backtest/analyze pipeline. It does not require a backtest change for the first
pass, because the evaluated fields already exist in `trade_alerts.meta` and the
GateEvaluator outputs.
"""

from __future__ import annotations

import hashlib
import sys
import argparse
import subprocess
from dataclasses import dataclass
from pathlib import Path
from typing import Any, Optional

import numpy as np
import pandas as pd
from sqlalchemy import text

SCRIPT_DIR = Path(__file__).resolve().parent
V2_SCRIPTS_DIR = SCRIPT_DIR.parent.parent / "v2" / "scripts"
if str(V2_SCRIPTS_DIR) not in sys.path:
    sys.path.insert(0, str(V2_SCRIPTS_DIR))

import suggest_version_tuning as base  # type: ignore


ONE_MINUTE_FIELDS = {
    "notional_1m",
    "vol_ratio_1m",
    "body_pct",
    "above_vwap_entry_pct",
    "room_to_hod_pct",
    "room_to_hod_atr",
    "close_position",
    "upper_wick_fraction",
    "time_blocked",
    "extreme_drop",
    "min_bars",
    "ema9_above_ema21_1m",
    "entry_spread_strength",
    "entry_vwap_dist_score",
    "entry_atr_score",
    "entry_vol_score",
    "entry_candle_score",
    "entry_time_bonus",
    "vwap_reclaim_strength_pct",
    "vwap_reclaim_wick_below_pct",
    "or_high_v252",
    "or_break_distance_pct",
    "or_retest_depth_pct",
    "or_hold_close_pct",
    "bars_since_or_break",
    "ema9_pullback_depth_pct",
    "ema9_reclaim_pct",
    "rsi",
    "ema_spread_pct",
}

AMBIGUOUS_FIELDS = {"entry_score_min", "entry_score_max"}

NON_FEATURE_COLUMNS = {
    "id",
    "alert_version_id",
    "asset_type",
    "created_at",
    "updated_at",
    "entry",
    "entry_price",
    "entry_ts_est",
    "exit_price",
    "exit_ts_est",
    "gate",
    "gate_name",
    "is_realtime",
    "is_win",
    "meta",
    "ml_model_version",
    "ml_scored_at",
    "ml_win_prob",
    "min",
    "max",
    "pnl",
    "pnl_dollars",
    "pnl_percent",
    "query_source",
    "reason",
    "r_multiple",
    "score",
    "signal_meta",
    "signal_ts_est",
    "signal_type",
    "stop",
    "symbol",
    "timeframe",
    "trade_id",
    "trading_date_est",
    "trading_time_est",
    "ts",
    "ts_est",
    "updated_by",
    "version",
    "version_string",
    "version_id",
    "pipeline_run",
    "alert_version",
}


@dataclass(frozen=True)
class NewGateResult:
    timeframe: str
    gate_name: str
    threshold_kind: str
    gate_label: str
    current_value: Optional[float]
    suggested_value: Optional[float]
    current_trades: int
    current_win_rate: float
    suggested_trades: int
    suggested_win_rate: float
    current_avg_pnl: float
    suggested_avg_pnl: float
    current_total_pnl: float
    suggested_total_pnl: float
    source: str
    note: str


def candidate_thresholds_free(values: pd.Series, steps: int) -> list[float]:
    clean = pd.to_numeric(values, errors="coerce").dropna().astype(float)
    if clean.empty:
        return []

    quantiles = np.linspace(0.05, 0.95, max(steps, 2))
    candidates = np.unique(clean.quantile(quantiles).astype(float).to_numpy())
    return sorted({round(float(value), 6) for value in candidates if np.isfinite(value)})


def infer_timeframe(field_name: str) -> str:
    if field_name in ONE_MINUTE_FIELDS or field_name.endswith("_1m"):
        return "1m"
    return "5m"


def is_numeric_scalar(value: Any) -> bool:
    if value is None:
        return False
    if isinstance(value, (bool, np.bool_)):
        return True
    if isinstance(value, (int, float, np.integer, np.floating)):
        return True
    if isinstance(value, str):
        try:
            float(value)
        except ValueError:
            return False
        return True
    return False


def discovery_field_names(existing_gate_pairs: set[tuple[str, str]]) -> list[tuple[str, str]]:
    fields: list[tuple[str, str]] = []
    for field_name in base.FEATURE_ALIASES.keys():
        if field_name in AMBIGUOUS_FIELDS:
            continue

        timeframe = infer_timeframe(field_name)
        if (timeframe, field_name) in existing_gate_pairs:
            continue
        fields.append((timeframe, field_name))

    # Keep deterministic ordering: 5m fields first, then 1m fields.
    return sorted(fields, key=lambda item: (item[0], item[1]))


def collect_numeric_alert_columns(alerts: pd.DataFrame) -> list[tuple[str, str]]:
    candidates: list[tuple[str, str]] = []
    for column in alerts.columns:
        if column in NON_FEATURE_COLUMNS or column in AMBIGUOUS_FIELDS:
            continue
        if column.startswith("ml_") or column.startswith("pnl_"):
            continue

        series = pd.to_numeric(alerts[column], errors="coerce")
        clean = series.dropna()
        if clean.empty or clean.nunique(dropna=True) < 6:
            continue

        candidates.append((infer_timeframe(column), column))

    return candidates


def collect_numeric_meta_fields(meta_objects: list[dict[str, Any]]) -> list[str]:
    names: set[str] = set()

    def walk(obj: Any) -> None:
        if not isinstance(obj, dict):
            return

        for key, value in obj.items():
            if key in NON_FEATURE_COLUMNS or key in AMBIGUOUS_FIELDS:
                continue
            if key.startswith("ml_") or key.startswith("pnl_"):
                continue

            if isinstance(value, dict):
                walk(value)
                continue

            if is_numeric_scalar(value):
                names.add(str(key))

    for meta in meta_objects:
        walk(meta)

    return sorted(names)


def series_fingerprint(series: pd.Series) -> str:
    numeric = pd.to_numeric(series, errors="coerce").round(6)
    hashed = pd.util.hash_pandas_object(numeric.fillna(-999999.0), index=False)
    return hashlib.sha1(hashed.values.tobytes()).hexdigest()


def evaluate_new_gate_direction(
    feature: pd.Series,
    pnl: pd.Series,
    *,
    timeframe: str,
    gate_name: str,
    direction: str,
    label: str,
    steps: int,
    min_trades: int,
    min_win_lift: float,
    min_retention: float,
) -> Optional[NewGateResult]:
    feature = pd.to_numeric(feature, errors="coerce")
    clean = feature.dropna().astype(float)
    if clean.empty or clean.nunique(dropna=True) < 6:
        return None

    candidates = candidate_thresholds_free(clean, steps)
    if not candidates:
        return None

    baseline_mask = pd.Series(True, index=pnl.index)
    current_trades, current_win_rate, current_avg_pnl, _ = base.summarize_subset(pnl, baseline_mask)
    current_total_pnl = float(pnl.sum())
    retention_floor = max(min_trades, int(current_trades * min_retention))

    qualifying: list[tuple[float, int, float, float, float]] = []
    for threshold in candidates:
        if direction == "higher":
            mask = feature.isna() | (feature >= threshold)
        else:
            mask = feature.isna() | (feature <= threshold)

        trades, win_rate, avg_pnl, _ = base.summarize_subset(pnl, mask)
        total_pnl = float(pnl[mask].sum())

        if trades < retention_floor:
            continue
        if (win_rate - current_win_rate) < min_win_lift:
            continue
        if avg_pnl < current_avg_pnl:
            continue
        if total_pnl <= current_total_pnl:
            continue

        qualifying.append((threshold, trades, win_rate, avg_pnl, total_pnl))

    if not qualifying:
        return None

    best = max(
        qualifying,
        key=lambda c: (
            c[4],
            c[1],
            c[2],
            c[3],
            -c[0] if direction == "higher" else c[0],
        ),
    )

    suggested_value, suggested_trades, suggested_win_rate, suggested_avg_pnl, suggested_total_pnl = best
    if suggested_value is None:
        return None

    return NewGateResult(
        timeframe=timeframe,
        gate_name=gate_name,
        threshold_kind="min" if direction == "higher" else "max",
        gate_label=label,
        current_value=None,
        suggested_value=suggested_value,
        current_trades=current_trades,
        current_win_rate=current_win_rate,
        suggested_trades=suggested_trades,
        suggested_win_rate=suggested_win_rate,
        current_avg_pnl=current_avg_pnl,
        suggested_avg_pnl=suggested_avg_pnl,
        current_total_pnl=current_total_pnl,
        suggested_total_pnl=suggested_total_pnl,
        source=f"{timeframe}:{gate_name}",
        note=(
            f"adds profitable subset with {round((suggested_total_pnl - current_total_pnl), 3)} total pnl points "
            f"and keeps {round(suggested_trades / max(1, current_trades) * 100)}% of current trades"
        ),
    )


def discovery_profile_for(version: base.VersionRecord, args: argparse.Namespace) -> dict[str, float | int]:
    # Apply the permissive discovery profile globally so every pipeline gets
    # the same chance to surface blocked winners and profitable new gates.
    boost_mode = True

    min_win_lift = float(args.min_win_lift)
    min_retention = float(args.min_retention)
    min_trades = int(args.min_trades)
    candidate_steps = int(args.candidate_steps)

    if boost_mode:
        min_win_lift = min(min_win_lift, 0.25)
        min_retention = min(min_retention, 0.20)
        min_trades = min(min_trades, 10)
        candidate_steps = max(candidate_steps, 25)

    return {
        "boost_mode": 1 if boost_mode else 0,
        "min_win_lift": min_win_lift,
        "min_retention": min_retention,
        "min_trades": min_trades,
        "candidate_steps": candidate_steps,
    }


def use_expanded_discovery(version: base.VersionRecord) -> bool:
    return True


def load_low_volume_proxy(engine, version: base.VersionRecord, args: argparse.Namespace, total_trades: int) -> Optional[pd.DataFrame]:
    if total_trades >= 50:
        return None

    proxy = None
    if getattr(args, "rejections_log", None):
        try:
            proxy = base.load_rejections_log(
                args.rejections_log,
                pipeline=version.pipeline_letter,
                per_pipeline_cap=15000,
            )
            if proxy.empty:
                proxy = None
        except Exception:
            proxy = None

    if proxy is None:
        try:
            proxy = base.fetch_market_proxy(engine, days=max(30, min(365, args.days)), limit=3000)
        except Exception:
            proxy = None

    return proxy


def choose_proxy_opening(
    feature: pd.Series,
    pnl: pd.Series,
    *,
    timeframe: str,
    gate_name: str,
    current_value: float,
    direction: str,
    threshold_kind: str,
    label: str,
    steps: int,
    min_trades: int,
    min_win_lift: float,
    min_retention: float,
) -> Optional[base.ThresholdResult]:
    safe_result = base.evaluate_loosen_gate(
        feature,
        pnl,
        timeframe=timeframe,
        gate_name=gate_name,
        threshold_kind=threshold_kind,
        current_value=current_value,
        direction=direction,
        label=label,
        steps=steps,
        min_trades=min_trades,
        min_win_lift=min_win_lift,
        min_retention=min_retention,
    )
    if safe_result is not None:
        return safe_result

    candidates = base.candidate_thresholds(feature, current_value, direction, steps)
    if not candidates:
        return None

    if direction == "higher":
        baseline_mask = feature.isna() | (feature <= current_value)
    else:
        baseline_mask = feature.isna() | (feature >= current_value)

    current_trades, current_win_rate, current_avg_pnl, _ = base.summarize_subset(pnl, baseline_mask)
    if current_trades == 0:
        return None

    selected: Optional[tuple[float, int, float, float]] = None
    for candidate in reversed(candidates):
        if candidate == current_value:
            continue

        if direction == "higher":
            mask = feature.isna() | (feature <= candidate)
        else:
            mask = feature.isna() | (feature >= candidate)

        trades, win_rate, avg_pnl, _ = base.summarize_subset(pnl, mask)
        if trades < min_trades:
            continue

        if selected is None:
            selected = (candidate, trades, win_rate, avg_pnl)

        if trades > current_trades and win_rate >= current_win_rate and avg_pnl >= current_avg_pnl:
            selected = (candidate, trades, win_rate, avg_pnl)
            break

    if selected is None:
        return None

    suggested_value, suggested_trades, suggested_win_rate, suggested_avg_pnl = selected
    return base.ThresholdResult(
        timeframe=timeframe,
        gate_name=gate_name,
        threshold_kind=threshold_kind,
        gate_label=label,
        current_value=current_value,
        suggested_value=suggested_value,
        current_trades=current_trades,
        current_win_rate=current_win_rate,
        suggested_trades=suggested_trades,
        suggested_win_rate=suggested_win_rate,
        current_avg_pnl=current_avg_pnl,
        suggested_avg_pnl=suggested_avg_pnl,
        note=f"proxy-driven loosen to reopen low-volume pipeline (keeps {round(suggested_trades / max(1, current_trades) * 100)}% of proxy trades)",
    )


def suggest_low_volume_openings(
    engine,
    version: base.VersionRecord,
    args: argparse.Namespace,
) -> list[base.ThresholdResult]:
    alerts = base.fetch_alerts(engine, version, args)
    total_trades = int(alerts.shape[0])
    proxy = load_low_volume_proxy(engine, version, args, total_trades)
    if proxy is None or proxy.empty:
        return []

    gates = base.fetch_gates(engine, version.id)
    proxy_meta = [base.parse_json_object(value) for value in proxy["meta"]] if "meta" in proxy.columns else []
    proxy_pnl = pd.to_numeric(proxy["pnl_percent"], errors="coerce").fillna(0.0)

    results: list[base.ThresholdResult] = []
    for gate in gates:
        if not gate.enabled or gate.gate_name == "room_atr_mult":
            continue

        feature = base.resolve_series(proxy, proxy_meta, base.gate_feature_name(gate.gate_name))
        if feature is None:
            continue

        feature = pd.to_numeric(feature, errors="coerce")

        if gate.threshold_min is not None:
            baseline_mask = feature.isna() | (feature >= float(gate.threshold_min))
            blocked_pct = round((~baseline_mask).sum() / max(1, len(proxy)) * 100, 1)
            if blocked_pct >= 50.0:
                result = choose_proxy_opening(
                    feature,
                    proxy_pnl,
                    timeframe=gate.timeframe,
                    gate_name=gate.gate_name,
                    current_value=float(gate.threshold_min),
                    direction="lower",
                    threshold_kind="min",
                    label=f"{gate.timeframe} {gate.gate_name} [min] OPEN",
                    steps=max(int(args.candidate_steps), 25),
                    min_trades=max(3, min(10, int(len(proxy) * 0.05))),
                    min_win_lift=0.0,
                    min_retention=0.10,
                )
                if result is not None:
                    results.append(result)

        if gate.threshold_max is not None:
            baseline_mask = feature.isna() | (feature <= float(gate.threshold_max))
            blocked_pct = round((~baseline_mask).sum() / max(1, len(proxy)) * 100, 1)
            if blocked_pct >= 50.0:
                result = choose_proxy_opening(
                    feature,
                    proxy_pnl,
                    timeframe=gate.timeframe,
                    gate_name=gate.gate_name,
                    current_value=float(gate.threshold_max),
                    direction="higher",
                    threshold_kind="max",
                    label=f"{gate.timeframe} {gate.gate_name} [max] OPEN",
                    steps=max(int(args.candidate_steps), 25),
                    min_trades=max(3, min(10, int(len(proxy) * 0.05))),
                    min_win_lift=0.0,
                    min_retention=0.10,
                )
                if result is not None:
                    results.append(result)

    return results


def apply_low_volume_openings(
    engine,
    version: base.VersionRecord,
    openings: list[base.ThresholdResult],
) -> list[str]:
    applied: list[str] = []
    if not openings:
        return applied

    with engine.begin() as connection:
        for result in openings:
            updated_rows = base.update_gate_threshold(connection, version.id, result)
            if updated_rows > 0:
                applied.append(
                    f"alert_version_gates[{result.timeframe}:{result.gate_name}:{result.threshold_kind}]={base.format_db_number(float(result.suggested_value))}"
                )

    return applied


def discover_new_gates(
    engine,
    version: base.VersionRecord,
    args: argparse.Namespace,
) -> list[NewGateResult]:
    alerts = base.fetch_alerts(engine, version, args)
    if alerts.empty:
        return []

    pnl = pd.to_numeric(alerts["pnl_percent"], errors="coerce").fillna(0.0)
    meta_objects = [base.parse_json_object(value) for value in alerts["meta"]] if "meta" in alerts.columns else []
    gates = base.fetch_gates(engine, version.id)
    existing_gate_pairs = {(gate.timeframe, gate.gate_name) for gate in gates}
    seen_fingerprints: set[str] = set()
    profile = discovery_profile_for(version, args)

    field_candidates = discovery_field_names(existing_gate_pairs)
    if use_expanded_discovery(version):
        field_candidates.extend(collect_numeric_alert_columns(alerts))
        for meta_field in collect_numeric_meta_fields(meta_objects):
            field_candidates.append((infer_timeframe(meta_field), meta_field))

    deduped_candidates: list[tuple[str, str]] = []
    seen_pairs: set[tuple[str, str]] = set()
    for item in field_candidates:
        if item in seen_pairs:
            continue
        seen_pairs.add(item)
        deduped_candidates.append(item)

    results: list[NewGateResult] = []
    for timeframe, field_name in deduped_candidates:
        feature = base.resolve_series(alerts, meta_objects, base.gate_feature_name(field_name))
        if feature is None:
            continue

        fingerprint = series_fingerprint(feature)
        if fingerprint in seen_fingerprints:
            continue
        seen_fingerprints.add(fingerprint)

        if pd.to_numeric(feature, errors="coerce").dropna().nunique() < 6:
            continue

        higher = evaluate_new_gate_direction(
            feature,
            pnl,
            timeframe=timeframe,
            gate_name=field_name,
            direction="higher",
            label=f"{timeframe} {field_name} [new-min]",
            steps=int(profile["candidate_steps"]),
            min_trades=int(profile["min_trades"]),
            min_win_lift=float(profile["min_win_lift"]),
            min_retention=float(profile["min_retention"]),
        )
        lower = evaluate_new_gate_direction(
            feature,
            pnl,
            timeframe=timeframe,
            gate_name=field_name,
            direction="lower",
            label=f"{timeframe} {field_name} [new-max]",
            steps=int(profile["candidate_steps"]),
            min_trades=int(profile["min_trades"]),
            min_win_lift=float(profile["min_win_lift"]),
            min_retention=float(profile["min_retention"]),
        )

        candidates = [candidate for candidate in (higher, lower) if candidate is not None]
        if not candidates:
            continue

        best = max(
            candidates,
            key=lambda c: (
                c.suggested_total_pnl - c.current_total_pnl,
                c.suggested_trades,
                c.suggested_win_rate,
                c.suggested_avg_pnl,
            ),
        )
        results.append(best)

    results.sort(
        key=lambda c: (
            c.suggested_total_pnl - c.current_total_pnl,
            c.suggested_win_rate,
            c.suggested_trades,
        ),
        reverse=True,
    )
    return results


def print_new_gate_report(results: list[NewGateResult], version_label: str) -> None:
    print("-" * 70)
    print(f"NEW GATE DISCOVERY — {version_label}")
    print("Looks for computed alert fields that are not already gates and sweeps")
    print("quantile thresholds to find profitable additions.")
    print("-" * 70)

    if not results:
        print("  No new-gate candidate met the constraints.")
        return

    rows: list[dict[str, Any]] = []
    for result in results:
        total_delta = result.suggested_total_pnl - result.current_total_pnl
        rows.append(
            {
                "gate": result.gate_label,
                "current": "none",
                "suggested": base.format_number(result.suggested_value, 4),
                "current_trades": result.current_trades,
                "suggested_trades": result.suggested_trades,
                "current_win_rate": f"{result.current_win_rate:.2f}%",
                "suggested_win_rate": f"{result.suggested_win_rate:.2f}%",
                "current_avg_pnl": f"{result.current_avg_pnl:.3f}%",
                "suggested_avg_pnl": f"{result.suggested_avg_pnl:.3f}%",
                "delta_total_pnl": f"{total_delta:+.3f}%",
                "source": result.source,
                "note": result.note,
            }
        )

    report = pd.DataFrame(rows)
    print(report.to_string(index=False))
    print()
    print("  These are SUGGESTIONS only — review them before applying.")
    print("  New gates are only proposed when they improve total P&L on the")
    print("  current analyzed sample and keep enough trades to matter.")


def apply_new_gates(engine, version: base.VersionRecord, results: list[NewGateResult]) -> list[str]:
    applied: list[str] = []
    if not results:
        return applied

    with engine.begin() as connection:
        for result in results:
            if result.suggested_value is None:
                continue

            gate_name_lower = result.gate_name.lower()
            if gate_name_lower.endswith("ts_est") or gate_name_lower.endswith("_ts") or gate_name_lower.endswith("_at"):
                continue

            if abs(float(result.suggested_value)) > 1_000_000:
                continue

            if result.threshold_kind == "min":
                threshold_min = result.suggested_value
                threshold_max = None
            else:
                threshold_min = None
                threshold_max = result.suggested_value

            connection.execute(
                text(
                    """
                    INSERT INTO alert_version_gates
                        (alert_version_id, timeframe, gate_name, threshold_min, threshold_max, enabled, created_at, updated_at)
                    VALUES
                        (:version_id, :timeframe, :gate_name, :threshold_min, :threshold_max, :enabled, NOW(), NOW())
                    ON DUPLICATE KEY UPDATE
                        threshold_min = VALUES(threshold_min),
                        threshold_max = VALUES(threshold_max),
                        enabled = VALUES(enabled),
                        updated_at = NOW()
                    """
                ),
                {
                    "version_id": version.id,
                    "timeframe": result.timeframe,
                    "gate_name": result.gate_name,
                    "threshold_min": threshold_min,
                    "threshold_max": threshold_max,
                    "enabled": True,
                },
            )
            applied.append(
                f"alert_version_gates[{result.timeframe}:{result.gate_name}:{result.threshold_kind}]={base.format_db_number(float(result.suggested_value))}"
            )

    return applied


def handle_version(engine, version: base.VersionRecord, args: argparse.Namespace) -> None:
    print("=" * 70)
    print(f"{version.pipeline_letter} / {version.version_string} (id={version.id}) — v3 discovery")
    print("=" * 70)
    print()

    profile = discovery_profile_for(version, args)
    if int(profile["boost_mode"]) == 1:
        print("Boost profile enabled for this pipeline: discovery is more permissive")
        print("(lower min-win-lift, lower retention floor, and more candidate steps).")
        print("Expanded field discovery is enabled for all pipelines so blocked winners")
        print("and profitable new gates can be surfaced consistently.")
        print()

    # Reuse the current v2 analysis for existing gates and ML threshold tuning.
    base.analyze_one_version(engine, version, args)

    low_volume_openings = suggest_low_volume_openings(engine, version, args)
    if low_volume_openings:
        print("-" * 70)
        print("LOW-VOLUME OPENINGS:")
        for opening in low_volume_openings:
            print(
                f"  - {opening.gate_label}: {base.format_number(opening.current_value, 4)} -> {base.format_number(opening.suggested_value, 4)} "
                f"(proxy trades {opening.current_trades} -> {opening.suggested_trades})"
            )

        if args.apply and not args.dry_run:
            applied = apply_low_volume_openings(engine, version, low_volume_openings)
            if applied:
                print("  Applied low-volume openings:")
                for item in applied:
                    print(f"    - {item}")
            else:
                print("  No low-volume openings were written.")
        else:
            print("  Suggest-only mode — no low-volume openings were written.")

    # Add new-gate discovery on top.
    new_gate_results = discover_new_gates(engine, version, args)
    print_new_gate_report(new_gate_results, f"{version.pipeline_letter}/{version.version_string}")

    if args.apply and not args.dry_run:
        applied = apply_new_gates(engine, version, new_gate_results)
        print("-" * 70)
        if applied:
            print("New gate database updates applied:")
            for item in applied:
                print(f"  - {item}")
        else:
            print("New gate database updates: no changes were applied")

        # Keep the version cache fresh for live/backtest reads.
        try:
            subprocess.run(
                ["php", "artisan", "cache:forget", "rt:config:tradingv2:versions"],
                cwd=str(base.REPO_ROOT),
                check=False,
            )
        except Exception:
            pass
    else:
        print("-" * 70)
        print("Suggest-only mode — NO database changes were made for new gates.")
        print("To apply these suggestions, re-run with --apply.")


def main() -> int:
    args = base.parse_args()
    base.load_environment()
    engine = base.make_engine()

    base.WIN_THRESHOLD = getattr(args, "win_threshold", 1.5)

    want_all = args.all or (args.version_id is None and not (args.pipeline and args.version_string))

    if want_all:
        versions = base.fetch_all_active_versions(engine)
        if not versions:
            print("No active alert versions found.")
            print("Hint: run analyze_all_picks.sh first so the tuner has alert rows to inspect.")
            return 0

        print("=" * 70)
        print(f"Analyzing ALL {len(versions)} active alert versions (v3 discovery)")
        print("=" * 70)
        print()

        summaries: list[dict[str, object]] = []
        for version in versions:
            handle_version(engine, version, args)
            summaries.append({"pipeline": version.pipeline_letter, "version": version.version_string})

        if summaries:
            print()
            print("=" * 70)
            print("SUMMARY — analyzed versions")
            print("=" * 70)
            print(pd.DataFrame(summaries).to_string(index=False))
            print()
            print("Recommendations above are SUGGESTIONS only — nothing was written unless --apply was used.")
        return 0

    try:
        version = base.fetch_version(engine, args)
    except ValueError as exc:
        print(f"ERROR: {exc}")
        return 1

    handle_version(engine, version, args)
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
