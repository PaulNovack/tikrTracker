#!/usr/bin/env python3
"""
BarBufferService — in-memory buffer for Alpaca streaming bars.

Collects 1-minute bar events from the WebSocket feed and flushes them
to one_minute_prices in configurable batches (by count or by time).

Now also computes per-bar technical indicators (VWAP, EMA9/21, ATR14,
RVOL, 30m move) in memory so that the PHP realtime-watch scanner can
apply Pipeline H/K/A quality gates from Redis instead of slow MySQL queries.

Usage (standalone flush):
    buffer = BarBufferService()
    buffer.warm_up_all_from_mysql(symbols)  # call once at startup
    buffer.add(bar)         # called from stream handler
    buffer.flush_if_ready() # called periodically, or awaited in asyncio loop

Flush is always safe to call; it's a no-op when the buffer is empty.
"""

import os
import sys
import logging
import threading
import time
from datetime import datetime, timezone
from pathlib import Path
from typing import Any

try:
    from zoneinfo import ZoneInfo
    _EST = ZoneInfo("America/New_York")
    _UTC = ZoneInfo("UTC")
    _HAS_ZONEINFO = True
except ImportError:
    _HAS_ZONEINFO = False

# ---------------------------------------------------------------------------
# DB connection — mirrors python/config.py pattern
# ---------------------------------------------------------------------------
env_path = Path(__file__).parent.parent / ".env"
if env_path.exists():
    with open(env_path, "r") as _f:
        for _line in _f:
            _line = _line.strip()
            if _line and not _line.startswith("#") and "=" in _line:
                _k, _v = _line.split("=", 1)
                _k, _v = _k.strip(), _v.strip()
                if _v and _v[0] in ('"', "'") and _v[-1] == _v[0]:
                    _v = _v[1:-1]
                os.environ[_k] = _v

DB_CONNECTION = os.environ.get("DB_CONNECTION", "mysql").lower()

DB_CONFIG: dict[str, Any] = {
    "host": os.environ.get("DB_HOST", "127.0.0.1"),
    "database": os.environ.get("DB_DATABASE", "laravelInvest"),
    "user": os.environ.get("DB_USERNAME", "laravel"),
    "password": os.environ.get("DB_PASSWORD", "laravel"),
    "port": int(os.environ.get("DB_PORT", 3306)),
}

# ---------------------------------------------------------------------------
# Logging
# ---------------------------------------------------------------------------
logging.basicConfig(
    level=logging.INFO,
    format="%(asctime)s [BarBuffer] %(levelname)s %(message)s",
    datefmt="%Y-%m-%d %H:%M:%S",
)
logger = logging.getLogger("bar_buffer")


# ---------------------------------------------------------------------------
# UTC ↔ EST helpers
# ---------------------------------------------------------------------------

def _utc_str_to_est_str(ts_utc: str) -> str:
    """Convert a UTC datetime string to America/New_York string."""
    if not ts_utc or not _HAS_ZONEINFO:
        return ts_utc
    try:
        fmt = "%Y-%m-%d %H:%M:%S"
        dt = datetime.strptime(ts_utc[:19], fmt).replace(tzinfo=_UTC)
        est = dt.astimezone(_EST)
        return est.strftime(fmt)
    except Exception:
        return ts_utc


def _market_open_utc_today() -> str:
    """Return today's 9:30 AM EST as a UTC string for MySQL queries."""
    if not _HAS_ZONEINFO:
        # Fallback: subtract 4h (EDT offset) from 09:30 → 13:30 UTC
        now = datetime.now(timezone.utc)
        open_utc = now.replace(hour=13, minute=30, second=0, microsecond=0)
        return open_utc.strftime("%Y-%m-%d %H:%M:%S")
    now_est = datetime.now(_EST)
    open_est = datetime(now_est.year, now_est.month, now_est.day, 9, 30, 0, tzinfo=_EST)
    open_utc = open_est.astimezone(_UTC)
    return open_utc.strftime("%Y-%m-%d %H:%M:%S")


