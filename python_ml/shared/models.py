"""
shared/models.py — Model building and evaluation helpers.

Provides:
  - build_model() — ColumnTransformer + XGBoost/LogisticRegression Pipeline
  - precision_at_k()
  - _print_subset_metrics()
  - _print_feature_importance()
  - print_probability_buckets()
  - make_label()
  - build_sample_weights()
"""

from __future__ import annotations

from typing import List, Optional

import numpy as np
import pandas as pd
from sklearn.calibration import CalibratedClassifierCV
from sklearn.compose import ColumnTransformer
from sklearn.impute import SimpleImputer
from sklearn.linear_model import LogisticRegression
from sklearn.metrics import (
    roc_auc_score,
)
from sklearn.pipeline import Pipeline
from sklearn.preprocessing import OneHotEncoder, StandardScaler
import xgboost as xgb


# ---------------------------------------------------------------------------
# Default XGBoost hyperparameters
# ---------------------------------------------------------------------------

DEFAULT_XGB_PARAMS = {
    "max_depth": 3,
    "learning_rate": 0.03,
    "n_estimators": 300,
    "subsample": 0.8,
    "colsample_bytree": 0.8,
    "reg_alpha": 0.5,
    "reg_lambda": 3.0,
    "min_child_weight": 8,
    "random_state": 42,
    "eval_metric": "logloss",
    "verbosity": 0,
}


def build_model(
    numeric_features: List[str],
    use_baseline: bool = False,
    scale_pos_weight: float = 1.0,
    calibrate: bool = False,
    categorical_features: List[str] | None = None,
    xgb_params: dict | None = None,
) -> Pipeline:
    """
    Build a scikit-learn Pipeline with ColumnTransformer + classifier.

    Parameters
    ----------
    numeric_features : columns for the numeric transformer (median impute)
    scale_pos_weight : passed to XGBClassifier for imbalanced classes
    calibrate : wrap XGBoost in CalibratedClassifierCV (isotonic, 3-fold)
    categorical_features : columns for one-hot encoding (only included if present)
    xgb_params : override dict for DEFAULT_XGB_PARAMS entries
    """
    if use_baseline:
        numeric_transformer = Pipeline(
            steps=[
                ("imputer", SimpleImputer(strategy="median")),
                ("scaler", StandardScaler()),
            ]
        )
    else:
        numeric_transformer = Pipeline(
            steps=[("imputer", SimpleImputer(strategy="median"))]
        )

    cat_transformer = Pipeline(
        steps=[
            ("imputer", SimpleImputer(strategy="constant", fill_value="missing")),
            ("onehot", OneHotEncoder(handle_unknown="ignore")),
        ]
    )

    cat_cols = categorical_features or []

    transformers = [("num", numeric_transformer, numeric_features)]
    if cat_cols:
        transformers.append(("cat", cat_transformer, cat_cols))

    pre = ColumnTransformer(transformers=transformers, remainder="drop")

    if use_baseline:
        clf = LogisticRegression(max_iter=2000, class_weight="balanced", n_jobs=None)
    else:
        params = {**DEFAULT_XGB_PARAMS, **(xgb_params or {})}
        params["scale_pos_weight"] = scale_pos_weight
        xgb_base = xgb.XGBClassifier(**params)

        if calibrate:
            clf = CalibratedClassifierCV(xgb_base, method="isotonic", cv=3)
        else:
            clf = xgb_base

    return Pipeline(steps=[("pre", pre), ("clf", clf)])


# ---------------------------------------------------------------------------
# Label / weight helpers
# ---------------------------------------------------------------------------


def make_label(
    df: pd.DataFrame, win_threshold_pct: float, verbose: bool = True
) -> pd.Series:
    if "pnl_percent" not in df.columns:
        raise KeyError(f"Missing 'pnl_percent' column. Columns: {list(df.columns)}")

    if verbose and "has_actual_fill" in df.columns:
        n_actual = int(df["has_actual_fill"].sum())
        n_bt = len(df) - n_actual
        n_win_actual = int(
            (
                (df["has_actual_fill"] == 1)
                & (df["pnl_percent"].astype(float) >= float(win_threshold_pct))
            ).sum()
        )
        n_win_bt = int(
            (
                (df["has_actual_fill"] == 0)
                & (df["pnl_percent"].astype(float) >= float(win_threshold_pct))
            ).sum()
        )
        print(
            f"[labels] actual fills: {n_actual} ({n_win_actual} wins @ {win_threshold_pct}%)  |  "
            f"BT-simulated: {n_bt} ({n_win_bt} wins @ {win_threshold_pct}%)"
        )

    return (df["pnl_percent"].astype(float) >= float(win_threshold_pct)).astype(int)


def build_sample_weights(
    df: pd.DataFrame, actual_fill_weight: float
) -> np.ndarray:
    if "has_actual_fill" in df.columns and actual_fill_weight != 1.0:
        weights = np.where(
            df["has_actual_fill"].to_numpy() == 1, actual_fill_weight, 1.0
        )
        n_actual = int((df["has_actual_fill"] == 1).sum())
        print(f"[weights] Boosting {n_actual} actual-fill rows by {actual_fill_weight}x")
        return weights
    return np.ones(len(df))


