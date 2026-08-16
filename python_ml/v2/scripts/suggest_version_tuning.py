#!/usr/bin/env python3
"""Suggest tighter ML threshold and gate settings for a version.

This script analyzes scored `trade_alerts` rows for one alert version and
compares them with the version's configured gates and ML threshold settings.
It only evaluates changes that can be inferred from the existing alert sample.

Important limitation:
The gate analysis works from alerts that already passed the current version's
filters, so it can safely recommend tighter settings that improve win rate on
the current population. It cannot reliably evaluate loosening gates because the
excluded alerts are not present in the table.
"""

from __future__ import annotations

import argparse
import json
import os
from dataclasses import dataclass
from pathlib import Path
from typing import Any, Optional

import numpy as np
import pandas as pd
from dotenv import load_dotenv
from sqlalchemy import create_engine, text


REPO_ROOT = Path(__file__).resolve().parents[3]
ENV_PATH = REPO_ROOT / ".env"


FEATURE_ALIASES: dict[str, list[str]] = {
    # 5m scanner gates
    "entry_score_min": ["score"],
    "entry_score_max": ["score"],
    "notional": ["notional", "notional_last5m"],
    "price": ["price", "entry", "entry_price"],
    "atr": ["atr", "atr_pct_5m"],
    "atr_pct": ["atr_pct", "atr_pct_5m"],
    "rvol_ratio": ["rvol_ratio", "rvol_5m", "entry_volume_ratio"],
    "move_30m_pct": ["move_30m_pct"],
    "move_from_open_pct": ["move_from_open_pct"],
    "net_progress_pct": ["five_min_net_progress", "net_progress_pct"],
    "three_bar_gain_pct": ["three_bar_gain_pct"],
    "move_rvol_composite": ["move_rvol_composite"],
    "rs_ratio": ["rs_ratio"],
    "higher_low_count": ["higher_low_count"],
    "closes_near_high_count": ["closes_near_high_count"],
    "vwap_violation_count": ["vwap_violation_count"],
    "green_close": ["green_close"],
    "above_vwap": ["above_vwap"],
    "above_vwap_pct": ["above_vwap_pct"],
    "vwap_distance_min": ["vwap_distance_min"],
    "max_above_vwap_pct": ["max_above_vwap_pct"],
    "ema9_above_ema21": ["ema9_above_ema21", "ema9_above_ema21_1m"],
    "ema9_slope_positive": ["ema9_slope_positive"],
    "ema_spread_pct": ["ema_spread_pct", "ema9_ema21_spread"],
    "green_bar_pct": ["green_bar_pct", "five_min_green_bar_pct"],
    "directional_changes": ["directional_changes", "five_min_directional_changes"],
    "directional_changes_max": ["directional_changes", "five_min_directional_changes"],
    "pullback_depth_pct": ["pullback_depth_pct", "ema9_pullback_depth_pct"],
    "range_contraction": ["range_contraction"],
    "distance_from_high_atr": ["distance_from_high_atr"],
    "dist_to_hod_pct": ["dist_to_hod_pct", "pct_below_intraday_high"],
    "opening_range_width_pct": ["opening_range_width_pct"],
    "opening_range_bar_count": ["opening_range_bar_count"],
    "breakout_detected": ["breakout_volume_ratio"],
    "consolidation_bars": ["consolidation_bars"],
    "breakout_volume_ratio": ["breakout_volume_ratio"],
    "rsi": ["rsi", "rsi_14_1m"],
    "benchmark_below_vwap": ["benchmark_below_vwap"],
    "benchmark_move_15m": ["benchmark_move_15m", "spy_move_30m_pct"],
    "market_weakness": ["market_weakness"],
    "multi_day_green_count": ["multi_day_green_count"],
    "yesterday_move_pct": ["yesterday_move_pct"],
    "yesterday_vol_mult": ["yesterday_vol_mult"],
    "daily_trend_5d_pct": ["daily_trend_5d_pct"],
    "range_position_60m": ["range_position_60m"],
    "pct_nd": ["pct_nd"],
    "universe_size": ["universe_size"],
    # 1m entry gates
    "notional_1m": ["entry_notional_1m", "notional_1m"],
    "vol_ratio_1m": ["vol_ratio_1m", "entry_volume_ratio", "rvol_ratio"],
    "body_pct": ["body_pct", "entry_body_pct"],
    "above_vwap_entry_pct": ["above_vwap_entry_pct", "above_vwap_pct"],
    "room_to_hod_pct": ["room_to_hod_pct"],
    "room_to_hod_atr": ["room_to_hod_atr"],
    "close_position": ["entry_close_position", "close_position"],
    "upper_wick_fraction": ["upper_wick_fraction"],
    "time_blocked": ["time_blocked"],
    "extreme_drop": ["extreme_drop"],
    "min_bars": ["min_bars"],
    "ema9_above_ema21_1m": ["ema9_above_ema21_1m", "ema9_above_ema21"],
    # Entry score sub-components (already stored as columns)
    "entry_spread_strength": ["entry_spread_strength"],
    "entry_vwap_dist_score": ["entry_vwap_dist_score"],
    "entry_atr_score": ["entry_atr_score"],
    "entry_vol_score": ["entry_vol_score"],
    "entry_candle_score": ["entry_candle_score"],
    "entry_time_bonus": ["entry_time_bonus"],
    # VWAP reclaim / ORB / EMA9 pullback specific
    "vwap_reclaim_strength_pct": ["vwap_reclaim_strength_pct"],
    "vwap_reclaim_wick_below_pct": ["vwap_reclaim_wick_below_pct"],
    "or_high_v252": ["or_high_v252"],
    "or_break_distance_pct": ["or_break_distance_pct"],
    "or_retest_depth_pct": ["or_retest_depth_pct"],
    "or_hold_close_pct": ["or_hold_close_pct"],
    "bars_since_or_break": ["bars_since_or_break"],
    "ema9_pullback_depth_pct": ["ema9_pullback_depth_pct"],
    "ema9_reclaim_pct": ["ema9_reclaim_pct"],
}


@dataclass(frozen=True)
class VersionRecord:
    id: int
    pipeline_letter: str
    version_string: str
    signal_type: str
    scanner_score_formula: Optional[str]
    enabled: bool


@dataclass(frozen=True)
class GateRecord:
    gate_name: str
    timeframe: str
    threshold_min: Optional[float]
    threshold_max: Optional[float]
    enabled: bool


@dataclass(frozen=True)
class ThresholdResult:
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
    note: str


def load_environment() -> None:
    if ENV_PATH.exists():
        load_dotenv(dotenv_path=ENV_PATH, override=False)


def make_engine():
    load_environment()
    host = os.environ.get("DB_HOST", "127.0.0.1")
    port = int(os.environ.get("DB_PORT", "3306"))
    name = os.environ.get("DB_DATABASE", "laravelInvest")
    user = os.environ.get("DB_USERNAME", "laravel")
    password = os.environ.get("DB_PASSWORD", "laravel")
    url = f"mysql+pymysql://{user}:{password}@{host}:{port}/{name}"

    return create_engine(url, pool_pre_ping=True)


