"""
shared/features.py — Feature engineering helpers shared across v4 trainers/scorers.

Provides:
  - add_derived_features(df) — the canonical v2-derived feature set
  - coerce_feature_columns(df, feature_cols, cat_cols) — MySQL Decimal → float coercion
  - resolve_numeric_cols(df, feature_cols) — filter to present, numeric columns
  - resolve_present_categorical_cols(df, cat_cols) — filter to present cat cols
"""

from __future__ import annotations

from typing import List

import numpy as np
import pandas as pd


def coerce_feature_columns(
    df: pd.DataFrame,
    feature_columns: List[str] | None = None,
    categorical_features: List[str] | None = None,
) -> pd.DataFrame:
    """Force numeric columns to float and categorical columns to string."""
    d = df.copy()
    if feature_columns is not None:
        for c in feature_columns:
            if c in d.columns:
                d[c] = pd.to_numeric(d[c], errors="coerce")
    if categorical_features is not None:
        for c in categorical_features:
            if c in d.columns:
                d[c] = d[c].astype(str)
    return d


def resolve_numeric_cols(df: pd.DataFrame, feature_columns: List[str]) -> List[str]:
    """Return feature_columns present in df, numeric, and with >=1 non-null value."""
    return [
        c
        for c in feature_columns
        if c in df.columns and pd.api.types.is_numeric_dtype(df[c]) and df[c].notna().any()
    ]


def resolve_present_categorical_cols(
    df: pd.DataFrame, categorical_features: List[str]
) -> List[str]:
    """Return categorical_features that are present in df."""
    return [c for c in categorical_features if c in df.columns]


# ---------------------------------------------------------------------------
# Derived feature engineering (canonical v2 core)
# ---------------------------------------------------------------------------

