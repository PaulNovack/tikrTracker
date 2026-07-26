# Redis-Backed Trade Alerts (v25.2)

## Overview

The v25.2 (Pipeline H) signal scanner can now use Redis sorted sets (`rt:bars:5m:*`) instead of MySQL for 5-minute bar data, eliminating slow SQL queries from the realtime scanning path.

When enabled, each 1-minute bar arriving via `stream_bars.py` is aggregated into 5-minute bars and written to Redis in real-time. The PHP scanner reads directly from Redis, computes ATR/RVOL/move/notional in memory, and applies the same quality gates as the SQL path.

## Architecture

```
Alpaca WebSocket
  ↓
stream_bars.py / bar_buffer.py
  ↓ (indicators computed in memory)
bar_buffer.py::_maybe_aggregate_5m()
  ├→ rt:bars:5m:{Ymd}:stock:{SYMBOL}  (sorted set, 100-bar max, 2-day TTL)
  └→ rt:events:bars                    (stream, 100k maxlen)
        ↓
php artisan bar-events:consume  (event-driven per-symbol)
php artisan trade:pipeline-h    (batch 5m scan)
        ↓
FiveMinuteSignalScannerV25_2Redis
  uses UsesRedisForScanning
    → RedisBarRepository::getLatestBars()
    → computes ATR, RVOL, move_30m, notional
    → applies quality gates
    → returns signals
```

## .env Configuration

```bash
# Pipeline H version (must be v25.2 or higher)
TRADE_ALERT_H_VERSION=v25.2

# Enable Redis scanning for v25.2 (Pipeline H)
TRADING_V25_SCANNER_USE_REDIS=true

# Other pipelines (off by default)
TRADING_V27_SCANNER_USE_REDIS=false
TRADING_V17_SCANNER_USE_REDIS=false
TRADING_V35_SCANNER_USE_REDIS=false
TRADING_V60_SCANNER_USE_REDIS=false
TRADING_V90_SCANNER_USE_REDIS=false
TRADING_V120_SCANNER_USE_REDIS=false

# Redis connection (standard Laravel settings)
REDIS_HOST=redis
REDIS_PORT=6379
REDIS_PASSWORD=null
```

## Prerequisites

### 1. Redis must be running

```bash
redis-cli ping   # Should return PONG
```

### 2. Warm up Redis with historical bar data (run once)

```bash
# Full session warm-up (all symbols with 1_min=1)
php artisan redis:hydrate-bars

# Specific symbols only
php artisan redis:hydrate-bars --symbols=AAPL,TSLA,MSFT

# 1-hour window for testing
php artisan redis:hydrate-bars --minutes-1m=60 --minutes-5m=60
```

This reads `one_minute_prices` from MySQL, aggregates into 5-minute bars, and writes them to `rt:bars:5m:{date}:stock:{SYMBOL}` sorted sets. It also writes events to the `rt:events:bars` stream.

**Without this step, `getLatestBars()` returns empty results until `stream_bars.py` has been running long enough to fill Redis with live bars.**

### 3. stream_bars.py must be running

```bash
# Start the Alpaca WebSocket bar/quote stream
/var/www/html/laravel-invest/.venv/bin/python3 alpaca_python_api/stream_bars.py
```

This keeps `rt:bars:5m:*` sorted sets updated in real-time as new 5-minute bars complete. Each 1-minute bar is aggregated into 5-minute buckets (OHLCV), written to the sorted set, and a `5m_bar` event is emitted to `rt:events:bars`.

## Running the Pipeline

### Real-time event-driven scanning (per-symbol)

```bash
# Consume bar events from rt:events:bars stream
# When a new 5m bar completes, scan just that symbol
php artisan bar-events:consume
```

Options:
```
--group=scanner-v25    Redis stream consumer group name
--consumer=worker-1    Consumer name (unique per process)
--batch=100            Max events per read
```

### Batch pipeline (all symbols, periodic)