def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser(
        description="Suggest tighter settings and gates for a version from scored trade_alerts.\n"
        "With no --pipeline/--version/--version-id, analyzes ALL active versions and prints a summary report.",
    )
    parser.add_argument("--version-id", type=int, help="alert_versions.id to analyze")
    parser.add_argument("--pipeline", help="Pipeline letter, e.g. G")
    parser.add_argument("--version", dest="version_string", help="Version string, e.g. v35.0")
    parser.add_argument("--days", type=int, default=600, help="Lookback window in days (default 600)")
    parser.add_argument("--from", dest="from_date", help="Start trading_date_est (YYYY-MM-DD)")
    parser.add_argument("--to", dest="to_date", help="End trading_date_est (YYYY-MM-DD)")
    parser.add_argument("--limit", type=int, default=200000, help="Maximum rows to analyze")
    parser.add_argument("--min-trades", type=int, default=20, help="Minimum rows required for a recommendation")
    parser.add_argument("--candidate-steps", type=int, default=15, help="Quantile steps to test per gate")
    parser.add_argument("--min-win-lift", type=float, default=1.0, help="Minimum win-rate lift in percentage points")
    parser.add_argument(
        "--min-retention",
        type=float,
        default=0.5,
        help="Keep at least this fraction of current trades when suggesting a tighter threshold "
        "(0.5 = never suggest a change that drops more than half the alerts). Prevents over-tightening.",
    )
    parser.add_argument(
        "--apply",
        action="store_true",
        help="Actually write the recommendations to the database. WITHOUT this flag the script "
        "only prints suggestions (no DB writes).",
    )
    parser.add_argument(
        "--dry-run",
        action="store_true",
        help="Compatibility alias — kept for old invocations. Same as not passing --apply (suggest-only).",
    )
    parser.add_argument(
        "--all",
        action="store_true",
        help="Analyze ALL active versions and print a summary report (default when no version is specified).",
    )
    parser.add_argument(
        "--rejections-log",
        help="Path to the gate-rejections.log JSON-lines file from a backtest run. "
        "When provided, the small-sample 'top blockers' report uses the actual "
        "rejected-candidate bars from this log instead of the biased market_proxy "
        "(alerts that passed some other pipeline).",
    )
    parser.add_argument(
        "--win-threshold",
        type=float,
        default=1.5,
        help="Minimum pnl_percent to count as a WIN (default 1.5). Anything below "
        "this — including any small positive — counts as a LOSS. Must match the "
        "analyze:trade-alerts-atr-immediate --win-threshold value.",
    )

    return parser.parse_args()


def read_one_value(engine, sql: str, params: dict[str, Any]) -> Optional[str]:
    with engine.connect() as connection:
        result = connection.execute(text(sql), params).fetchone()

    if result is None:
        return None

    value = result[0]
    return None if value is None else str(value)


def fetch_version(engine, args: argparse.Namespace) -> VersionRecord:
    if args.version_id is not None:
        query = text(
            """
            SELECT id, pipeline_letter, version_string, signal_type, scanner_score_formula, enabled
            FROM alert_versions
            WHERE id = :version_id
            LIMIT 1
            """
        )
        params = {"version_id": args.version_id}
    else:
        if not args.pipeline or not args.version_string:
            raise ValueError("Provide either --version-id or both --pipeline and --version.")

        query = text(
            """
            SELECT id, pipeline_letter, version_string, signal_type, scanner_score_formula, enabled
            FROM alert_versions
            WHERE pipeline_letter = :pipeline AND version_string = :version_string
            LIMIT 1
            """
        )
        params = {"pipeline": args.pipeline.upper(), "version_string": args.version_string}

    with engine.connect() as connection:
        row = connection.execute(query, params).mappings().first()

    if row is None:
        raise ValueError("Could not find the requested alert version.")

    return VersionRecord(
        id=int(row["id"]),
        pipeline_letter=str(row["pipeline_letter"]),
        version_string=str(row["version_string"]),
        signal_type=str(row["signal_type"]),
        scanner_score_formula=row["scanner_score_formula"],
        enabled=bool(row["enabled"]),
    )


def fetch_all_active_versions(engine) -> list[VersionRecord]:
    query = text(
        """
        SELECT id, pipeline_letter, version_string, signal_type, scanner_score_formula, enabled
        FROM alert_versions
        WHERE enabled = 1
        ORDER BY pipeline_letter
        """
    )
    with engine.connect() as connection:
        rows = connection.execute(query).mappings().all()

    return [
        VersionRecord(
            id=int(row["id"]),
            pipeline_letter=str(row["pipeline_letter"]),
            version_string=str(row["version_string"]),
            signal_type=str(row["signal_type"]),
            scanner_score_formula=row["scanner_score_formula"],
            enabled=bool(row["enabled"]),
        )
        for row in rows
    ]


def fetch_gates(engine, version_id: int) -> list[GateRecord]:
    query = text(
        """
        SELECT gate_name, timeframe, threshold_min, threshold_max, enabled
        FROM alert_version_gates
        WHERE alert_version_id = :version_id
        ORDER BY timeframe, gate_name
        """
    )

    with engine.connect() as connection:
        rows = connection.execute(query, {"version_id": version_id}).mappings().all()

    gates: list[GateRecord] = []
    for row in rows:
        gates.append(
            GateRecord(
                gate_name=str(row["gate_name"]),
                timeframe=str(row["timeframe"]),
                threshold_min=float(row["threshold_min"]) if row["threshold_min"] is not None else None,
                threshold_max=float(row["threshold_max"]) if row["threshold_max"] is not None else None,
                enabled=bool(row["enabled"]),
            )
        )

    return gates


def fetch_current_thresholds(engine, pipeline_letter: str) -> dict[str, Optional[float]]:
    pipeline = pipeline_letter.lower()
    keys = {
        "baseline": f"trading.pipeline_{pipeline}.ml_threshold",
        "override": f"trading.pipeline_{pipeline}.ml_threshold_override",
        "global": "trading.ml_threshold.global",
    }

    values: dict[str, Optional[float]] = {}
    for label, key in keys.items():
        raw = read_one_value(
            engine,
            "SELECT value FROM settings WHERE name = :name ORDER BY updated_at DESC, id DESC LIMIT 1",
            {"name": key},
        )
        try:
            values[label] = float(raw) if raw is not None else None
        except ValueError:
            values[label] = None

    effective = values["override"] if values["override"] is not None else values["baseline"]
    if effective is None:
        effective = values["global"]

    values["effective"] = effective
    return values


def fetch_alerts(engine, version: VersionRecord, args: argparse.Namespace) -> pd.DataFrame:
    base_sql = [
        "SELECT *",
        "FROM trade_alerts",
        "WHERE pipeline_run = :pipeline_run",
        "  AND version = :version_string",
        "  AND pnl_percent IS NOT NULL",
    ]
    params: dict[str, Any] = {
        "pipeline_run": version.pipeline_letter,
        "version_string": version.version_string,
    }

    if args.from_date and args.to_date:
        base_sql.append("  AND trading_date_est BETWEEN :from_date AND :to_date")
        params["from_date"] = args.from_date
        params["to_date"] = args.to_date
    elif args.from_date:
        base_sql.append("  AND trading_date_est >= :from_date")
        params["from_date"] = args.from_date
    elif args.to_date:
        base_sql.append("  AND trading_date_est <= :to_date")
        params["to_date"] = args.to_date
    else:
        base_sql.append("  AND trading_date_est >= DATE_SUB(CURDATE(), INTERVAL :days DAY)")
        params["days"] = args.days

    base_sql.append("ORDER BY trading_date_est ASC, id ASC")
    if args.limit > 0:
        base_sql.append("LIMIT :limit")
        params["limit"] = args.limit

    query = text("\n".join(base_sql))
    return pd.read_sql_query(query, engine, params=params)


