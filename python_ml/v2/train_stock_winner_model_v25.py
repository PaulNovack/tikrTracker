#!/usr/bin/env python3
"""
train_stock_winner_model_v25.py

Standalone v2.5 winner model trainer.

This version keeps the v2 feature set and adds the pipeline_h entry-quality and
room-to-run columns:
  - entry_spread_strength
  - entry_vwap_dist_score
  - entry_atr_score
  - entry_vol_score
  - entry_candle_score
  - entry_time_bonus
  - room_to_hod_atr

The existing v2 trainer is left untouched so you can test this model side by side.
"""

from __future__ import annotations

import argparse
import os
import sys
from dataclasses import dataclass
from pathlib import Path
from typing import List, Tuple

import joblib
import numpy as np
import pandas as pd
from dotenv import load_dotenv
from sqlalchemy import create_engine, text
from sqlalchemy.engine import URL
from sklearn.metrics import classification_report, confusion_matrix, roc_auc_score
from sklearn.pipeline import Pipeline

ROOT_DIR = Path(__file__).resolve().parents[2]
if str(ROOT_DIR) not in sys.path:
    sys.path.insert(0, str(ROOT_DIR))

from python_ml.shared.features import (  # noqa: E402
    add_derived_features,
    coerce_feature_columns,
    resolve_numeric_cols,
    resolve_present_categorical_cols,
)
from python_ml.shared.models import (  # noqa: E402
    _print_feature_importance,
    _print_subset_metrics,
    build_model,
    build_sample_weights,
    make_label,
    precision_at_k,
    print_probability_buckets,
    score_candidates,
)


@dataclass
class DBConfig:
    host: str
    port: int
    name: str
    user: str
    password: str


FEATURE_COLUMNS = [
    "alert_score",
    "alert_vol_ratio",
    "alert_atr",
    "alert_atr_pct",
    "daily_trend_5d_pct",
    "range_position_60m",
    "alert_rsi_14_1m",
    "five_min_directional_changes",
    "five_min_green_bar_pct",
    "five_min_net_progress",
    "consolidation_bars",
    "breakout_volume_ratio",
    "pct_below_intraday_high",
    "minutes_since_high",
    "price_velocity_5min",
    "price_velocity_10min",
    "failed_rally_count",
    "move_30m_pct",
    "rvol_5m",
    "atr_pct_5m",
    "notional_last5m",
    "spy_move_30m_pct",
    "hod",
    "room_to_hod_pct",
    "room_to_hod_atr",
    "above_vwap_entry_pct",
    "entry_body_pct",
    "entry_close_position",
    "entry_volume_ratio",
    "entry_notional_1m",
    "risk_pct",
    "suggested_trailing_stop_pct",
    "avg_dollar_volume_per_minute",
    "sentiment_score_1_100",
    "entry_spread_strength",
    "entry_vwap_dist_score",
    "entry_atr_score",
    "entry_vol_score",
    "entry_candle_score",
    "entry_time_bonus",
    "vwap_dist_pct",
    "above_vwap",
    "ema9_ema21_spread",
    "ema9_above_ema21",
    "omp_atr_pct",
    "fmp_vwap_dist_pct",
    "fmp_above_vwap",
    "fmp_ema_spread",
    "fmp_ema9_above_ema21",
    "fmp_atr_pct",
    "fmp_rsi_14",
    "abs_vwap_dist_pct",
    "abs_ema_spread",
    "alert_rsi_centered",
    "fmp_rsi_centered",
    "trend_alignment_1m",
    "trend_alignment_5m",
    "spread_1m_minus_5m",
    "rsi_1m_overbought",
    "rsi_1m_oversold",
    "rsi_1m_extreme",
    "rsi_5m_overbought",
    "rsi_5m_oversold",
    "vwap_extended",
    "vwap_very_extended",
    "vol_ratio_extreme",
    "vol_ratio_moderate",
    "atr_too_low",
    "atr_too_high",
    "atr_sweet_spot",
    "green_bars_high",
    "green_bars_balanced",
    "choppy",
    "clean_trend",
    "near_high",
    "off_highs",
    "overextension_score",
    "healthy_setup_score",
    "mkt_day_pct",
    "mkt_5m_ema_trend",
    "mkt_5m_vwap_dist",
    "mkt_5m_rsi",
    "mkt_is_green",
    "mkt_is_strong",
    "mkt_is_weak",
    "mkt_trending_up",
    "rs_spread_vs_market",
    "minutes_since_open",
]

