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

`UsesRedisForEntryFinding` **only overrides `fetchOneMinuteBars()`** to read from Redis.
The parent's `doFindBestLong()` runs unchanged — same entry types ML models expect.

## Pipeline Status

| Pipeline | ENV | Gate parity | Status |
|---|---|---|---|
| A (v90.1) | true | Standard | ✅ Prod-ready |
| B (v120.0) | true | Standard | ✅ Prod-ready |
| C (v101.0) | false | Custom | SQL fallback |
| D (v60.3) | true | Standard | ✅ Prod-ready |
| E (v400.0) | false | Custom | SQL fallback |
| F (v900.1) | false | Custom | SQL fallback |
| G (v35.0) | true | Standard | ✅ Prod-ready |
| H (v25.2) | true | Standard | ✅ Prod-ready |
| I (v17.0) | true | Standard | ✅ Prod-ready |
| J (v2000.0) | false | Custom | SQL fallback |
| K (v1100.0) | false | Custom | SQL fallback |
| L (v1600.0) | false | Custom | SQL fallback |
| M (v103.0) | true | Standard | ✅ Prod-ready |
| N (v1200.0) | false | Custom | SQL fallback |
| P (v140.0) | true | Standard | ✅ Prod-ready |
| Q (v27.0) | true | Standard | ✅ Prod-ready |

R and S are different stacks (realtime watch/VWAP reversal) — not applicable.

---

## What Needs to Be Done for Custom-Gate Pipelines

These pipelines have SQL with additional gates beyond the standard 6 (notional, ATR%,
activity, move_floor, RS). The `*Redis` classes exist but `shouldUseRedis()` returns
`false`, falling back to SQL until gate parity is achieved.

### Per-Pipeline Gap Analysis

| Pipeline | Version | Missing Gates |
|---|---|---|
| C | v101.0 | above_vwap=1, EMA9>EMA21, composite score, priority boost, pre-breakout RVOL |
| E | v400.0 | multi-day structure: above_vwap, EMA9>EMA21, trend confirmation, impulse detection, choppiness |
| F | v900.1 | yesterday move, vol_mult, momentum continuation score |
| J | v2000.0 | market-movers universe, freshness window, movers filtering |
| K | v1100.0 | SPY below VWAP, RS ratio vs benchmark, EMA spread, range contraction, distance from high (ATR multiples), green close requirement |
| L | v1600.0 | active window, top_days universe, losers_limit, pre-breakout detection |
| N | v1200.0 | market movers universe, two-bar momentum, min_gain_pct |

### Fix Strategy (Option B: per-class gate override)

For each custom-gate pipeline, the `*Redis` scanner class should:

1. **Override `doScan()`** — call `redisRepo()->getLatestBars('5m', ...)` to get bars,
   then apply pipeline-specific gates in PHP (mirroring the SQL logic).
2. **Override `scanSymbol()`** — same as above but for single-symbol event-driven path.
3. **Add required data to Redis bar payload** if needed:
   - `above_vwap`, `ema9`, `ema21`, `vwap` — add to `MarketBar` DTO and bar payload
   - Benchmark data (SPY VWAP, RS ratio) — compute in PHP from benchmark bars
4. **Universe building** — the `buildIntradayUniverse()` method already handles
   market movers expansion via config. The Redis `doScan()` already calls it.

Entry finder side is already ML-safe for all pipelines — no changes needed.

### Example: Fix Pipeline K (v1100.0)

v1100's additional gates require:
- **SPY below VWAP**: Can be computed in PHP from benchmark bars
- **RS ratio vs benchmark**: `(stock_move - spy_move)` — already in `spyMove30m` from trait
- **EMA spread**: Need ema9/ema21 in bar payload (add to Python writer)
- **Distance from high**: Computable from bar high values
- **Green close**: `close > open` — simple

### Quick Start for a Fix

```php
// In FiveMinuteSignalScannerV1100_0Redis.php:
class FiveMinuteSignalScannerV1100_0Redis extends FiveMinuteSignalScannerV1100_0
{
    use UsesRedisForScanning;
    
    // Override to true when gate parity is verified
    protected function shouldUseRedis(): bool
    {
        return (bool) config('trading.pipelines.k.use_redis', false);
    }
    
    protected function doScan(...): array
    {
        if (! $this->shouldUseRedis()) {
            return parent::doScan(...func_get_args());
        }
        
        // Read 5m bars from Redis
        // Apply standard 6 gates + v1100-specific gates
        // Return signals in same format as parent
    }
}
```

## Startup

```bash
php artisan redis:hydrate-bars                              # Warm-up (once)
python3 alpaca_python_api/stream_bars.py                     # Real-time bar stream
php artisan bar-events:consume --group=scanner-h             # Event consumer
```

## Gate Comparison

| Gate | SQL | Redis | Match? |
|---|---|---|---|
| ATR(14) | MySQL AVG(GREATEST(...)) | Same in PHP | ✅ |
| RVOL(20) | MySQL AVG(volume) | Same in PHP | ✅ |
| 30m move | MySQL LAG(price, 6) | Same in PHP | ✅ |
| Standard 6 gates | SQL WHERE clauses | Same in PHP | ✅ |
| Entry VWAP/EMA/ATR | SQL | Same in PHP | ✅ |
| Entry classification | Pipeline-specific | Inherited from parent | ✅ |

## Redis Data Model

| Key | Type | Retention |
|---|---|---|
| `rt:bars:1m:{Ymd}:stock:{SYM}` | Sorted set | 420 bars, 2d TTL |
| `rt:bars:5m:{Ymd}:stock:{SYM}` | Sorted set | 100 bars, 2d TTL |
| `rt:events:bars` | Stream | ~100k events |
| `rt:candidate:v{NN}:stock:{SYM}` | String/JSON | 10 min |
| `rt:alert-lock:v{NN}:{SYM}:{epoch}` | String | 5-10 min |