def parse_json_object(value: Any) -> dict[str, Any]:
    if not isinstance(value, str) or not value.strip():
        return {}

    try:
        parsed = json.loads(value)
    except json.JSONDecodeError:
        return {}

    return parsed if isinstance(parsed, dict) else {}


def resolve_series(df: pd.DataFrame, meta_objects: list[dict[str, Any]], names: list[str]) -> Optional[pd.Series]:
    # 1. Direct column match first.
    for name in names:
        if name in df.columns:
            return pd.to_numeric(df[name], errors="coerce")

    if not meta_objects:
        return None

    # 2. Look in the meta JSON. Values may live at the top level of meta,
    #    OR nested inside meta.signal_meta / meta.entry_meta.
    #    Check nested objects first since they hold the raw gate values.
    extracted = []
    for meta in meta_objects:
        value = None

        # Search nested objects (signal_meta, entry_meta) first — they hold the
        # raw GateEvaluator values that aren't stored as trade_alerts columns.
        for nested_key in ("signal_meta", "entry_meta"):
            nested = meta.get(nested_key) if isinstance(meta, dict) else None
            if not isinstance(nested, dict):
                continue
            for name in names:
                if name in nested:
                    value = nested[name]
                    break
            if value is not None:
                break

        # Fall back to the top level of the meta object.
        if value is None:
            for name in names:
                if name in meta:
                    value = meta[name]
                    break

        extracted.append(value)

    if not any(item is not None for item in extracted):
        return None

    return pd.to_numeric(pd.Series(extracted, index=df.index), errors="coerce")


def format_number(value: Optional[float], decimals: int = 3) -> str:
    if value is None:
        return "n/a"
    return f"{value:.{decimals}f}"


def format_db_number(value: float) -> str:
    return f"{value:.6f}"


# Win threshold used for classification (set once from --win-threshold).
# Matches analyze:trade-alerts-atr-immediate so win rates are consistent.
WIN_THRESHOLD: float = 1.5


def summarize_subset(pnl_series: pd.Series, mask: pd.Series) -> tuple[int, float, float, float]:
    selected = pnl_series[mask].astype(float)
    trades = int(selected.shape[0])
    if trades == 0:
        return 0, 0.0, 0.0, 0.0

    wins = int((selected >= WIN_THRESHOLD).sum())
    win_rate = (wins / trades) * 100.0
    avg_pnl = float(selected.mean())
    median_pnl = float(selected.median())
    return trades, win_rate, avg_pnl, median_pnl


def candidate_thresholds(values: pd.Series, current_value: float, direction: str, steps: int) -> list[float]:
    clean = values.dropna().astype(float)
    if clean.empty:
        return []

    quantiles = np.linspace(0.05, 0.95, max(steps, 2))
    candidates = np.unique(clean.quantile(quantiles).astype(float).to_numpy())
    candidates = [float(value) for value in candidates if np.isfinite(value)]

    if direction == "higher":
        candidates = [value for value in candidates if value >= current_value]
        if current_value not in candidates:
            candidates.append(current_value)
        return sorted(set(round(value, 6) for value in candidates), reverse=True)

    if direction == "lower":
        candidates = [value for value in candidates if value <= current_value]
        if current_value not in candidates:
            candidates.append(current_value)
        return sorted(set(round(value, 6) for value in candidates))

    raise ValueError(f"Unknown direction: {direction}")


def evaluate_numeric_gate(
    feature: pd.Series,
    pnl: pd.Series,
    *,
    timeframe: str,
    gate_name: str,
    threshold_kind: str,
    current_value: float,
    direction: str,
    label: str,
    steps: int,
    min_trades: int,
    min_win_lift: float,
    min_retention: float = 0.5,
) -> Optional[ThresholdResult]:
    candidates = candidate_thresholds(feature, current_value, direction, steps)
    if not candidates:
        return None

    if direction == "higher":
        baseline_mask = feature.isna() | (feature >= current_value)
    else:
        baseline_mask = feature.isna() | (feature <= current_value)

    current_trades, current_win_rate, current_avg_pnl, _ = summarize_subset(pnl, baseline_mask)
    if current_trades == 0:
        return None

    # Retention floor: a suggestion must keep at least this fraction of the
    # current trades (and at least min_trades). This prevents the classic
    # over-tightening failure where a threshold is chosen that keeps only a
    # handful of lucky trades but decimates the alert count.
    retention_floor = max(min_trades, int(current_trades * min_retention))

    # Collect ALL candidates that satisfy the hard constraints:
    #   - keeps at least the retention floor of trades
    #   - improves win rate by at least min_win_lift (quality)
    #   - does not lower average PnL
    qualifying: list[tuple[float, int, float, float]] = []
    for threshold in candidates:
        if direction == "higher":
            mask = feature.isna() | (feature >= threshold)
        else:
            mask = feature.isna() | (feature <= threshold)

        trades, win_rate, avg_pnl, _ = summarize_subset(pnl, mask)

        if trades < retention_floor:
            continue
        if (win_rate - current_win_rate) < min_win_lift:
            continue
        if avg_pnl < current_avg_pnl:
            continue

        qualifying.append((threshold, trades, win_rate, avg_pnl))

    if not qualifying:
        return None

    # Choose the LEAST destructive candidate first: among qualifying thresholds,
    # prefer the one that keeps the MOST trades (highest retention). Only tie-break
    # by win rate, then avg PnL, then the milder threshold. This guarantees a
    # suggestion never blocks everything — it keeps as many alerts as possible
    # while still achieving the quality lift.
    best = max(qualifying, key=lambda c: (c[1], c[2], c[3], -c[0] if direction == "higher" else c[0]))

    suggested_value, suggested_trades, suggested_win_rate, suggested_avg_pnl = best
    if suggested_value == current_value:
        return None

    return ThresholdResult(
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
        note=f"tighten {'minimum' if direction == 'higher' else 'maximum'} threshold "
        f"(keeps {round(suggested_trades / max(1, current_trades) * 100)}% of current trades)",
    )


