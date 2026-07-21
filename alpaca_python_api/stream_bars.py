#!/usr/bin/env python3
"""
stream_bars.py — Alpaca SIP WebSocket bars + real-time quotes daemon.

Subscribes to Alpaca real-time market data for all asset_info symbols where
1_min = 1.

Streams:
  - 1-minute bars into one_minute_prices via BarBufferService
  - real-time quotes into latest_stock_quotes
  - latest quote snapshots into Redis for fast stale-quote checks

Requires Algo Trader Plus for full SIP real-time stock data.

Run:
    /var/www/html/laravel-invest/.venv/bin/python3 alpaca_python_api/stream_bars.py

Recommended .env:
    ALPACA_STREAM_ENABLED=true
    ALPACA_STREAM_FEED=sip
    ALPACA_STREAM_BARS_ENABLED=true
    ALPACA_STREAM_QUOTES_ENABLED=true
"""

import asyncio
import logging
import os
import signal
import sys
import time
from datetime import datetime, timezone
from pathlib import Path
from typing import Any


# ---------------------------------------------------------------------------
# Load .env and .secret
# ---------------------------------------------------------------------------
def _load_env_file(path: Path) -> None:
    if not path.exists():
        return

    with open(path, "r") as _f:
        for _line in _f:
            _line = _line.strip()
            if _line and not _line.startswith("#") and "=" in _line:
                _k, _v = _line.split("=", 1)
                _k, _v = _k.strip(), _v.strip()

                if _v and _v[0] in ('"', "'") and _v[-1] == _v[0]:
                    _v = _v[1:-1]

                # .secret is loaded after .env and should override it.
                os.environ[_k] = _v


BASE_DIR = Path(__file__).parent.parent
_load_env_file(BASE_DIR / ".env")
_load_env_file(BASE_DIR / ".secret")


# ---------------------------------------------------------------------------
# Small env helpers
# ---------------------------------------------------------------------------
def env_bool(name: str, default: bool = False) -> bool:
    raw = os.environ.get(name)
    if raw is None:
        return default
    return raw.strip().lower() in ("true", "1", "yes", "y", "on")


def env_float(name: str, default: float) -> float:
    raw = os.environ.get(name)
    if raw is None or raw == "":
        return default
    return float(raw)


def env_int(name: str, default: int) -> int:
    raw = os.environ.get(name)
    if raw is None or raw == "":
        return default
    return int(raw)


# ---------------------------------------------------------------------------
# Feature flag
# ---------------------------------------------------------------------------
if not env_bool("ALPACA_STREAM_ENABLED", False):
    print("[stream_bars] ALPACA_STREAM_ENABLED is not true — exiting.")
    sys.exit(0)


# ---------------------------------------------------------------------------
# Logging
# ---------------------------------------------------------------------------
logging.basicConfig(
    level=logging.INFO,
    format="%(asctime)s [stream_bars] %(levelname)s %(message)s",
    datefmt="%Y-%m-%d %H:%M:%S",
)
logger = logging.getLogger("stream_bars")


# ---------------------------------------------------------------------------
# Alpaca credentials
# ---------------------------------------------------------------------------
ALPACA_KEY = os.environ.get("ALPACA_KEY_ID", "")
ALPACA_SECRET = os.environ.get("ALPACA_SECRET_KEY", "")

if not ALPACA_KEY or not ALPACA_SECRET:
    logger.error("ALPACA_KEY_ID and ALPACA_SECRET_KEY must be set in .env or .secret")
    sys.exit(1)


# ---------------------------------------------------------------------------
# Feed choice — now default to SIP because you bought Algo Trader Plus
# ---------------------------------------------------------------------------
from alpaca.data.enums import DataFeed  # noqa: E402

_feed_str = os.environ.get("ALPACA_STREAM_FEED", "sip").strip().lower()

if _feed_str == "sip":
    FEED = DataFeed.SIP
elif _feed_str == "iex":
    FEED = DataFeed.IEX
else:
    logger.error("Invalid ALPACA_STREAM_FEED=%r. Use 'sip' or 'iex'.", _feed_str)
    sys.exit(1)

logger.info("Using Alpaca data feed: %s", FEED.value)


# ---------------------------------------------------------------------------
# Stream toggles
# ---------------------------------------------------------------------------
STREAM_BARS_ENABLED = env_bool("ALPACA_STREAM_BARS_ENABLED", True)
STREAM_QUOTES_ENABLED = env_bool("ALPACA_STREAM_QUOTES_ENABLED", True)
STREAM_UPDATED_BARS_ENABLED = env_bool("ALPACA_STREAM_UPDATED_BARS_ENABLED", False)

