#!/usr/bin/env python3

from __future__ import annotations

import argparse
import json
import math
import os
import re
from dataclasses import dataclass
from typing import Any, Dict, List, Tuple

import numpy as np
import pandas as pd
import pymysql
from sklearn.metrics import classification_report, confusion_matrix
from sklearn.model_selection import train_test_split
from sklearn.tree import DecisionTreeClassifier


@dataclass(frozen=True)
class Feature:
    name: str
    sql: str


def qident(name: str) -> str:
    return "`" + name.replace("`", "``") + "`"


def safe_class_suffix(value: str) -> str:
    value = value.strip()
    value = value.replace(".", "_").replace("-", "_")
    value = re.sub(r"[^A-Za-z0-9_]", "", value)
    if not value:
        value = "2200_0"
    if value[0].isdigit():
        value = "V" + value
    return value


def fetch_df(conn, sql: str, params: List[Any]) -> pd.DataFrame:
    with conn.cursor() as cur:
        cur.execute(sql, params)
        rows = cur.fetchall()
    return pd.DataFrame(rows)


def sql_float(value: float) -> str:
    if value is None or not math.isfinite(float(value)):
        return "0"
    return f"{float(value):.10f}".rstrip("0").rstrip(".")


def make_feature_select(features: List[Feature]) -> str:
    return ",\n                ".join(
        f"({f.sql}) AS {qident(f.name)}" for f in features
    )


def build_signal_features(include_forward: bool = True) -> List[Feature]:
    prev15 = """
        (
            SELECT p.price
            FROM five_minute_prices p
            WHERE p.symbol = f.symbol
              AND p.asset_type = f.asset_type
              AND p.ts_est < f.ts_est
              AND p.ts_est >= DATE_SUB(f.ts_est, INTERVAL 20 MINUTE)
            ORDER BY p.ts_est ASC
            LIMIT 1
        )
    """

    prev30 = """
        (
            SELECT p.price
            FROM five_minute_prices p
            WHERE p.symbol = f.symbol
              AND p.asset_type = f.asset_type
              AND p.ts_est < f.ts_est
              AND p.ts_est >= DATE_SUB(f.ts_est, INTERVAL 35 MINUTE)
            ORDER BY p.ts_est ASC
            LIMIT 1
        )
    """

    prev60 = """
        (
            SELECT p.price
            FROM five_minute_prices p
            WHERE p.symbol = f.symbol
              AND p.asset_type = f.asset_type
              AND p.ts_est < f.ts_est
              AND p.ts_est >= DATE_SUB(f.ts_est, INTERVAL 65 MINUTE)
            ORDER BY p.ts_est ASC
            LIMIT 1
        )
    """

    base = [
        Feature("time_minute_est", "TIME_TO_SEC(f.trading_time_est) / 60"),
        Feature("price", "f.price"),
        Feature("log_price", "LOG10(GREATEST(f.price, 0.000001))"),
        Feature("log_volume", "LOG10(GREATEST(COALESCE(f.volume, 0), 1))"),

        Feature("bar_move_pct", "((f.price - f.open) / NULLIF(f.open, 0)) * 100"),
        Feature("range_pct", "((f.high - f.low) / NULLIF(f.open, 0)) * 100"),
        Feature("close_pos", "((f.price - f.low) / NULLIF(f.high - f.low, 0))"),

        Feature("atr_pct", "f.atr_pct"),
        Feature("atr_price_pct", "(f.atr / NULLIF(f.price, 0)) * 100"),

        Feature("vwap_dist_pct", "f.vwap_dist_pct"),
        Feature("above_vwap", "f.above_vwap"),
        Feature("ema9_above_ema21", "f.ema9_above_ema21"),
        Feature("ema_spread_price_pct", "((f.ema9 - f.ema21) / NULLIF(f.price, 0)) * 100"),

        Feature(
            "vol_ratio_60m",
            """
            f.volume / NULLIF((
                SELECT AVG(p.volume)
                FROM five_minute_prices p
                WHERE p.symbol = f.symbol
                  AND p.asset_type = f.asset_type
                  AND p.ts_est < f.ts_est
                  AND p.ts_est >= DATE_SUB(f.ts_est, INTERVAL 60 MINUTE)
                  AND p.volume IS NOT NULL
                  AND p.volume > 0
            ), 0)
            """,
        ),

        Feature("ret_15m_pct", f"((f.price - {prev15}) / NULLIF({prev15}, 0)) * 100"),
        Feature("ret_30m_pct", f"((f.price - {prev30}) / NULLIF({prev30}, 0)) * 100"),
        Feature("ret_60m_pct", f"((f.price - {prev60}) / NULLIF({prev60}, 0)) * 100"),
    ]

    if not include_forward:
        return base

    forward = [
        Feature(
            "fwd_max_ret_0_15_pct",
            """
            (
                SELECT MAX(((p.high - f.price) / NULLIF(f.price, 0)) * 100)
                FROM one_minute_prices p
                WHERE p.symbol = f.symbol
                  AND p.asset_type = f.asset_type
                  AND p.ts_est > f.ts_est
                  AND p.ts_est <= DATE_ADD(f.ts_est, INTERVAL 15 MINUTE)
            )
            """,
        ),
        Feature(
            "fwd_max_ret_15_135_pct",
            """
            (
                SELECT MAX(((p.high - f.price) / NULLIF(f.price, 0)) * 100)
                FROM one_minute_prices p
                WHERE p.symbol = f.symbol
                  AND p.asset_type = f.asset_type
                  AND p.ts_est > DATE_ADD(f.ts_est, INTERVAL 15 MINUTE)
                  AND p.ts_est <= DATE_ADD(f.ts_est, INTERVAL 135 MINUTE)
            )
            """,
        ),
        Feature(
            "fwd_min_ret_135_pct",
            """
            (
                SELECT MIN(((p.low - f.price) / NULLIF(f.price, 0)) * 100)
                FROM one_minute_prices p
                WHERE p.symbol = f.symbol
                  AND p.asset_type = f.asset_type
                  AND p.ts_est > f.ts_est
                  AND p.ts_est <= DATE_ADD(f.ts_est, INTERVAL 135 MINUTE)
            )
            """,
        ),
        Feature(
            "fwd_late_ret_110_135_pct",
            """
            (
                SELECT MAX(((p.price - f.price) / NULLIF(f.price, 0)) * 100)
                FROM one_minute_prices p
                WHERE p.symbol = f.symbol
                  AND p.asset_type = f.asset_type
                  AND p.ts_est >= DATE_ADD(f.ts_est, INTERVAL 110 MINUTE)
                  AND p.ts_est <= DATE_ADD(f.ts_est, INTERVAL 135 MINUTE)
            )
            """,
        ),
        Feature(
            "fwd_hit_4pct_135",
            """
            CASE WHEN EXISTS (
                SELECT 1
                FROM one_minute_prices p
                WHERE p.symbol = f.symbol
                  AND p.asset_type = f.asset_type
                  AND p.ts_est > f.ts_est
                  AND p.ts_est <= DATE_ADD(f.ts_est, INTERVAL 135 MINUTE)
                  AND p.high >= f.price * 1.04
            ) THEN 1 ELSE 0 END
            """,
        ),
        Feature(
            "fwd_hit_5pct_135",
            """
            CASE WHEN EXISTS (
                SELECT 1
                FROM one_minute_prices p
                WHERE p.symbol = f.symbol
                  AND p.asset_type = f.asset_type
                  AND p.ts_est > f.ts_est
                  AND p.ts_est <= DATE_ADD(f.ts_est, INTERVAL 135 MINUTE)
                  AND p.high >= f.price * 1.05
            ) THEN 1 ELSE 0 END
            """,
        ),
    ]

    return base + forward


