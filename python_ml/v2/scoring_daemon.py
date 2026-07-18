#!/usr/bin/env python3
"""
ML Scoring Daemon — persistent Unix socket server.

Eliminates Python cold-start overhead (~1.5–2.5s per alert) by keeping all
models and the DB connection pool loaded in memory.  The Laravel job
ScoreTradeAlertWithMl sends a single JSON line; the daemon responds with a
single JSON line and updates the DB itself, exactly as score_single_alert_v2.py
does.

Protocol (newline-delimited JSON over a Unix socket):
  Request:  {"alert_id": 123, "table": "trade_alerts", "model_path": "python_ml/models/winner_model_xgb.joblib"}
  Response: {"ok": true,  "prob": 0.723456, "alert_id": 123, "ms": 12}
  Response: {"ok": false, "error": "...", "alert_id": 123}

Usage:
  python python_ml/v2/scoring_daemon.py --socket storage/ml-scoring.sock [--models model1.joblib,model2.joblib]

The daemon pre-warms every model listed in --models at startup so the first
real request is fast.  Any model not pre-warmed is loaded on first use and
cached for subsequent requests.
"""

import os
import sys
import json
import time
import signal
import socket
import logging
import argparse
import threading
import traceback
from pathlib import Path
from typing import Any

import pandas as pd
import joblib
from sqlalchemy import create_engine, text
from sqlalchemy.engine import URL
from dotenv import load_dotenv


# ---------------------------------------------------------------------------
# Logging
# ---------------------------------------------------------------------------

logging.basicConfig(
    level=logging.INFO,
    format="%(asctime)s [SCORING-DAEMON] %(levelname)s %(message)s",
    stream=sys.stdout,
)
log = logging.getLogger("scoring_daemon")


# ---------------------------------------------------------------------------
# Environment / DB helpers
# ---------------------------------------------------------------------------

def load_parent_env() -> None:
    env_path = Path(__file__).resolve().parents[2] / ".env"
    if env_path.exists():
        load_dotenv(dotenv_path=env_path, override=False)


def make_engine():
    load_parent_env()
    url = URL.create(
        "mysql+pymysql",
        username=os.environ.get("DB_USERNAME", "laravel"),
        password=os.environ.get("DB_PASSWORD", "laravel"),
        host=os.environ.get("DB_HOST", "127.0.0.1"),
        port=int(os.environ.get("DB_PORT", "3306")),
        database=os.environ.get("DB_DATABASE", "laravelInvest"),
    )
    return create_engine(
        url,
        pool_pre_ping=True,
        pool_size=5,
        max_overflow=10,
        pool_recycle=1800,
    )


def get_benchmark_symbol() -> str:
    load_parent_env()
    return os.environ.get("TRADING_MARKET_BENCHMARK_SYMBOL", "QQQ")


def get_default_model_paths(project_root: Path) -> list[str]:
    """Collect default model paths from .env for global + pipeline-specific models."""
    load_parent_env()

    candidates: list[str] = []

    global_model = os.environ.get("TRADING_ML_MODEL_PATH", "").strip()
    if global_model:
        candidates.append(global_model)

    for letter in "ABCDEFGHIJKLMNOPQRSEXTERNAL":
        key = f"TRADING_ML_PIPELINE_{letter}_MODEL_PATH"
        value = os.environ.get(key, "").strip()
        if value:
            candidates.append(value)

    unique_paths: list[str] = []
    seen: set[str] = set()
    for raw_path in candidates:
        abs_path = raw_path if os.path.isabs(raw_path) else str(project_root / raw_path)
        if abs_path not in seen:
            seen.add(abs_path)
            unique_paths.append(abs_path)

    return unique_paths


# ---------------------------------------------------------------------------
# Feature engineering (must stay in sync with score_single_alert_v2.py)
# ---------------------------------------------------------------------------