if not STREAM_BARS_ENABLED and not STREAM_QUOTES_ENABLED and not STREAM_UPDATED_BARS_ENABLED:
    logger.error("No streams enabled. Enable bars, quotes, or updated bars.")
    sys.exit(1)


# ---------------------------------------------------------------------------
# Import BarBufferService
# ---------------------------------------------------------------------------
sys.path.insert(0, str(Path(__file__).parent))
from bar_buffer import BarBufferService  # noqa: E402


# ---------------------------------------------------------------------------
# DB config
# ---------------------------------------------------------------------------
def db_config() -> dict[str, Any]:
    return {
        "host": os.environ.get("DB_HOST", "127.0.0.1"),
        "port": int(os.environ.get("DB_PORT", 3306)),
        "db": os.environ.get("DB_DATABASE", "laravelInvest"),
        "user": os.environ.get("DB_USERNAME", "laravel"),
        "password": os.environ.get("DB_PASSWORD", "laravel"),
        "charset": "utf8mb4",
        "autocommit": True,
    }


def validate_table_name(name: str) -> str:
    safe = name.replace("_", "").replace("-", "")
    if not safe.isalnum():
        raise ValueError(f"Unsafe table name: {name!r}")
    return name


LATEST_QUOTES_TABLE = validate_table_name(
    os.environ.get("ALPACA_LATEST_QUOTES_TABLE", "latest_stock_quotes")
)


def load_symbols() -> list[str]:
    """Return all stock symbols where 1_min = 1 from asset_info."""
    import pymysql

    conn = pymysql.connect(**db_config())
    try:
        cursor = conn.cursor()
        cursor.execute(
            "SELECT symbol FROM asset_info "
            "WHERE asset_type = 'stock' "
            "AND `1_min` = 1 "
            "AND deleted_at IS NULL "
            "ORDER BY symbol"
        )
        return [row[0] for row in cursor.fetchall()]
    finally:
        conn.close()


def ensure_latest_quotes_table() -> None:
    """Create latest_stock_quotes if it does not already exist."""
    import pymysql

    sql = f"""
    CREATE TABLE IF NOT EXISTS `{LATEST_QUOTES_TABLE}` (
        symbol VARCHAR(32) NOT NULL PRIMARY KEY,
        bid_price DECIMAL(18, 6) NULL,
        ask_price DECIMAL(18, 6) NULL,
        bid_size BIGINT UNSIGNED NULL,
        ask_size BIGINT UNSIGNED NULL,
        bid_exchange VARCHAR(32) NULL,
        ask_exchange VARCHAR(32) NULL,
        quote_ts_utc DATETIME(6) NULL,
        received_at_utc DATETIME(6) NOT NULL,
        feed VARCHAR(16) NOT NULL DEFAULT 'sip',
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

        INDEX idx_quote_ts_utc (quote_ts_utc),
        INDEX idx_received_at_utc (received_at_utc),
        INDEX idx_bid_ask (bid_price, ask_price)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    """

    conn = pymysql.connect(**db_config())
    try:
        with conn.cursor() as cursor:
            cursor.execute(sql)
    finally:
        conn.close()


# ---------------------------------------------------------------------------
# Redis
# ---------------------------------------------------------------------------
import binascii as _binascii  # noqa: E402
import json as _json  # noqa: E402
import redis as _redis_mod  # noqa: E402

_redis = _redis_mod.Redis(
    host=os.environ.get("REDIS_HOST", "127.0.0.1"),
    port=int(os.environ.get("REDIS_PORT", 6379)),
    password=(
        os.environ.get("REDIS_PASSWORD")
        if os.environ.get("REDIS_PASSWORD") not in (None, "", "null")
        else None
    ),
    decode_responses=True,
)

REDIS_PREFIX = os.environ.get("REDIS_PREFIX", "tikrtracker-database-")

STREAM_SIGNAL_KEY = REDIS_PREFIX + "stream:last_bar_ts"
QUOTE_SIGNAL_KEY = REDIS_PREFIX + "stream:last_quote_ts"
QUOTE_KEY_PREFIX = REDIS_PREFIX + "stream:quote:"

