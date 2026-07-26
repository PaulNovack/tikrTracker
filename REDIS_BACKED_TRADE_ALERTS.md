# Redis-Backed Trade Alerts (v25.2)

## Overview

Pipeline H (v25.2) now supports an **event-driven Redis path** that eliminates MySQL from the realtime scanning pipeline. Bar events arrive on a Redis stream and trigger per-symbol scanning — no more polling the full universe via `trade:pipeline-h`.

**`trade:pipeline-h` and all other `trade:pipeline-*` commands are unchanged and remain SQL-based for backtests and fallback.**

## Architecture — Two Paths

### Event-Driven Redis (new — `bar-events:consume`)

```
Alpaca WebSocket ↓
stream_bars.py / bar_buffer.py ↓ (1m bar → indicators)
  ├─ _cache_latest_bars_redis():
  │    ├→ rt:bars:1m:{Ymd}:stock:{SYMBOL}  sorted set  420 bars, 2d TTL
  │    │   + "1m_bar" event → rt:events:bars stream
  │    └→ {prefix}stream:bar:{SYMBOL}       hash         1h TTL (snapshot)
  └─ _maybe_aggregate_5m() → _cache_5m_bar_to_sorted_set():
       ├→ rt:bars:5m:{Ymd}:stock:{SYMBOL}  sorted set  100 bars, 2d TTL
       │   + "5m_bar" event → rt:events:bars stream
       └─ MySQL one_minute_prices (async, independent)

                ↓ rt:events:bars stream ↓

php artisan bar-events:consume → BarEventConsumer::run() (XREADGROUP)
  ├─ handle5mBar():  dedup → scanSymbol() → rt:bars:5m:*
  │     → ATR(14) RVOL(20) move_30m notional → gates → rt:candidate:v25.2:stock:{SYM}
  └─ handle1mBar():  candidate? → findBestLong() → rt:bars:1m:*
        → VWAP EMA9/21 ATR14 HOD → gates → upsertAlert() → trade_alerts
```

### Batch SQL (unchanged — `trade:pipeline-*`)

```
php artisan trade:pipeline-h
  → FiveMinuteSignalScannerV25_2::scan()  (SQL CTE on five_minute_prices)
  → EntryFinder::findBestLong()            (SQL on one_minute_prices)
  → TradeAlertWriterV1::upsertAlert()      → trade_alerts
```

All `trade:pipeline-*` commands are **NOT modified** — SQL-only for backtests and fallback.

## Full Startup

```bash
# 1. Warm up Redis with today's bars (once or cron)
php artisan redis:hydrate-bars

# 2. Start Alpaca WebSocket bar/quote stream
/var/www/html/laravel-invest/.venv/bin/python3 alpaca_python_api/stream_bars.py

# 3. Start event consumer (scans per-symbol, writes trade_alerts)
php artisan bar-events:consume

# Options:
# --group=scanner-v25    (consumer group, default: scanner-v25)
# --consumer=worker-1    (unique name per process, default: worker-1)
# --batch=100            (max events per XREADGROUP, default: 100)
```

After all three are running, **`trade:pipeline-h` can be disabled** — trade alerts are generated entirely by bar events.

## .env Settings

```bash
TRADE_ALERT_H_VERSION=v25.2

# Only v25.2 Redis scanning enabled
TRADING_V25_SCANNER_USE_REDIS=true
TRADING_V27_SCANNER_USE_REDIS=false
TRADING_V17_SCANNER_USE_REDIS=false
TRADING_V35_SCANNER_USE_REDIS=false
TRADING_V60_SCANNER_USE_REDIS=false
TRADING_V90_SCANNER_USE_REDIS=false
TRADING_V120_SCANNER_USE_REDIS=false

REDIS_HOST=redis
REDIS_PORT=6379
REDIS_PASSWORD=null
```

## Per-Pipeline Toggle