def add_derived_features(df: pd.DataFrame) -> pd.DataFrame:
    d = df.copy()

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
            d["above_vwap"].fillna(0).astype(float) * d["ema9_above_ema21"].fillna(0).astype(float)
        )
    if "fmp_above_vwap" in d.columns and "fmp_ema9_above_ema21" in d.columns:
        d["trend_alignment_5m"] = (
            d["fmp_above_vwap"].fillna(0).astype(float) * d["fmp_ema9_above_ema21"].fillna(0).astype(float)
        )
    if "ema9_ema21_spread" in d.columns and "fmp_ema_spread" in d.columns:
        d["spread_1m_minus_5m"] = d["ema9_ema21_spread"].astype(float) - d["fmp_ema_spread"].astype(float)

    if "alert_rsi_14_1m" in d.columns:
        rsi1 = d["alert_rsi_14_1m"].astype(float)
        d["rsi_1m_overbought"] = (rsi1 > 70).astype(float)
        d["rsi_1m_oversold"] = (rsi1 < 30).astype(float)
        d["rsi_1m_extreme"] = ((rsi1 > 75) | (rsi1 < 25)).astype(float)
    if "fmp_rsi_14" in d.columns:
        rsi5 = d["fmp_rsi_14"].astype(float)
        d["rsi_5m_overbought"] = (rsi5 > 70).astype(float)
        d["rsi_5m_oversold"] = (rsi5 < 30).astype(float)
    if "vwap_dist_pct" in d.columns:
        vd = d["vwap_dist_pct"].astype(float).abs()
        d["vwap_extended"] = (vd > 1.0).astype(float)
        d["vwap_very_extended"] = (vd > 2.0).astype(float)
    if "alert_vol_ratio" in d.columns:
        vr = d["alert_vol_ratio"].astype(float)
        d["vol_ratio_extreme"] = (vr > 5.0).astype(float)
        d["vol_ratio_moderate"] = ((vr >= 2.0) & (vr <= 4.0)).astype(float)
    if "alert_atr_pct" in d.columns:
        ap = d["alert_atr_pct"].astype(float)
        d["atr_too_low"] = (ap < 0.3).astype(float)
        d["atr_too_high"] = (ap > 2.0).astype(float)
        d["atr_sweet_spot"] = ((ap >= 0.5) & (ap <= 1.2)).astype(float)
    if "five_min_green_bar_pct" in d.columns:
        gb = d["five_min_green_bar_pct"].astype(float)
        d["green_bars_high"] = (gb > 75).astype(float)
        d["green_bars_balanced"] = ((gb >= 50) & (gb <= 70)).astype(float)
    if "five_min_directional_changes" in d.columns:
        dc = d["five_min_directional_changes"].astype(float)
        d["choppy"] = (dc > 6).astype(float)
        d["clean_trend"] = (dc <= 4).astype(float)
    if "pct_below_intraday_high" in d.columns:
        bh = d["pct_below_intraday_high"].astype(float)
        d["near_high"] = (bh < 0.5).astype(float)
        d["off_highs"] = (bh > 2.0).astype(float)

    warning_cols = [c for c in d.columns if c in [
        "rsi_1m_overbought", "rsi_5m_overbought", "vwap_extended",
        "vol_ratio_extreme", "green_bars_high", "near_high", "rsi_1m_extreme",
    ]]
    if warning_cols:
        d["overextension_score"] = d[warning_cols].sum(axis=1).astype(float)

    healthy_cols = [c for c in d.columns if c in [
        "vol_ratio_moderate", "atr_sweet_spot", "green_bars_balanced",
        "clean_trend", "off_highs",
    ]]
    if healthy_cols:
        d["healthy_setup_score"] = d[healthy_cols].sum(axis=1).astype(float)

    if "mkt_day_pct" in d.columns:
        mkt = d["mkt_day_pct"].astype(float)
        d["mkt_is_green"] = (mkt > 0.0).astype(float)
        d["mkt_is_strong"] = (mkt > 0.5).astype(float)
        d["mkt_is_weak"] = (mkt < -0.3).astype(float)
        if "mkt_5m_ema_trend" in d.columns:
            d["mkt_trending_up"] = (
                (d["mkt_5m_ema_trend"].fillna(0).astype(float) > 0) & (mkt > 0.0)
            ).astype(float)
        if "stock_intraday_pct" in d.columns:
            d["rs_spread_vs_market"] = d["stock_intraday_pct"].astype(float) - mkt

    if "entry_ts_est" in d.columns:
        ts = pd.to_datetime(d["entry_ts_est"])
        d["minutes_since_open"] = ((ts.dt.hour - 9) * 60 + ts.dt.minute - 30).clip(lower=0).astype(float)

    return d


# ---------------------------------------------------------------------------
# Scoring query (same as score_single_alert_v2.py)
# ---------------------------------------------------------------------------