def evaluate_loosen_gate(
    feature: pd.Series,
    pnl: pd.Series,
    *,
    timeframe: str,
    gate_name: str,
    threshold_kind: str,
    current_value: float,
    direction: str,
    label: str,
    steps: int,
    min_trades: int,
    min_win_lift: float,
    min_retention: float = 0.5,
) -> Optional[ThresholdResult]:
    """Suggest LOOSENING a gate that is currently blocking winners.

    direction here describes how we RELAX the gate:
      - "lower" for a minimum threshold (min currently too high → lower it)
      - "higher" for a maximum threshold (max currently too low → raise it)

    Only proposes a looser threshold that (a) adds enough blocked winners back
    to materially improve overall win rate / avg PnL, and (b) keeps the gate
    meaningful (does not remove it entirely unless that's clearly better).
    """
    candidates = candidate_thresholds(feature, current_value, direction, steps)
    if not candidates:
        return None

    if direction == "higher":  # relaxing a MAX: threshold_max raised
        baseline_mask = feature.isna() | (feature <= current_value)
    else:  # relaxing a MIN: threshold_min lowered
        baseline_mask = feature.isna() | (feature >= current_value)

    current_trades, current_win_rate, current_avg_pnl, _ = summarize_subset(pnl, baseline_mask)
    if current_trades == 0:
        return None

    # We want the looser threshold to capture more trades. Target: at least the
    # current count plus a meaningful fraction of what was blocked.
    loosened_floor = current_trades + max(min_trades, int((pnl.shape[0] - current_trades) * (1 - min_retention)))

    qualifying: list[tuple[float, int, float, float]] = []
    for threshold in candidates:
        if direction == "higher":  # max gate: threshold_max <= threshold passes
            mask = feature.isna() | (feature <= threshold)
        else:  # min gate: threshold_min >= threshold passes
            mask = feature.isna() | (feature >= threshold)

        trades, win_rate, avg_pnl, _ = summarize_subset(pnl, mask)
        if trades < loosened_floor:
            continue
        # Loosening must not tank win rate or avg PnL below the current level.
        if win_rate < current_win_rate - min_win_lift:
            continue
        if avg_pnl < current_avg_pnl - 0.05:
            continue

        qualifying.append((threshold, trades, win_rate, avg_pnl))

    if not qualifying:
        return None

    # Pick the loosest candidate that keeps quality — but prefer adding the
    # most trades while staying above the quality floor. Among ties, prefer the
    # least extreme relaxation (closest to current).
    best = max(qualifying, key=lambda c: (c[1], c[2], c[3], -abs(c[0] - current_value)))

    suggested_value, suggested_trades, suggested_win_rate, suggested_avg_pnl = best
    if suggested_value == current_value:
        return None

    return ThresholdResult(
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
        note=f"LOOSEN {'minimum' if direction == 'lower' else 'maximum'} threshold to stop blocking winners "
        f"(captures {round(suggested_trades / max(1, pnl.shape[0]) * 100)}% of all alerts)",
    )


def evaluate_room_multiplier(
    room_feature: pd.Series,
    atr_pct: pd.Series,
    pnl: pd.Series,
    *,
    timeframe: str,
    min_room_threshold: float,
    current_multiplier: float,
    steps: int,
    min_trades: int,
    min_win_lift: float,
    min_retention: float = 0.5,
) -> Optional[ThresholdResult]:
    atr = atr_pct.astype(float).replace(0, np.nan)
    required = (min_room_threshold - room_feature.astype(float)).clip(lower=0)
    required = required / atr
    required = required.replace([np.inf, -np.inf], np.nan).fillna(0.0)

    candidates = candidate_thresholds(required, current_multiplier, "lower", steps)
    if not candidates:
        return None

    baseline_room = room_feature.astype(float).combine(atr_pct.astype(float) * current_multiplier, max)
    baseline_mask = room_feature.isna() | atr_pct.isna() | (baseline_room >= min_room_threshold)
    current_trades, current_win_rate, current_avg_pnl, _ = summarize_subset(pnl, baseline_mask)
    if current_trades == 0:
        return None

    retention_floor = max(min_trades, int(current_trades * min_retention))

    qualifying: list[tuple[float, int, float, float]] = []
    for multiplier in candidates:
        room = room_feature.astype(float).combine(atr_pct.astype(float) * multiplier, max)
        mask = room_feature.isna() | atr_pct.isna() | (room >= min_room_threshold)
        trades, win_rate, avg_pnl, _ = summarize_subset(pnl, mask)

        if trades < retention_floor:
            continue
        if (win_rate - current_win_rate) < min_win_lift:
            continue
        if avg_pnl < current_avg_pnl:
            continue

        qualifying.append((multiplier, trades, win_rate, avg_pnl))

    if not qualifying:
        return None

    # Least destructive first: prefer the candidate keeping the MOST trades,
    # then higher win rate, then higher avg PnL, then the mildest multiplier.
    best = max(qualifying, key=lambda c: (c[1], c[2], c[3], -c[0]))

    suggested_value, suggested_trades, suggested_win_rate, suggested_avg_pnl = best
    if suggested_value == current_multiplier:
        return None

    return ThresholdResult(
        timeframe=timeframe,
        gate_name="room_atr_mult",
        threshold_kind="min",
        gate_label="room_atr_mult",
        current_value=current_multiplier,
        suggested_value=suggested_value,
        current_trades=current_trades,
        current_win_rate=current_win_rate,
        suggested_trades=suggested_trades,
        suggested_win_rate=suggested_win_rate,
        current_avg_pnl=current_avg_pnl,
        suggested_avg_pnl=suggested_avg_pnl,
        note=f"lower multiplier tightens the room requirement "
        f"(keeps {round(suggested_trades / max(1, current_trades) * 100)}% of current trades)",
    )


def report_current_settings(version: VersionRecord, thresholds: dict[str, Optional[float]]) -> None:
    print("=" * 70)
    print(f"Version: {version.pipeline_letter}/{version.version_string}  (id={version.id})")
    print(f"Signal type: {version.signal_type}")
    print(f"Enabled: {'yes' if version.enabled else 'no'}")
    if version.scanner_score_formula:
        print(f"Scanner score formula: {version.scanner_score_formula}")
    print("-" * 70)
    print("Current ML threshold settings:")
    print(f"  baseline:  {format_number(thresholds.get('baseline'), 4)}")
    print(f"  override:  {format_number(thresholds.get('override'), 4)}")
    print(f"  global:    {format_number(thresholds.get('global'), 4)}")
    print(f"  effective: {format_number(thresholds.get('effective'), 4)}")


def report_overall_metrics(df: pd.DataFrame) -> None:
    pnl = pd.to_numeric(df["pnl_percent"], errors="coerce").fillna(0.0)
    wins = pnl >= WIN_THRESHOLD
    trades = int(pnl.shape[0])
    win_rate = float(wins.mean() * 100.0) if trades else 0.0
    avg_pnl = float(pnl.mean()) if trades else 0.0
    median_pnl = float(pnl.median()) if trades else 0.0
    ml = pd.to_numeric(df["ml_win_prob"], errors="coerce")

    print("Overall current alert sample:")
    print(f"  rows analyzed: {trades}")
    print(f"  win rate:      {win_rate:.2f}%   (win = pnl >= {WIN_THRESHOLD}%)")
    print(f"  avg pnl:       {avg_pnl:.3f}%")
    print(f"  median pnl:     {median_pnl:.3f}%")
    if "ml_win_prob" in df.columns and not ml.dropna().empty:
        print(f"  ml_win_prob:    mean {ml.mean():.4f} | median {ml.median():.4f}")


