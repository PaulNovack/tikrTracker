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

## Pipeline Status (as of 2026-07-26)

| Pipeline | .env Version | Redis Scanner | Redis Finder | use_redis | Status |
|---|---|---|---|---|---|
| A | v90.1 | ✅ V90_1Redis | ✅ V90_1Redis | true | Prod-ready |
| B | v120.0 | ✅ V120_0Redis | ✅ V120_0Redis | true | Prod-ready |
| C | v101.0 | ✅ V101_0Redis | ✅ V101_0Redis | true | Prod-ready |
| D | v60.3 | ✅ V60_3Redis | ✅ V60_3Redis | true | Prod-ready |
| E | v400.0 | ✅ V400_0Redis | ✅ V400_0Redis | true | Prod-ready |
| F | v900.1 | ✅ V900_1Redis | ✅ V900_1Redis | **false** | Disabled — gate helpers incomplete |
| G | v35.0 | ✅ V35_0Redis | ✅ V35_0Redis | true | Prod-ready |
| H | v25.2 | ✅ V25_2Redis | ✅ V25_2Redis | true | Prod-ready (tested) |
| I | v17.0 | ✅ V17_0Redis | ✅ V17_0Redis | true | Prod-ready |
| J | v2000.0 | ✅ V2000_0Redis | ✅ V2000_0Redis | true | Prod-ready |
| K | v1100.0 | ✅ V1100_0Redis | ✅ V1100_0Redis | true | Prod-ready |
| L | v1600.0 | ✅ V1600_0Redis | ✅ V1600_0Redis | true | Prod-ready |
| M | v103.0 | ✅ V103_0Redis | ✅ V103_0Redis | true | Prod-ready |
| N | v1200.0 | ✅ V1200_0Redis | ✅ V1200_0Redis | true | Prod-ready |
| P | v140.0 | ✅ V140_0Redis | ✅ V140_0Redis | true | Prod-ready |
| Q | v27.0 | ✅ V27_0Redis | ✅ V27_0Redis | true | Prod-ready |

**Not Redis-backed:**
| Pipeline | Version | Reason |
|---|---|---|
| O | v1500.0 | No Redis scanner/finder classes created |
| R | rt-v2.0 | Realtime watch — different stack |
| S | rt-v1.0 | VWAP reversal — different stack |
| F | v900.1 | `TRADING_PIPELINE_F_USE_REDIS=false` — gate helpers need completion |

**Overall: 16/19 pipelines have Redis classes, 15 enabled.**

## Known Issues

1. **BarEventConsumer hardcoded to v25.2**: The `handle5mBar()` and `handle1mBar()`
   methods use hardcoded Redis keys (`v25.2:...`). This means only pipeline H
   works correctly in the event-driven path. Needs to derive the version from the
   injected scanner via `$this->scanner->getVersion()`.

2. **ConsumeBarEvents is dynamic** but the `--pipeline` flag must be passed
   explicitly. Defaults to `h`.

3. **Supervisor has only pipeline H**: `laravel-invest-worker.conf` and
   `docker/supervisord.conf` only contain `bar-events-h`. Need entries for A-Q
   (except F, O, R, S).

4. **Pipeline O (v1500.0)**: No Redis scanner/finder classes. No `.env` entry.
   Need `FiveMinuteSignalScannerV1500_0Redis.php` and
   `OneMinuteEntryFinderV1500_0Redis.php`.

## What's Left

| Item | Action |
|---|---|
| Pipeline O Redis classes | Create V1500_0Redis scanner + finder |
| BarEventConsumer version | Derive version from scanner, not hardcoded |
| Supervisor entries | Add bar-events-a through bar-events-q |
| Pipeline F gate helpers | Complete helper methods to enable Redis |
| config/trading.php use_redis | Add to all pipeline sections |

## Startup

```bash
# 1. Warm-up (one-time, then run as cron before market open)
php artisan redis:hydrate-bars

# 2. Real-time bar stream (runs continuously via supervisor)
python3 alpaca_python_api/stream_bars.py

# 3. Event consumer — per pipeline (run via supervisor for A-Q)
php artisan bar-events:consume --pipeline=h --group=scanner-h --consumer=h-01 --batch=100
```

## Deployment

### Supervisor Configuration

Ubuntu supervisor config path: `/etc/supervisor/conf.d/*.conf`

**Deploy supervisor config:**
```bash
sudo cp laravel-invest-worker.conf /etc/supervisor/conf.d/
sudo supervisorctl reread
sudo supervisorctl update
sudo supervisorctl start all
```

**Currently deployed:** Only pipeline H in `laravel-invest-worker.conf`.

**To deploy all pipelines:**

**Docker:**
`docker/supervisord.conf` includes `bar-events-h`, `bar-stream` (stream_bars.py),
and `hydrate-bars` (manual one-shot).

### Verify Deployment

```bash
sudo supervisorctl status | grep bar-events
redis-cli ZCARD rt:bars:5m:$(date +%Y%m%d):stock:AAPL
redis-cli XLEN rt:events:bars
sudo supervisorctl tail -f laravel-invest-bar-events-h
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