SCORE_QUERY = """
SELECT
    ta.id AS alert_id,
    ta.symbol,
    ta.asset_type,
    ta.trading_date_est,
    ta.as_of_ts_est,
    ta.signal_ts_est,
    ta.time_of_day,
    ta.entry_type,
    ta.entry_ts_est,
    ta.entry,
    ta.stop,
    ta.pipeline_run,
    ta.version,

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
    END                          AS mkt_day_pct,
    mkt_fmp.ema9_above_ema21     AS mkt_5m_ema_trend,
    mkt_fmp.vwap_dist_pct        AS mkt_5m_vwap_dist,
    mkt_fmp.rsi_14               AS mkt_5m_rsi,

    CASE
        WHEN stk_open.open IS NOT NULL AND stk_open.open > 0 AND fmp.price IS NOT NULL
            THEN (fmp.price - stk_open.open) / stk_open.open * 100
        ELSE NULL
    END                          AS stock_intraday_pct

FROM {table} ta

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

WHERE ta.id = :alert_id
"""

UPDATE_QUERY = """
UPDATE {table}
SET ml_win_prob      = :prob,
    ml_scored_at     = NOW(),
        passed_ml = 1,
    ml_model_version = :model_version
WHERE id = :alert_id
"""

ALLOWED_TABLES = {"trade_alerts", "trade_alerts_unfiltered"}


# ---------------------------------------------------------------------------
# Model cache + scoring logic
# ---------------------------------------------------------------------------

class ModelCache:
    """Thread-safe model cache.  Loads on first use, keeps in memory forever."""

    def __init__(self):
        self._cache: dict[str, Any] = {}
        self._lock = threading.Lock()

    def get(self, model_path: str):
        """Return (model, model_features, model_version) for the given path."""
        abs_path = str(Path(model_path).resolve())
        with self._lock:
            if abs_path in self._cache:
                return self._cache[abs_path]

        log.info(f"Loading model: {abs_path}")
        t0 = time.monotonic()
        payload = joblib.load(abs_path)
        if isinstance(payload, dict) and "model" in payload:
            model = payload["model"]
            meta = payload.get("meta", {})
        else:
            model = payload
            meta = {}

        model_version = meta.get("model_version") or Path(abs_path).stem

        # Prefer feature_columns from model metadata (saved by trainer).
        # This ensures exact column order and set used during training.
        model_features = meta.get("feature_columns")
        if not model_features:
            try:
                model_features = list(model.named_steps["pre"].transformers_[0][2])
            except Exception as exc:
                raise RuntimeError(
                    f"Cannot extract feature list from {abs_path}. "
                    "Model must be saved by train_stock_winner_model_v2.py."
                ) from exc

        entry = (model, model_features, model_version)
        ms = round((time.monotonic() - t0) * 1000)
        log.info(f"Loaded model {Path(abs_path).name} in {ms}ms ({len(model_features)} features)")

        with self._lock:
            self._cache[abs_path] = entry

        return entry


def score_alert(
    alert_id: int,
    table: str,
    model_path: str,
    model_cache: ModelCache,
    engine,
    benchmark_symbol: str,
) -> float:
    """Score one alert and write result to DB.  Returns win probability."""
    if table not in ALLOWED_TABLES:
        raise ValueError(f"table must be one of: {', '.join(sorted(ALLOWED_TABLES))}")

    model, model_features, model_version = model_cache.get(model_path)

    query = text(SCORE_QUERY.format(table=table))
    with engine.connect() as conn:
        df = pd.read_sql(
            query,
            conn,
            params={"alert_id": alert_id, "benchmark_symbol": benchmark_symbol},
        )

    if df.empty:
        raise ValueError(
            f"Alert {alert_id} not found in {table}, or entry_ts_est has no "
            "matching row in one_minute_prices."
        )

    df["recent_losses_today"] = 0.0
    df = add_derived_features(df)

    for col in model_features:
        if col in df.columns:
            df[col] = pd.to_numeric(df[col], errors="coerce")

    X = df.reindex(columns=model_features)
    prob = float(model.predict_proba(X)[0, 1])

    update = text(UPDATE_QUERY.format(table=table))
    with engine.connect() as conn:
        conn.execute(update, {"prob": prob, "model_version": model_version, "alert_id": alert_id})
        conn.commit()

    return prob


# ---------------------------------------------------------------------------
# Unix socket server
# ---------------------------------------------------------------------------