def suggest_ml_threshold(
    df: pd.DataFrame,
    current_threshold: Optional[float],
    min_trades: int,
    steps: int,
    min_win_lift: float,
    min_retention: float = 0.5,
) -> Optional[ThresholdResult]:
    ml = pd.to_numeric(df["ml_win_prob"], errors="coerce")
    pnl = pd.to_numeric(df["pnl_percent"], errors="coerce")
    candidates = candidate_thresholds(ml, float(ml.median()), "higher", steps)
    if current_threshold is not None and current_threshold not in candidates:
        candidates.append(current_threshold)
    candidates = sorted(set(round(value, 6) for value in candidates))

    current_mask = ml.isna() | (ml >= (current_threshold if current_threshold is not None else -np.inf))
    current_trades, current_win_rate, current_avg_pnl, _ = summarize_subset(pnl, current_mask)
    if current_trades == 0:
        return None

    retention_floor = max(min_trades, int(current_trades * min_retention))

    qualifying: list[tuple[float, int, float, float]] = []
    for threshold in candidates:
        mask = ml.isna() | (ml >= threshold)
        trades, win_rate, avg_pnl, _ = summarize_subset(pnl, mask)

        if trades < retention_floor:
            continue
        if (win_rate - current_win_rate) < min_win_lift:
            continue
        if avg_pnl < current_avg_pnl:
            continue

        qualifying.append((threshold, trades, win_rate, avg_pnl))

    if not qualifying:
        return None

    # Least destructive first: prefer the candidate keeping the MOST trades,
    # then higher win rate, then higher avg PnL, then the lowest threshold.
    best = max(qualifying, key=lambda c: (c[1], c[2], c[3], -c[0]))

    suggested_value, suggested_trades, suggested_win_rate, suggested_avg_pnl = best
    if current_threshold is not None and suggested_value == current_threshold:
        return None

    return ThresholdResult(
        timeframe="settings",
        gate_name="ml_threshold",
        threshold_kind="baseline",
        gate_label="ml_win_prob",
        current_value=current_threshold,
        suggested_value=suggested_value,
        current_trades=current_trades,
        current_win_rate=current_win_rate,
        suggested_trades=suggested_trades,
        suggested_win_rate=suggested_win_rate,
        current_avg_pnl=current_avg_pnl,
        suggested_avg_pnl=suggested_avg_pnl,
        note=f"higher threshold usually increases win rate "
        f"(keeps {round(suggested_trades / max(1, current_trades) * 100)}% of current trades)",
    )


def gate_feature_name(gate_name: str) -> list[str]:
    aliases = FEATURE_ALIASES.get(gate_name, [])
    if gate_name not in aliases:
        aliases = [gate_name, *aliases]
    return aliases


def upsert_setting_value(connection, setting_name: str, value: float) -> None:
    connection.execute(
        text(
            """
            INSERT INTO settings (name, value, updated_at)
            VALUES (:name, :value, NOW())
            ON DUPLICATE KEY UPDATE value = VALUES(value), updated_at = NOW()
            """
        ),
        {"name": setting_name, "value": format_db_number(value)},
    )


def update_gate_threshold(connection, version_id: int, gate: ThresholdResult) -> int:
    if gate.threshold_kind == "min":
        sql = text(
            """
            UPDATE alert_version_gates
            SET threshold_min = :value, updated_at = NOW()
            WHERE alert_version_id = :version_id
              AND timeframe = :timeframe
              AND gate_name = :gate_name
            """
        )
    elif gate.threshold_kind == "max":
        sql = text(
            """
            UPDATE alert_version_gates
            SET threshold_max = :value, updated_at = NOW()
            WHERE alert_version_id = :version_id
              AND timeframe = :timeframe
              AND gate_name = :gate_name
            """
        )
    else:
        raise ValueError(f"Unsupported threshold kind: {gate.threshold_kind}")

    result = connection.execute(
        sql,
        {
            "version_id": version_id,
            "timeframe": gate.timeframe,
            "gate_name": gate.gate_name,
            "value": format_db_number(float(gate.suggested_value or 0.0)),
        },
    )

    return int(result.rowcount or 0)


def apply_recommendations(
    engine,
    version: VersionRecord,
    thresholds: dict[str, Optional[float]],
    ml_result: Optional[ThresholdResult],
    gate_results: list[ThresholdResult],
) -> list[str]:
    applied: list[str] = []

    with engine.begin() as connection:
        if ml_result is not None and ml_result.suggested_value is not None:
            baseline_key = f"trading.pipeline_{version.pipeline_letter.lower()}.ml_threshold"
            upsert_setting_value(connection, baseline_key, float(ml_result.suggested_value))
            applied.append(f"{baseline_key}={format_db_number(float(ml_result.suggested_value))}")

            if thresholds.get("override") is not None:
                override_key = f"trading.pipeline_{version.pipeline_letter.lower()}.ml_threshold_override"
                upsert_setting_value(connection, override_key, float(ml_result.suggested_value))
                applied.append(f"{override_key}={format_db_number(float(ml_result.suggested_value))}")

        for gate in gate_results:
            if gate.suggested_value is None or gate.current_value is None:
                continue
            if gate.suggested_value == gate.current_value:
                continue

            updated_rows = update_gate_threshold(connection, version.id, gate)
            if updated_rows > 0:
                applied.append(
                    f"alert_version_gates[{gate.timeframe}:{gate.gate_name}:{gate.threshold_kind}]={format_db_number(float(gate.suggested_value))}"
                )

    return applied


def fetch_market_proxy(engine, days: int = 90, limit: int = 3000) -> pd.DataFrame:
    """Fetch a recent sample of alerts across ALL pipelines as a proxy for
    'what the market produced recently'. Used to estimate how restrictive each
    gate is even when a specific pipeline has few analyzed trades."""
    query = text(
        """
        SELECT *
        FROM trade_alerts
        WHERE trading_date_est >= DATE_SUB(CURDATE(), INTERVAL :days DAY)
          AND meta IS NOT NULL
        ORDER BY id DESC
        LIMIT :limit
        """
    )
    return pd.read_sql_query(query, engine, params={"days": days, "limit": limit})


def load_rejections_log(path: str, pipeline: Optional[str] = None, limit: int = 100000, per_pipeline_cap: int = 15000) -> pd.DataFrame:
    """Parse the backtest gate-rejections JSON-lines log into a DataFrame.

    Each line looks like:
      [2026-08-03 01:21:08] local.DEBUG: [TradingV2Backtest] gate rejected {json}

    The JSON object has the rejection details plus a full `gate_values` snapshot
    of every computed feature at the rejected bar. The `gate_values` dict is
    exploded into columns so each rejected bar becomes one row whose columns are
    the gate features (usable directly as a market-proxy sample).

    Optionally filter to a single pipeline via the `pipeline` field.
    """
    rows: list[dict[str, Any]] = []
    path = os.path.expanduser(path)
    if not os.path.exists(path):
        raise FileNotFoundError(f"Rejections log not found: {path}")

    per_pipeline_counts: dict[str, int] = {}
    count = 0
    with open(path, "r", encoding="utf-8", errors="replace") as fh:
        for line in fh:
            line = line.strip()
            if not line:
                continue
            # Strip the monolog prefix: "... [TradingV2Backtest] gate rejected {json}"
            idx = line.find("{")
            if idx < 0:
                continue
            json_part = line[idx:]
            try:
                record = json.loads(json_part)
            except json.JSONDecodeError:
                continue
            count += 1
            if limit and count > limit:
                break

            # Per-pipeline cap: stop collecting a pipeline once it hits the cap,
            # so the in-memory sample stays bounded even if the log is huge.
            rec_pipeline = str(record.get("pipeline", ""))
            if pipeline is not None and rec_pipeline.upper() != str(pipeline).upper():
                continue
            per_pipeline_counts[rec_pipeline] = per_pipeline_counts.get(rec_pipeline, 0) + 1
            if per_pipeline_counts[rec_pipeline] > per_pipeline_cap:
                continue

            gate_values = record.get("gate_values")
            if not isinstance(gate_values, dict):
                # No full feature snapshot — skip; not usable as a feature row.
                continue

            # Flatten: top-level rejection fields + gate_values columns.
            row: dict[str, Any] = {}
            for key in ("pipeline", "version", "timeframe", "symbol", "ts_est", "gate", "reason", "value", "min", "max"):
                row[key] = record.get(key)
            row.update(gate_values)

            rows.append(row)

    if not rows:
        return pd.DataFrame()

    df = pd.DataFrame(rows)
    # Coerce all gate feature columns to numeric (they are scalar floats/ints).
    for col in df.columns:
        if col not in ("pipeline", "version", "timeframe", "symbol", "ts_est", "gate", "reason"):
            df[col] = pd.to_numeric(df[col], errors="coerce")

    return df