```bash
# Run Pipeline H — 5m scan → 1m entries → store alerts
php artisan trade:pipeline-h

# Backtest mode
php artisan trade:pipeline-h --backtest --from=2026-07-01 --to=2026-07-26

# Rolling window backtest
php artisan trade:pipeline-h --rolling-window
```

The command output shows which data source is active:
```
Pipeline H: Redis (rt:bars) data source
```

## Verifying Redis Data

```bash
# Enter Laravel Tinker
php artisan tinker

# Check if a symbol has 5m bars in Redis
>>> \Illuminate\Support\Facades\Redis::zcard('rt:bars:5m:20260726:stock:AAPL')
=> 50

# List today's keys
>>> \Illuminate\Support\Facades\Redis::keys('rt:bars:5m:20260726:stock:*')
```

## Per-Pipeline Toggle

Each pipeline with a `*Redis` scanner class has its own `.env` flag:

| Pipeline | ENV Var | Scanner Class |
|---|---|---|
| H (v25.2) | `TRADING_V25_SCANNER_USE_REDIS` | `FiveMinuteSignalScannerV25_2Redis` |
| Q (v27.0) | `TRADING_V27_SCANNER_USE_REDIS` | `FiveMinuteSignalScannerV27_0Redis` |
| I (v17.0) | `TRADING_V17_SCANNER_USE_REDIS` | `FiveMinuteSignalScannerV17_0Redis` |
| D (v60.3) | `TRADING_V60_SCANNER_USE_REDIS` | `FiveMinuteSignalScannerV60_3Redis` |
| A (v90.1) | `TRADING_V90_SCANNER_USE_REDIS` | `FiveMinuteSignalScannerV90_1Redis` |
| B (v120.0)| `TRADING_V120_SCANNER_USE_REDIS` | `FiveMinuteSignalScannerV120_0Redis` |
| — (v35.0) | `TRADING_V35_SCANNER_USE_REDIS` | `FiveMinuteSignalScannerV35_0Redis` |

When `true`, the `*Redis` class is instantiated with the `UsesRedisForScanning` trait, which reads bars from Redis. When `false`, the base SQL class is used.

## Key Files

| File | Purpose |
|---|---|
| `app/Services/Trading/UsesRedisForScanning.php` | Trait: overrides `doScan()` to read from Redis |
| `app/Repositories/RedisBarRepository.php` | Reads/writes `rt:bars:*` Redis sorted sets |
| `app/Console/Commands/RedisBarHydrator.php` | `redis:hydrate-bars` — warms up Redis from MySQL |
| `app/Console/Commands/TradePipelineRunH.php` | `trade:pipeline-h` — Pipeline H command |
| `app/Console/Commands/ConsumeBarEvents.php` | `bar-events:consume` — event-driven scanning |
| `alpaca_python_api/bar_buffer.py` | Computes indicators, aggregates 5m bars, writes to Redis |
| `config/trading.php` | Pipeline config (`scanner.use_redis` keys) |
| `.env` | `TRADING_V25_SCANNER_USE_REDIS=true` |

## Troubleshooting

### No signals returned

1. Check Redis has data: `redis-cli ZCARD rt:bars:5m:20260726:stock:AAPL`
2. Run warm-up: `php artisan redis:hydrate-bars`
3. Verify stream_bars.py is running and producing bars
4. Check `.env` has `TRADING_V25_SCANNER_USE_REDIS=true`
5. Run pipeline with debug: add `SCANNER_V25_DEBUG=1` to `.env`

### "Unable to locate file in Vite manifest" error

This is unrelated to Redis scanning — run `npm run build` or `npm run dev`.

### Empty results after restart

Run `php artisan redis:hydrate-bars` to re-populate Redis after a restart or Redis flush. Redis bar keys have a 2-day TTL.

### Backtest still uses SQL

Backtest mode (`--backtest`) is cache-free by design — it runs against `five_minute_prices_full` table for reproducibility. Redis scanning is designed for live/realtime mode.