def build_entry_features(include_forward: bool = True) -> List[Feature]:
    risk_expr = """
        CASE
            WHEN e.atr IS NULL OR e.atr <= 0
                THEN e.price * 0.02
            ELSE LEAST(e.atr * 3.0, e.price * 0.02)
        END
    """

    stop_expr = f"(e.price - ({risk_expr}))"

    base = [
        Feature("since_signal_min", "TIMESTAMPDIFF(MINUTE, s.ts_est, e.ts_est)"),
        Feature("entry_price", "e.price"),
        Feature("log_entry_price", "LOG10(GREATEST(e.price, 0.000001))"),
        Feature("log_entry_volume", "LOG10(GREATEST(COALESCE(e.volume, 0), 1))"),

        Feature("entry_bar_move_pct", "((e.price - e.open) / NULLIF(e.open, 0)) * 100"),
        Feature("entry_range_pct", "((e.high - e.low) / NULLIF(e.open, 0)) * 100"),
        Feature("entry_close_pos", "((e.price - e.low) / NULLIF(e.high - e.low, 0))"),

        Feature("price_vs_signal_pct", "((e.price - s.price) / NULLIF(s.price, 0)) * 100"),
        Feature("signal_bar_move_pct", "((s.price - s.open) / NULLIF(s.open, 0)) * 100"),

        Feature("entry_atr_pct", "e.atr_pct"),
        Feature("entry_atr_price_pct", "(e.atr / NULLIF(e.price, 0)) * 100"),
        Feature("risk_pct", f"(({risk_expr}) / NULLIF(e.price, 0)) * 100"),

        Feature("entry_vwap_dist_pct", "e.vwap_dist_pct"),
        Feature("entry_above_vwap", "e.above_vwap"),
        Feature("entry_ema9_above_ema21", "e.ema9_above_ema21"),
        Feature("entry_ema_spread_price_pct", "((e.ema9 - e.ema21) / NULLIF(e.price, 0)) * 100"),

        Feature(
            "entry_vol_ratio_20m",
            """
            e.volume / NULLIF((
                SELECT AVG(p.volume)
                FROM one_minute_prices p
                WHERE p.symbol = e.symbol
                  AND p.asset_type = e.asset_type
                  AND p.ts_est < e.ts_est
                  AND p.ts_est >= DATE_SUB(e.ts_est, INTERVAL 20 MINUTE)
                  AND p.volume IS NOT NULL
                  AND p.volume > 0
            ), 0)
            """,
        ),

        Feature(
            "pullback_from_signal_high_pct",
            """
            ((e.price - (
                SELECT MAX(p.high)
                FROM one_minute_prices p
                WHERE p.symbol = e.symbol
                  AND p.asset_type = e.asset_type
                  AND p.ts_est > s.ts_est
                  AND p.ts_est <= e.ts_est
            )) / NULLIF(e.price, 0)) * 100
            """,
        ),
    ]

    if not include_forward:
        return base

    forward = [
        Feature(
            "fwd_entry_mfe_120_pct",
            """
            (
                SELECT MAX(((p.high - e.price) / NULLIF(e.price, 0)) * 100)
                FROM one_minute_prices p
                WHERE p.symbol = e.symbol
                  AND p.asset_type = e.asset_type
                  AND p.ts_est > e.ts_est
                  AND p.ts_est <= DATE_ADD(e.ts_est, INTERVAL 120 MINUTE)
            )
            """,
        ),
        Feature(
            "fwd_entry_mae_120_pct",
            """
            (
                SELECT MIN(((p.low - e.price) / NULLIF(e.price, 0)) * 100)
                FROM one_minute_prices p
                WHERE p.symbol = e.symbol
                  AND p.asset_type = e.asset_type
                  AND p.ts_est > e.ts_est
                  AND p.ts_est <= DATE_ADD(e.ts_est, INTERVAL 120 MINUTE)
            )
            """,
        ),
        Feature(
            "fwd_entry_late_ret_pct",
            """
            (
                SELECT MAX(((p.price - e.price) / NULLIF(e.price, 0)) * 100)
                FROM one_minute_prices p
                WHERE p.symbol = e.symbol
                  AND p.asset_type = e.asset_type
                  AND p.ts_est >= DATE_ADD(e.ts_est, INTERVAL 110 MINUTE)
                  AND p.ts_est <= DATE_ADD(e.ts_est, INTERVAL 120 MINUTE)
            )
            """,
        ),
        Feature(
            "fwd_entry_hit_4pct",
            """
            CASE WHEN EXISTS (
                SELECT 1
                FROM one_minute_prices p
                WHERE p.symbol = e.symbol
                  AND p.asset_type = e.asset_type
                  AND p.ts_est > e.ts_est
                  AND p.ts_est <= DATE_ADD(e.ts_est, INTERVAL 120 MINUTE)
                  AND p.high >= e.price * 1.04
            ) THEN 1 ELSE 0 END
            """,
        ),
        Feature(
            "fwd_entry_hit_5pct",
            """
            CASE WHEN EXISTS (
                SELECT 1
                FROM one_minute_prices p
                WHERE p.symbol = e.symbol
                  AND p.asset_type = e.asset_type
                  AND p.ts_est > e.ts_est
                  AND p.ts_est <= DATE_ADD(e.ts_est, INTERVAL 120 MINUTE)
                  AND p.high >= e.price * 1.05
            ) THEN 1 ELSE 0 END
            """,
        ),
        Feature(
            "fwd_entry_stop_hit_120",
            f"""
            CASE WHEN EXISTS (
                SELECT 1
                FROM one_minute_prices p
                WHERE p.symbol = e.symbol
                  AND p.asset_type = e.asset_type
                  AND p.ts_est > e.ts_est
                  AND p.ts_est <= DATE_ADD(e.ts_est, INTERVAL 120 MINUTE)
                  AND p.low <= {stop_expr}
            ) THEN 1 ELSE 0 END
            """,
        ),
        Feature(
            "fwd_entry_minutes_to_4pct",
            """
            (
                SELECT TIMESTAMPDIFF(MINUTE, e.ts_est, MIN(p.ts_est))
                FROM one_minute_prices p
                WHERE p.symbol = e.symbol
                  AND p.asset_type = e.asset_type
                  AND p.ts_est > e.ts_est
                  AND p.ts_est <= DATE_ADD(e.ts_est, INTERVAL 120 MINUTE)
                  AND p.high >= e.price * 1.04
            )
            """,
        ),
    ]

    return base + forward