CATEGORICAL_FEATURES = [
    "signal_type",
    "entry_type",
    "time_of_day",
    "pipeline_run",
]


def load_parent_env() -> None:
    script_path = Path(__file__).resolve()
    env_path = script_path.parents[2] / ".env"
    if env_path.exists():
        load_dotenv(dotenv_path=env_path, override=False)


def _get_benchmark_symbol() -> str:
    load_parent_env()
    return os.environ.get("TRADING_MARKET_BENCHMARK_SYMBOL", "QQQ")


def get_db_config_from_env() -> DBConfig:
    load_parent_env()
    return DBConfig(
        host=os.environ.get("DB_HOST", "127.0.0.1"),
        port=int(os.environ.get("DB_PORT", "3306")),
        name=os.environ.get("DB_DATABASE", os.environ.get("DB_NAME", "laravelInvest")),
        user=os.environ.get("DB_USERNAME", os.environ.get("DB_USER", "laravel")),
        password=os.environ.get("DB_PASSWORD", os.environ.get("DB_PASS", "laravel")),
    )


def make_engine(cfg: DBConfig):
    url = URL.create(
        "mysql+pymysql",
        username=cfg.user,
        password=cfg.password,
        host=cfg.host,
        port=cfg.port,
        database=cfg.name,
    )
    return create_engine(url, pool_pre_ping=True)


def _sanitize_pipeline_values(pipeline_value: str | None) -> list[str]:
    if not pipeline_value:
        return []

    pipelines = [value.strip().upper() for value in pipeline_value.split(",") if value.strip()]
    for pipeline in pipelines:
        if not pipeline.isalnum():
            raise SystemExit(f"Invalid pipeline value: {pipeline!r}")
    return pipelines


def _build_pipeline_filter(pipeline_value: str | None) -> str:
    pipelines = _sanitize_pipeline_values(pipeline_value)
    if not pipelines:
        return ""
    if len(pipelines) == 1:
        return f"AND ta.pipeline_run = '{pipelines[0]}'"
    pipeline_list = "', '".join(pipelines)
    return f"AND ta.pipeline_run IN ('{pipeline_list}')"