# ---------------------------------------------------------------------------
# Worker stream config — mirrors redis_keys.py / config.py naming.
# When enabled, each completed 1-min bar is pushed to the same partitioned
# Redis streams that worker.py consumes via XREADGROUP.
# Enable with:  ALPACA_STREAM_REDIS_WORKER_ENABLED=true
# ---------------------------------------------------------------------------
WORKER_STREAM_ENABLED = env_bool("ALPACA_STREAM_REDIS_WORKER_ENABLED", False)
BOT_REDIS_ENABLED = env_bool("ALPACA_BOT_REDIS", True)
WORKER_REDIS_PREFIX = os.environ.get("ALPACA_WORKER_REDIS_PREFIX", "alpaca_bot")
WORKER_STREAM_PARTITIONS = env_int("ALPACA_WORKER_REDIS_STREAM_PARTITIONS", 16)
WORKER_STREAM_MAXLEN = env_int("ALPACA_WORKER_REDIS_STREAM_MAXLEN", 250_000)


def _worker_stream_key(symbol: str) -> str:
    partition = _binascii.crc32(symbol.encode("utf-8")) % WORKER_STREAM_PARTITIONS
    return f"{WORKER_REDIS_PREFIX}:trades:p{partition:03d}"


def _write_bar_to_worker_stream(bar: Any) -> None:
    """Push a completed 1-min bar into the partitioned Redis trade stream
    so worker.py can process it via its normal consumer-group path."""
    try:
        if not WORKER_STREAM_ENABLED or not BOT_REDIS_ENABLED:
            return

        symbol = str(_get_value(bar, "symbol", "S") or "").upper().strip()
        if not symbol:
            return

        close = _as_float(_get_value(bar, "close", "c"))
        volume = _as_int(_get_value(bar, "volume", "v"))
        ts = _to_utc_datetime_string(_get_value(bar, "timestamp", "t"))

        if close is None or close <= 0:
            return
        if ts is None:
            ts = _now_utc_string()

        _redis.xadd(
            _worker_stream_key(symbol),
            {
                "symbol": symbol,
                "price": str(close),
                "volume": str(volume if volume is not None else 0),
                "timestamp": ts,
                "conditions": "[]",
            },
            maxlen=WORKER_STREAM_MAXLEN,
            approximate=True,
        )
    except Exception as exc:
        logger.warning("Worker stream write failed for bar %s: %s",
                       _get_value(bar, "symbol", "S") or "?", exc)


def _signal_new_bar(ts_utc: str) -> None:
    try:
        _redis.set(STREAM_SIGNAL_KEY, ts_utc)
    except Exception as exc:
        logger.warning("Redis bar signal failed: %s", exc)


def _signal_new_quote(ts_utc: str) -> None:
    try:
        _redis.set(QUOTE_SIGNAL_KEY, ts_utc)
    except Exception as exc:
        logger.warning("Redis quote signal failed: %s", exc)


def _write_quotes_to_redis(rows: list[dict[str, Any]]) -> None:
    """Write latest quote snapshots to Redis, one hash per symbol.
    Only writes symbols with non-None bid_price and ask_price > 0."""
    if not rows:
        return

    try:
        pipe = _redis.pipeline(transaction=False)
        written = 0

        for row in rows:
            # Skip symbols without real quote data
            if row["bid_price"] is None or row["ask_price"] is None:
                continue
            if float(row["bid_price"]) <= 0 or float(row["ask_price"]) <= 0:
                continue

            symbol = row["symbol"]
            key = QUOTE_KEY_PREFIX + symbol

            pipe.hset(
                key,
                mapping={
                    "symbol": symbol,
                    "bid_price": str(row["bid_price"]),
                    "ask_price": str(row["ask_price"]),
                    "bid_size": str(row["bid_size"]) if row["bid_size"] is not None else "",
                    "ask_size": str(row["ask_size"]) if row["ask_size"] is not None else "",
                    "bid_exchange": str(row["bid_exchange"]) if row["bid_exchange"] is not None else "",
                    "ask_exchange": str(row["ask_exchange"]) if row["ask_exchange"] is not None else "",
                    "quote_ts_utc": str(row["quote_ts_utc"]) if row["quote_ts_utc"] is not None else "",
                    "received_at_utc": str(row["received_at_utc"]),
                    "feed": str(row["feed"]),
                },
            )

            # Expire after 1 hour so old dead quotes do not live forever.
            pipe.expire(key, 3600)
            written += 1

        if written > 0:
            pipe.execute()
    except Exception as exc:
        logger.warning("Redis latest quote write failed: %s", exc)