def add_derived_features(df: pd.DataFrame) -> pd.DataFrame:
    """
    Canonical derived feature engineering (v2 core).
    Trainers needing ADDITIONAL derived features should call this first, then add extras.
    """
    d = df.copy()

    def _to_float(series: pd.Series, default: float = 0.0) -> pd.Series:
        return pd.to_numeric(series, errors="coerce").fillna(default).astype(float)

    if "vwap_dist_pct" in d.columns:
        d["abs_vwap_dist_pct"] = d["vwap_dist_pct"].astype(float).abs()

    if "ema9_ema21_spread" in d.columns:
        d["abs_ema_spread"] = d["ema9_ema21_spread"].astype(float).abs()

    if "alert_rsi_14_1m" in d.columns:
        d["alert_rsi_centered"] = (d["alert_rsi_14_1m"].astype(float) - 50.0) / 50.0

    if "fmp_rsi_14" in d.columns:
        d["fmp_rsi_centered"] = (d["fmp_rsi_14"].astype(float) - 50.0) / 50.0

    if "above_vwap" in d.columns and "ema9_above_ema21" in d.columns:
        d["trend_alignment_1m"] = (
            _to_float(d["above_vwap"]) * _to_float(d["ema9_above_ema21"])
        )

    if "fmp_above_vwap" in d.columns and "fmp_ema9_above_ema21" in d.columns:
        d["trend_alignment_5m"] = (
            _to_float(d["fmp_above_vwap"]) * _to_float(d["fmp_ema9_above_ema21"])
        )

    if "ema9_ema21_spread" in d.columns and "fmp_ema_spread" in d.columns:
        d["spread_1m_minus_5m"] = (
            d["ema9_ema21_spread"].astype(float) - d["fmp_ema_spread"].astype(float)
        )

    # Over-extension detection
    if "alert_rsi_14_1m" in d.columns:
        d["rsi_1m_overbought"] = (d["alert_rsi_14_1m"].astype(float) > 70).astype(float)
        d["rsi_1m_oversold"] = (d["alert_rsi_14_1m"].astype(float) < 30).astype(float)
        d["rsi_1m_extreme"] = (
            (d["alert_rsi_14_1m"].astype(float) > 75)
            | (d["alert_rsi_14_1m"].astype(float) < 25)
        ).astype(float)

    if "fmp_rsi_14" in d.columns:
        d["rsi_5m_overbought"] = (d["fmp_rsi_14"].astype(float) > 70).astype(float)
        d["rsi_5m_oversold"] = (d["fmp_rsi_14"].astype(float) < 30).astype(float)

    if "vwap_dist_pct" in d.columns:
        d["vwap_extended"] = (d["vwap_dist_pct"].astype(float).abs() > 1.0).astype(float)
        d["vwap_very_extended"] = (
            d["vwap_dist_pct"].astype(float).abs() > 2.0
        ).astype(float)

    if "alert_vol_ratio" in d.columns:
        d["vol_ratio_extreme"] = (d["alert_vol_ratio"].astype(float) > 5.0).astype(float)
        d["vol_ratio_moderate"] = (
            (d["alert_vol_ratio"].astype(float) >= 2.0)
            & (d["alert_vol_ratio"].astype(float) <= 4.0)
        ).astype(float)

    if "alert_atr_pct" in d.columns:
        d["atr_too_low"] = (d["alert_atr_pct"].astype(float) < 0.3).astype(float)
        d["atr_too_high"] = (d["alert_atr_pct"].astype(float) > 2.0).astype(float)
        d["atr_sweet_spot"] = (
            (d["alert_atr_pct"].astype(float) >= 0.5)
            & (d["alert_atr_pct"].astype(float) <= 1.2)
        ).astype(float)

    if "five_min_green_bar_pct" in d.columns:
        d["green_bars_high"] = (
            d["five_min_green_bar_pct"].astype(float) > 75
        ).astype(float)
        d["green_bars_balanced"] = (
            (d["five_min_green_bar_pct"].astype(float) >= 50)
            & (d["five_min_green_bar_pct"].astype(float) <= 70)
        ).astype(float)

    if "five_min_directional_changes" in d.columns:
        d["choppy"] = (d["five_min_directional_changes"].astype(float) > 6).astype(float)
        d["clean_trend"] = (
            d["five_min_directional_changes"].astype(float) <= 4
        ).astype(float)

    if "pct_below_intraday_high" in d.columns:
        d["near_high"] = (
            d["pct_below_intraday_high"].astype(float) < 0.5
        ).astype(float)
        d["off_highs"] = (
            d["pct_below_intraday_high"].astype(float) > 2.0
        ).astype(float)

    warning_cols = [
        c
        for c in d.columns
        if c
        in (
            "rsi_1m_overbought",
            "rsi_5m_overbought",
            "vwap_extended",
            "vol_ratio_extreme",
            "green_bars_high",
            "near_high",
            "rsi_1m_extreme",
        )
    ]
    if warning_cols:
        d["overextension_score"] = d[warning_cols].sum(axis=1).astype(float)

    healthy_cols = [
        c
        for c in d.columns
        if c
        in (
            "vol_ratio_moderate",
            "atr_sweet_spot",
            "green_bars_balanced",
            "clean_trend",
            "off_highs",
        )
    ]
    if healthy_cols:
        d["healthy_setup_score"] = d[healthy_cols].sum(axis=1).astype(float)

    # Market context derived features
    if "mkt_day_pct" in d.columns:
        mkt = _to_float(d["mkt_day_pct"])
        d["mkt_is_green"] = (mkt > 0.0).astype(float)
        d["mkt_is_strong"] = (mkt > 0.5).astype(float)
        d["mkt_is_weak"] = (mkt < -0.3).astype(float)
        if "mkt_5m_ema_trend" in d.columns:
            d["mkt_trending_up"] = (
                (_to_float(d["mkt_5m_ema_trend"]) > 0) & (mkt > 0.0)
            ).astype(float)
        if "stock_intraday_pct" in d.columns:
            d["rs_spread_vs_market"] = d["stock_intraday_pct"].astype(float) - mkt

    # Minutes since market open (9:30 EST)
    if "entry_ts_est" in d.columns:
        ts = pd.to_datetime(d["entry_ts_est"])
        d["minutes_since_open"] = (ts.dt.hour - 9) * 60 + ts.dt.minute - 30
        d["minutes_since_open"] = d["minutes_since_open"].clip(lower=0).astype(float)

    return d
