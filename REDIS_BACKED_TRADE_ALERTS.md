# Redis-Backed Trade Alerts — Event-Driven Pipeline

## Architecture

Two independent pipelines produce `trade_alerts`. The SQL batch path (unchanged) runs
via `trade:pipeline-*` commands. The Redis event-driven path runs via `bar-events:consume`.

### Event-Driven Redis Path (new)

```
┌──────────────────────────────────────────────────────────────────┐
│ Python (stream_bars.py + bar_buffer.py)                         │
│                                                                  │
│ Alpaca WebSocket ↓                                              │
│  1m bar → _cache_latest_bars_redis()                            │
│    ├→ rt:bars:1m:{Ymd}:stock:{SYM}    (sorted set, 420 bars)   │
│    │   + "1m_bar" event → rt:events:bars stream                │
│    └→ {prefix}stream:bar:{SYM}         (hash, latest snapshot)  │
│                                                                  │
│  1m bar → _maybe_aggregate_5m() → _cache_5m_bar_to_sorted_set() │
│    ├→ rt:bars:5m:{Ymd}:stock:{SYM}    (sorted set, 100 bars)   │
│    │   + "5m_bar" event → rt:events:bars stream                │
│    └→ MySQL one_minute_prices (async, independent)              │
└──────────────────────────────────────────────────────────────────┘
                           ↓ rt:events:bars
┌──────────────────────────────────────────────────────────────────┐
│ PHP (bar-events:consume → BarEventConsumer)                     │
│                                                                  │
│ XREADGROUP rt:events:bars                                       │
│   ├─ "5m_bar" event → handle5mBar()                             │
│   │     dedup lock → scanSymbol()                               │
│   │     read rt:bars:5m:* → compute ATR(14)/RVOL(20)/move_30m  │
│   │     apply scanner gates → if passes →                      │
│   │     store rt:candidate:v{NN}:stock:{SYM} (10 min TTL)      │
│   │                                                             │
│   └─ "1m_bar" event → handle1mBar()                             │
│         check rt:candidate:* → if active →                      │
│         findBestLong() → fetchOneMinuteBars() [FROM REDIS]      │
│         read rt:bars:1m:* → compute VWAP/EMA9/EMA21/ATR14/HOD   │
│         apply entry gates → parent's classify logic →           │
│         TradeAlertWriterV1::upsertAlert() → trade_alerts        │
└──────────────────────────────────────────────────────────────────┘
```

### Batch SQL Path (unchanged)

```
php artisan trade:pipeline-h
  → FiveMinuteSignalScannerV25_2::scan()    (SQL CTE on five_minute_prices)
  → OneMinuteEntryFinderV25_2::findBestLong() (SQL on one_minute_prices)
  → TradeAlertWriterV1::upsertAlert()        → trade_alerts
```

All `trade:pipeline-*` commands remain SQL-only.

---

## What Was Changed

### New files created
| File | Purpose |
|---|---|
| `UsesRedisForScanning.php` | Trait: `doScan()` + `scanSymbol()` from Redis 5m bars |
| `BarEventConsumer.php` | Consumes `rt:events:bars` stream, dispatches 5m/1m handlers |
| 9 `*Redis` scanner classes | Thin wrappers: extends base + `UsesRedisForScanning` |
| 9 `*Redis` entry finder classes | Thin wrappers: extends base + `UsesRedisForEntryFinding` |
| `REDIS_BACKED_TRADE_ALERTS.md` | This document |

### Modified files
| File | Change |
|---|---|
| `UsesRedisForEntryFinding.php` | **Rewritten**: now only overrides `fetchOneMinuteBars()` to read from Redis. Parent classification logic runs unchanged — ML-compatible entry types preserved. |
| `RedisBarRepository.php` | `getBars()` key format fixed to `YYYYMMDD` (was `YYYY-MM-DD`) |
| `AbstractSignalScanner.php` | Added default `scanSymbol()` returning null |
| `ConsumeBarEvents.php` | Wired `TradeAlertWriterV1` injection, variable naming fixed |
| `bar_buffer.py` | Added `_cache_5m_bar_to_sorted_set()` + `_maybe_aggregate_5m()` + 1m/5m bar + event writes to Redis |
| `config/trading.php` | Added `use_redis` keys to scanner sections |
| `.env` | All 16 pipeline toggles set to `true` |

---

## ML Compatibility — How Entry Types Are Preserved

The original `UsesRedisForEntryFinding` trait had its own `_redisClassifyEntryType` that
only returned 4 types: `VWAP_RECLAIM_STRONG`, `ORB_RETEST`, `EMA9_PULLBACK`, `MOMENTUM`.
Pipeline-specific types like `ELITE_MULTI_DAY`, `INSTITUTIONAL_ACCUMULATION`, etc. were
lost, which would break ML scoring.