# ---------------------------------------------------------------------------
# Graceful shutdown
# ---------------------------------------------------------------------------
_shutdown_event: asyncio.Event | None = None
_active_loop: asyncio.AbstractEventLoop | None = None


def _handle_signal(signum: int, _frame: Any) -> None:
    logger.info("Received signal %d — shutting down…", signum)

    if _active_loop and _shutdown_event:
        _active_loop.call_soon_threadsafe(_shutdown_event.set)


signal.signal(signal.SIGTERM, _handle_signal)
signal.signal(signal.SIGINT, _handle_signal)


# ---------------------------------------------------------------------------
# Time / object extraction helpers
# ---------------------------------------------------------------------------
def _get_value(obj: Any, *names: str) -> Any:
    """
    Works with both alpaca-py objects and raw dict-style messages.
    Alpaca object names usually look like bid_price.
    Raw websocket messages may look like bp, ap, bs, as, t, S.
    """
    if isinstance(obj, dict):
        for name in names:
            if name in obj:
                return obj[name]

    for name in names:
        if hasattr(obj, name):
            return getattr(obj, name)

    return None


def _to_utc_datetime_string(value: Any) -> str | None:
    if value is None:
        return None

    try:
        # pandas Timestamp or similar
        if hasattr(value, "to_pydatetime"):
            value = value.to_pydatetime()

        if isinstance(value, str):
            # Handle Zulu timestamp strings.
            cleaned = value.replace("Z", "+00:00")
            value = datetime.fromisoformat(cleaned)

        if not isinstance(value, datetime):
            return None

        if value.tzinfo is None:
            value = value.replace(tzinfo=timezone.utc)

        value = value.astimezone(timezone.utc)
        return value.strftime("%Y-%m-%d %H:%M:%S.%f")
    except Exception:
        return None


def _now_utc_string() -> str:
    return datetime.now(timezone.utc).strftime("%Y-%m-%d %H:%M:%S.%f")


def _as_float(value: Any) -> float | None:
    if value is None or value == "":
        return None
    try:
        return float(value)
    except Exception:
        return None


def _as_int(value: Any) -> int | None:
    if value is None or value == "":
        return None
    try:
        return int(value)
    except Exception:
        try:
            return int(float(value))
        except Exception:
            return None


# ---------------------------------------------------------------------------
# Bar buffer
# ---------------------------------------------------------------------------
bar_buffer = BarBufferService(
    flush_size=env_int("ALPACA_STREAM_FLUSH_SIZE", 50),
    flush_interval=env_float("ALPACA_STREAM_FLUSH_INTERVAL", 10.0),
)

_orig_bar_flush = bar_buffer.flush


def _flush_bars_and_signal() -> int:
    written = _orig_bar_flush()

    if written > 0:
        _signal_new_bar(_now_utc_string())

    return written


bar_buffer.flush = _flush_bars_and_signal  # type: ignore[method-assign]