class ScoringDaemon:
    def __init__(self, socket_path: str, engine, model_cache: ModelCache, benchmark_symbol: str):
        self.socket_path = socket_path
        self.engine = engine
        self.model_cache = model_cache
        self.benchmark_symbol = benchmark_symbol
        self._stop = threading.Event()

    def handle_client(self, conn: socket.socket):
        """Handle one client connection — read a JSON line, respond with a JSON line."""
        try:
            data = b""
            while b"\n" not in data:
                chunk = conn.recv(4096)
                if not chunk:
                    return
                data += chunk

            req = json.loads(data.split(b"\n", 1)[0].decode())
            alert_id = int(req["alert_id"])
            table = str(req.get("table", "trade_alerts"))
            model_path = str(req["model_path"])

            t0 = time.monotonic()
            prob = score_alert(
                alert_id=alert_id,
                table=table,
                model_path=model_path,
                model_cache=self.model_cache,
                engine=self.engine,
                benchmark_symbol=self.benchmark_symbol,
            )
            ms = round((time.monotonic() - t0) * 1000)

            resp = json.dumps({"ok": True, "prob": prob, "alert_id": alert_id, "ms": ms})
            log.info(f"Scored alert {alert_id} ({table}) prob={prob:.4f} in {ms}ms")

        except Exception as exc:
            tb = traceback.format_exc()
            log.error(f"Error scoring alert: {exc}\n{tb}")
            resp = json.dumps({"ok": False, "error": str(exc), "alert_id": req.get("alert_id") if "req" in dir() else None})

        try:
            conn.sendall((resp + "\n").encode())
        except Exception:
            pass
        finally:
            conn.close()

    def run(self):
        # Remove stale socket file if it exists
        if os.path.exists(self.socket_path):
            os.unlink(self.socket_path)

        sock = socket.socket(socket.AF_UNIX, socket.SOCK_STREAM)
        sock.setsockopt(socket.SOL_SOCKET, socket.SO_REUSEADDR, 1)
        sock.bind(self.socket_path)
        os.chmod(self.socket_path, 0o660)
        sock.listen(64)
        sock.settimeout(1.0)

        log.info(f"Scoring daemon listening on {self.socket_path}")

        while not self._stop.is_set():
            try:
                conn, _ = sock.accept()
                t = threading.Thread(target=self.handle_client, args=(conn,), daemon=True)
                t.start()
            except socket.timeout:
                continue
            except Exception as exc:
                log.error(f"Accept error: {exc}")

        sock.close()
        if os.path.exists(self.socket_path):
            os.unlink(self.socket_path)
        log.info("Scoring daemon stopped.")

    def stop(self):
        self._stop.set()


# ---------------------------------------------------------------------------
# Entry point
# ---------------------------------------------------------------------------

def main():
    parser = argparse.ArgumentParser(description="ML Scoring Daemon")
    parser.add_argument(
        "--socket",
        default="storage/ml-scoring.sock",
        help="Unix socket path (relative to project root or absolute)",
    )
    parser.add_argument(
        "--models",
        default="",
        help="Comma-separated list of model paths to pre-warm at startup",
    )
    args = parser.parse_args()

    # Resolve socket path relative to project root
    project_root = Path(__file__).resolve().parents[1]
    socket_path = args.socket if os.path.isabs(args.socket) else str(project_root / args.socket)

    os.makedirs(Path(socket_path).parent, exist_ok=True)

    load_parent_env()
    engine = make_engine()
    benchmark_symbol = get_benchmark_symbol()
    model_cache = ModelCache()

    # Always pre-warm configured pipeline models from .env to avoid first-hit latency.
    model_paths = get_default_model_paths(project_root)

    # Pre-warm models listed in --models
    if args.models:
        for raw_path in args.models.split(","):
            raw_path = raw_path.strip()
            if not raw_path:
                continue
            abs_path = raw_path if os.path.isabs(raw_path) else str(project_root / raw_path)
            if abs_path not in model_paths:
                model_paths.append(abs_path)

    for abs_path in model_paths:
        try:
            model_cache.get(abs_path)
        except Exception as exc:
            log.warning(f"Could not pre-warm {abs_path}: {exc}")

    daemon = ScoringDaemon(socket_path, engine, model_cache, benchmark_symbol)

    def _shutdown(sig, frame):
        log.info(f"Received signal {sig}, shutting down…")
        daemon.stop()

    signal.signal(signal.SIGTERM, _shutdown)
    signal.signal(signal.SIGINT, _shutdown)

    daemon.run()


if __name__ == "__main__":
    main()