| Pipeline | ENV Var | Scanner Class |
|---|---|---|
| H (v25.2) | `TRADING_V25_SCANNER_USE_REDIS` | `FiveMinuteSignalScannerV25_2Redis` |
| Q (v27.0) | `TRADING_V27_SCANNER_USE_REDIS` | `FiveMinuteSignalScannerV27_0Redis` |
| I (v17.0) | `TRADING_V17_SCANNER_USE_REDIS` | `FiveMinuteSignalScannerV17_0Redis` |
| D (v60.3) | `TRADING_V60_SCANNER_USE_REDIS` | `FiveMinuteSignalScannerV60_3Redis` |
| A (v90.1) | `TRADING_V90_SCANNER_USE_REDIS` | `FiveMinuteSignalScannerV90_1Redis` |
| B (v120.0) | `TRADING_V120_SCANNER_USE_REDIS` | `FiveMinuteSignalScannerV120_0Redis` |
| — (v35.0) | `TRADING_V35_SCANNER_USE_REDIS` | `FiveMinuteSignalScannerV35_0Redis` |

When `true`: `*Redis` class is used, reading bars from `rt:bars:*` sorted sets.
When `false`: base SQL class is used (MySQL queries).

## Redis Data Model

| Key Pattern | Type | Retention | Writer |
|---|---|---|---|
| `rt:bars:1m:{Ymd}:stock:{SYM}` | Sorted set | 420 bars, 2d TTL | bar_buffer.py + hydrate |
| `rt:bars:5m:{Ymd}:stock:{SYM}` | Sorted set | 100 bars, 2d TTL | bar_buffer.py + hydrate |
| `rt:events:bars` | Stream | ~100k events | bar_buffer.py |
| `rt:candidate:v25.2:stock:{SYM}` | String/JSON | 10 min | BarEventConsumer |
| `rt:alert-lock:v25.2:{SYM}:{epoch}:{type}` | String | 5-10 min | BarEventConsumer |
| `{prefix}stream:bar:{SYM}` | Hash | 1h | bar_buffer.py |

**Bar member format** (stored as JSON in sorted sets):
```json
{"ts":1785166200,"ts_est":"2026-07-26 09:30:00","symbol":"AAPL","open":12.34,"high":12.51,"low":12.30,"close":12.48,"volume":184350,"vwap":12.4275,"is_final":true,"source":"alpaca_stream"}
```

## Key Files

| File | Purpose |
|---|---|
| `app/Services/Trading/UsesRedisForScanning.php` | Trait: `doScan()` + `scanSymbol()` from Redis |
| `app/Services/Trading/UsesRedisForEntryFinding.php` | Trait: `doFindBestLong()` from Redis 1m bars |
| `app/Repositories/RedisBarRepository.php` | `getBars()` / `getLatestBars()` |
| `app/Services/Trading/BarEventConsumer.php` | Reads `rt:events:bars`, dispatches 5m/1m handlers |
| `app/Console/Commands/ConsumeBarEvents.php` | `bar-events:consume` command |
| `app/Console/Commands/RedisBarHydrator.php` | `redis:hydrate-bars` warm-up |
| `app/Services/Trading/AbstractSignalScanner.php` | Has `scanSymbol()` default |
| `alpaca_python_api/bar_buffer.py` | Writes 1m + 5m bars, emits events |
| `config/trading.php` | `v*.scanner.use_redis` keys |
| `.env` | Pipeline toggles |

## Verification

```bash
# Check bar counts
redis-cli ZCARD rt:bars:5m:20260726:stock:AAPL
redis-cli ZCARD rt:bars:1m:20260726:stock:AAPL

# Check stream length
redis-cli XLEN rt:events:bars

# Tinker
php artisan tinker
>>> Redis::zcard('rt:bars:5m:20260726:stock:AAPL')
```

## Troubleshooting

| Symptom | Check |
|---|---|
| No trade_alerts | `redis-cli XLEN rt:events:bars` > 0? Is `bar-events:consume` running? |
| No bar data in Redis | Run `php artisan redis:hydrate-bars` |
| stale data | Is `stream_bars.py` running? Redis restart requires re-hydration |
| Entry finder returns nothing | Check `rt:bars:1m:*` has data (warm-up + Python must be active) |
| Consumer group errors | `redis-cli XGROUP DESTROY rt:events:bars scanner-v25` | 