def make_alert_where(args, alias: str = "ta") -> Tuple[str, List[Any]]:
    clauses = []
    params: List[Any] = []

    if args.alert_signal_type_expr and args.signal_type:
        clauses.append(f"{args.alert_signal_type_expr} = %s")
        params.append(args.signal_type)

    if args.alert_asset_type_expr:
        clauses.append(f"{args.alert_asset_type_expr} = %s")
        params.append(args.asset_type)

    clauses.append(f"{args.alert_symbol_expr} IS NOT NULL")
    clauses.append(f"{args.alert_signal_ts_expr} IS NOT NULL")

    if not clauses:
        return "1=1", params

    return "\n              AND ".join(clauses), params


def load_signal_data(conn, args, features: List[Feature]) -> pd.DataFrame:
    alert_where, alert_params = make_alert_where(args)
    feature_sql = make_feature_select(features)

    pos_sql = f"""
        SELECT
            1 AS y,
            a.symbol,
            a.asset_type,
            a.signal_ts_est,
            {feature_sql}
        FROM (
            SELECT DISTINCT
                {args.alert_symbol_expr} AS symbol,
                {args.alert_asset_type_expr} AS asset_type,
                {args.alert_signal_ts_expr} AS signal_ts_est
            FROM {args.trade_alerts_table} ta
            WHERE {alert_where}
        ) a
        JOIN five_minute_prices f
          ON f.symbol = a.symbol
         AND f.asset_type = a.asset_type
         AND f.ts_est = a.signal_ts_est
    """

    pos = fetch_df(conn, pos_sql, alert_params)

    if pos.empty:
        raise RuntimeError("No positive signal rows found. Check trade_alerts columns/expressions.")

    dates = sorted(set(str(x)[:10] for x in pos["signal_ts_est"].dropna().astype(str)))
    date_placeholders = ",".join(["%s"] * len(dates))

    neg_limit = max(len(pos) * args.signal_negative_ratio, args.min_negatives)
    neg_sql = f"""
        SELECT
            0 AS y,
            f.symbol,
            f.asset_type,
            f.ts_est AS signal_ts_est,
            {feature_sql}
        FROM five_minute_prices f
        WHERE f.asset_type = %s
          AND f.trading_date_est IN ({date_placeholders})
          AND f.trading_time_est BETWEEN '09:30:00' AND '13:45:00'
          AND f.price IS NOT NULL
          AND f.price > 0
          AND NOT EXISTS (
              SELECT 1
              FROM {args.trade_alerts_table} ta
              WHERE {args.alert_symbol_expr} = f.symbol
                AND {args.alert_asset_type_expr} = f.asset_type
                AND {args.alert_signal_ts_expr} = f.ts_est
                {"AND " + args.alert_signal_type_expr + " = %s" if args.alert_signal_type_expr and args.signal_type else ""}
              LIMIT 1
          )
        ORDER BY RAND()
        LIMIT {int(neg_limit)}
    """

    neg_params: List[Any] = [args.asset_type] + dates
    if args.alert_signal_type_expr and args.signal_type:
        neg_params.append(args.signal_type)

    neg = fetch_df(conn, neg_sql, neg_params)

    df = pd.concat([pos, neg], ignore_index=True)
    return df


