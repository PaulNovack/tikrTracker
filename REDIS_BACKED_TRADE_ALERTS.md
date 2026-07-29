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
| F | v900.1 | ✅ V900_1Redis | ✅ V900_1Redis | **true** | Enabled 2026-07-26 — gate helpers completed |
| G | v35.0 | ✅ V35_0Redis | ✅ V35_0Redis | true | Prod-ready |
| H | v25.2 | ✅ V25_2Redis | ✅ V25_2Redis | true | Prod-ready (tested) |
| I | v17.0 | ✅ V17_0Redis | ✅ V17_0Redis | true | Prod-ready |
| J | v2000.0 | ✅ V2000_0Redis | ✅ V2000_0Redis | true | Prod-ready |
| K | v1100.0 | ✅ V1100_0Redis | ✅ V1100_0Redis | true | Prod-ready |
| L | v1600.0 | ✅ V1600_0Redis | ✅ V1600_0Redis | true | Prod-ready |
| M | v103.0 | ✅ V103_0Redis | ✅ V103_0Redis | true | Prod-ready |
| N | v1200.0 | ✅ V1200_0Redis | ✅ V1200_0Redis | true | Prod-ready |
| O | v1500.0 | ✅ V1500_0Redis | ✅ V1500_0Redis | true | Prod-ready — enabled 2026-07-26 |
| P | v140.0 | ✅ V140_0Redis | ✅ V140_0Redis | true | Prod-ready |
| Q | v27.0 | ✅ V27_0Redis | ✅ V27_0Redis | true | Prod-ready |

**Not Redis-backed:**
| Pipeline | Version | Reason |
|---|---|---|
| R | rt-v2.0 | Different stack — Realtime Watch (not applicable) |
| S | rt-v1.0 | Different stack — VWAP Reversal (not applicable) |

**Overall: 17/19 pipelines have Redis classes, 17 enabled (R, S excluded).**

## Known Issues

1. **BarEventConsumer version dynamic** ✅ — FIXED. `handle5mBar()` and `handle1mBar()`
   now use `$this->scanner->getVersion()`, not hardcoded `v25.2`. All pipelines work.

2. **ConsumeBarEvents is dynamic** but the `--pipeline` flag must be passed
   explicitly. Defaults to `h`.

3. **Supervisor entries added** ✅ — Both `docker/supervisord.conf` and
   `laravel-invest-worker.conf` now contain bar-events entries for pipelines
   A through Q (O commented out pending testing).
   Pipelines R and S are excluded (different stacks).

4. **Pipeline O (v1500.0)** ✅ — Redis scanner and finder classes created:
   `FiveMinuteSignalScannerV1500_0Redis.php` and
   `OneMinuteEntryFinderV1500_0Redis.php`. Now extends AbstractSignalScanner
   and AbstractOneMinuteEntryFinder respectively. Ready for testing.

5. **Pipeline F gate helpers** ✅ — Standard Redis gate keys added to
   `FiveMinuteSignalScannerV900_1::scanConfig()`. Set
   `TRADING_PIPELINE_F_USE_REDIS=true` to enable.

6. **config/trading.php use_redis** ✅ — Pipeline-letter fallback added via
   `trading.pipelines.{letter}.use_redis` config entries. `shouldUseRedis()`
   checks both version-based and pipeline-letter configs.

7. **Pipelines R and S excluded** — Different architecture stacks:
   - R (rt-v2.0): Realtime watch — separate realtime stack
   - S (rt-v1.0): VWAP reversal — separate realtime stack

8. **Predis XREADGROUP compatibility** — Fixed by using `Redis::command('XREADGROUP', [...])`
   with flat argument arrays compatible with Predis. Both `ensureGroup()` and
   `run()` updated. Supervisor workers require Docker environment (Redis at
   hostname `redis`) or local Redis at `127.0.0.1:6379`.

9. ⚠️ **Redis gate keys missing from 7 scanners (fixed 2026-07-27)** — V17.0, V60.3,
   V90.1, V120.0, V1100.0, V1200.0, V2000.0 had `scanConfig()` without `min_notional_5m`,
   `min_atr_pct_5m`, `min_rvol_5m`, `min_move_30m_pct`. This caused default $75K notional /
   0.35% ATR / 2.0x RVOL / 1.2% move gates to apply, blocking most signals. Fixed by
   adding all 4 keys = 0 (disabled) to match SQL behavior.