# ---------------------------------------------------------------------------
# SymbolIndicatorState
# ---------------------------------------------------------------------------

class SymbolIndicatorState:
    """
    Per-symbol rolling state for fast technical indicator computation.

    Maintains EMA9/21, ATR14, cumulative day VWAP, 20-bar volume average,
    and a 30-bar price history for the 30m move — all updated incrementally
    as each 1-minute bar arrives, with zero MySQL queries after warm-up.
    """

    EMA9_K: float = 2.0 / (9 + 1)    # 0.2
    EMA21_K: float = 2.0 / (21 + 1)  # ~0.0909
    ATR_PERIOD: int = 14
    VOL_PERIOD: int = 20
    MOVE_PERIOD: int = 30  # 30 1-min bars = 30m move

    def __init__(self, symbol: str) -> None:
        self.symbol = symbol
        self.seeded: bool = False

        self.ema9: float | None = None
        self.ema21: float | None = None
        self.prev_close: float | None = None

        self.tr_values: list[float] = []
        self.vol_history: list[float] = []
        self.price_history: list[float] = []

        # VWAP: cumulative typical_price*volume / cumulative_volume, reset each day
        self.vwap_num: float = 0.0
        self.vwap_den: float = 0.0
        self.current_day: str = ""  # EST date YYYY-MM-DD

        # Last processed bar snapshot (raw OHLCV + computed indicators) for Redis warm-up
        self.last_bar_snapshot: dict | None = None

    def seed_from_bars(self, bars: list[dict]) -> None:
        """
        Replay historical bars (ascending ts order) to warm up state.
        Call once at startup so indicators are accurate when live bars arrive.
        """
        for bar in bars:
            self._process(bar)
        self.seeded = True

    def update(self, bar: dict) -> dict:
        """Process one live bar and return the computed indicator dict."""
        indicators = self._process(bar)
        self.seeded = True
        return indicators

    def _process(self, bar: dict) -> dict:
        # Normalise field names — historical bars may use 'price' or 'close'
        close = float(bar.get("price") or bar.get("close") or 0)
        high = float(bar.get("high") or close)
        low = float(bar.get("low") or close)
        volume = float(bar.get("volume") or 0)
        ts_utc = str(bar.get("ts") or "")

        if close <= 0:
            return {}

        # ── Day change → reset VWAP accumulator ──────────────────────────
        ts_est = _utc_str_to_est_str(ts_utc)
        bar_day = ts_est[:10] if len(ts_est) >= 10 else ""
        if bar_day and bar_day != self.current_day:
            self.vwap_num = 0.0
            self.vwap_den = 0.0
            self.current_day = bar_day

        # ── VWAP ─────────────────────────────────────────────────────────
        typical = (high + low + close) / 3.0
        self.vwap_num += typical * volume
        self.vwap_den += volume
        vwap = (self.vwap_num / self.vwap_den) if self.vwap_den > 0 else close
        vwap_dist = close - vwap
        vwap_dist_pct = (vwap_dist / vwap * 100) if vwap > 0 else 0.0
        above_vwap = 1 if close > vwap else 0

        # ── EMA9 / EMA21 ─────────────────────────────────────────────────
        if self.ema9 is None:
            self.ema9 = close
        else:
            self.ema9 = close * self.EMA9_K + self.ema9 * (1 - self.EMA9_K)

        if self.ema21 is None:
            self.ema21 = close
        else:
            self.ema21 = close * self.EMA21_K + self.ema21 * (1 - self.EMA21_K)

        ema9_ema21_spread = ((self.ema9 - self.ema21) / self.ema21 * 100) if self.ema21 > 0 else 0.0
        ema9_above_ema21 = 1 if self.ema9 > self.ema21 else 0

        # ── ATR14 ────────────────────────────────────────────────────────
        if self.prev_close is not None:
            tr = max(high - low, abs(high - self.prev_close), abs(low - self.prev_close))
        else:
            tr = high - low
        self.tr_values.append(tr)
        if len(self.tr_values) > self.ATR_PERIOD:
            self.tr_values.pop(0)
        atr = sum(self.tr_values) / len(self.tr_values)
        atr_pct = (atr / close * 100) if close > 0 else 0.0
        self.prev_close = close

        # ── RVOL (relative volume vs 20-bar average of prior bars) ───────
        self.vol_history.append(volume)
        if len(self.vol_history) > self.VOL_PERIOD + 1:
            self.vol_history.pop(0)
        prior_vols = self.vol_history[:-1] if len(self.vol_history) > 1 else [volume]
        avg_vol_20 = sum(prior_vols) / len(prior_vols) if prior_vols else volume
        rvol = (volume / avg_vol_20) if avg_vol_20 > 0 else 1.0

        # ── 30m move ─────────────────────────────────────────────────────
        self.price_history.append(close)
        if len(self.price_history) > self.MOVE_PERIOD + 1:
            self.price_history.pop(0)
        if len(self.price_history) >= self.MOVE_PERIOD:
            price_30m_ago = self.price_history[0]
            move_30m_pct = ((close - price_30m_ago) / price_30m_ago * 100) if price_30m_ago > 0 else 0.0
        else:
            move_30m_pct = 0.0

        # ── ts_est / trading_date_est / trading_time_est ─────────────────
        trading_date_est = ts_est[:10] if len(ts_est) >= 10 else None
        trading_time_est = ts_est[11:19] if len(ts_est) >= 19 else None

        indicators = {
            # DB columns (fill the NULL-ed indicator columns written by stream)
            "ts_est": ts_est or None,
            "trading_date_est": trading_date_est,
            "trading_time_est": trading_time_est,
            "vwap": round(vwap, 6),
            "vwap_dist": round(vwap_dist, 6),
            "vwap_dist_pct": round(vwap_dist_pct, 6),
            "above_vwap": above_vwap,
            "ema9": round(self.ema9, 6),
            "ema21": round(self.ema21, 6),
            "ema9_ema21_spread": round(ema9_ema21_spread, 6),
            "ema9_above_ema21": ema9_above_ema21,
            "atr": round(atr, 6),
            "atr_pct": round(atr_pct, 6),
            # Redis-only (not stored in DB)
            "rvol": round(rvol, 4),
            "avg_vol_20": round(avg_vol_20, 2),
            "move_30m_pct": round(move_30m_pct, 4),
        }

        # Keep snapshot of latest bar for Redis warm-up write
        self.last_bar_snapshot = {
            **indicators,
            "symbol": self.symbol,
            "ts": ts_utc,
            "price": close,
            "open": float(bar.get("open") or close),
            "high": high,
            "low": low,
            "volume": volume,
        }

        return indicators