def _common_select_list() -> str:
    return """
        ta.id AS alert_id,
        ta.symbol,
        ta.asset_type,
        ta.trading_date_est,
        ta.as_of_ts_est,
        ta.signal_ts_est,
        ta.signal_type,
        ta.time_of_day,
        ta.entry_type,
        ta.entry_ts_est,
        ta.entry,
        ta.stop,
        ta.pipeline_run,
        ta.version,

        ta.has_actual_fill,
        ta.pnl_percent,
        ta.actual_pnl_pct,
        ta.r_multiple,

        ta.score AS alert_score,
        ta.vol_ratio AS alert_vol_ratio,
        ta.atr AS alert_atr,
        ta.atr_pct AS alert_atr_pct,
        ta.daily_trend_5d_pct,
        ta.range_position_60m,
        ta.rsi_14_1m AS alert_rsi_14_1m,
        ta.five_min_directional_changes,
        ta.five_min_green_bar_pct,
        ta.five_min_net_progress,
        ta.consolidation_bars,
        ta.breakout_volume_ratio,
        ta.pct_below_intraday_high,
        ta.minutes_since_high,
        ta.price_velocity_5min,
        ta.price_velocity_10min,
        ta.failed_rally_count,
        ta.move_30m_pct,
        ta.rvol_5m,
        ta.atr_pct_5m,
        ta.notional_last5m,
        ta.spy_move_30m_pct,
        ta.hod,
        ta.room_to_hod_pct,
        ta.room_to_hod_atr,
        ta.above_vwap_entry_pct,
        ta.entry_body_pct,
        ta.entry_close_position,
        ta.entry_volume_ratio,
        ta.entry_notional_1m,
        ta.risk_pct,
        ta.suggested_trailing_stop_pct,
        ta.avg_dollar_volume_per_minute,
        ta.sentiment_score_1_100,
        ta.entry_spread_strength,
        ta.entry_vwap_dist_score,
        ta.entry_atr_score,
        ta.entry_vol_score,
        ta.entry_candle_score,
        ta.entry_time_bonus,

        omp.price AS omp_entry_price,
        omp.open  AS omp_open,
        omp.high  AS omp_high,
        omp.low   AS omp_low,
        omp.volume AS omp_volume,
        omp.vwap,
        omp.vwap_dist_pct,
        omp.above_vwap,
        omp.ema9,
        omp.ema21,
        omp.ema9_ema21_spread,
        omp.ema9_above_ema21,
        omp.atr AS omp_atr,
        omp.atr_pct AS omp_atr_pct,

        fmp.price AS fmp_price,
        fmp.open  AS fmp_open,
        fmp.high  AS fmp_high,
        fmp.low   AS fmp_low,
        fmp.volume AS fmp_volume,
        fmp.vwap AS fmp_vwap,
        fmp.vwap_dist_pct AS fmp_vwap_dist_pct,
        fmp.above_vwap AS fmp_above_vwap,
        fmp.ema9 AS fmp_ema9,
        fmp.ema21 AS fmp_ema21,
        fmp.ema9_ema21_spread AS fmp_ema_spread,
        fmp.ema9_above_ema21 AS fmp_ema9_above_ema21,
        fmp.atr AS fmp_atr,
        fmp.atr_pct AS fmp_atr_pct,
        fmp.rsi_14 AS fmp_rsi_14,

        CASE
            WHEN mkt_open.open IS NOT NULL AND mkt_open.open > 0 AND mkt_fmp.price IS NOT NULL
            THEN (mkt_fmp.price - mkt_open.open) / mkt_open.open * 100
            ELSE NULL
        END AS mkt_day_pct,
        mkt_fmp.ema9_above_ema21 AS mkt_5m_ema_trend,
        mkt_fmp.vwap_dist_pct AS mkt_5m_vwap_dist,
        mkt_fmp.rsi_14 AS mkt_5m_rsi,

        CASE
            WHEN stk_open.open IS NOT NULL AND stk_open.open > 0 AND fmp.price IS NOT NULL
            THEN (fmp.price - stk_open.open) / stk_open.open * 100
            ELSE NULL
        END AS stock_intraday_pct
    """


def _common_joins(table_name: str) -> str:
    return f"""
    FROM {table_name} ta

    JOIN one_minute_prices omp
        ON omp.symbol     = ta.symbol
       AND omp.asset_type = ta.asset_type
       AND omp.ts_est = (
           SELECT MAX(omp2.ts_est)
           FROM one_minute_prices omp2
           WHERE omp2.symbol     = ta.symbol
             AND omp2.asset_type = ta.asset_type
             AND omp2.ts_est    <= ta.entry_ts_est
       )

    LEFT JOIN five_minute_prices fmp
        ON fmp.symbol     = ta.symbol
       AND fmp.asset_type = ta.asset_type
       AND fmp.ts_est = (
           SELECT MAX(ts_est)
           FROM five_minute_prices
           WHERE symbol     = ta.symbol
             AND asset_type = ta.asset_type
             AND ts_est    <= ta.signal_ts_est
       )

    LEFT JOIN five_minute_prices mkt_fmp
        ON mkt_fmp.symbol     = :benchmark_symbol
       AND mkt_fmp.asset_type = 'stock'
       AND mkt_fmp.ts_est     = (
           SELECT MAX(ts_est)
           FROM five_minute_prices
           WHERE symbol     = :benchmark_symbol
             AND asset_type = 'stock'
             AND ts_est    <= ta.signal_ts_est
       )

    LEFT JOIN five_minute_prices mkt_open
        ON mkt_open.symbol     = :benchmark_symbol
       AND mkt_open.asset_type = 'stock'
       AND mkt_open.ts_est     = (
           SELECT MIN(ts_est)
           FROM five_minute_prices
           WHERE symbol     = :benchmark_symbol
             AND asset_type = 'stock'
             AND DATE(ts_est) = ta.trading_date_est
       )

    LEFT JOIN five_minute_prices stk_open
        ON stk_open.symbol     = ta.symbol
       AND stk_open.asset_type = ta.asset_type
       AND stk_open.ts_est     = (
           SELECT MIN(ts_est)
           FROM five_minute_prices
           WHERE symbol     = ta.symbol
             AND asset_type = ta.asset_type
             AND DATE(ts_est) = ta.trading_date_est
       )
    """


