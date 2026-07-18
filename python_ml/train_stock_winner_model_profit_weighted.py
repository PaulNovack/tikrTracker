#!/usr/bin/env python3
"""
train_stock_winner_model_profit_weighted.py

Drop-in trainer compatible with train_stock_winner_model_v2.py payload/scoring contract,
with optional return-aware sample weighting to emphasize high-impact outcomes.

Usage (same as v2):
  python python_ml/train_stock_winner_model_profit_weighted.py train --start ... --end ... --model-out ...

Scoring command is preserved and delegated to v2 implementation.
"""

from __future__ import annotations

import argparse
import os
from contextlib import contextmanager

import joblib
import numpy as np
import pandas as pd

import train_stock_winner_model_v2 as base


@contextmanager
def patched_sample_weight_builder(weight_fn):
    """Temporarily replace v2 sample-weight builder so all downstream training paths use it."""
    original = base.build_sample_weights
    base.build_sample_weights = weight_fn
    try:
        yield
    finally:
        base.build_sample_weights = original


def build_profit_weighted_sample_weights(
    df: pd.DataFrame,
    actual_fill_weight: float,
    mode: str,
    scale: float,
    loss_multiplier: float,
    clip_pos_pct: float,
    clip_neg_pct: float,
) -> np.ndarray:
    """
    Build combined sample weights:
    1) Keep existing actual-fill weighting behavior from v2
    2) Multiply by return-aware weights derived from pnl_percent
    3) Normalize to mean=1.0 for training stability
    """
    base_weights = np.ones(len(df), dtype=float)
    if "has_actual_fill" in df.columns and actual_fill_weight != 1.0:
        base_weights = np.where(df["has_actual_fill"].to_numpy() == 1, float(actual_fill_weight), 1.0)
        n_actual = int((df["has_actual_fill"] == 1).sum())
        print(f"[weights] Boosting {n_actual} actual-fill rows by {actual_fill_weight}x")

    if mode == "none":
        return base_weights

    pnl = pd.to_numeric(df.get("pnl_percent"), errors="coerce").fillna(0.0).to_numpy(dtype=float)
    clipped = np.clip(pnl, -abs(float(clip_neg_pct)), abs(float(clip_pos_pct)))

    if mode == "abs_return":
        # Emphasize larger absolute moves regardless of direction.
        profit_weights = 1.0 + float(scale) * np.abs(clipped)
    elif mode == "asymmetric":
        # Emphasize both tails while allowing extra penalty learning from large losses.
        pos = np.maximum(clipped, 0.0)
        neg = np.abs(np.minimum(clipped, 0.0))
        profit_weights = 1.0 + float(scale) * (pos + float(loss_multiplier) * neg)
    else:
        raise ValueError(f"Unsupported profit weighting mode: {mode}")

    combined = base_weights * profit_weights

    mean_weight = float(np.mean(combined))
    if mean_weight > 0:
        combined = combined / mean_weight

    print(
        "[profit-weights] "
        f"mode={mode}, scale={scale}, loss_multiplier={loss_multiplier}, "
        f"clip=[-{clip_neg_pct}, +{clip_pos_pct}]%, "
        f"min={combined.min():.3f}, p50={np.percentile(combined, 50):.3f}, "
        f"p90={np.percentile(combined, 90):.3f}, max={combined.max():.3f}"
    )

    return combined