def report_blocking_gates(
    alerts: pd.DataFrame,
    gates: list[GateRecord],
    pnl: pd.Series,
    meta_objects: list[dict[str, Any]],
    total_trades: int,
    version_label: str,
    market_proxy: Optional[pd.DataFrame] = None,
) -> list[GateRecord]:
    """Identify which gates exclude the most alerts, and crucially whether the
    excluded alerts are winners (a gate blocking winners is over-strict).

    When the pipeline's own sample is tiny (< 50 trades), outcome-based blocking
    is not meaningful. In that case, if a market_proxy sample is provided, we
    fall back to showing the TOP BLOCKERS by rejection rate against the broader
    market proxy — so the report still surfaces which gates are throttling
    alert volume.

    Returns the list of gates flagged as "blocks winners" so the caller can
    suggest LOOSENING them (they are over-strict and should be relaxed, not
    tightened).
    """
    blocked_rows: list[dict[str, Any]] = []
    winner_blockers: list[GateRecord] = []

    small_sample = total_trades < 50

    # Pre-resolve proxy feature series once so the fallback path is fast.
    proxy_meta = None
    if market_proxy is not None and not market_proxy.empty and small_sample:
        proxy_meta = [parse_json_object(value) for value in market_proxy["meta"]] if "meta" in market_proxy.columns else []

    for gate in gates:
        if not gate.enabled:
            continue

        if gate.gate_name == "room_atr_mult":
            continue  # handled via companion gate below

        feature_names = gate_feature_name(gate.gate_name)
        feature = resolve_series(alerts, meta_objects, feature_names)
        if feature is None:
            continue

        feature = feature.astype(float)
        passed = pd.Series(True, index=alerts.index)

        if gate.threshold_min is not None:
            passed &= feature.isna() | (feature >= float(gate.threshold_min))
        if gate.threshold_max is not None:
            passed &= feature.isna() | (feature <= float(gate.threshold_max))

        blocked = ~passed
        blocked_count = int(blocked.sum())
        if blocked_count == 0:
            continue

        blocked_pnl = pnl[blocked]
        blocked_trades = int(blocked_pnl.shape[0])
        if blocked_trades == 0:
            continue

        blocked_wins = int((blocked_pnl > 0).sum())
        blocked_win_rate = (blocked_wins / blocked_trades) * 100.0
        blocked_avg_pnl = float(blocked_pnl.mean())

        passed_count = total_trades - blocked_count
        passed_pnl = pnl[passed]
        passed_win_rate = (int((passed_pnl > 0).sum()) / max(1, passed_count)) * 100.0

        blocks_winners = blocked_win_rate > passed_win_rate

        # ── Small-sample fallback: also measure rejection against the market proxy ──
        # When there are too few of the pipeline's own trades to trust outcome-based
        # blocking, show how often this gate rejects the broader market sample. This
        # surfaces the real volume throttlers even with near-zero analyzed trades.
        proxy_blocked_count = None
        proxy_blocked_pct = None
        if small_sample and proxy_meta is not None and feature is not None:
            proxy_feature = resolve_series(market_proxy, proxy_meta, feature_names)
            if proxy_feature is not None:
                proxy_feature = proxy_feature.astype(float)
                proxy_passed = pd.Series(True, index=market_proxy.index)
                if gate.threshold_min is not None:
                    proxy_passed &= proxy_feature.isna() | (proxy_feature >= float(gate.threshold_min))
                if gate.threshold_max is not None:
                    proxy_passed &= proxy_feature.isna() | (proxy_feature <= float(gate.threshold_max))
                proxy_blocked_count = int((~proxy_passed).sum())
                proxy_blocked_pct = round(proxy_blocked_count / max(1, len(market_proxy)) * 100, 1)

        # Compute a suggested value that stops blocking winners, when applicable.
        suggested = None
        if blocks_winners:
            if gate.threshold_min is not None:
                res = evaluate_loosen_gate(
                    feature,
                    pnl,
                    timeframe=gate.timeframe,
                    gate_name=gate.gate_name,
                    threshold_kind="min",
                    current_value=float(gate.threshold_min),
                    direction="lower",
                    label=f"{gate.timeframe} {gate.gate_name} [min] LOOSEN",
                    steps=15,
                    min_trades=min(10, max(3, int(total_trades * 0.05))),
                    min_win_lift=1.0,
                    min_retention=0.3,
                )
                if res is not None:
                    suggested = f"min→{res.suggested_value:.4g}"
            elif gate.threshold_max is not None:
                res = evaluate_loosen_gate(
                    feature,
                    pnl,
                    timeframe=gate.timeframe,
                    gate_name=gate.gate_name,
                    threshold_kind="max",
                    current_value=float(gate.threshold_max),
                    direction="higher",
                    label=f"{gate.timeframe} {gate.gate_name} [max] LOOSEN",
                    steps=15,
                    min_trades=min(10, max(3, int(total_trades * 0.05))),
                    min_win_lift=1.0,
                    min_retention=0.3,
                )
                if res is not None:
                    suggested = f"max→{res.suggested_value:.4g}"

        blocked_rows.append(
            {
                "version": version_label,
                "gate": f"{gate.timeframe} {gate.gate_name}",
                "current": f"{gate.threshold_min:.4g}→{gate.threshold_max:.4g}" if gate.threshold_min is not None and gate.threshold_max is not None
                else (f"min {gate.threshold_min:.4g}" if gate.threshold_min is not None
                else (f"max {gate.threshold_max:.4g}" if gate.threshold_max is not None else "bool")),
                "suggested": suggested or "—",
                "blocked_trades": blocked_count,
                "blocked_pct": round(blocked_count / max(1, total_trades) * 100, 1),
                "blocked_win_rate": round(blocked_win_rate, 1),
                "passed_win_rate": round(passed_win_rate, 1),
                "blocked_avg_pnl": round(blocked_avg_pnl, 3),
                "proxy_blocked_pct": proxy_blocked_pct,
                "warning": "⚠ BLOCKS WINNERS" if blocks_winners else "",
            }
        )

        if blocks_winners:
            winner_blockers.append(gate)

    if blocked_rows:
        report = pd.DataFrame(blocked_rows)

        # Show proxy rejection prominently when the pipeline sample is small.
        if small_sample and any(r.get("proxy_blocked_pct") is not None for r in blocked_rows):
            report["market_reject%"] = report["proxy_blocked_pct"].fillna(0)
            report = report.sort_values("market_reject%", ascending=False)
            print("=" * 70)
            print(f"BLOCKING GATES — {version_label} (total trades: {total_trades})")
            print("Sample too small for outcome-based blocking, so 'market_reject%'")
            print("shows how often each gate rejects a recent market-wide alert sample.")
            print("High reject% = the gate is throttling alert volume.")
            print("-" * 70)
            cols = [c for c in ["version", "gate", "current", "market_reject%", "blocked_trades", "blocked_pct", "blocked_win_rate", "passed_win_rate", "warning"] if c in report.columns]
            print(report[cols].to_string(index=False))
        else:
            report = report.sort_values("blocked_trades", ascending=False)
            print("=" * 70)
            print(f"BLOCKING GATES — {version_label} (total trades: {total_trades})")
            print("Gates that exclude alerts, with a 'suggested' value to stop")
            print("blocking winners (⚠ = excluded alerts have a HIGHER win rate than")
            print("survivors — the gate is too strict and should be LOOSENED).")
            print("-" * 70)
            print(report.to_string(index=False))
        print()

    return winner_blockers