def load_training_data(
    engine,
    start_ts: str,
    end_ts: str,
    table_name: str,
    pipeline_value: str | None,
    limit: int,
    actual_fills_only: bool,
):
    pipeline_filter = _build_pipeline_filter(pipeline_value)
    extra_filters = ["ta.entry IS NOT NULL"]
    if actual_fills_only:
        extra_filters.append("ta.has_actual_fill = 1")

    where_clause = " AND ".join(extra_filters)
    if where_clause:
        where_clause = f"AND {where_clause}"

    limit_clause = f"LIMIT {int(limit)}" if int(limit or 0) > 0 else ""

    sql = f"""
    SELECT
        {_common_select_list()}
    {_common_joins(table_name)}
    WHERE ta.entry_ts_est >= :start_ts
      AND ta.entry_ts_est <= :end_ts
      {where_clause}
      {pipeline_filter}
    ORDER BY ta.entry_ts_est ASC
    {limit_clause}
    """

    with engine.connect() as conn:
        return pd.read_sql(
            text(sql),
            conn,
            params={"start_ts": start_ts, "end_ts": end_ts, "benchmark_symbol": _get_benchmark_symbol()},
        )


def load_candidates(engine, as_of_ts_est: str, table_name: str, pipeline_value: str | None):
    pipeline_filter = _build_pipeline_filter(pipeline_value)
    sql = f"""
    SELECT
        {_common_select_list()}
    {_common_joins(table_name)}
    WHERE ta.as_of_ts_est = :as_of_ts_est
      AND ta.entry IS NOT NULL
      {pipeline_filter}
    """

    with engine.connect() as conn:
        return pd.read_sql(
            text(sql),
            conn,
            params={"as_of_ts_est": as_of_ts_est, "benchmark_symbol": _get_benchmark_symbol()},
        )


def split_by_time(df: pd.DataFrame, test_size: float = 0.2) -> Tuple[pd.DataFrame, pd.DataFrame]:
    if "entry_ts_est" not in df.columns:
        raise KeyError(f"Missing 'entry_ts_est' column. Columns: {list(df.columns)}")

    d = df.sort_values("entry_ts_est").reset_index(drop=True)
    n_test = max(1, int(len(d) * test_size)) if test_size > 0 else 0
    if n_test == 0:
        return d, d.iloc[0:0].copy()
    return d.iloc[:-n_test].copy(), d.iloc[-n_test:].copy()


def split_by_trading_day(df: pd.DataFrame, test_size: float = 0.2) -> Tuple[pd.DataFrame, pd.DataFrame]:
    if "trading_date_est" not in df.columns:
        return split_by_time(df, test_size=test_size)

    days = sorted(pd.to_datetime(df["trading_date_est"]).dropna().dt.date.unique())
    n_test_days = max(1, int(len(days) * test_size)) if test_size > 0 else 0
    if n_test_days == 0:
        return df.copy(), df.iloc[0:0].copy()

    test_days = set(days[-n_test_days:])
    mask = pd.to_datetime(df["trading_date_est"]).dt.date.isin(test_days)
    return df.loc[~mask].copy(), df.loc[mask].copy()