# ---------------------------------------------------------------------------
# Evaluation helpers
# ---------------------------------------------------------------------------


def precision_at_k(y_true: np.ndarray, y_prob: np.ndarray, k: int) -> float:
    k = int(min(k, len(y_true)))
    if k <= 0:
        return float("nan")
    idx = np.argsort(-y_prob)[:k]
    return float(np.mean(y_true[idx]))


def _print_subset_metrics(
    name: str, y_true: np.ndarray, y_prob: np.ndarray, top_k: int
) -> None:
    print(f"\n  [{name}] rows={len(y_true)}  win_rate={float(np.mean(y_true)):.3f}")
    if len(np.unique(y_true)) > 1:
        print(f"  [{name}] AUC={roc_auc_score(y_true, y_prob):.4f}")
    print(f"  [{name}] Precision@{top_k}={precision_at_k(y_true, y_prob, top_k):.3f}")


def _print_feature_importance(model: Pipeline, feature_names: List[str]) -> None:
    """Dump feature importance so low-signal features can be pruned."""
    try:
        estimator = model.named_steps["clf"]
        if hasattr(estimator, "calibrated_classifiers_"):
            all_importances = []
            for calibrated in estimator.calibrated_classifiers_:
                if hasattr(calibrated.base_estimator, "feature_importances_"):
                    all_importances.append(
                        calibrated.base_estimator.feature_importances_
                    )
            if all_importances:
                importances = np.mean(all_importances, axis=0)
            else:
                return
        elif hasattr(estimator, "feature_importances_"):
            importances = estimator.feature_importances_
        else:
            return

        if len(importances) != len(feature_names):
            return

        feature_imp = sorted(zip(feature_names, importances), key=lambda x: -x[1])
        print(f"\n[feature_importance] Top 15 features:")
        for name, imp in feature_imp[:15]:
            print(f"  {name:30s} {imp:.6f}")
        zero_imp = [name for name, imp in feature_imp if imp < 0.001]
        if zero_imp:
            print(
                f"[feature_importance] {len(zero_imp)} features with near-zero importance "
                f"(candidates for removal): {zero_imp[:10]}{'...' if len(zero_imp) > 10 else ''}"
            )
    except Exception:
        pass  # Non-critical


def print_probability_buckets(
    scored: pd.DataFrame, win_threshold_pct: float, top_k: int = 20
) -> None:
    d = scored.copy()
    d["is_win"] = (
        d["pnl_percent"].astype(float) >= float(win_threshold_pct)
    ).astype(int)
    d["bucket"] = pd.cut(
        d["win_prob"],
        bins=[0, 0.3, 0.4, 0.5, 0.6, 0.7, 0.8, 0.9, 1.0],
        include_lowest=True,
    )

    out = (
        d.groupby("bucket", observed=True)
        .agg(
            rows=("is_win", "size"),
            win_rate=("is_win", "mean"),
            avg_pnl=("pnl_percent", "mean"),
            median_pnl=("pnl_percent", "median"),
        )
        .reset_index()
    )

    print("\n=== Probability Buckets ===")
    print(
        "(When model says X%, is actual win rate really X%? Are high-prob bins profitable?)"
    )
    print(out.to_string(index=False))


# ---------------------------------------------------------------------------
# Scoring helpers
# ---------------------------------------------------------------------------


def score_candidates(
    model: Pipeline,
    candidates: pd.DataFrame,
    top_n: int,
    feature_columns: List[str] | None = None,
) -> pd.DataFrame:
    """Score a candidates dataframe and return top_n by predicted probability."""
    from shared.features import add_derived_features

    d = add_derived_features(candidates)

    if feature_columns:
        keep_cols = feature_columns
    else:
        keep_cols = model.named_steps["pre"].transformers_[0][2]

    X = d.reindex(columns=keep_cols)
    proba = model.predict_proba(X)[:, 1]

    out = candidates.copy()
    out["win_prob"] = proba
    out = out.sort_values("win_prob", ascending=False).head(int(top_n)).reset_index(
        drop=True
    )
    return out


def dump_topk_examples(
    df: pd.DataFrame,
    model: Pipeline,
    win_threshold_pct: float,
    k: int = 10,
    feature_columns: List[str] | None = None,
) -> None:
    from shared.features import add_derived_features

    d = add_derived_features(df)

    if feature_columns:
        keep_cols = feature_columns
    else:
        keep_cols = model.named_steps["pre"].transformers_[0][2]

    X = d.reindex(columns=keep_cols)
    p = model.predict_proba(X)[:, 1]

    out = df.copy()
    out["win_prob"] = p
    out["is_win"] = (
        out["pnl_percent"].astype(float) >= float(win_threshold_pct)
    ).astype(int)

    cols = [
        c
        for c in [
            "symbol",
            "signal_type",
            "entry_type",
            "time_of_day",
            "entry_ts_est",
            "entry",
            "stop",
            "pnl_percent",
            "actual_pnl_pct",
            "has_actual_fill",
            "r_multiple",
            "win_prob",
            "is_win",
        ]
        if c in out.columns
    ]

    print(f"\n=== TOP {k} PICKS (by win_prob) ===")
    print(out.sort_values("win_prob", ascending=False).head(k)[cols].to_string(index=False))