# ---------------------------------------------------------------------------
# BarBufferService
# ---------------------------------------------------------------------------
class BarBufferService:
    """
    Thread-safe in-memory buffer for streaming 1-minute bars.

    Bars accumulate until either:
      - ``flush_size`` bars are pending, or
      - ``flush_interval`` seconds have elapsed since the last flush.

    Call ``add(bar)`` from your asyncio stream handler (safe from any thread).
    Call ``flush_if_ready()`` from a periodic loop, or call ``flush()`` to
    force an immediate write regardless of thresholds.
    """

    # Upsert source tag written to the ``source`` column
    SOURCE = "alpaca_stream"

    def __init__(
        self,
        flush_size: int = 50,
        flush_interval: float = 10.0,
    ) -> None:
        """
        :param flush_size:      Flush automatically once this many bars are buffered.
        :param flush_interval:  Flush automatically after this many seconds regardless of count.
        """
        self.flush_size = flush_size
        self.flush_interval = flush_interval

        self._lock = threading.Lock()
        self._pending: list[dict] = []
        self._last_flush_at: float = time.monotonic()
        self._total_flushed: int = 0
        self._total_errors: int = 0

        # Per-symbol indicator state — keyed by uppercase symbol string
        self._states: dict[str, SymbolIndicatorState] = {}

    # ------------------------------------------------------------------
    # Public API
    # ------------------------------------------------------------------

    def add(self, bar: Any) -> None:
        """
        Accepts an Alpaca ``Bar`` data object (or any object/dict with the
        expected OHLCV + timestamp fields) and appends it to the pending list.

        Automatically flushes if ``flush_size`` is reached.
        Computes technical indicators (VWAP, EMA, ATR, RVOL, 30m move)
        incrementally and attaches them to the row for MySQL and Redis.
        """
        row = self._normalise_bar(bar)
        if row is None:
            return

        # ── Compute indicators (outside the lock — state is single-threaded) ──
        sym = row["symbol"]
        if sym not in self._states:
            self._states[sym] = SymbolIndicatorState(sym)
        indicators = self._states[sym].update(row)
        if indicators:
            row.update(indicators)

        with self._lock:
            self._pending.append(row)
            should_flush = len(self._pending) >= self.flush_size

        if should_flush:
            self.flush()

    def flush_if_ready(self) -> int:
        """
        Flush only if ``flush_interval`` seconds have elapsed since the last
        flush. Returns the number of rows written (0 if nothing was flushed).
        """
        with self._lock:
            elapsed = time.monotonic() - self._last_flush_at
            is_ready = elapsed >= self.flush_interval and len(self._pending) > 0

        if is_ready:
            return self.flush()
        return 0

    def flush(self) -> int:
        """
        Unconditionally write all buffered bars to ``one_minute_prices``.
        Returns the number of rows successfully written.
        Thread-safe — concurrent calls will result in one writing and the
        other being a no-op (the batch is drained atomically).
        """
        with self._lock:
            if not self._pending:
                return 0
            batch = self._pending[:]
            self._pending.clear()
            self._last_flush_at = time.monotonic()

        written = self._upsert_batch(batch)
        self._total_flushed += written

        logger.info(
            "Flushed %d bars (total flushed: %d, total errors: %d)",
            written,
            self._total_flushed,
            self._total_errors,
        )
        return written

    @property
    def pending_count(self) -> int:
        """Number of bars currently buffered and not yet written."""
        with self._lock:
            return len(self._pending)

    @property
    def stats(self) -> dict[str, int]:
        """Snapshot of lifetime flush statistics."""
        return {
            "total_flushed": self._total_flushed,
            "total_errors": self._total_errors,
            "pending": self.pending_count,
        }

    # ------------------------------------------------------------------
    # Internal helpers
    # ------------------------------------------------------------------

    def _normalise_bar(self, bar: Any) -> dict | None:
        """
        Convert an Alpaca ``Bar`` object (or a plain dict) to the flat dict
        used for DB upsert.

        Alpaca Bar attributes:
          symbol, open, high, low, close, volume, timestamp (datetime, UTC-aware)
        """
        try:
            if isinstance(bar, dict):
                symbol = bar["symbol"]
                open_ = float(bar["open"])
                high = float(bar["high"])
                low = float(bar["low"])
                close = float(bar["close"])
                volume = int(bar.get("volume", 0) or 0)
                ts = bar["timestamp"]
            else:
                symbol = bar.symbol
                open_ = float(bar.open)
                high = float(bar.high)
                low = float(bar.low)
                close = float(bar.close)
                volume = int(bar.volume or 0)
                ts = bar.timestamp

            # Ensure UTC datetime string for MySQL
            if isinstance(ts, str):
                ts_utc = ts  # already formatted
            elif hasattr(ts, "astimezone"):
                ts_utc = ts.astimezone(timezone.utc).strftime("%Y-%m-%d %H:%M:%S")
            else:
                ts_utc = str(ts)

            return {
                "symbol": symbol.upper(),
                "asset_type": "stock",
                "source": self.SOURCE,
                "ts": ts_utc,
                "price": close,
                "open": open_,
                "high": high,
                "low": low,
                "volume": volume,
            }
        except Exception as exc:
            logger.warning("Failed to normalise bar %r: %s", bar, exc)
            self._total_errors += 1
            return None

    def _upsert_batch(self, batch: list[dict]) -> int:
        """
        Upsert a batch of normalised bar dicts into ``one_minute_prices``.
        Uses MySQL ``ON DUPLICATE KEY UPDATE`` against the unique key
        (symbol, asset_type, ts).

        Retries up to 3 times on deadlock errors (MySQL 1213) with
        progressive backoff matching the PHP command conventions
        (100 ms, 200 ms, 300 ms).
        """
        if not batch:
            return 0

        # Note: ts_est, trading_date_est, trading_time_est are GENERATED ALWAYS AS
        # columns in MySQL — they are computed automatically from `ts` and must
        # NOT appear in INSERT or ON DUPLICATE KEY UPDATE clauses.
        sql = """
            INSERT INTO one_minute_prices
                (symbol, asset_type, source, ts, price, open, high, low, volume,
                 vwap, vwap_dist, vwap_dist_pct, above_vwap,
                 ema9, ema21, ema9_ema21_spread, ema9_above_ema21,
                 atr, atr_pct,
                 created_at, updated_at)
            VALUES
                (%s, %s, %s, %s, %s, %s, %s, %s, %s,
                 %s, %s, %s, %s,
                 %s, %s, %s, %s,
                 %s, %s,
                 NOW(), NOW())
            ON DUPLICATE KEY UPDATE
                source             = VALUES(source),
                price              = VALUES(price),
                open               = VALUES(open),
                high               = VALUES(high),
                low                = VALUES(low),
                volume             = VALUES(volume),
                vwap               = COALESCE(VALUES(vwap), vwap),
                vwap_dist          = COALESCE(VALUES(vwap_dist), vwap_dist),
                vwap_dist_pct      = COALESCE(VALUES(vwap_dist_pct), vwap_dist_pct),
                above_vwap         = COALESCE(VALUES(above_vwap), above_vwap),
                ema9               = COALESCE(VALUES(ema9), ema9),
                ema21              = COALESCE(VALUES(ema21), ema21),
                ema9_ema21_spread  = COALESCE(VALUES(ema9_ema21_spread), ema9_ema21_spread),
                ema9_above_ema21   = COALESCE(VALUES(ema9_above_ema21), ema9_above_ema21),
                atr                = COALESCE(VALUES(atr), atr),
                atr_pct            = COALESCE(VALUES(atr_pct), atr_pct),
                updated_at         = NOW()
        """

        rows = [
            (
                r["symbol"],
                r["asset_type"],
                r["source"],
                r["ts"],
                r["price"],
                r["open"],
                r["high"],
                r["low"],
                r["volume"],
                r.get("vwap"),
                r.get("vwap_dist"),
                r.get("vwap_dist_pct"),
                r.get("above_vwap"),
                r.get("ema9"),
                r.get("ema21"),
                r.get("ema9_ema21_spread"),
                r.get("ema9_above_ema21"),
                r.get("atr"),
                r.get("atr_pct"),
            )
            for r in batch
        ]

        max_retries = 3
        for attempt in range(max_retries + 1):
            conn = None
            try:
                conn = self._get_connection()
                cursor = conn.cursor()
                cursor.executemany(sql, rows)
                conn.commit()
                cursor.close()

                # After MySQL is done, cache latest bars to Redis
                # so PHP realtime-watch can read them in <1ms instead of
                # scanning 14M MySQL rows per loop iteration.
                BarBufferService._cache_latest_bars_redis(batch)

                return len(batch)  # rowcount for executemany is unreliable; use batch len
            except Exception as exc:
                err_msg = str(exc)
                is_deadlock = (
                    "1213" in err_msg
                    or "Deadlock" in err_msg
                    or "40001" in err_msg
                    or "1205" in err_msg
                )

                if is_deadlock and attempt < max_retries:
                    wait_ms = (attempt + 1) * 100  # 100, 200, 300 ms
                    logger.warning(
                        "DB upsert deadlock (attempt %d/%d, %d rows) — retrying in %d ms",
                        attempt + 1,
                        max_retries,
                        len(batch),
                        wait_ms,
                    )
                    time.sleep(wait_ms / 1000.0)
                    if conn and not conn._closed:
                        try:
                            conn.rollback()
                        except Exception:
                            pass
                        try:
                            conn.close()
                        except Exception:
                            pass
                    continue

                logger.error("DB upsert failed (%d rows): %s", len(batch), exc)
                self._total_errors += len(batch)
                if conn and not conn._closed:
                    try:
                        conn.rollback()
                    except Exception:
                        pass
                return 0
            finally:
                if conn and not conn._closed:
                    try:
                        conn.close()
                    except Exception:
                        pass

    @staticmethod
    def _get_connection():
        """Open a short-lived MySQL connection for a flush batch."""
        import pymysql

        return pymysql.connect(
            host=DB_CONFIG["host"],
            port=DB_CONFIG["port"],
            db=DB_CONFIG["database"],
            user=DB_CONFIG["user"],
            password=DB_CONFIG["password"],
            charset="utf8mb4",
            autocommit=False,
        )

    # ------------------------------------------------------------------
    # Redis cache: write latest bar snapshot so PHP realtime-watch can
    # read bars in microseconds instead of scanning 14M MySQL rows.
    # ------------------------------------------------------------------
    _redis = None

    @classmethod
    def _get_redis(cls):
        if cls._redis is None:
            import redis as redis_mod
            cls._redis = redis_mod.Redis(
                host=os.environ.get("REDIS_HOST", "127.0.0.1"),
                port=int(os.environ.get("REDIS_PORT", 6379)),
                password=(
                    os.environ.get("REDIS_PASSWORD")
                    if os.environ.get("REDIS_PASSWORD") not in (None, "", "null")
                    else None
                ),
                decode_responses=False,
            )
        return cls._redis

    @classmethod
    def _cache_latest_bars_redis(cls, batch: list[dict]) -> None:
        """Write latest bar per symbol to Redis as a hash.
        Key: {prefix}stream:bar:{SYMBOL}
        Fields: ts, price (close), open, high, low, volume,
                vwap, vwap_dist_pct, above_vwap,
                ema9, ema21, ema9_above_ema21, atr_pct,
                rvol, avg_vol_20, move_30m_pct, ts_est
        TTL: 1 hour (bars auto-refresh every minute from WebSocket)
        """
        try:
            r = cls._get_redis()
            prefix = os.environ.get("REDIS_PREFIX", "tikrtracker-database-")
            pipe = r.pipeline(transaction=False)
            for bar in batch:
                key = f"{prefix}stream:bar:{bar['symbol']}"
                mapping: dict[bytes, bytes] = {
                    b"ts": str(bar["ts"]).encode(),
                    b"price": str(bar["price"]).encode(),
                    b"open": str(bar["open"]).encode(),
                    b"high": str(bar["high"]).encode(),
                    b"low": str(bar["low"]).encode(),
                    b"volume": str(bar["volume"]).encode(),
                }
                # Attach computed indicators when present
                for field in (
                    "ts_est", "vwap", "vwap_dist_pct", "above_vwap",
                    "ema9", "ema21", "ema9_above_ema21", "atr_pct",
                    "rvol", "avg_vol_20", "move_30m_pct",
                ):
                    val = bar.get(field)
                    if val is not None:
                        mapping[field.encode()] = str(val).encode()

                pipe.hset(key, mapping=mapping)
                pipe.expire(key, 3700)  # 1h + small buffer
            pipe.execute()
        except Exception:
            pass  # Redis is optional; MySQL is the source of truth

    # ------------------------------------------------------------------
    # Startup warm-up: seed indicator states from today's MySQL bars
    # ------------------------------------------------------------------

    def warm_up_all_from_mysql(self, symbols: list[str]) -> None:
        """
        Bulk-load today's 1-minute bars from MySQL for all symbols so that
        VWAP, EMA, ATR, and volume history are accurate from first live bar.

        Call once at stream startup BEFORE subscribing to Alpaca.
        This is a synchronous query — safe to call before asyncio.run().
        """
        if not symbols:
            return

        market_open_utc = _market_open_utc_today()
        logger.info(
            "Warming up indicator states for %d symbols from MySQL (since %s UTC)...",
            len(symbols),
            market_open_utc,
        )

        try:
            conn = self._get_connection()
            cursor = conn.cursor()

            # Bulk fetch all today's bars for the universe in one query
            placeholders = ",".join(["%s"] * len(symbols))
            cursor.execute(
                f"""
                SELECT symbol, ts, price, `open`, high, low, volume
                FROM one_minute_prices
                WHERE asset_type = 'stock'
                  AND symbol IN ({placeholders})
                  AND ts >= %s
                ORDER BY symbol ASC, ts ASC
                """,
                (*symbols, market_open_utc),
            )
            rows = cursor.fetchall()
            cursor.close()
            conn.close()
        except Exception as exc:
            logger.warning("Indicator warm-up MySQL query failed: %s", exc)
            return

        # Group by symbol and feed through each state
        by_symbol: dict[str, list[dict]] = {}
        for row in rows:
            sym = str(row[0]).upper()
            ts_val = row[1]
            ts_str = ts_val.strftime("%Y-%m-%d %H:%M:%S") if hasattr(ts_val, "strftime") else str(ts_val)
            by_symbol.setdefault(sym, []).append({
                "symbol": sym,
                "ts": ts_str,
                "price": float(row[2]),
                "open": float(row[3]),
                "high": float(row[4]),
                "low": float(row[5]),
                "volume": float(row[6]),
            })

        seeded = 0
        for sym, bars in by_symbol.items():
            state = self._states.setdefault(sym, SymbolIndicatorState(sym))
            state.seed_from_bars(bars)
            seeded += 1

        logger.info(
            "Indicator warm-up complete: %d symbols seeded (%d total bars loaded)",
            seeded,
            len(rows),
        )

        # Write the latest computed indicators for every seeded symbol to Redis
        # so the PHP scanner has fresh data immediately (not just after first live bar)
        warmup_batch = [
            state.last_bar_snapshot
            for state in self._states.values()
            if state.last_bar_snapshot is not None
        ]
        if warmup_batch:
            BarBufferService._cache_latest_bars_redis(warmup_batch)
            logger.info(
                "Indicator warm-up: wrote %d symbol snapshots to Redis",
                len(warmup_batch),
            )


# ---------------------------------------------------------------------------
# Quick smoke test — run directly to verify DB connectivity
# ---------------------------------------------------------------------------
if __name__ == "__main__":
    import json

    logger.info("Running BarBufferService smoke test…")

    buffer = BarBufferService(flush_size=5, flush_interval=3.0)

    # Fake bars
    now_utc = datetime.now(timezone.utc)
    fake_bars = [
        {
            "symbol": "SMOKE",
            "open": 10.00 + i * 0.01,
            "high": 10.05 + i * 0.01,
            "low": 9.95 + i * 0.01,
            "close": 10.02 + i * 0.01,
            "volume": 100 + i,
            "timestamp": now_utc.strftime("%Y-%m-%d %H:%M:%S"),
        }
        for i in range(7)
    ]

    for bar in fake_bars:
        buffer.add(bar)
        logger.info("  Added bar for %s @ %s (pending: %d)", bar["symbol"], bar["timestamp"], buffer.pending_count)

    # Force flush any remainder
    buffer.flush()

    logger.info("Smoke test complete. Stats: %s", json.dumps(buffer.stats))