def train_and_eval(
    df: pd.DataFrame,
    win_threshold_pct: float,
    top_k: int,
    baseline: bool,
    test_size: float = 0.2,
    split_mode: str = "time",
    actual_fill_weight: float = 1.0,
    eval_on_actual_only: bool = False,
    calibrate: bool = False,
) -> Tuple[Pipeline, dict, pd.DataFrame]:
    df2 = coerce_feature_columns(add_derived_features(df), FEATURE_COLUMNS, CATEGORICAL_FEATURES)

    if "pnl_percent" not in df2.columns:
        raise KeyError(f"Expected pnl_percent from SQL but it is missing. Columns: {list(df2.columns)}")

    if split_mode == "day":
        train_df, test_df = split_by_trading_day(df2, test_size=test_size)
    else:
        train_df, test_df = split_by_time(df2, test_size=test_size)

    numeric_cols = resolve_numeric_cols(train_df, FEATURE_COLUMNS)
    cat_cols = resolve_present_categorical_cols(train_df, CATEGORICAL_FEATURES)
    print(f"[features] Using {len(numeric_cols)} numeric + {len(cat_cols)} categorical feature columns.")

    y_train = make_label(train_df, win_threshold_pct).to_numpy()
    if len(np.unique(y_train)) < 2:
        raise SystemExit(
            f"Training set has only one class. wins={int(np.sum(y_train == 1))}, losses={int(np.sum(y_train == 0))}. "
            "Use a wider date range, lower win threshold, or different pipeline filter."
        )

    feature_cols = numeric_cols + cat_cols
    X_train = train_df[feature_cols]
    sample_weight_train = build_sample_weights(train_df, actual_fill_weight)

    num_negative = int(np.sum(y_train == 0))
    num_positive = int(np.sum(y_train == 1))
    scale_pos_weight = num_negative / num_positive if num_positive > 0 else 1.0
    if not baseline:
        print(f"\nClass distribution: {num_positive} wins, {num_negative} losses")
        print(f"Using scale_pos_weight={scale_pos_weight:.2f} for XGBoost")

    if calibrate and not baseline and "has_actual_fill" in train_df.columns:
        actual_count = int((train_df["has_actual_fill"] == 1).sum())
        if actual_count < 100:
            print(
                f"[calibration] Disabled — only {actual_count} actual-fill rows in training set "
                "(need >=100 for reliable isotonic calibration)"
            )
            calibrate = False

    model = build_model(
        numeric_cols,
        use_baseline=baseline,
        scale_pos_weight=scale_pos_weight,
        calibrate=calibrate,
        categorical_features=cat_cols,
    )

    model.fit(X_train, y_train, clf__sample_weight=sample_weight_train)

    if not baseline:
        _print_feature_importance(model, numeric_cols)

    if len(test_df) == 0:
        metrics = {
            "test_auc": float("nan"),
            "confusion_matrix": [],
            "classification_report": "No test split (test_size=0). Train-only model.",
            "precision_at_k": float("nan"),
            "win_rate_test": float("nan"),
            "rows_train": int(len(train_df)),
            "rows_test": 0,
            "features_used": feature_cols,
        }
        return model, metrics, pd.DataFrame()

    y_test = make_label(test_df, win_threshold_pct).to_numpy()
    X_test = test_df[feature_cols]
    p_test = model.predict_proba(X_test)[:, 1]

    metrics = {
        "test_auc": roc_auc_score(y_test, p_test) if len(np.unique(y_test)) > 1 else float("nan"),
        "confusion_matrix": confusion_matrix(y_test, (p_test >= 0.5).astype(int)).tolist(),
        "classification_report": classification_report(y_test, (p_test >= 0.5).astype(int), digits=4),
        "precision_at_k": precision_at_k(y_test, p_test, top_k),
        "win_rate_test": float(np.mean(y_test)),
        "rows_train": int(len(train_df)),
        "rows_test": int(len(test_df)),
        "features_used": feature_cols,
    }

    if "has_actual_fill" in test_df.columns:
        print("\n=== Subset Metrics ===")
        for subset_name, mask in {
            "actual_only": test_df["has_actual_fill"].to_numpy() == 1,
            "bt_only": test_df["has_actual_fill"].to_numpy() == 0,
        }.items():
            actual_count = int(mask.sum())
            if actual_count > 20:
                _print_subset_metrics(subset_name, y_test[mask], p_test[mask], top_k)
            else:
                print(f"\n  [{subset_name}] only {actual_count} rows — skipping subset metrics")

        if eval_on_actual_only:
            actual_mask = test_df["has_actual_fill"].to_numpy() == 1
            if actual_mask.sum() > 5:
                y_actual = y_test[actual_mask]
                p_actual = p_test[actual_mask]
                metrics["test_auc_actual_only"] = (
                    roc_auc_score(y_actual, p_actual) if len(np.unique(y_actual)) > 1 else float("nan")
                )
                metrics["precision_at_k_actual_only"] = precision_at_k(y_actual, p_actual, top_k)
                metrics["rows_test_actual_only"] = int(actual_mask.sum())
                metrics["win_rate_actual_only"] = float(np.mean(y_actual))
                print(
                    f"\n[actual_only_eval] rows={int(actual_mask.sum())}  "
                    f"win_rate={float(np.mean(y_actual)):.3f}  "
                    f"AUC={metrics['test_auc_actual_only']:.4f}  "
                    f"Precision@{top_k}={metrics['precision_at_k_actual_only']:.3f}"
                )

    scored = test_df.copy()
    scored["win_prob"] = p_test
    scored["is_win"] = (scored["pnl_percent"].astype(float) >= float(win_threshold_pct)).astype(int)
    print_probability_buckets(scored, win_threshold_pct, top_k)

    return model, metrics, scored