def analyze_one_version(engine, version: VersionRecord, args: argparse.Namespace) -> Optional[int]:
    gates = fetch_gates(engine, version.id)
    thresholds = fetch_current_thresholds(engine, version.pipeline_letter)

    try:
        alerts = fetch_alerts(engine, version, args)
    except Exception as exc:  # noqa: BLE001
        print(f"ERROR: failed to load alerts for {version.pipeline_letter}: {exc}")
        return None

    if alerts.empty:
        print(f"No scored trade_alerts found for {version.pipeline_letter}/{version.version_string} and filters.")
        return 0

    report_current_settings(version, thresholds)
    print("-" * 70)
    report_overall_metrics(alerts)

    pnl = pd.to_numeric(alerts["pnl_percent"], errors="coerce").fillna(0.0)
    meta_objects = [parse_json_object(value) for value in alerts["meta"]] if "meta" in alerts.columns else []
    total_trades = int(pnl.shape[0])

    # Fetch a market-wide proxy sample so the blocking report can show top
    # blockers by rejection rate even when this pipeline has few analyzed trades.
    # Prefer the actual backtest rejection log when provided (real rejected bars),
    # falling back to the DB market proxy (alerts that passed other pipelines).
    market_proxy = None
    if total_trades < 50:
        if getattr(args, "rejections_log", None):
            try:
                market_proxy = load_rejections_log(
                    args.rejections_log,
                    pipeline=version.pipeline_letter,
                    per_pipeline_cap=15000,
                )
                if market_proxy.empty:
                    print(f"  (rejections log has no rows for pipeline {version.pipeline_letter} — using DB proxy)")
                    market_proxy = None
            except FileNotFoundError as exc:
                print(f"  (rejections log not found: {exc} — using DB proxy)")
                market_proxy = None
            except Exception as exc:  # noqa: BLE001
                print(f"  (failed to parse rejections log: {exc} — using DB proxy)")
                market_proxy = None

        if market_proxy is None:
            try:
                market_proxy = fetch_market_proxy(engine, days=max(30, min(365, args.days)), limit=3000)
            except Exception:  # noqa: BLE001
                market_proxy = None

    # ── Blocking-gate analysis (what excludes the most, and does it exclude winners) ──
    winner_blockers = report_blocking_gates(
        alerts, gates, pnl, meta_objects, total_trades,
        f"{version.pipeline_letter}/{version.version_string}",
        market_proxy=market_proxy,
    )

    print("-" * 70)
    print(f"Recommendation constraints: min-trades={args.min_trades} | min-retention={args.min_retention} "
          f"(never cut below {round(args.min_retention * 100)}% of current trades) | min-win-lift={args.min_win_lift}pp")
    print("-" * 70)
    print("ML threshold tuning:")
    ml_result = None
    has_ml = "ml_win_prob" in alerts.columns and not pd.to_numeric(alerts["ml_win_prob"], errors="coerce").dropna().empty
    if not has_ml:
        print("  No ML-scored alerts in sample — skipping ML threshold tuning (gate tuning below still applies).")
    else:
        ml_result = suggest_ml_threshold(
            alerts,
            thresholds.get("effective"),
            args.min_trades,
            args.candidate_steps,
            args.min_win_lift,
            args.min_retention,
        )
        if ml_result is None:
            print("  No ML threshold recommendation met the minimum trade count.")
        else:
            print(
                f"  current:  {format_number(ml_result.current_value, 4)} | win rate {ml_result.current_win_rate:.2f}% on {ml_result.current_trades} trades"
            )
            print(
                f"  suggest:  {format_number(ml_result.suggested_value, 4)} | win rate {ml_result.suggested_win_rate:.2f}% on {ml_result.suggested_trades} trades"
            )
            lift = ml_result.suggested_win_rate - ml_result.current_win_rate
            print(f"  lift:     {lift:+.2f} percentage points")
            print(f"  note:     {ml_result.note}")

    print("-" * 70)
    print("Gate tuning suggestions:")

    analyses: list[ThresholdResult] = []
    skipped: list[str] = []

    for gate in gates:
        if not gate.enabled:
            skipped.append(f"{gate.timeframe} {gate.gate_name} (disabled in config)")
            continue

        if gate.gate_name == "room_atr_mult":
            room_feature = resolve_series(alerts, meta_objects, gate_feature_name("room_to_hod_pct"))
            atr_feature = resolve_series(alerts, meta_objects, gate_feature_name("atr_pct"))
            if room_feature is None or atr_feature is None:
                skipped.append(f"{gate.timeframe} {gate.gate_name} (missing room or atr feature)")
                continue

            current_room_threshold = None
            for other in gates:
                if other.gate_name == "room_to_hod_pct" and other.timeframe == gate.timeframe:
                    current_room_threshold = other.threshold_min
                    break

            if current_room_threshold is None or gate.threshold_min is None:
                skipped.append(f"{gate.timeframe} {gate.gate_name} (missing companion room thresholds)")
                continue

            result = evaluate_room_multiplier(
                room_feature,
                atr_feature,
                pnl,
                timeframe=gate.timeframe,
                min_room_threshold=current_room_threshold,
                current_multiplier=gate.threshold_min,
                steps=args.candidate_steps,
                min_trades=args.min_trades,
                min_win_lift=args.min_win_lift,
                min_retention=args.min_retention,
            )
            if result is None:
                skipped.append(f"{gate.timeframe} {gate.gate_name} (no tighter multiplier met min trades)")
                continue

            analyses.append(result)
            continue

        feature_names = gate_feature_name(gate.gate_name)
        feature = resolve_series(alerts, meta_objects, feature_names)

        if feature is None:
            skipped.append(f"{gate.timeframe} {gate.gate_name} (no matching column or meta field)")
            continue

        if gate.threshold_min is not None:
            result = evaluate_numeric_gate(
                feature,
                pnl,
                timeframe=gate.timeframe,
                gate_name=gate.gate_name,
                threshold_kind="min",
                current_value=gate.threshold_min,
                direction="higher",
                label=f"{gate.timeframe} {gate.gate_name} [min]",
                steps=args.candidate_steps,
                min_trades=args.min_trades,
                min_win_lift=args.min_win_lift,
                min_retention=args.min_retention,
            )
            if result is not None:
                analyses.append(result)

        if gate.threshold_max is not None:
            result = evaluate_numeric_gate(
                feature,
                pnl,
                timeframe=gate.timeframe,
                gate_name=gate.gate_name,
                threshold_kind="max",
                current_value=gate.threshold_max,
                direction="lower",
                label=f"{gate.timeframe} {gate.gate_name} [max]",
                steps=args.candidate_steps,
                min_trades=args.min_trades,
                min_win_lift=args.min_win_lift,
                min_retention=args.min_retention,
            )
            if result is not None:
                analyses.append(result)

        if gate.threshold_min is None and gate.threshold_max is None:
            skipped.append(f"{gate.timeframe} {gate.gate_name} (boolean gate has no safe tightening sweep)")

    if analyses:
        rows: list[dict[str, Any]] = []
        for analysis in analyses:
            lift = analysis.suggested_win_rate - analysis.current_win_rate
            rows.append(
                {
                    "gate": analysis.gate_label,
                    "current": format_number(analysis.current_value, 4),
                    "suggested": format_number(analysis.suggested_value, 4),
                    "current_trades": analysis.current_trades,
                    "suggested_trades": analysis.suggested_trades,
                    "current_win_rate": f"{analysis.current_win_rate:.2f}%",
                    "suggested_win_rate": f"{analysis.suggested_win_rate:.2f}%",
                    "lift": f"{lift:+.2f}pp",
                    "note": analysis.note,
                }
            )

        report = pd.DataFrame(rows)
        report["_lift"] = report["lift"].str.replace("pp", "", regex=False).astype(float)
        report = report.sort_values(["_lift", "suggested_trades"], ascending=[False, False]).drop(columns=["_lift"])
        print(report.to_string(index=False))
        print()
        print("  These are SUGGESTIONS only — review them before applying.")
        print("  Each keeps at least the configured fraction of current trades,")
        print("  improves win rate, and does not lower average P&L.")
    else:
        print("  No gate recommendation met the constraints "
              f"(min-trades={args.min_trades}, min-retention={args.min_retention}, min-win-lift={args.min_win_lift}pp). "
              "This is intentional — it means no reasonable tightening was found.")

    # ── LOOSEN suggestions: gates flagged as blocking winners should be relaxed, not tightened ──
    if winner_blockers:
        print("-" * 70)
        print("LOOSEN suggestions (gates flagged ⚠ above are blocking WINNERS — consider relaxing them):")
        loosen_analyses: list[ThresholdResult] = []
        loosen_skipped: list[str] = []

        for gate in winner_blockers:
            feature_names = gate_feature_name(gate.gate_name)
            feature = resolve_series(alerts, meta_objects, feature_names)
            if feature is None:
                loosen_skipped.append(f"{gate.timeframe} {gate.gate_name} (no feature)")
                continue

            # A gate blocks winners when its min is too high OR its max is too low.
            # Propose lowering the min (direction "lower") or raising the max (direction "higher").
            if gate.threshold_min is not None:
                result = evaluate_loosen_gate(
                    feature,
                    pnl,
                    timeframe=gate.timeframe,
                    gate_name=gate.gate_name,
                    threshold_kind="min",
                    current_value=gate.threshold_min,
                    direction="lower",
                    label=f"{gate.timeframe} {gate.gate_name} [min] LOOSEN",
                    steps=args.candidate_steps,
                    min_trades=args.min_trades,
                    min_win_lift=args.min_win_lift,
                    min_retention=args.min_retention,
                )
                if result is not None:
                    loosen_analyses.append(result)

            if gate.threshold_max is not None:
                result = evaluate_loosen_gate(
                    feature,
                    pnl,
                    timeframe=gate.timeframe,
                    gate_name=gate.gate_name,
                    threshold_kind="max",
                    current_value=gate.threshold_max,
                    direction="higher",
                    label=f"{gate.timeframe} {gate.gate_name} [max] LOOSEN",
                    steps=args.candidate_steps,
                    min_trades=args.min_trades,
                    min_win_lift=args.min_win_lift,
                    min_retention=args.min_retention,
                )
                if result is not None:
                    loosen_analyses.append(result)

        if loosen_analyses:
            lrows: list[dict[str, Any]] = []
            for analysis in loosen_analyses:
                lrows.append(
                    {
                        "gate": analysis.gate_label,
                        "current": format_number(analysis.current_value, 4),
                        "suggested": format_number(analysis.suggested_value, 4),
                        "current_trades": analysis.current_trades,
                        "suggested_trades": analysis.suggested_trades,
                        "current_win_rate": f"{analysis.current_win_rate:.2f}%",
                        "suggested_win_rate": f"{analysis.suggested_win_rate:.2f}%",
                        "note": analysis.note,
                    }
                )
            lreport = pd.DataFrame(lrows)
            lreport = lreport.sort_values("suggested_trades", ascending=False)
            print(lreport.to_string(index=False))
        else:
            print("  No safe loosening found for the flagged gates (loosening would tank quality).")
        if loosen_skipped:
            for item in loosen_skipped:
                print(f"  - {item}")

    if args.apply and not args.dry_run:
        applied = apply_recommendations(engine, version, thresholds, ml_result, analyses)
        print("-" * 70)
        if applied:
            print("Database updates applied:")
            for item in applied:
                print(f"  - {item}")
        else:
            print("Database updates: no changes were applied")
    else:
        print("-" * 70)
        print("Suggest-only mode — NO database changes were made.")
        print("To apply these suggestions, re-run with --apply.")

    if skipped:
        print("-" * 70)
        print("Skipped gates:")
        for item in skipped:
            print(f"  - {item}")

    return total_trades


