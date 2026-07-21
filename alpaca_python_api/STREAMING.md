# Alpaca WebSocket Streaming Daemon

Real-time 1-minute bar + quote ingestion via Alpaca's WebSocket feed, replacing the
`alpaca:sync-1m` cron job during market hours. In-memory technical indicator
computation (VWAP, EMA9/21, ATR14, RVOL, 30m move) feeds the realtime scanner
via Redis with sub-millisecond read latency.

## Files

| File | Purpose |
|------|---------|
| `stream_bars.py` | Daemon — connects to Alpaca WebSocket, handles bars, quotes, and updated bars |
| `bar_buffer.py` | Thread-safe in-memory buffer — batch-upserts bars to `one_minute_prices`, computes technical indicators, caches latest snapshots to Redis |
| `proxy_config.py` | Optional WebSocket proxy configuration for Alpaca connectivity |

## Prerequisites

- Alpaca **Algo Trader Plus** plan ($99/mo) for SIP feed access
- Without it the daemon works on the free **IEX** feed (sparser data, no real-time quotes)
- Python dependencies: `alpaca-py`, `pymysql`, `redis`

## Environment Variables (`.env`)

```dotenv
# Master on/off switch — daemon exits cleanly when false
ALPACA_STREAM_ENABLED=true

# iex = free plan  |  sip = paid plan (Algo Trader Plus)
ALPACA_STREAM_FEED=sip

# Stream toggles — selectively enable bar / quote / updated-bar channels
ALPACA_STREAM_BARS_ENABLED=true
ALPACA_STREAM_QUOTES_ENABLED=true
ALPACA_STREAM_UPDATED_BARS_ENABLED=false

# Flush to DB after N bars OR after M seconds — whichever comes first
ALPACA_STREAM_FLUSH_SIZE=50
ALPACA_STREAM_FLUSH_INTERVAL=10.0

# Quote buffer thresholds (quotes are deduplicated — only latest per symbol kept)
ALPACA_QUOTE_FLUSH_SIZE=250
ALPACA_QUOTE_FLUSH_INTERVAL=0.50

# When false, stream_bars.py will NOT write alpaca_bot:* Redis keys
ALPACA_BOT_REDIS=false

# Push completed 1-min bars to partitioned Redis streams for worker.py consumption
ALPACA_STREAM_REDIS_WORKER_ENABLED=true

# Optional WebSocket proxy (docker: shlomik/alpaca-proxy-agent)
ALPACA_PROXY_ENABLED=true
ALPACA_PROXY_URL=ws://127.0.0.1:8765

# Target MySQL table for latest quotes (auto-created if missing)
ALPACA_LATEST_QUOTES_TABLE=latest_stock_quotes
```

## Supervisor (`laravel-invest-worker.conf`)

The `laravel-invest-bar-stream` program is already defined with `autostart=true`:

```
[program:laravel-invest-bar-stream]
command=/var/www/html/laravel-invest/scripts/log-bar-stream.sh
autostart=true
autorestart=true
stopasgroup=true
killasgroup=true
user=pnovack
numprocs=1
```

The wrapper script (`scripts/log-bar-stream.sh`) routes output to a dated log:
```bash
exec python3 alpaca_python_api/stream_bars.py >> storage/logs/bar-stream-$(date +%Y-%m-%d).log 2>&1
```

## Go-Live Checklist

1.  Pay the $99 Alpaca Algo Trader Plus upgrade
2.  In `.env`:
    ```dotenv
    ALPACA_STREAM_ENABLED=true
    ALPACA_STREAM_FEED=sip
    ALPACA_STREAM_BARS_ENABLED=true
    ALPACA_STREAM_QUOTES_ENABLED=true
    ```
3.  Reload Supervisor:
    ```bash
    sudo supervisorctl reread && sudo supervisorctl update
    sudo supervisorctl start laravel-invest-bar-stream
    ```
4.  Verify bars and quotes are landing:
    ```bash
    tail -f storage/logs/bar-stream-$(date +%Y-%m-%d).log
    ```