10. ⚠️ **`alert_id: false` — alerts silently rejected** — All pipelines default to
    `run_cron = false` when no key exists in Redis. Must explicitly set:
    `TradingSettingService::set('trading.pipeline_X.run_cron', true)` for each pipeline.

11. ⚠️ **Timezone epoch bugs in RedisBarRepository** (fixed 2026-07-27) — `getBars()`
    and `getLatestBars()` used server-timezone-dependent `date()` for Redis key YYYYMMDD,
    causing key mismatches against Python's EST-based keys. Fixed with direct string
    extraction from EST input strings. `isFresh()` used hardcoded `-0500` (no DST).
    Fixed to use `America/New_York`.

12. ⚠️ **PHP 8.x typed property crashes** (fixed 2026-07-27) — 32+ entry finders accessed
    `$this->version` before initialization. Replaced with `$this->getVersion()`.

13. ⚠️ **SQL syntax errors** (fixed 2026-07-27) — 30+ entry finders had `FROM table AND`
    instead of `FROM table WHERE`. Fixed.

14. ⚠️ **Variable name mismatches** (fixed 2026-07-27) — 5 files used `$tsEst` instead of
    `$time` in `isAllowedTime()`. Fixed.

15. ⚠️ **Stale alerts when consumers fall behind** (fixed 2026-07-28) — `BarEventConsumer`
    had no staleness gate. When Redis stream consumers fell behind (backlog), they
    processed bars from hours ago and created alerts with stale data. The
    `TradeAlertWriterV1::upsertAlert()` freshness gate was skipped for `isRealtime=true`,
    assuming realtime consumers always process fresh bars — an invalid assumption when
    the consumer is catching up.

    **Root cause:** Two separate time horizons race against each other:

    1. A `5m_bar` event stores a candidate in Redis with a 600s (10 min) TTL.
    2. A `1m_bar` event arriving later checks for an existing candidate.
    3. The 1m_bar event itself may be only 9 minutes old (just under the event-age gate),
       but the **candidate** from the 5m_bar could be 30+ minutes old.
    4. The candidate was still valid (600s TTL) and the event passed the age gate,
       so the entry finder fired on the stale signal.

    **Fix (2026-07-28):**
    - `BarEventConsumer::handleMessage()` — discards events with `epoch` > 10 minutes
      old before any scanning/entry-finding. Prevents old bars from even being scanned.
    - `BarEventConsumer::handle1mBar()` — checks candidate `signal_epoch` against now.
      Discards candidates older than 10 minutes AND deletes the Redis key. Prevents
      the 5m_bar/1m_bar race where a "fresh" 1m event picks up a very stale candidate.
    - Candidate TTL reduced from 600s → 120s to match the 10-min freshness window
      (no point keeping candidates alive longer than they can be used).
    - `signal_epoch` stored in candidate JSON alongside `signal_ts_est` for fast
      integer-based age comparison.

16. ⚠️ **Bar-events consumers under-provisioned** (fixed 2026-07-28) — 17 pipeline
    consumer groups sharing one stream, each with only 1 consumer. During high-volume
    periods, a single consumer per pipeline couldn't keep up, causing the stream to
    back up. Even with the 10-minute discard, events piled up faster than they could
    be discarded.

    **Fix:** `numprocs` increased from 1 → 3 for all `laravel-invest-bar-events-*`
    programs in both `laravel-invest-worker.conf` and `docker/supervisord.conf`.
    Consumer names changed from `{letter}-01` to `{letter}-%(process_num)02d` so
    multiple consumers share each consumer group. Affects all 17 Redis-backed
    pipelines (A-Q).

## What's Left