def cmd_train(args):
    if getattr(args, "no_test_split", False):
        args.test_size = 0.0

    if not (0.0 <= args.test_size < 1.0):
        raise SystemExit("--test-size must be >= 0 and < 1 (e.g. 0.2 for 20% holdout)")

    import sys as _sys
    from datetime import datetime as _dt

    _log_dir = os.path.join(os.path.dirname(__file__), "training_logs")
    os.makedirs(_log_dir, exist_ok=True)
    _log_date = _dt.now().strftime("%Y-%m-%d_%H")
    _pipeline_suffix = args.pipeline or "all"
    _log_path = os.path.join(_log_dir, f"{_pipeline_suffix}-{_log_date}.log")
    _log_fh = open(_log_path, "w")

    class _Tee:  # noqa: N801
        def __init__(self, *files):
            self.files = files

        def write(self, s):
            for f in self.files:
                f.write(s)
                f.flush()

        def flush(self):
            for f in self.files:
                f.flush()

    _sys.stdout = _Tee(_sys.stdout, _log_fh)
    _sys.stderr = _sys.stdout
    print(f"[v2.5] Log: {_log_path}")

    cfg = get_db_config_from_env()
    engine = make_engine(cfg)

    df = load_training_data(
        engine,
        args.start,
        args.end,
        args.table,
        args.pipeline,
        args.limit,
        getattr(args, "actual_fills_only", False),
    )

    if getattr(args, "bt_only", False) and "has_actual_fill" in df.columns:
        n_before = len(df)
        df = df[df["has_actual_fill"] == 0].copy()
        n_actual_excluded = n_before - len(df)
        if n_actual_excluded > 0:
            print(
                f"[bt-only] Excluded {n_actual_excluded} trades with real Alpaca fills "
                f"({len(df)} BT-simulated trades remain)"
            )

    if args.debug:
        print("Loaded rows:", len(df))
        print("Columns:", list(df.columns))
        if args.pipeline:
            print(f"Filtered to pipeline(s): {args.pipeline}")

    if df.empty:
        raise SystemExit(
            "No rows returned. Check:\n"
            "- Date range\n"
            "- That one_minute_prices has matching ts_est for trade_alerts.entry_ts_est\n"
            "- That trade_alerts.pnl_percent is populated for those rows"
        )

    if "pnl_percent" not in df.columns:
        raise SystemExit(
            "Expected 'pnl_percent' in training data but it was missing.\n"
            f"Columns returned: {list(df.columns)}"
        )

    actual_fill_weight = getattr(args, "actual_fill_weight", 1.0)
    print(f"[config] actual_fill_weight={actual_fill_weight}x for rows with real Alpaca fills")

    eval_on_actual = getattr(args, "eval_on_actual_only", False)
    if eval_on_actual:
        print("[config] eval_on_actual_only=True — metrics report actual-fill performance separately")
    calibrate = bool(getattr(args, "calibrate", False))

    model, metrics, topk_df = train_and_eval(
        df=df,
        win_threshold_pct=args.win_threshold,
        top_k=args.top_k,
        baseline=args.baseline,
        test_size=args.test_size,
        split_mode=args.split_mode,
        actual_fill_weight=actual_fill_weight,
        eval_on_actual_only=eval_on_actual,
        calibrate=calibrate,
    )

    if args.train_full:
        df2 = coerce_feature_columns(add_derived_features(df), FEATURE_COLUMNS, CATEGORICAL_FEATURES)
        numeric_cols = [c for c in metrics["features_used"] if c not in CATEGORICAL_FEATURES]
        y_full = make_label(df2, args.win_threshold).to_numpy()
        num_negative = int(np.sum(y_full == 0))
        num_positive = int(np.sum(y_full == 1))
        scale_pos_weight = num_negative / num_positive if num_positive > 0 else 1.0
        model = build_model(
            numeric_cols,
            use_baseline=args.baseline,
            scale_pos_weight=scale_pos_weight,
            calibrate=False,
            categorical_features=resolve_present_categorical_cols(df2, CATEGORICAL_FEATURES),
        )
        feature_cols = metrics["features_used"]
        X = df2[feature_cols]
        sample_weight = build_sample_weights(df2, actual_fill_weight)
        model.fit(X, y_full, clf__sample_weight=sample_weight)
        print("\nTrained FINAL model on 100% of data (after evaluation).")

    os.makedirs(os.path.dirname(args.model_out) or ".", exist_ok=True)
    payload = {
        "model": model,
        "meta": {
            "model_version": Path(args.model_out).stem,
            "win_threshold_pct": args.win_threshold,
            "top_k_eval": args.top_k,
            "baseline": args.baseline,
            "table_name": args.table,
            "test_size": args.test_size,
            "trained_full": bool(args.train_full),
            "pipeline": args.pipeline,
            "metrics": metrics,
            "feature_columns": metrics["features_used"],
        },
    }
    joblib.dump(payload, args.model_out)
    print("Saved model to:", args.model_out)

    print("\n=== Metrics (evaluation split) ===")
    print("Rows train:", metrics["rows_train"], " Rows test:", metrics["rows_test"])
    print("Test AUC:", metrics["test_auc"])
    print(f"Precision@{args.top_k}:", metrics["precision_at_k"])
    print("Test win rate:", metrics["win_rate_test"])

    if metrics.get("test_auc_actual_only") is not None:
        print(f"  Win rate (actual): {metrics['win_rate_actual_only']:.4f}")
        print(f"  AUC (actual):      {metrics['test_auc_actual_only']:.4f}")
        print(f"  Precision@{args.top_k} (actual): {metrics['precision_at_k_actual_only']:.3f}")
        print(f"  Rows (actual):     {metrics['rows_test_actual_only']}")

    if metrics["confusion_matrix"]:
        print("\nConfusion matrix [ [TN, FP], [FN, TP] ]:", metrics["confusion_matrix"])
        print("\nClassification report:\n", metrics["classification_report"])
        if not topk_df.empty:
            print(f"\n=== TOP {args.top_k} TEST PICKS (by win_prob) ===")
            display_cols = [c for c in [
                "symbol", "signal_type", "entry_type", "time_of_day", "entry_ts_est",
                "entry", "stop", "pnl_percent", "r_multiple", "win_prob", "is_win",
                "pipeline_run", "version",
            ] if c in topk_df.columns]
            print(topk_df.sort_values("win_prob", ascending=False).head(args.top_k)[display_cols].to_string(index=False))
    else:
        print("\n(No test split; test_size=0)")

    if args.train_full:
        print("\n=== Saved model is FULL-DATA model ===")
        if args.test_size > 0:
            print("Evaluation metrics above are from the holdout split.")


