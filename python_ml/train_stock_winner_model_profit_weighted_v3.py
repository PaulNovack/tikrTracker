#!/usr/bin/env python3
"""
train_stock_winner_model_profit_weighted_v3.py

Return-aware sample-weighting wrapper over train_stock_winner_model_v3.

Extends v3 (which adds alert_age_minutes + price_move_since_signal_pct features) with the
same profit-weighted training strategy used by train_stock_winner_model_profit_weighted.py.

Payload contract:
  - Compatible with score_single_alert_v3.py (same Pipeline extraction contract)
  - meta.trainer_variant == "profit_weighted_v3"

Usage:
  python python_ml/train_stock_winner_model_profit_weighted_v3.py train \\
      --start 2025-01-01 --end 2026-06-01 \\
      --pipeline H --train-full \\
      --profit-weighting-mode asymmetric \\
      --model-out python_ml/models/winner_model_pipeline_h_v3_pw.joblib
"""

from __future__ import annotations

import argparse
import os
from contextlib import contextmanager

import joblib
import numpy as np
import pandas as pd

import train_stock_winner_model_v3 as base


@contextmanager
def patched_sample_weight_builder(weight_fn):
    """Temporarily replace v3 sample-weight builder so all downstream training paths use it."""
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
    1) Keep existing actual-fill weighting behavior from v3
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

    if "pnl_percent" not in df.columns:
        print("[weights] WARNING: pnl_percent not found — falling back to base weights")
        return base_weights

    pnl = df["pnl_percent"].astype(float).to_numpy()

    if mode == "abs_return":
        clipped     = np.clip(np.abs(pnl), 0.0, float(clip_pos_pct))
        return_w    = 1.0 + scale * clipped / float(clip_pos_pct)

    elif mode == "asymmetric":
        pos_raw     = np.clip(pnl, 0.0, float(clip_pos_pct))
        neg_raw     = np.clip(-pnl, 0.0, float(clip_neg_pct))
        pos_w       = scale * pos_raw / float(clip_pos_pct)
        neg_w       = scale * neg_raw / float(clip_neg_pct) * float(loss_multiplier)
        return_w    = 1.0 + np.maximum(pos_w, neg_w)

    else:
        raise ValueError(f"Unknown profit_weighting_mode: {mode!r}")

    combined = base_weights * return_w
    mean_w   = combined.mean()
    if mean_w > 0:
        combined /= mean_w

    win_mask  = pnl >= 0
    loss_mask = pnl <  0
    print(
        f"[weights] profit_weighted ({mode}): "
        f"wins_avg={combined[win_mask].mean():.3f}  "
        f"losses_avg={combined[loss_mask].mean():.3f}  "
        f"overall_mean={combined.mean():.3f}"
    )
    return combined


def cmd_train(args):
    if getattr(args, "no_test_split", False):
        args.test_size = 0.0

    # Build the per-call weight function, closing over args
    def local_weight_builder(df: pd.DataFrame, actual_fill_weight: float) -> np.ndarray:
        return build_profit_weighted_sample_weights(
            df=df,
            actual_fill_weight=actual_fill_weight,
            mode=args.profit_weighting_mode,
            scale=args.profit_weight_scale,
            loss_multiplier=args.profit_loss_multiplier,
            clip_pos_pct=args.profit_clip_pos_pct,
            clip_neg_pct=args.profit_clip_neg_pct,
        )

    print(f"[profit_weighted_v3] mode={args.profit_weighting_mode}  "
          f"scale={args.profit_weight_scale}  "
          f"loss_multiplier={args.profit_loss_multiplier}  "
          f"clip_pos={args.profit_clip_pos_pct}%  "
          f"clip_neg={args.profit_clip_neg_pct}%")

    with patched_sample_weight_builder(local_weight_builder):
        base.cmd_train(args)

    # Enrich the payload with profit-weighting metadata
    payload = joblib.load(args.model_out)
    payload["meta"]["trainer_variant"] = "profit_weighted_v3"
    payload["meta"]["profit_weighting"] = {
        "mode":            args.profit_weighting_mode,
        "scale":           args.profit_weight_scale,
        "loss_multiplier": args.profit_loss_multiplier,
        "clip_pos_pct":    args.profit_clip_pos_pct,
        "clip_neg_pct":    args.profit_clip_neg_pct,
    }
    joblib.dump(payload, args.model_out)
    print(f"[profit_weighted_v3] Enriched payload saved to: {args.model_out}")


def build_parser() -> argparse.ArgumentParser:
    ap = argparse.ArgumentParser()
    sub = ap.add_subparsers(dest="cmd", required=True)

    ap_train = sub.add_parser("train", help="Train a v3 winner model with optional profit-aware sample weighting")
    ap_train.add_argument("--start", required=True, help="EST datetime (YYYY-MM-DD or YYYY-MM-DD HH:MM:SS)")
    ap_train.add_argument("--end",   required=True, help="EST datetime (YYYY-MM-DD or YYYY-MM-DD HH:MM:SS)")
    ap_train.add_argument(
        "--table",
        default="trade_alerts",
        choices=["trade_alerts", "trade_alerts_unfiltered"],
        help="Table to train from",
    )
    ap_train.add_argument("--pipeline", default=None, help="Filter to specific pipeline(s): 'M' or 'A,B,C'")
    ap_train.add_argument("--win-threshold",    type=float, default=1.0,  help="Win if pnl_percent >= threshold")
    ap_train.add_argument("--top-k",            type=int,   default=10,   help="Evaluate precision@K on the test split")
    ap_train.add_argument("--baseline",         action="store_true",      help="Use logistic regression baseline")
    ap_train.add_argument("--test-size",        type=float, default=0.2,  help="Time-based holdout fraction")
    ap_train.add_argument("--train-full",       action="store_true",      help="After eval, retrain on 100% and save")
    ap_train.add_argument("--no-test-split",    dest="no_test_split",     action="store_true",
                          help="Same as --test-size 0")
    ap_train.add_argument("--model-out",        default="models/winner_model_profit_weighted_v3.joblib")
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

    ap_score = sub.add_parser("score", help="Score mode (delegates to v3 scorer contract)")
    ap_score.add_argument("--model-in", required=True)
    ap_score.add_argument("--as-of-ts-est", dest="as_of_ts_est", required=True)
    ap_score.add_argument("--top-n", type=int, default=10)
    ap_score.set_defaults(func=base.cmd_score)

    return ap


def main() -> None:
    parser = build_parser()
    args   = parser.parse_args()
    args.func(args)


if __name__ == "__main__":
    main()