def load_entry_data(conn, args, features: List[Feature]) -> pd.DataFrame:
    if not args.alert_entry_ts_expr:
        raise RuntimeError(
            "Entry extraction requires --alert-entry-ts-expr, for example ta.entry_ts_est "
            "or JSON_UNQUOTE(JSON_EXTRACT(ta.best_entry, '$.entry_ts_est'))."
        )

    alert_where, alert_params = make_alert_where(args)
    feature_sql = make_feature_select(features)

    sample_threshold = max(1, min(1000000, int(args.entry_negative_hash_threshold)))

    sql = f"""
        SELECT
            CASE WHEN e.ts_est = a.entry_ts_est THEN 1 ELSE 0 END AS y,
            a.symbol,
            a.asset_type,
            a.signal_ts_est,
            e.ts_est AS entry_ts_est,
            {feature_sql}
        FROM (
            SELECT DISTINCT
                {args.alert_symbol_expr} AS symbol,
                {args.alert_asset_type_expr} AS asset_type,
                {args.alert_signal_ts_expr} AS signal_ts_est,
                {args.alert_entry_ts_expr} AS entry_ts_est
            FROM {args.trade_alerts_table} ta
            WHERE {alert_where}
              AND {args.alert_entry_ts_expr} IS NOT NULL
        ) a
        JOIN five_minute_prices s
          ON s.symbol = a.symbol
         AND s.asset_type = a.asset_type
         AND s.ts_est = a.signal_ts_est
        JOIN one_minute_prices e
          ON e.symbol = a.symbol
         AND e.asset_type = a.asset_type
         AND e.ts_est > a.signal_ts_est
         AND e.ts_est <= DATE_ADD(a.signal_ts_est, INTERVAL 15 MINUTE)
        WHERE e.price IS NOT NULL
          AND e.price > 0
          AND (
                e.ts_est = a.entry_ts_est
                OR MOD(CRC32(CONCAT(e.symbol, '#', e.ts_est)), 1000000) < {sample_threshold}
          )
    """

    df = fetch_df(conn, sql, alert_params)

    if df.empty or int(df["y"].sum()) == 0:
        raise RuntimeError(
            "No positive entry rows found. Check --alert-entry-ts-expr and make sure entry timestamps match one_minute_prices.ts_est."
        )

    return df


def prepare_xy(df: pd.DataFrame, features: List[Feature]) -> Tuple[pd.DataFrame, pd.Series, Dict[str, float]]:
    names = [f.name for f in features]
    X = df[names].apply(pd.to_numeric, errors="coerce")
    y = df["y"].astype(int)

    medians = X.median(numeric_only=True).replace([np.inf, -np.inf], np.nan).fillna(0.0)
    X = X.replace([np.inf, -np.inf], np.nan).fillna(medians)

    return X, y, {k: float(v) for k, v in medians.to_dict().items()}


def train_tree(
    df: pd.DataFrame,
    features: List[Feature],
    max_depth: int,
    min_samples_leaf: int,
    class_weight: str | None,
) -> Tuple[DecisionTreeClassifier, Dict[str, float], Dict[str, Any]]:
    X, y, medians = prepare_xy(df, features)

    can_split = y.nunique() == 2 and y.value_counts().min() >= 2 and len(df) >= 20

    if can_split:
        X_train, X_test, y_train, y_test = train_test_split(
            X,
            y,
            test_size=0.25,
            random_state=42,
            stratify=y,
        )
    else:
        X_train, X_test, y_train, y_test = X, X, y, y

    clf = DecisionTreeClassifier(
        max_depth=max_depth,
        min_samples_leaf=min_samples_leaf,
        class_weight=class_weight,
        random_state=42,
    )

    clf.fit(X_train, y_train)

    train_pred = clf.predict(X_train)
    test_pred = clf.predict(X_test)

    report = {
        "rows": int(len(df)),
        "positives": int(y.sum()),
        "negatives": int((y == 0).sum()),
        "max_depth": max_depth,
        "min_samples_leaf": min_samples_leaf,
        "class_weight": class_weight,
        "train_confusion_matrix": confusion_matrix(y_train, train_pred).tolist(),
        "test_confusion_matrix": confusion_matrix(y_test, test_pred).tolist(),
        "train_report": classification_report(y_train, train_pred, output_dict=True, zero_division=0),
        "test_report": classification_report(y_test, test_pred, output_dict=True, zero_division=0),
    }

    return clf, medians, report


