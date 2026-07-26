# Redis-Backed Trade Alerts — Event-Driven Pipeline

## Architecture

Two independent paths produce `trade_alerts`. The SQL batch path (`trade:pipeline-*`)
is unchanged. The Redis event-driven path (`bar-events:consume`) is new.

```
stream_bars.py / bar_buffer.py
  ├→ rt:bars:5m:{Ymd}:stock:{SYM}  (sorted set) + "5m_bar" event → rt:events:bars
  ├→ rt:bars:1m:{Ymd}:stock:{SYM}  (sorted set) + "1m_bar" event → rt:events:bars
  └→ MySQL one_minute_prices (async)

php artisan bar-events:consume → BarEventConsumer (XREADGROUP rt:events:bars)
  ├─ handle5mBar(): scanSymbol() → rt:bars:5m:* → gates → candidate store
  └─ handle1mBar(): candidate check → findBestLong() → fetchOneMinuteBars() [FROM REDIS]
       → parent pipeline-specific classification → upsertAlert() → trade_alerts

Batch SQL: php artisan trade:pipeline-*  (unchanged, SQL-only)
```

## ML Compatibility

`UsesRedisForEntryFinding` only overrides `fetchOneMinuteBars()` to read from Redis.
The parent's `doFindBestLong()` runs unchanged — same entry types ML models expect.

## Pipeline Status

| Pipeline | ENV | Gate parity | Status |
|---|---|---|---|
| A (v90.1) | true | Standard | Prod-ready |
| B (v120.0) | true | Standard | Prod-ready |
| C (v101.0) | true | Standard + VWAP/EMA | Prod-ready |
| D (v60.3) | true | Standard | Prod-ready |
| E (v400.0) | true | Standard + VWAP/EMA | Prod-ready |
| F (v900.1) | true | Standard + helpers | Ready (helpers added) |
| G (v35.0) | true | Standard | Prod-ready |
| H (v25.2) | true | Standard | Prod-ready |
| I (v17.0) | true | Standard | Prod-ready |
| J (v2000.0) | true | Custom universe + bars | Prod-ready |
| K (v1100.0) | true | Full custom (gates match SQL) | Prod-ready |
| L (v1600.0) | true | Custom universe + bars | Prod-ready |
| M (v103.0) | true | Standard | Prod-ready |
| N (v1200.0) | true | Two-bar momentum | Prod-ready |
| P (v140.0) | true | Standard | Prod-ready |
| Q (v27.0) | true | Standard | Prod-ready |

R and S are different stacks (realtime watch/VWAP reversal) — not applicable.

## Custom-Gate Gap Analysis

| Pipeline | Missing Gates |
|---|---|
| L (v1600.0) | active window, top_days universe, losers_limit, pre-breakout detection |

## Fix Strategy (per-class gate override)

1. Override `doScan()` — call `redisRepo()->getLatestBars()` to get bars, apply pipeline-specific gates in PHP
2. Add required data to `MarketBar` DTO and Redis bar payload (ema9, ema21, aboveVwap, rsi14, bbPosition already done)
3. `buildIntradayUniverse()` handles market movers expansion — already called by Redis `doScan()`
4. Entry finder is ML-safe for all pipelines — no changes needed

## What's Left

Only 2 pipelines remain on SQL fallback:

| Pipeline | Missing Gates | Action |
|---|---|---|


14 of 16 pipelines are production-ready for Redis event-driven scanning.

## Startup

```bash
php artisan redis:hydrate-bars                              # Warm-up (once)
python3 alpaca_python_api/stream_bars.py                     # Real-time bar stream
php artisan bar-events:consume --group=scanner-h             # Event consumer
```

## Gate Comparison

| Gate | SQL | Redis |
|---|---|---|
| ATR(14) | MySQL AVG(GREATEST(...)) | Same in PHP |
| RVOL(20) | MySQL AVG(volume) | Same in PHP |
| 30m move | MySQL LAG(price, 6) | Same in PHP |
| Standard 6 gates | SQL WHERE clauses | Same in PHP |
| Entry VWAP/EMA/ATR | SQL | Same in PHP |
| Entry classification | Pipeline-specific | Inherited from parent |

## Redis Data Model

| Key | Type | Retention |
|---|---|---|
| rt:bars:1m:{Ymd}:stock:{SYM} | Sorted set | 420 bars, 2d TTL |
| rt:bars:5m:{Ymd}:stock:{SYM} | Sorted set | 100 bars, 2d TTL |
| rt:events:bars | Stream | ~100k events |
| rt:candidate:v{NN}:stock:{SYM} | String/JSON | 10 min |
| rt:alert-lock:v{NN}:{SYM}:{epoch} | String | 5-10 min |
| rt:daily:close:{date}:stock:{SYM} | String | 24h TTL |