# ---------------------------------------------------------------------------
# Quote buffer
# ---------------------------------------------------------------------------
class QuoteBufferService:
    """
    Stores only the latest quote per symbol and flushes snapshots in batches.

    This is important because quote streams can be very noisy. You do not want
    to insert every quote tick into MySQL for every watched symbol.
    """

    def __init__(self, flush_size: int = 250, flush_interval: float = 0.50):
        self.flush_size = flush_size
        self.flush_interval = flush_interval
        self.latest_by_symbol: dict[str, dict[str, Any]] = {}
        self.last_flush = time.monotonic()

        self.total_received = 0
        self.total_written = 0
        self.total_flushes = 0
        self.total_errors = 0

    @property
    def stats(self) -> dict[str, Any]:
        return {
            "pending_symbols": len(self.latest_by_symbol),
            "total_received": self.total_received,
            "total_written": self.total_written,
            "total_flushes": self.total_flushes,
            "total_errors": self.total_errors,
        }

    def add(self, quote: Any) -> None:
        symbol = _get_value(quote, "symbol", "S")
        if not symbol:
            return

        symbol = str(symbol).upper().strip()
        received_at_utc = _now_utc_string()

        row = {
            "symbol": symbol,
            "bid_price": _as_float(_get_value(quote, "bid_price", "bp")),
            "ask_price": _as_float(_get_value(quote, "ask_price", "ap")),
            "bid_size": _as_int(_get_value(quote, "bid_size", "bs")),
            "ask_size": _as_int(_get_value(quote, "ask_size", "as")),
            "bid_exchange": _get_value(quote, "bid_exchange", "bx"),
            "ask_exchange": _get_value(quote, "ask_exchange", "ax"),
            "quote_ts_utc": _to_utc_datetime_string(_get_value(quote, "timestamp", "t")),
            "received_at_utc": received_at_utc,
            "feed": FEED.value,
        }

        self.latest_by_symbol[symbol] = row
        self.total_received += 1

    def should_flush(self) -> bool:
        if not self.latest_by_symbol:
            return False

        if len(self.latest_by_symbol) >= self.flush_size:
            return True

        return (time.monotonic() - self.last_flush) >= self.flush_interval

    def flush_if_ready(self) -> int:
        if self.should_flush():
            return self.flush()
        return 0

    def flush(self) -> int:
        if not self.latest_by_symbol:
            self.last_flush = time.monotonic()
            return 0

        rows = list(self.latest_by_symbol.values())
        self.latest_by_symbol.clear()
        self.last_flush = time.monotonic()

        sql = f"""
        INSERT INTO `{LATEST_QUOTES_TABLE}` (
            symbol,
            bid_price,
            ask_price,
            bid_size,
            ask_size,
            bid_exchange,
            ask_exchange,
            quote_ts_utc,
            received_at_utc,
            feed
        ) VALUES (
            %(symbol)s,
            %(bid_price)s,
            %(ask_price)s,
            %(bid_size)s,
            %(ask_size)s,
            %(bid_exchange)s,
            %(ask_exchange)s,
            %(quote_ts_utc)s,
            %(received_at_utc)s,
            %(feed)s
        )
        ON DUPLICATE KEY UPDATE
            bid_price = VALUES(bid_price),
            ask_price = VALUES(ask_price),
            bid_size = VALUES(bid_size),
            ask_size = VALUES(ask_size),
            bid_exchange = VALUES(bid_exchange),
            ask_exchange = VALUES(ask_exchange),
            quote_ts_utc = VALUES(quote_ts_utc),
            received_at_utc = VALUES(received_at_utc),
            feed = VALUES(feed),
            updated_at = CURRENT_TIMESTAMP
        """

        try:
            import pymysql

            conn = pymysql.connect(**db_config())
            try:
                with conn.cursor() as cursor:
                    cursor.executemany(sql, rows)
            finally:
                conn.close()

            _write_quotes_to_redis(rows)
            _signal_new_quote(_now_utc_string())

            written = len(rows)
            self.total_written += written
            self.total_flushes += 1

            return written

        except Exception as exc:
            self.total_errors += 1
            logger.error("Quote flush failed: %s", exc)

            # Put the latest rows back so they are not lost.
            for row in rows:
                self.latest_by_symbol[row["symbol"]] = row

            return 0


quote_buffer = QuoteBufferService(
    flush_size=env_int("ALPACA_QUOTE_FLUSH_SIZE", 250),
    flush_interval=env_float("ALPACA_QUOTE_FLUSH_INTERVAL", 0.50),
)


# ---------------------------------------------------------------------------
# Stream handlers
# ---------------------------------------------------------------------------
async def on_bar(bar: Any) -> None:
    """Handler called by StockDataStream for each completed 1-minute bar."""
    bar_buffer.add(bar)
    if WORKER_STREAM_ENABLED:
        _write_bar_to_worker_stream(bar)


async def on_updated_bar(bar: Any) -> None:
    """Handler for in-progress updated bars — buffered to MySQL only,
    not forwarded to the worker stream to avoid duplicate events."""
    bar_buffer.add(bar)


async def on_quote(quote: Any) -> None:
    """Handler called by StockDataStream for each incoming quote."""
    quote_buffer.add(quote)


async def periodic_flush(interval: float = 0.25) -> None:
    """
    Flush both buffers regularly.

    Quote flushing is intentionally more frequent than bar flushing because
    your live entry logic needs very fresh bid/ask data.
    """
    while _shutdown_event and not _shutdown_event.is_set():
        try:
            await asyncio.sleep(interval)
        except asyncio.CancelledError:
            break

        if STREAM_BARS_ENABLED or STREAM_UPDATED_BARS_ENABLED:
            bar_buffer.flush_if_ready()

        if STREAM_QUOTES_ENABLED:
            quote_buffer.flush_if_ready()

    # Final flush on shutdown
    if STREAM_BARS_ENABLED or STREAM_UPDATED_BARS_ENABLED:
        flushed_bars = bar_buffer.flush()
        if flushed_bars:
            logger.info("Final shutdown bar flush: %d bars written", flushed_bars)

    if STREAM_QUOTES_ENABLED:
        flushed_quotes = quote_buffer.flush()
        if flushed_quotes:
            logger.info("Final shutdown quote flush: %d quote rows written", flushed_quotes)