def extract_positive_rules(
    clf: DecisionTreeClassifier,
    df: pd.DataFrame,
    features: List[Feature],
    medians: Dict[str, float],
    min_precision: float,
    min_positive_support: int,
    max_rules: int,
) -> List[Dict[str, Any]]:
    feature_names = [f.name for f in features]
    feature_by_name = {f.name: f for f in features}

    X, y, _ = prepare_xy(df, features)
    leaf_ids = clf.apply(X)

    leaf_stats: Dict[int, Dict[str, int]] = {}
    for leaf_id, target in zip(leaf_ids, y):
        stats = leaf_stats.setdefault(int(leaf_id), {"pos": 0, "neg": 0})
        if int(target) == 1:
            stats["pos"] += 1
        else:
            stats["neg"] += 1

    tree = clf.tree_
    raw_rules: List[Dict[str, Any]] = []

    def walk(node_id: int, path: List[Tuple[str, str, float]]) -> None:
        left = tree.children_left[node_id]
        right = tree.children_right[node_id]

        if left == right:
            stats = leaf_stats.get(int(node_id), {"pos": 0, "neg": 0})
            pos = stats["pos"]
            neg = stats["neg"]
            total = pos + neg
            precision = pos / total if total else 0.0

            if pos >= min_positive_support and precision >= min_precision:
                sql_parts = []
                readable = []

                for feature_name, op, threshold in path:
                    feat = feature_by_name[feature_name]
                    median = medians.get(feature_name, 0.0)
                    expr = f"COALESCE(({feat.sql}), {sql_float(median)})"
                    part = f"{expr} {op} {sql_float(threshold)}"
                    sql_parts.append(part)
                    readable.append(f"{feature_name} {op} {sql_float(threshold)}")

                raw_rules.append({
                    "leaf_id": int(node_id),
                    "positive_support": int(pos),
                    "negative_support": int(neg),
                    "precision": float(precision),
                    "conditions": readable,
                    "sql": "(" + "\n                AND ".join(sql_parts) + ")",
                    "weight": round((precision * 100.0) + math.log1p(pos) * 5.0, 6),
                })
            return

        feature_index = tree.feature[node_id]
        threshold = tree.threshold[node_id]
        feature_name = feature_names[feature_index]

        walk(left, path + [(feature_name, "<=", threshold)])
        walk(right, path + [(feature_name, ">", threshold)])

    walk(0, [])

    raw_rules.sort(
        key=lambda r: (
            r["precision"],
            r["positive_support"],
            -r["negative_support"],
        ),
        reverse=True,
    )

    return raw_rules[:max_rules]


def rules_to_where(rules: List[Dict[str, Any]]) -> str:
    if not rules:
        return "0 = 1"
    return "(\n            " + "\n        OR\n            ".join(r["sql"] for r in rules) + "\n        )"


def rules_to_score(rules: List[Dict[str, Any]]) -> str:
    if not rules:
        return "0"

    parts = []
    for r in rules:
        parts.append(f"(CASE WHEN {r['sql']} THEN {sql_float(r['weight'])} ELSE 0 END)")

    return "\n            + ".join(parts)


def write_sql_outputs(
    out_dir: str,
    signal_where: str,
    signal_score: str,
    entry_where: str,
    entry_score: str,
) -> None:
    os.makedirs(out_dir, exist_ok=True)

    signal_sql = f"""
-- Extracted five-minute signal scanner SQL.
-- Alias required: five_minute_prices f

SELECT
    f.symbol,
    f.asset_type,
    f.ts_est AS signal_ts_est,
    f.price AS close_price,
    f.open,
    f.high,
    f.low,
    f.volume,
    f.atr,
    f.atr_pct,
    ({signal_score}) AS extracted_score
FROM five_minute_prices f
WHERE f.asset_type = ?
  AND f.trading_date_est = DATE(?)
  AND f.ts_est <= ?
  AND f.ts_est >= DATE_SUB(?, INTERVAL ? MINUTE)
  AND f.trading_time_est BETWEEN '09:30:00' AND '13:45:00'
  AND ({signal_where})
ORDER BY extracted_score DESC, f.ts_est DESC, f.symbol ASC
LIMIT ?;
""".strip()

    entry_sql = f"""
-- Extracted one-minute entry finder SQL.
-- Aliases required:
--   one_minute_prices e
--   five_minute_prices s

SELECT
    e.symbol,
    e.asset_type,
    e.ts_est AS entry_ts_est,
    e.price AS entry_price,
    e.open,
    e.high,
    e.low,
    e.volume,
    e.atr,
    e.atr_pct,
    (
        CASE
            WHEN e.atr IS NULL OR e.atr <= 0
                THEN e.price * 0.02
            ELSE LEAST(e.atr * 3.0, e.price * 0.02)
        END
    ) AS risk_per_share,
    e.price - (
        CASE
            WHEN e.atr IS NULL OR e.atr <= 0
                THEN e.price * 0.02
            ELSE LEAST(e.atr * 3.0, e.price * 0.02)
        END
    ) AS stop_price,
    ({entry_score}) AS extracted_score
FROM one_minute_prices e
JOIN five_minute_prices s
  ON s.symbol = e.symbol
 AND s.asset_type = e.asset_type
 AND s.ts_est = ?
WHERE e.symbol = ?
  AND e.asset_type = ?
  AND e.trading_date_est = DATE(?)
  AND e.ts_est >= ?
  AND e.ts_est > ?
  AND e.ts_est <= DATE_ADD(?, INTERVAL 15 MINUTE)
  AND ({entry_where})
ORDER BY extracted_score DESC, e.ts_est ASC
LIMIT 1;
""".strip()

    with open(os.path.join(out_dir, "five_minute_signal_rules.sql"), "w", encoding="utf-8") as f:
        f.write(signal_sql + "\n")

    with open(os.path.join(out_dir, "one_minute_entry_rules.sql"), "w", encoding="utf-8") as f:
        f.write(entry_sql + "\n")


def php_string(value: str) -> str:
    return value.replace("\\", "\\\\").replace("'", "\\'")