def main() -> int:
    args = parse_args()
    load_environment()
    engine = make_engine()

    # Apply the win threshold globally so summarize_subset classifies wins as
    # pnl >= threshold (matching analyze:trade-alerts-atr-immediate).
    global WIN_THRESHOLD
    WIN_THRESHOLD = getattr(args, "win_threshold", 1.5)

    # Default to analyzing ALL active versions when no specific version is given.
    want_all = args.all or (args.version_id is None and not (args.pipeline and args.version_string))

    if want_all:
        versions = fetch_all_active_versions(engine)
        if not versions:
            print("No active alert versions found.")
            return 0

        print("=" * 70)
        print(f"Analyzing ALL {len(versions)} active alert versions")
        print("=" * 70)
        print()

        summaries: list[dict[str, Any]] = []
        for version in versions:
            print("=" * 70)
            print(f"  {version.pipeline_letter} / {version.version_string} (id={version.id})")
            print("=" * 70)
            analyzed = analyze_one_version(engine, version, args)
            if analyzed is not None:
                summaries.append({"pipeline": version.pipeline_letter, "version": version.version_string, "trades": analyzed})

        if summaries:
            print()
            print("=" * 70)
            print("SUMMARY — analyzed alert counts per pipeline")
            print("=" * 70)
            print(pd.DataFrame(summaries).to_string(index=False))
            print()
            print("Recommendations above are SUGGESTIONS only — nothing was written.")
            print("Re-run a specific pipeline with --apply to persist changes.")

        return 0

    try:
        version = fetch_version(engine, args)
    except ValueError as exc:
        print(f"ERROR: {exc}")
        return 1

    analyzed = analyze_one_version(engine, version, args)

    return 0 if analyzed is not None else 1


if __name__ == "__main__":
    raise SystemExit(main())