async def stats_logger(interval: float = 30.0) -> None:
    while _shutdown_event and not _shutdown_event.is_set():
        try:
            await asyncio.sleep(interval)
        except asyncio.CancelledError:
            break

        logger.info(
            "Stats: bars=%s quotes=%s",
            getattr(bar_buffer, "stats", {}),
            quote_buffer.stats,
        )


async def run_stream(symbols: list[str]) -> None:
    global _shutdown_event, _active_loop

    from alpaca.data.live import StockDataStream

    _active_loop = asyncio.get_running_loop()
    _shutdown_event = asyncio.Event()

    # Apply proxy env var so alpaca-py SDK connects via proxy if configured
    from proxy_config import apply_proxy_config

    apply_proxy_config()

    stream = StockDataStream(ALPACA_KEY, ALPACA_SECRET, feed=FEED)

    if STREAM_BARS_ENABLED:
        stream.subscribe_bars(on_bar, *symbols)

    if STREAM_UPDATED_BARS_ENABLED:
        stream.subscribe_updated_bars(on_updated_bar, *symbols)

    if STREAM_QUOTES_ENABLED:
        stream.subscribe_quotes(on_quote, *symbols)

    logger.info(
        "Subscribed to %d symbols on feed=%s bars=%s updated_bars=%s quotes=%s "
        "(bar_flush_size=%d bar_flush_interval=%.2fs quote_flush_size=%d quote_flush_interval=%.2fs)",
        len(symbols),
        FEED.value,
        STREAM_BARS_ENABLED,
        STREAM_UPDATED_BARS_ENABLED,
        STREAM_QUOTES_ENABLED,
        bar_buffer.flush_size,
        bar_buffer.flush_interval,
        quote_buffer.flush_size,
        quote_buffer.flush_interval,
    )

    # alpaca-py exposes run(), but because this daemon needs its own async flush
    # loop, we use the SDK's async internal loop here like your existing script.
    stream_task = asyncio.create_task(stream._run_forever())
    flush_task = asyncio.create_task(periodic_flush())
    stats_task = asyncio.create_task(stats_logger())

    await _shutdown_event.wait()

    logger.info("Shutting down stream...")

    try:
        await stream.stop_ws()
    except Exception as exc:
        logger.warning("stream.stop_ws() failed: %s", exc)

    stream_task.cancel()
    flush_task.cancel()
    stats_task.cancel()

    for task in (stream_task, flush_task, stats_task):
        try:
            await task
        except asyncio.CancelledError:
            pass
        except Exception as exc:
            logger.warning("Task ended with error during shutdown: %s", exc)

    logger.info(
        "Stream closed. Final stats: bars=%s quotes=%s",
        getattr(bar_buffer, "stats", {}),
        quote_buffer.stats,
    )


def main() -> None:
    logger.info("stream_bars starting up...")

    backoff = 5

    while True:
        if _shutdown_event and _shutdown_event.is_set():
            break

        try:
            if STREAM_QUOTES_ENABLED:
                ensure_latest_quotes_table()

            symbols = load_symbols()
            if not symbols:
                logger.error("No symbols found in asset_info where 1_min=1. Exiting.")
                sys.exit(1)

            logger.info("Loaded %d symbols from asset_info", len(symbols))

            if STREAM_BARS_ENABLED or STREAM_UPDATED_BARS_ENABLED:
                bar_buffer.warm_up_all_from_mysql(symbols)

            if FEED != DataFeed.SIP:
                logger.warning(
                    "You are not using SIP. Set ALPACA_STREAM_FEED=sip for Algo Trader Plus."
                )

            asyncio.run(run_stream(symbols))
            break

        except KeyboardInterrupt:
            logger.info("KeyboardInterrupt received. Exiting.")
            break

        except Exception as exc:
            logger.warning("Startup/stream error: %s — retrying in %ds", exc, backoff)
            time.sleep(backoff)
            backoff = min(backoff * 2, 60)

    logger.info("stream_bars exited.")


if __name__ == "__main__":
    main()