def generate_php_signal_scanner(
    class_suffix: str,
    version_label: str,
    signal_where: str,
    signal_score: str,
) -> str:
    cls = f"FiveMinuteSignalScanner{class_suffix}"

    return f"""<?php

namespace App\\Services\\Trading;

/**
 * {version_label} - Extracted Forward-Biased Five Minute Signal Scanner
 *
 * Generated by extract_forward_pipeline.py.
 * This intentionally may contain forward-looking SQL features.
 * Use for research/backtest/training-data recreation only.
 */
class {cls}
{{
    use HasPriceTables;

    private string $version = '{php_string(version_label)}';

    public function getVersion(): string
    {{
        return $this->version;
    }}

    public function scan(
        string $assetType,
        string $asOfTsEst,
        int $lookbackMinutes = 60,
        float $minMovePct = 0.4,
        float $volMult = 1.5,
        int $limit = 10000
    ): array {{
        $tradeDate = substr($asOfTsEst, 0, 10);
        $safeLookbackMinutes = max(1, (int) $lookbackMinutes);
        $safeLimit = max(1, (int) $limit);

        $ruleSql = <<<'SQL'
{signal_where}
SQL;

        $scoreSql = <<<'SQL'
{signal_score}
SQL;

        $sql = '
            SELECT
                f.symbol,
                f.asset_type,
                f.ts_est,
                f.price AS close_price,
                f.open,
                f.high,
                f.low,
                f.volume,
                f.atr,
                f.atr_pct,
                (' . $scoreSql . ') AS extracted_score
            FROM five_minute_prices f
            WHERE f.asset_type = ?
              AND f.trading_date_est = ?
              AND f.ts_est <= ?
              AND f.ts_est >= DATE_SUB(?, INTERVAL ' . $safeLookbackMinutes . ' MINUTE)
              AND f.trading_time_est BETWEEN \\'09:30:00\\' AND \\'13:45:00\\'
              AND (' . $ruleSql . ')
            ORDER BY extracted_score DESC, f.ts_est DESC, f.symbol ASC
            LIMIT ?
        ';

        $rows = $this->dbSelect($sql, [
            $assetType,
            $tradeDate,
            $asOfTsEst,
            $asOfTsEst,
            $safeLimit,
        ]);

        if (empty($rows)) {{
            return [];
        }}

        $out = [];

        foreach ($rows as $rank => $row) {{
            $out[] = [
                'symbol' => (string) $row->symbol,
                'asset_type' => (string) $row->asset_type,
                'signal_type' => 'EXTRACTED_FORWARD_5M',
                'signal_ts_est' => (string) $row->ts_est,
                'score' => round((float) $row->extracted_score, 3),
                'atr' => $row->atr !== null ? (float) $row->atr : null,
                'atr_pct' => $row->atr_pct !== null ? (float) $row->atr_pct : null,
                'meta' => [
                    'version' => $this->version,
                    'forward_bias' => true,
                    'rank' => $rank + 1,
                    'current_price' => (float) $row->close_price,
                    'current_volume' => $row->volume !== null ? (float) $row->volume : null,
                    'lookback_minutes' => $lookbackMinutes,
                    'min_move_pct' => $minMovePct,
                    'vol_mult' => $volMult,
                ],
            ];
        }}

        return $out;
    }}
}}
"""