def cmd_score(args):
    payload = joblib.load(args.model_in)
    model = payload["model"]
    feature_columns = payload.get("meta", {}).get("feature_columns")

    cfg = get_db_config_from_env()
    engine = make_engine(cfg)

    cand = load_candidates(engine, args.as_of_ts_est, args.table, args.pipeline)
    if cand.empty:
        raise SystemExit(
            "No candidates returned. Check:\n"
            "- You passed an EST timestamp matching trade_alerts.as_of_ts_est\n"
            "- trade_alerts has rows for that minute with entry IS NOT NULL\n"
            "- one_minute_prices has matching ts_est for those entry_ts_est values\n"
        )

    ranked = score_candidates(model, cand, top_n=args.top_n, feature_columns=feature_columns)
    print(ranked.to_string(index=False))


def main():
    ap = argparse.ArgumentParser()
    sub = ap.add_subparsers(dest="cmd", required=True)

    ap_train = sub.add_parser("train", help="Train a v2.5 winner model from trade_alerts outcomes")
    ap_train.add_argument("--start", required=True, help="EST datetime (YYYY-MM-DD or YYYY-MM-DD HH:MM:SS)")
    ap_train.add_argument("--end", required=True, help="EST datetime (YYYY-MM-DD or YYYY-MM-DD HH:MM:SS)")
    ap_train.add_argument("--table", default="trade_alerts", choices=["trade_alerts", "trade_alerts_unfiltered"], help="Table to train from")
    ap_train.add_argument("--pipeline", default=None, help="Filter to specific pipeline(s) — single: 'M' or multiple: 'A,B,C,D'")
    ap_train.add_argument("--limit", type=int, default=8000, help="Keep the most recent N filtered rows before splitting; use 0 to disable")
    ap_train.add_argument("--bt-only", action="store_true", default=False, help="Exclude trades with real Alpaca fills (train on BT-simulated outcomes only)")
    ap_train.add_argument("--include-actual-fills", dest="bt_only", action="store_false", help="Include trades with real Alpaca fills in training data")
    ap_train.add_argument("--win-threshold", type=float, default=2.0, help="Win if pnl_percent >= threshold")
    ap_train.add_argument("--top-k", type=int, default=10, help="Evaluate precision@K on the test split")
    ap_train.add_argument("--baseline", action="store_true", help="Use logistic regression baseline instead of XGBoost")
    ap_train.add_argument("--test-size", type=float, default=0.2, help="Time-based holdout fraction (e.g. 0.2 = last 20%% is test). Use 0 for no split.")
    ap_train.add_argument("--split-mode", choices=["time", "day"], default="time", help="How to build the holdout split: time sorts by entry_ts_est; day holds out trading dates.")
    ap_train.add_argument("--train-full", action="store_true", help="After eval, retrain final model on 100%% of rows and save that model")
    ap_train.add_argument("--no-test-split", dest="no_test_split", action="store_true", help="Disable holdout split (same as --test-size 0)")
    ap_train.add_argument("--model-out", default="models/winner_model_v25.joblib")
    ap_train.add_argument("--actual-fill-weight", type=float, default=1.0, help="Sample weight multiplier for rows with actual Alpaca fills (default: 1x). Try 10-20x to emphasize real fills when BT-simulated labels dominate.")
    ap_train.add_argument("--actual-fills-only", action="store_true", help="Train and evaluate ONLY on rows with actual Alpaca fills (ignores all BT-simulated labels). Requires sufficient live trading history.")
    ap_train.add_argument("--eval-on-actual-only", action="store_true", help="Train on all data but report additional evaluation metrics computed ONLY on actual-fill test rows.")
    ap_train.add_argument("--calibrate", action="store_true", help="Enable isotonic probability calibration. Automatically disabled if <100 actual-fill rows in training set.")
    ap_train.add_argument("--debug", action="store_true", help="Print debug info (rows + columns loaded)")
    ap_train.set_defaults(func=cmd_train)

    ap_score = sub.add_parser("score", help="Score trade alerts at a specific EST minute timestamp")
    ap_score.add_argument("--model-in", required=True)
    ap_score.add_argument("--as-of-ts-est", dest="as_of_ts_est", required=True, help="EST datetime (YYYY-MM-DD HH:MM:SS) matching trade_alerts.as_of_ts_est")
    ap_score.add_argument("--top-n", type=int, default=10)
    ap_score.add_argument("--pipeline", default=None, help="Filter candidates to this pipeline (must match training pipeline)")
    ap_score.add_argument("--table", default="trade_alerts", choices=["trade_alerts", "trade_alerts_unfiltered"], help="Table to score from")
    ap_score.set_defaults(func=cmd_score)

    args = ap.parse_args()
    args.func(args)


if __name__ == "__main__":
    main()