5.  Comment out `alpaca:sync-1m` in `routes/console.php` (keep as fallback until
    streaming is confirmed stable for a full market day)

## How It Works

```
Alpaca WebSocket (SIP)
      │
      ├── 1-min bar events ──►  on_bar()
      │                              │
      │                    BarBufferService.add(bar)
      │                              │
      │                  ┌─ Compute indicators (VWAP, EMA9/21, ATR14, RVOL, 30m move)
      │                  │         │
      │                  │  (every N bars or M seconds)
      │                  │         │
      │                  │    BarBufferService.flush()
      │                  │         │
      │                  │    INSERT … ON DUPLICATE KEY UPDATE
      │                  │         │
      │                  │    one_minute_prices (source='alpaca_stream')
      │                  │         │
      │                  │    Redis: stream:bar:{SYMBOL} hash (latest snapshot)
      │                  │    Redis: stream:last_bar_ts  (signal key)
      │                  │    Redis: alpaca_bot:trades:pNNN (partitioned worker stream)
      │                  │
      ├── quote events ──►  on_quote()
      │                         │
      │               QuoteBufferService.add(quote)  — deduplicated per symbol
      │                         │
      │              (every 0.5s or 250 symbols)
      │                         │
      │               QuoteBufferService.flush()
      │                         │
      │               INSERT … ON DUPLICATE KEY UPDATE
      │                         │
      │               latest_stock_quotes
      │                         │
      │               Redis: stream:quote:{SYMBOL} hash (latest snapshot)
      │               Redis: stream:last_quote_ts  (signal key)
      │
      └── updated_bar events ──► on_updated_bar()  (disabled by default)
```

**BarBufferService details:**
- Bars accumulate in memory behind a `threading.Lock`
- Per-symbol `SymbolIndicatorState` maintains rolling EMA9/21, ATR14, cumulative day VWAP, 20-bar volume average, and 30-bar price history
- Indicators are computed incrementally — zero MySQL queries after warm-up
- Auto-flush when `ALPACA_STREAM_FLUSH_SIZE` bars are pending
- Time-based flush every `ALPACA_STREAM_FLUSH_INTERVAL` seconds (catches quiet periods)
- Deadlock retries up to 3 attempts with progressive backoff (100ms, 200ms, 300ms)
- After each MySQL flush, latest bar snapshots (with indicators) are cached to Redis
- Startup warm-up loads today's bars from MySQL to seed indicator states
- Final flush on `SIGTERM` / `SIGINT` so no bars are lost on graceful shutdown

**QuoteBufferService details:**
- Deduplicates quotes — only the latest per symbol is kept in memory
- Flushes deduplicated snapshots every `ALPACA_QUOTE_FLUSH_INTERVAL` seconds or when `ALPACA_QUOTE_FLUSH_SIZE` symbols are pending
- On flush failure, rows are retained (not lost)
- Writes latest quote snapshots to Redis hashes with 1hr TTL

**stream_bars.py details:**
- Loads all `asset_info` symbols where `1_min = 1` at startup
- Three parallel async tasks: WebSocket stream, periodic flush (250ms loop), stats logger (30s)
- Reconnects automatically on transient WebSocket drops (5s → 10s → … → 60s back-off)
- Supervisor handles true process crashes with `autorestart=true`
- Exits immediately (code 0) when `ALPACA_STREAM_ENABLED` is not `true` — safe for Supervisor
- Automatically creates `latest_stock_quotes` table if missing
- Loads `.env` and `.secret` files for credentials

## Smoke Testing (without live feed)

```bash
# Test BarBufferService DB connectivity + indicator computation
/var/www/html/laravel-invest/.venv/bin/python3 alpaca_python_api/bar_buffer.py

# Confirm stream_bars exits cleanly when disabled
/var/www/html/laravel-invest/.venv/bin/python3 alpaca_python_api/stream_bars.py
# → "[stream_bars] ALPACA_STREAM_ENABLED is not true — exiting."
```