def generate_php_entry_finder(
    class_suffix: str,
    version_label: str,
    entry_where: str,
    entry_score: str,
) -> str:
    cls = f"OneMinuteEntryFinder{class_suffix}"

    return f"""<?php

namespace App\\Services\\Trading;

use DateInterval;
use DateTimeImmutable;
use DateTimeZone;

/**
 * {version_label} - Extracted Forward-Biased One Minute Entry Finder
 *
 * Generated by extract_forward_pipeline.py.
 * This intentionally may contain forward-looking SQL features.
 * Use for research/backtest/training-data recreation only.
 */
class {cls}
{{
    use HasPriceTables;

    private string $version = '{php_string(version_label)}';

    public function getVersion(): string
    {{
        return $this->version;
    }}

    public function findBestLong(
        string $symbol,
        string $assetType,
        string $signalTsEst,
        string $asOfTsEst,
        ...$rest
    ): array {{
        $entryWindowEndTsEst = $this->addMinutes($signalTsEst, 15);
        $entrySearchStartTsEst = $this->maxTsEst($signalTsEst, $asOfTsEst);

        if ($entrySearchStartTsEst > $entryWindowEndTsEst) {{
            return [
                'ok' => 0,
                'best_entry' => null,
                'reason' => 'entry_window_closed',
                'meta' => [
                    'version' => $this->version,
                    'forward_bias' => true,
                    'signal_ts_est' => $signalTsEst,
                    'as_of_ts_est' => $asOfTsEst,
                    'entry_window_end_ts_est' => $entryWindowEndTsEst,
                ],
            ];
        }}

        $ruleSql = <<<'SQL'
{entry_where}
SQL;

        $scoreSql = <<<'SQL'
{entry_score}
SQL;

        $sql = '
            SELECT
                e.symbol,
                e.asset_type,
                e.ts_est AS entry_ts_est,
                e.price AS entry_price,
                e.open,
                e.high,
                e.low,
                e.volume,
                e.atr,
                e.atr_pct,
                (
                    CASE
                        WHEN e.atr IS NULL OR e.atr <= 0
                            THEN e.price * 0.02
                        ELSE LEAST(e.atr * 3.0, e.price * 0.02)
                    END
                ) AS risk_per_share,
                e.price - (
                    CASE
                        WHEN e.atr IS NULL OR e.atr <= 0
                            THEN e.price * 0.02
                        ELSE LEAST(e.atr * 3.0, e.price * 0.02)
                    END
                ) AS stop_price,
                (' . $scoreSql . ') AS extracted_score
            FROM one_minute_prices e
            JOIN five_minute_prices s
              ON s.symbol = e.symbol
             AND s.asset_type = e.asset_type
             AND s.ts_est = ?
            WHERE e.symbol = ?
              AND e.asset_type = ?
              AND e.trading_date_est = DATE(?)
              AND e.ts_est >= ?
              AND e.ts_est > ?
              AND e.ts_est <= DATE_ADD(?, INTERVAL 15 MINUTE)
              AND (' . $ruleSql . ')
            ORDER BY extracted_score DESC, e.ts_est ASC
            LIMIT 1
        ';

        $rows = $this->dbSelect($sql, [
            $signalTsEst,
            $symbol,
            $assetType,
            $signalTsEst,
            $entrySearchStartTsEst,
            $signalTsEst,
            $signalTsEst,
        ]);

        if (empty($rows)) {{
            return [
                'ok' => 0,
                'best_entry' => null,
                'reason' => 'no_extracted_entry_match',
                'meta' => [
                    'version' => $this->version,
                    'forward_bias' => true,
                    'symbol' => $symbol,
                    'asset_type' => $assetType,
                    'signal_ts_est' => $signalTsEst,
                    'as_of_ts_est' => $asOfTsEst,
                    'entry_search_start_ts_est' => $entrySearchStartTsEst,
                    'entry_window_end_ts_est' => $entryWindowEndTsEst,
                ],
            ];
        }}

        $row = $rows[0];

        $entryPrice = (float) $row->entry_price;
        $stopPrice = max(0.01, (float) $row->stop_price);
        $riskPerShare = max(0.01, (float) $row->risk_per_share);
        $riskPct = round(($riskPerShare / max(0.01, $entryPrice)) * 100, 2);

        $bestEntry = [
            'type' => 'EXTRACTED_FORWARD_1M',
            'entry_ts_est' => (string) $row->entry_ts_est,
            'entry' => round($entryPrice, 4),
            'stop' => round($stopPrice, 4),
            'risk_pct' => $riskPct,
            'risk_per_share' => round($riskPerShare, 4),
            'score' => round((float) $row->extracted_score, 3),
            'vol_ratio' => null,
            'atr' => $row->atr !== null ? (float) $row->atr : null,
            'atr_pct' => $row->atr_pct !== null ? (float) $row->atr_pct : null,
            'suggested_trailing_stop' => round($riskPerShare, 4),
            'suggested_trailing_stop_pct' => $riskPct,
            'targets' => $this->buildTargets($entryPrice, $riskPerShare),
            'meta' => [
                'version' => $this->version,
                'forward_bias' => true,
                'signal_ts_est' => $signalTsEst,
                'as_of_ts_est' => $asOfTsEst,
                'entry_search_start_ts_est' => $entrySearchStartTsEst,
                'entry_window_end_ts_est' => $entryWindowEndTsEst,
            ],
        ];

        return [
            'ok' => 1,
            'best_entry' => $bestEntry,
            'meta' => [
                'version' => $this->version,
                'forward_bias' => true,
                'as_of_ts_est' => $asOfTsEst,
                'signal_ts_est' => $signalTsEst,
            ],
        ];
    }}

    public function findBestShort(
        string $symbol,
        string $assetType,
        string $signalTsEst,
        string $asOfTsEst,
        ...$rest
    ): array {{
        return [
            'ok' => 0,
            'best_entry' => null,
            'reason' => 'short_not_implemented',
        ];
    }}

    private function buildTargets(float $entryPrice, float $riskPerShare): array
    {{
        $riskPerShare = max(0.01, $riskPerShare);

        return [
            '1R' => round($entryPrice + $riskPerShare, 4),
            '2R' => round($entryPrice + ($riskPerShare * 2), 4),
            '3R' => round($entryPrice + ($riskPerShare * 3), 4),
            '3pct' => round($entryPrice * 1.03, 4),
            '4pct' => round($entryPrice * 1.04, 4),
            '5pct' => round($entryPrice * 1.05, 4),
        ];
    }}

    private function addMinutes(string $tsEst, int $minutes): string
    {{
        $dt = new DateTimeImmutable($tsEst, new DateTimeZone('America/New_York'));

        return $dt->add(new DateInterval('PT' . max(0, $minutes) . 'M'))->format('Y-m-d H:i:s');
    }}

    private function maxTsEst(string $a, string $b): string
    {{
        return $a >= $b ? $a : $b;
    }}
}}
"""


def write_php_outputs(
    out_dir: str,
    class_suffix: str,
    version_label: str,
    signal_where: str,
    signal_score: str,
    entry_where: str,
    entry_score: str,
) -> None:
    os.makedirs(out_dir, exist_ok=True)

    signal_php = generate_php_signal_scanner(
        class_suffix=class_suffix,
        version_label=version_label,
        signal_where=signal_where,
        signal_score=signal_score,
    )

    entry_php = generate_php_entry_finder(
        class_suffix=class_suffix,
        version_label=version_label,
        entry_where=entry_where,
        entry_score=entry_score,
    )

    with open(os.path.join(out_dir, f"FiveMinuteSignalScanner{class_suffix}.php"), "w", encoding="utf-8") as f:
        f.write(signal_php)

    with open(os.path.join(out_dir, f"OneMinuteEntryFinder{class_suffix}.php"), "w", encoding="utf-8") as f:
        f.write(entry_php)