**The fix:** The rewritten trait only overrides `fetchOneMinuteBars()` to read bars from
Redis `rt:bars:1m:*` sorted sets. The parent entry finder's `doFindBestLong()` runs
completely unchanged — same VWAP/EMA/ATR/HOD/OR calculations, same entry gates, same
**pipeline-specific classification**. ML models see the exact same entry types as SQL.

### Example call chain for Pipeline H (v25.2):
```
OneMinuteEntryFinderV25_2Redis::findBestLong()
  → AbstractOneMinuteEntryFinder::findBestLong()     [unchanged wrapper]
    → UsesRedisForEntryFinding::fetchOneMinuteBars() [REDIS — only this changes]
    → OneMinuteEntryFinderV25_2::doFindBestLong()    [ALL gate + classify logic preserved]
      → classifyEntry() → returns "VWAP_RECLAIM", "ORB_RETEST", etc.
    → TradeAlertWriterV1::upsertAlert() → trade_alerts
```

---

## Pipeline Status

| Pipeline | Version | Scanner Redis | Entry finder Redis | Config | .env | ML safe? |
|---|---|---|---|---|---|---|
| A | v90.1 | ✅ V90_1Redis | ✅ V90_1Redis | `trading.v90.scanner.use_redis` | true | ✅ |
| B | v120.0 | ✅ V120_0Redis | ✅ V120_0Redis | `trading.v120.scanner.use_redis` | true | ✅ |
| C | v101.0 | ✅ V101_0Redis | ✅ V101_0Redis | defaults via trait | true | ✅ |
| D | v60.3 | ✅ V60_3Redis | ✅ V60_3Redis | `trading.v60.scanner.use_redis` | true | ✅ |
| E | v400.0 | ✅ V400_0Redis | ✅ V400_0Redis | defaults via trait | true | ✅ |
| F | v900.1 | ✅ V900_1Redis | ✅ V900_1Redis | defaults via trait | true | ✅ |
| G | v35.0 | ✅ V35_0Redis | ✅ V35_0Redis | defaults via trait | true | ✅ |
| H | v25.2 | ✅ V25_2Redis | ✅ V25_2Redis | `trading.v25.scanner.use_redis` | true | ✅ |
| I | v17.0 | ✅ V17_0Redis | ✅ V17_0Redis | `trading.v17.scanner.use_redis` | true | ✅ |
| J | v2000.0 | ✅ V2000_0Redis | ✅ V2000_0Redis | defaults via trait | true | ✅ |
| K | v1100.0 | ✅ V1100_0Redis | ✅ V1100_0Redis | defaults via trait | true | ✅ |
| L | v1600.0 | ✅ V1600_0Redis | ✅ V1600_0Redis | defaults via trait | true | ✅ |
| M | v103.0 | ✅ V103_0Redis | ✅ V103_0Redis | defaults via trait | true | ✅ |
| N | v1200.0 | ✅ V1200_0Redis | ✅ V1200_0Redis | defaults via trait | true | ✅ |
| P | v140.0 | ✅ V140_0Redis | ✅ V140_0Redis | defaults via trait | true | ✅ |
| Q | v27.0 | ✅ V27_0Redis | ✅ V27_0Redis | `trading.v27.scanner.use_redis` | true | ✅ |

---

## Running the Event Pipeline

```bash
# 1. Warm up Redis with historical bars (run once, or as cron)
php artisan redis:hydrate-bars

# 2. Start Alpaca WebSocket stream (continuous during market hours)
/var/www/html/laravel-invest/.venv/bin/python3 alpaca_python_api/stream_bars.py

# 3. Start event consumer (continuous)
php artisan bar-events:consume --group=scanner-h --consumer=worker-1
```

---

## Gate Comparison — Redis vs SQL

All scanner gates and entry finder gates are **identical** between the two paths.
The only difference is the data source:

| Component | SQL Path | Redis Path |
|---|---|---|
| 5m bars | `five_minute_prices` table | `rt:bars:5m:*` sorted set |
| 1m bars | `one_minute_prices` table | `rt:bars:1m:*` sorted set |
| Scanner gates | MySQL CTE | PHP (same formulas) |
| Entry finder gates | SQL query | PHP (same formulas) |
| Entry types | Pipeline-specific | Pipeline-specific (parent logic) |
| Alert writing | `upsertAlert()` | `upsertAlert()` (identical) |

## Redis Data Model

| Key | Type | Retention | Writer |
|---|---|---|---|
| `rt:bars:1m:{Ymd}:stock:{SYM}` | Sorted set | 420 bars, 2d TTL | Python + hydrate |
| `rt:bars:5m:{Ymd}:stock:{SYM}` | Sorted set | 100 bars, 2d TTL | Python + hydrate |
| `rt:events:bars` | Stream | ~100k events | Python |
| `rt:candidate:{NN}:stock:{SYM}` | String/JSON | 10 min | BarEventConsumer |
| `rt:alert-lock:{NN}:{SYM}:{epoch}` | String | 5-10 min | BarEventConsumer |