def cmd_train(args):
    # Back-compat: legacy --no-test-split flag
    if getattr(args, "no_test_split", False):
        args.test_size = 0.0

    if not (0.0 <= args.test_size < 1.0):
        raise SystemExit("--test-size must be >= 0 and < 1 (e.g. 0.2 for 20% holdout)")

    cfg = base.get_db_config_from_env()
    engine = base.make_engine(cfg)

    df = base.load_training_data(engine, args.start, args.end, args.table, args.pipeline)
    df = base.add_recent_loss_streak(df)

    if args.debug:
        print("Loaded rows:", len(df))
        print("Columns:", list(df.columns))
        if args.pipeline:
            pipelines = base._sanitize_pipeline_values(args.pipeline)
            print(f"Filtered to pipeline(s): {', '.join(pipelines)}")

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

    print(
        "[config] actual_fill_weight="
        f"{args.actual_fill_weight}x, profit_weighting_mode={args.profit_weighting_mode}, "
        f"profit_weight_scale={args.profit_weight_scale}"
    )

    def local_weight_builder(frame: pd.DataFrame, actual_fill_weight: float) -> np.ndarray:
        return build_profit_weighted_sample_weights(
            df=frame,
            actual_fill_weight=actual_fill_weight,
            mode=args.profit_weighting_mode,
            scale=args.profit_weight_scale,
            loss_multiplier=args.profit_loss_multiplier,
            clip_pos_pct=args.profit_clip_pos_pct,
            clip_neg_pct=args.profit_clip_neg_pct,
        )

    with patched_sample_weight_builder(local_weight_builder):
        model, metrics, topk_df = base.train_and_eval(
            df=df,
            win_threshold_pct=args.win_threshold,
            top_k=args.top_k,
            baseline=args.baseline,
            test_size=args.test_size,
            actual_fill_weight=args.actual_fill_weight,
        )

        if args.train_full:
            df2 = base.add_derived_features(df)
            numeric_cols = metrics["features_used"]
            model = base.train_full_model(
                df=df2,
                win_threshold_pct=args.win_threshold,
                baseline=args.baseline,
                numeric_cols=numeric_cols,
                actual_fill_weight=args.actual_fill_weight,
            )
            print("\nTrained FINAL model on 100% of data (after evaluation).")

    os.makedirs(os.path.dirname(args.model_out) or ".", exist_ok=True)
    payload = {
        "model": model,
        "meta": {
            "win_threshold_pct": args.win_threshold,
            "top_k_eval": args.top_k,
            "baseline": args.baseline,
            "table_name": args.table,
            "test_size": args.test_size,
            "trained_full": bool(args.train_full),
            "metrics": metrics,
            "feature_columns": metrics["features_used"],
            "trainer_variant": "profit_weighted",
            "profit_weighting": {
                "mode": args.profit_weighting_mode,
                "scale": args.profit_weight_scale,
                "loss_multiplier": args.profit_loss_multiplier,
                "clip_pos_pct": args.profit_clip_pos_pct,
                "clip_neg_pct": args.profit_clip_neg_pct,
            },
        },
    }
    joblib.dump(payload, args.model_out)
    print("Saved model to:", args.model_out)

    print("\n=== Metrics (evaluation split) ===")
    print("Rows train:", metrics["rows_train"], " Rows test:", metrics["rows_test"])
    print("Test AUC:", metrics["test_auc"])
    print(f"Precision@{args.top_k}:", metrics["precision_at_k"])
    print("Test win rate:", metrics["win_rate_test"])

    if metrics["confusion_matrix"]:
        print("\nConfusion matrix [ [TN, FP], [FN, TP] ]:", metrics["confusion_matrix"])
        print("\nClassification report:\n", metrics["classification_report"])
        if not topk_df.empty:
            print(f"\n=== TOP {args.top_k} TEST PICKS (by win_prob) ===")
            print(topk_df.to_string(index=False))
    else:
        print("\n(No test split; test_size=0)")

    if args.train_full:
        print("\n=== Saved model is FULL-DATA model ===")
        if args.test_size > 0:
            print("Evaluation metrics above are from the holdout split.")


def build_parser() -> argparse.ArgumentParser:
    ap = argparse.ArgumentParser()
    sub = ap.add_subparsers(dest="cmd", required=True)

    ap_train = sub.add_parser("train", help="Train a winner model with optional profit-aware sample weighting")
    ap_train.add_argument("--start", required=True, help="EST datetime (YYYY-MM-DD or YYYY-MM-DD HH:MM:SS)")
    ap_train.add_argument("--end", required=True, help="EST datetime (YYYY-MM-DD or YYYY-MM-DD HH:MM:SS)")
    ap_train.add_argument(
        "--table",
        default="trade_alerts",
        choices=["trade_alerts", "trade_alerts_unfiltered"],
        help="Table to train from",
    )
    ap_train.add_argument("--pipeline", default=None, help="Filter to specific pipeline(s): 'M' or 'A,B,C'")
    ap_train.add_argument("--win-threshold", type=float, default=1.0, help="Win if pnl_percent >= threshold")
    ap_train.add_argument("--top-k", type=int, default=10, help="Evaluate precision@K on the test split")
    ap_train.add_argument("--baseline", action="store_true", help="Use logistic regression baseline")
    ap_train.add_argument("--test-size", type=float, default=0.2, help="Time-based holdout fraction")
    ap_train.add_argument("--train-full", action="store_true", help="After eval, retrain on 100% and save")
    ap_train.add_argument("--no-test-split", dest="no_test_split", action="store_true", help="Same as --test-size 0")
    ap_train.add_argument("--model-out", default="models/winner_model_profit_weighted.joblib")
    ap_train.add_argument(
        "--actual-fill-weight",
        type=float,
        default=1.0,
        help="Sample weight multiplier for rows with actual Alpaca fills",
    )

    # Profit-aware weighting controls
    ap_train.add_argument(
        "--profit-weighting-mode",
        choices=["none", "abs_return", "asymmetric"],
        default="asymmetric",
        help="How pnl_percent contributes to sample weighting",
    )
    ap_train.add_argument(
        "--profit-weight-scale",
        type=float,
        default=0.25,
        help="Global scale for return-aware weighting",
    )
    ap_train.add_argument(
        "--profit-loss-multiplier",
        type=float,
        default=1.5,
        help="Extra emphasis on loss magnitude in asymmetric mode",
    )
    ap_train.add_argument(
        "--profit-clip-pos-pct",
        type=float,
        default=10.0,
        help="Positive return clip (in pct points) before weighting",
    )
    ap_train.add_argument(
        "--profit-clip-neg-pct",
        type=float,
        default=5.0,
        help="Negative return clip magnitude (in pct points) before weighting",
    )

    ap_train.add_argument("--debug", action="store_true", help="Print debug info")
    ap_train.set_defaults(func=cmd_train)

    ap_score = sub.add_parser("score", help="Score mode (delegates to v2 scorer contract)")
    ap_score.add_argument("--model-in", required=True)
    ap_score.add_argument("--as-of-ts-est", dest="as_of_ts_est", required=True)
    ap_score.add_argument("--top-n", type=int, default=10)
    ap_score.set_defaults(func=base.cmd_score)

    return ap


def main() -> None:
    parser = build_parser()
    args = parser.parse_args()
    args.func(args)


if __name__ == "__main__":
    main()