def parse_args() -> argparse.Namespace:
    p = argparse.ArgumentParser()

    p.add_argument("--host", default="127.0.0.1")
    p.add_argument("--port", type=int, default=3306)
    p.add_argument("--user", required=True)
    p.add_argument("--password", required=True)
    p.add_argument("--database", required=True)

    p.add_argument("--asset-type", default="stock")
    p.add_argument("--trade-alerts-table", default="trade_alerts")

    p.add_argument("--alert-symbol-expr", default="ta.symbol")
    p.add_argument("--alert-asset-type-expr", default="ta.asset_type")
    p.add_argument("--alert-signal-ts-expr", default="ta.signal_ts_est")
    p.add_argument("--alert-entry-ts-expr", default="ta.entry_ts_est")
    p.add_argument("--alert-signal-type-expr", default="ta.signal_type")
    p.add_argument("--signal-type", default="")

    p.add_argument("--include-forward-features", type=int, default=1)

    p.add_argument("--signal-max-depth", type=int, default=7)
    p.add_argument("--entry-max-depth", type=int, default=7)
    p.add_argument("--min-samples-leaf", type=int, default=10)
    p.add_argument("--class-weight", default="balanced")

    p.add_argument("--signal-min-precision", type=float, default=0.80)
    p.add_argument("--entry-min-precision", type=float, default=0.80)
    p.add_argument("--signal-min-positive-support", type=int, default=5)
    p.add_argument("--entry-min-positive-support", type=int, default=5)
    p.add_argument("--max-rules", type=int, default=25)

    p.add_argument("--signal-negative-ratio", type=int, default=15)
    p.add_argument("--min-negatives", type=int, default=5000)
    p.add_argument("--entry-negative-hash-threshold", type=int, default=250000)

    p.add_argument("--out-dir", default="./extracted_pipeline")
    p.add_argument("--php-class-suffix", default="V2200_0")
    p.add_argument("--version-label", default="v2200.0")

    return p.parse_args()


def main() -> None:
    args = parse_args()

    include_forward = bool(args.include_forward_features)

    class_weight = args.class_weight
    if class_weight.lower() in ("none", "null", "false", "0"):
        class_weight = None

    class_suffix = safe_class_suffix(args.php_class_suffix)

    conn = pymysql.connect(
        host=args.host,
        port=args.port,
        user=args.user,
        password=args.password,
        database=args.database,
        cursorclass=pymysql.cursors.DictCursor,
        autocommit=True,
    )

    os.makedirs(args.out_dir, exist_ok=True)

    signal_features = build_signal_features(include_forward=include_forward)
    entry_features = build_entry_features(include_forward=include_forward)

    print("Loading signal training data...")
    signal_df = load_signal_data(conn, args, signal_features)
    print(f"Signal rows: {len(signal_df):,}; positives: {int(signal_df['y'].sum()):,}")

    print("Training signal tree...")
    signal_clf, signal_medians, signal_report = train_tree(
        signal_df,
        signal_features,
        max_depth=args.signal_max_depth,
        min_samples_leaf=args.min_samples_leaf,
        class_weight=class_weight,
    )

    signal_rules = extract_positive_rules(
        signal_clf,
        signal_df,
        signal_features,
        signal_medians,
        min_precision=args.signal_min_precision,
        min_positive_support=args.signal_min_positive_support,
        max_rules=args.max_rules,
    )

    print(f"Extracted signal rules: {len(signal_rules)}")

    print("Loading entry training data...")
    entry_df = load_entry_data(conn, args, entry_features)
    print(f"Entry rows: {len(entry_df):,}; positives: {int(entry_df['y'].sum()):,}")

    print("Training entry tree...")
    entry_clf, entry_medians, entry_report = train_tree(
        entry_df,
        entry_features,
        max_depth=args.entry_max_depth,
        min_samples_leaf=args.min_samples_leaf,
        class_weight=class_weight,
    )

    entry_rules = extract_positive_rules(
        entry_clf,
        entry_df,
        entry_features,
        entry_medians,
        min_precision=args.entry_min_precision,
        min_positive_support=args.entry_min_positive_support,
        max_rules=args.max_rules,
    )

    print(f"Extracted entry rules: {len(entry_rules)}")

    signal_where = rules_to_where(signal_rules)
    signal_score = rules_to_score(signal_rules)

    entry_where = rules_to_where(entry_rules)
    entry_score = rules_to_score(entry_rules)

    write_sql_outputs(
        args.out_dir,
        signal_where=signal_where,
        signal_score=signal_score,
        entry_where=entry_where,
        entry_score=entry_score,
    )

    write_php_outputs(
        args.out_dir,
        class_suffix=class_suffix,
        version_label=args.version_label,
        signal_where=signal_where,
        signal_score=signal_score,
        entry_where=entry_where,
        entry_score=entry_score,
    )

    report = {
        "version_label": args.version_label,
        "php_class_suffix": class_suffix,
        "include_forward_features": include_forward,
        "signal": {
            "report": signal_report,
            "rules": signal_rules,
            "feature_medians": signal_medians,
        },
        "entry": {
            "report": entry_report,
            "rules": entry_rules,
            "feature_medians": entry_medians,
        },
    }

    with open(os.path.join(args.out_dir, "extraction_report.json"), "w", encoding="utf-8") as f:
        json.dump(report, f, indent=2, default=str)

    print("")
    print("Done.")
    print(f"Wrote output to: {args.out_dir}")
    print(f"- five_minute_signal_rules.sql")
    print(f"- one_minute_entry_rules.sql")
    print(f"- FiveMinuteSignalScanner{class_suffix}.php")
    print(f"- OneMinuteEntryFinder{class_suffix}.php")
    print(f"- extraction_report.json")


if __name__ == "__main__":
    main()