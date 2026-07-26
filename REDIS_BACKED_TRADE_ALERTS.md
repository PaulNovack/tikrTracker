# Redis-Backed Trade Alerts — Event-Driven Pipeline

## Architecture

Two independent paths produce `trade_alerts`. The SQL batch path (`trade:pipeline-*`)
is unchanged. The Redis event-driven path (`bar-events:consume`) is new.

### Event-Driven Redis Path

```
stream_bars.py / bar_buffer.py
  ├→ rt:bars:5m:{Ymd}:stock:{SYM}  (sorted set) + "5m_bar" event → rt:events:bars
  ├→ rt:bars:1m:{Ymd}:stock:{SYM}  (sorted set) + "1m_bar" event → rt:events:bars
  └→ MySQL one_minute_prices (async)

php artisan bar-events:consume → BarEventConsumer (XREADGROUP rt:events:bars)
  ├─ handle5mBar(): scanSymbol() → rt:bars:5m:* → gates → candidate store
  └─ handle1mBar(): candidate check → findBestLong() → fetchOneMinuteBars() [FROM REDIS]
       → parent pipeline-specific classification → upsertAlert() → trade_alerts
```

### Batch SQL Path (unchanged)

```
php artisan trade:pipeline-h
  → FiveMinuteSignalScannerV25_2::scan()  (SQL)
  → OneMinuteEntryFinderV25_2::findBestLong()  (SQL)
  → TradeAlertWriterV1::upsertAlert() → trade_alerts
```

## ML Compatibility

The `UsesRedisForEntryFinding` trait **only overrides `fetchOneMinuteBars()`** to read
from `rt:bars:1m:*` sorted sets instead of MySQL. The parent entry finder's
`doFindBestLong()` runs **completely unchanged** — same VWAP/EMA/ATR/HOD/OR
calculations, same entry gates, same **pipeline-specific classification**.
ML models see the exact same `entry_type` values as the SQL path.

## How It Works

### Scanner (event-driven, per-symbol)
- `scanSymbol(symbol, asOfTsEst)` reads the latest ~30 5m bars from `rt:bars:5m:*`
- Computes ATR(14), RVOL(20), 30m move, notional in PHP
- Applies the same 6 gates as the SQL CTE (notional, ATR%, activity, move_floor, RS)
- Returns signal array or null

### Entry finder (runs after scanner signal)
- `findBestLong()` → parent's `doFindBestLong()` 
- Calls `fetchOneMinuteBars()` → overridden by trait → reads `rt:bars:1m:*` from Redis
- Parent computes VWAP/EMA9/EMA21/ATR14/HOD/OR-high from Redis bars
- Parent applies all entry gates (notional, body%, VWAP, room, trend, vol_ratio)
- Parent classifies entry type using its own pipeline-specific logic
- Returns `entry_price`, `stop_loss`, `entry_type`, `entry_meta`

### Alert writing
- `TradeAlertWriterV1::upsertAlert()` — same dedup, freshness, sentiment, position sizing
- `INSERT INTO trade_alerts` — same table, same schema

## Pipeline Status

All 16 pipelines have `*Redis` scanner + entry finder classes and pipeline-letter `.env` toggles:

| Pipeline | Version | `.env` toggle |
|---|---|---|
| A | v90.1 | `TRADING_PIPELINE_A_USE_REDIS=true` |
| B | v120.0 | `TRADING_PIPELINE_B_USE_REDIS=true` |
| C | v101.0 | `TRADING_PIPELINE_C_USE_REDIS=true` |
| D | v60.3 | `TRADING_PIPELINE_D_USE_REDIS=true` |
| E | v400.0 | `TRADING_PIPELINE_E_USE_REDIS=true` |
| F | v900.1 | `TRADING_PIPELINE_F_USE_REDIS=true` |
| G | v35.0 | `TRADING_PIPELINE_G_USE_REDIS=true` |
| H | v25.2 | `TRADING_PIPELINE_H_USE_REDIS=true` |
| I | v17.0 | `TRADING_PIPELINE_I_USE_REDIS=true` |
| J | v2000.0 | `TRADING_PIPELINE_J_USE_REDIS=true` |
| K | v1100.0 | `TRADING_PIPELINE_K_USE_REDIS=true` |
| L | v1600.0 | `TRADING_PIPELINE_L_USE_REDIS=true` |
| M | v103.0 | `TRADING_PIPELINE_M_USE_REDIS=true` |
| N | v1200.0 | `TRADING_PIPELINE_N_USE_REDIS=true` |
| P | v140.0 | `TRADING_PIPELINE_P_USE_REDIS=true` |
| Q | v27.0 | `TRADING_PIPELINE_Q_USE_REDIS=true` |

**R and S are different stacks (realtime watch/VWAP reversal) — not applicable.**

## Gate Comparison

| Gate | SQL | Redis | Match? |
|---|---|---|---|
| Scanner: ATR(14) | AVG(GREATEST(...)) in MySQL CTE | Same formula in PHP | ✅ |
| Scanner: RVOL(20) | AVG(volume) in MySQL CTE | Same formula in PHP | ✅ |
| Scanner: 30m move | LAG(price, 6) in MySQL | Same formula in PHP | ✅ |
| Scanner: 6 gates | Same thresholds, same logic | Same | ✅ |
| Entry: VWAP | Typical×Vol cumulative | Same | ✅ |
| Entry: EMA9/21 | 2/(N+1) coefficient | Same | ✅ |
| Entry: ATR14 | max(H-L,|H-P|,|L-P|) | Same | ✅ |
| Entry: HOD | max(high) over session | Same | ✅ |
| Entry: Classification | Pipeline-specific | Pipeline-specific (parent logic) | ✅ |
| Alert: upsertAlert() | Same dedup/freshness/sentiment | Same | ✅ |

## Startup

```bash
# 1. Warm up Redis (run tonight or early AM)
php artisan redis:hydrate-bars

# 2. Start Alpaca stream (continuous market hours)
/var/www/html/laravel-invest/.venv/bin/python3 alpaca_python_api/stream_bars.py

# 3. Start event consumer (continuous)
php artisan bar-events:consume --group=scanner-h --consumer=worker-1

# 4. Batch pipeline (optional — auto-skips when Redis enabled, works for backtests)
php artisan trade:pipeline-h
```

## Redis Data Model

| Key | Type | Retention | Writer |
|---|---|---|---|
| `rt:bars:1m:{Ymd}:stock:{SYM}` | Sorted set | 420 bars, 2d TTL | Python + hydrate |
| `rt:bars:5m:{Ymd}:stock:{SYM}` | Sorted set | 100 bars, 2d TTL | Python + hydrate |
| `rt:events:bars` | Stream | ~100k events | Python |
| `rt:candidate:v25.2:stock:{SYM}` | String/JSON | 10 min | BarEventConsumer |
| `rt:alert-lock:v25.2:{SYM}:{epoch}` | String | 5-10 min | BarEventConsumer |

## Key Files

| File | Purpose |
|---|---|
| `UsesRedisForScanning.php` | Trait: `doScan()` + `scanSymbol()` from Redis 5m bars |
| `UsesRedisForEntryFinding.php` | Trait: `fetchOneMinuteBars()` from Redis 1m bars |
| `RedisBarRepository.php` | `getBars()` / `getLatestBars()` |
| `BarEventConsumer.php` | Reads `rt:events:bars`, dispatches 5m/1m handlers, calls `upsertAlert()` |
| `ConsumeBarEvents.php` | `bar-events:consume` command |
| `AbstractSignalScanner.php` | Default `scanSymbol()`, Redis-aware `scan()` skip |
| `RedisBarHydrator.php` | `redis:hydrate-bars` warm-up |
| `bar_buffer.py` | Writes 5m + 1m bars to Redis, emits events |