| Item | Action | Status |
|---|---|---|
| Pipeline O Redis classes | V1500_0Redis scanner + finder created, ORB scanSymbol override | ✅ Done 2026-07-26 |
| BarEventConsumer version | Already derives version from `$this->scanner->getVersion()` | ✅ N/A |
| Supervisor entries | bar-events-a through bar-events-q added to both configs | ✅ Done 2026-07-26 |
| Pipeline F gate helpers | Standard Redis gate keys added to V900_1::scanConfig() | ✅ Done 2026-07-26 |
| config/trading.php use_redis | Pipeline-letter fallback in shouldUseRedis() + use_redis in all pipelines[] | ✅ Done 2026-07-26 |
| Pipeline O enablement | TRADING_PIPELINE_O_USE_REDIS=true, supervisor bar-events-o uncommented in both configs and deployed to /etc/supervisor/conf.d/, running via supervisor | ✅ Done 2026-07-26 |

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

**Currently deployed:** All 17 pipelines (A-Q) running via supervisor.

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

## Gate Comparison Audit (revised 2026-07-27)

**Original audit (2026-07-26) was incorrect — 7 of 17 scanners were missing Redis gate keys in `scanConfig()`, causing `UsesRedisForScanning::scanSymbol()` to apply overly strict default gates ($75K notional, 0.35% ATR, 2.0x RVOL, 1.2% move) that don't match SQL behavior.**

### Gate Discrepancies Found and Fixed (2026-07-27)

| Pipeline | Issue | Fix |
|---|---|---|
| A (v90.1) | scanConfig() empty for gate keys | Added all 4 gate keys = 0 (disabled) |
| B (v120.0) | scanConfig() empty for gate keys | Added all 4 gate keys = 0 (disabled) |
| D (v60.3) | scanConfig() empty for gate keys | Added all 4 gate keys = 0 (disabled) |
| I (v17.0) | scanConfig() missing gate keys | Added all 4 gate keys = 0 (SQL uses move%/vol ratio, not Redis gates) |
| J (v2000.0) | scanConfig() empty for gate keys | Added all 4 gate keys = 0 (disabled) |
| K (v1100.0) | scanConfig() empty for gate keys | Added all 4 gate keys = 0 (disabled) |
| N (v1200.0) | scanConfig() empty for gate keys | Added all 4 gate keys = 0 (disabled) |
| O (v1500.0) | scanConfig() empty for gate keys | Added all 4 gate keys = 0 (disabled, ORB-specific logic) |

### How gate keys work in Redis path

`UsesRedisForScanning::scanSymbol()` reads from `scanConfig()` with defaults:

```php
$minNotional5m = (float) ($cfg['min_notional_5m'] ?? 75000);      // default: $75K
$minAtrPct5m = (float) ($cfg['min_atr_pct_5m'] ?? 0.35);          // default: 0.35%
$minRvol5m = (float) ($cfg['min_rvol_5m'] ?? 2.0);                // default: 2.0x
$minMove30m = (float) ($cfg['min_move_30m_pct'] ?? 1.2);          // default: 1.2%
```

When `scanConfig()` doesn't include these keys, the **strict defaults** apply.
Setting them to `0` disables the Redis-side gate, letting the scanner's own
`scanSymbol()` or parent `doScan()` logic handle filtering — matching SQL behavior.

### Other issues fixed (2026-07-27)

| Issue | Files Affected | Impact | Status |
|---|---|---|---|
| `$this->version` uninitialized | 32+ entry finders | PHP typed property crash | ✅ Fixed → `$this->getVersion()` |
| SQL missing `WHERE` | 30+ entry finders | MySQL syntax error | ✅ Fixed |
| `$tsEst` → `$time` mismatch | 5 files (V35, V45, V101, V1600, V3000) | Undefined variable crash | ✅ Fixed |
| `scanSymbol()` EST/UTC bug | `UsesRedisForScanning.php` | Signal stale (age:600 vs max:360) | ✅ Fixed → `America/New_York` |
| `doScan()` bare `strtotime()` | `UsesRedisForScanning.php` | Server timezone-dependent | ✅ Fixed |
| Log search OOM on 20GB file | `LogViewerController.php` | Page broken | ✅ Fixed → streaming reads |
| `alert_id: false` — writer rejection | `TradeAlertWriterV1` | `run_cron` disabled by default | ✅ Fixed → explicit logging added |

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
