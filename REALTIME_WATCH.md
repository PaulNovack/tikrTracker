# `php artisan trading:realtime-watch` — Complete Reference

Last updated: 2026-06-25

---

## Overview

`trading:realtime-watch` is a long-running PHP Artisan command that continuously scans ~3,828 symbols for real-time intraday momentum entry signals. It replaces the old 5-minute batch pipeline scanners with sub-second detection of 1-minute bar breakouts.

**Key metrics:**
- 3,828 symbols watched (all `asset_info` rows with `1_min = 1`)
- 500ms loop interval (configurable via `TRADING_REALTIME_LOOP_SLEEP_MS`)
- 1 bulk MySQL query per loop (quotes) + lazy Redis reads (bars)
- ~500-600ms per loop in current configuration

**What it does:** Continuously monitors live quotes and 1-minute bars from the Alpaca streaming feed, scores every symbol for early momentum signals, creates "watching" candidates, then evaluates those candidates on subsequent loops for entry triggers. When an entry fires, it creates a `TradeAlert` and dispatches ML scoring through Pipeline H.

---

## Architecture — Data Flow

```
┌─────────────────────────────────────────────────────────────────────┐
│                      Alpaca WebSocket (SIP)                         │
│  stream_bars.py receives bars + quotes continuously                │
└──────┬──────────────────────────────────┬──────────────────────────┘
       │                                  │
       ▼                                  ▼
┌──────────────┐                 ┌──────────────────┐
│  MySQL       │                 │  Redis           │
│  one_minute_ │                 │  stream:bar:SYM  │ ← bar_buffer.py
│  prices      │                 │  (OHLCV hash)    │    writes here
│              │                 │                  │
│  latest_     │                 │  stream:quote:   │ ← stream_bars.py
│  stock_      │                 │  SYM (hash)      │    writes here
│  quotes      │                 │                  │
└──────┬───────┘                 └────────┬─────────┘
       │                                  │
       │    ┌─────────────────────────────┘
       │    │
       ▼    ▼
┌──────────────────────────────────────────────────────────────────┐
│              RealtimeMarketDataService                           │
│                                                                  │
│  warmUpBars()    → 1 bulk SQL: `latest_stock_quotes`             │
│                    WHERE symbol IN (3828)                        │
│                                                                  │
│  latestQuote()   → reads from in-memory $quoteCache              │
│                                                                  │
│  latestPartial   → Redis HGETALL stream:bar:SYM (first)         │
│  OneMinuteBar()    MySQL ORDER BY ts_est DESC (fallback)        │
│                                                                  │
│  recentOneMinute → MySQL per-symbol (lazy), cached in-memory    │
│  Bars()            per loop after first hit                      │
└──────────────────────────────────────────────────────────────────┘
```

---

## Layer 1 — Data Ingestion (`stream_bars.py` + `bar_buffer.py`)

### Quotes
`stream_bars.py` subscribes to Alpaca's SIP quote feed. Every quote is:
1. Buffered in `QuoteBufferService` (deduplicated by symbol)
2. Flushed to `latest_stock_quotes` via `REPLACE INTO` every 250 quotes or 0.5s
3. Written to Redis `{prefix}stream:quote:{SYMBOL}` as a hash (TTL: 1 hour)

### Bars
`stream_bars.py` subscribes to Alpaca's 1-minute bar feed. Every bar is:
1. Normalized by `BarBufferService._normalise_bar()`
2. Buffered until 50 bars or 10 seconds elapse
3. Upserted to `one_minute_prices` via `ON DUPLICATE KEY UPDATE`
4. **After MySQL commit** → written to Redis `{prefix}stream:bar:{SYMBOL}` (TTL: 1 hour)
   - Hash fields: `ts`, `price` (close), `open`, `high`, `low`, `volume`
   - Plus computed indicators: `vwap`, `vwap_dist_pct`, `above_vwap`, `ema9`, `ema21`, `ema9_above_ema21`, `atr_pct`, `rvol`, `avg_vol_20`, `move_30m_pct`
   - This is the critical optimization — PHP reads bars from Redis in <1ms

---

## Layer 2 — PHP Command (`TradingRealtimeWatchCommand`)

### Loop Flow (every 500ms while market open)

```
1. symbolsToWatch()
   → SELECT symbol FROM asset_info WHERE 1_min = 1
   → 3,828 symbols (capped at TRADING_WATCH_SYMBOLS_LIMIT=5000)

2. RealtimeMarketDataService::clearCache()
   → Resets $quoteCache, $barCache, $recentBarCache arrays

3. RealtimeMarketDataService::warmUpBars($symbols)
   → 1 bulk MySQL query:
     SELECT * FROM latest_stock_quotes WHERE symbol IN (3828)
   → Populates $quoteCache[SYM] = {bid, ask, ts_est, ...}
   → Bars are NOT bulk-loaded — read lazily from Redis per symbol

4. Per-symbol scan (3,828 iterations)
   → EarlyCandidateDetectorService::detectForSymbol(symbol)
   → See Layer 3 below

5. Entry watcher
   → RealtimeEntryWatcherService::watch()
   → See Layer 4 below

6. sleep(500ms) → goto 1
```

### Inline candidate evaluation (NEW)
After a candidate is detected in step 4, the watcher immediately calls `evaluateCandidate()` to check for entry signals. This means a hot symbol can trigger within the same loop — no waiting for the next loop iteration. Both inline and batch evaluation use the same Redis lock (`rt_candidate_watch:{ID}`) to prevent double-triggering.

---

## Layer 3 — Candidate Detection (`EarlyCandidateDetectorService`)

For each symbol, **15 gates** run in order. The first failure logs a skip reason and moves to the next symbol:

| # | Gate | Config Key | Default | Skip Reason |
|---|---|---|---|---|
| 1 | Quote exists | — | — | `no_quote_or_partial` |
| 2 | Bar exists | — | — | `no_quote_or_partial` |
| 3 | Quote age ≤ 60s | `max_candidate_quote_age_seconds` | 60s | `quote_stale` |
| 4 | Bid/ask valid | — | — | `bad_bid_ask` |
| 5 | Spread ≤ 0.35% | `max_spread_pct` | 0.35% | `wide_spread` |
| 6 | Bar OHLCV valid | — | — | `invalid_partial_bar` |
| 7 | Price ≥ $5.00 | `min_price` | $5.00 | `price_too_low` |
| 8 | Dollar volume ≥ $50K/min | `min_dollar_volume_1m` | $50,000 | `low_dollar_volume` |
| 9 | ATR% ≥ 0.25% | `min_atr_pct` | 0.25% | `low_atr_pct` |
| 10 | RVOL ≥ 1.5× | `min_rvol` | 1.5 | `low_rvol` |
| 11 | 30m move ≥ 0.5% | `min_move_30m_pct` | 0.5% | `low_move_30m` |
| 12 | VWAP extension ≤ 2.0% | `max_vwap_extension_pct` | 2.0% | `too_far_above_vwap` |
| 13 | **Early score ≥ 55** | `early_score_min` | 55 | `score_too_low` |
| 14 | No existing watching candidate | — | — | (skips silently) |

### Pipeline H/K/A Quality Gates (NEW)

Gates 9-12 are new Pipeline H/K/A quality filters that use indicators computed by `stream_bars.py` and cached in Redis:

- **ATR% (min 0.25%)**: Ensures enough volatility for price to move. ATR is computed over 14 periods by the Python stream.
- **RVOL (min 1.5×)**: Relative volume — current bar's volume vs 20-period average. Ensures real buying/selling interest, not just noise.
- **30m Move (min 0.5%)**: Net price change over the last 30 minutes. Ensures sustained directional momentum, not just a 1-minute blip.
- **VWAP Distance (≤ 2.0%)**: Prevents chasing stocks that are too extended above VWAP.

### Early Score Formula (0–100) — Pipeline H/K/A Scoring

The scoring was redesigned on 2026-06-25 to use the same quality metrics as Pipelines H, K, and A:

```
Max 100 pts:

+ 30 × min((rvol - 1.0) / 4.0, 1)      RVOL: scales 1×→5×, 30 pts
+ 25 × min((move30m - 0.5) / 2.5, 1)    30m momentum: scales 0.5%→3%, 25 pts
+ 20 × min((atr - 0.25) / 1.25, 1)      ATR%: scales 0.25%→1.5%, 20 pts
+ 8  × above_vwap                        Above VWAP: 8 pts
+ 7  × ema9_above_ema21                  EMA trend: 7 pts
+ 10 × min(imbalance / 0.50, 1)          Buy-side imbalance: 10 pts
− 15 × min(spread / max_spread, 1)       Spread penalty: up to -15
− 10 × min((vwap_dist - 1.0) / 1.5, 1)   VWAP over-extension penalty (>1% above VWAP)
```

The scoring heavily weights RVOL (30 pts) and 30-minute momentum (25 pts). ATR% volatility gets 20 pts, and trend confirmation (above VWAP + EMA alignment) gets 15 pts. Spread and VWAP over-extension serve as penalties, not rewards.

### Candidate Creation

If a symbol passes all gates AND doesn't already have a `watching` candidate in the DB, a new `realtime_trade_candidates` row is inserted with:
- `status = 'watching'`
- `detected_price = ask`
- Full OHLCV + spread + score + computed indicators snapshot
- New fields: `atr_pct`, `rvol`, `move_30m_pct`, `ema9_above_ema21`

---

## Layer 4 — Entry Watcher (`RealtimeEntryWatcherService`)

Runs AFTER the full symbol scan (and inline during scan for fresh candidates). Loads all `status='watching'` candidates.

### For each watching candidate:

**Step 1 — TTL check**
```
age = now() - detected_ts_est
if age > candidate_ttl_seconds (300s) → mark 'expired'
```

**Step 2 — Acquire lock**
```
Cache::lock('rt_candidate_watch:{id}', 5s)
Prevents double-trigger across overlapping loops
```

**Step 3 — Move-since-candidate gate**
```
move_pct = (ask - detected_price) / detected_price × 100
if move_pct > 0.75% → mark 'rejected' (moved_too_far_since_candidate)
```

**Step 4 — Entry trigger (`RealtimeEntryTriggerService`)**

Two paths:

**Path A — Custom entry finder** (if `TRADING_REALTIME_ENTRY_FINDER_CLASS` is set)
- Currently commented out in `.env`
- When active: calls `OneMinuteEntryFinderV2000_1::findBestLong()`
- Detects specific patterns: VWAP_RECLAIM, BULL_FLAG_BREAKOUT, HIGHER_LOW_BREAK, etc.
- Places proper stops based on pattern type

**Path B — Default trigger** (currently active)
Gates in order:

| Gate | Config Key | Default |
|---|---|---|
| Quote exists & valid | — | — |
| Quote age ≤ 5s | `max_quote_age_seconds` | 5s |
| Spread ≤ 0.35% | `max_spread_pct` | 0.35% |
| Move since candidate ≤ 0.75% | `max_move_since_candidate_pct` | 0.75% |
| Move since candidate ≥ -0.10% | `entry_above_candidate_min_pct` | -0.10% |
| 1m return ≥ 0.20% | `entry_return_1m_min_pct` | 0.20% |
| Volume ratio ≥ 1.50× | `entry_volume_ratio_min` | 1.50 |
| Price below VWAP → reject | — | — |
| VWAP extension ≤ 1.25% | `max_vwap_extension_pct` | 1.25% |

If all gates pass: returns an entry with `score = early_score + 5`,
`entry_price = ask`, `reason = 'default_realtime_momentum_trigger'`.

**Step 5 — Move-since-entry gate**
```
move_pct = (ask - entry_price) / entry_price × 100
if move_pct > 0.35% → mark 'rejected' (moved_too_far_since_entry)
```

**Step 6 — Create TradeAlert**
`RealtimeTradeAlertFactoryService::createFromCandidate()` inserts a `trade_alerts` row with all candidate + entry data (see Layer 6).

**Step 7 — Dispatch ML scoring**
`RealtimeIntegrationDispatcherService::dispatchAfterAlertCreated()` queues `ScoreTradeAlertWithMl::dispatch(alertId, 'trade_alerts', 'H')`.

**Gate fail logging**: Every 10 loops (~5 seconds), a summary of trigger gate failures is logged with counts per reason.

---

## Layer 5 — Caching Strategy

### Three-tier cache hierarchy:

**Tier 1 — PHP in-memory arrays (per-loop, fastest)**
- `$quoteCache[SYM]` — populated by `warmUpBars()` bulk query
- `$barCache[SYM]` — lazy-filled from Redis/MySQL on first access
- `$recentBarCache[SYM]` — lazy-filled from MySQL (last 5 bars, most recent first)

**Tier 2 — Redis (cross-process, sub-ms)**
- `{prefix}stream:bar:{SYM}` — written by `bar_buffer.py` on every bar flush. Contains OHLCV + computed indicators (vwap, ema9, ema21, atr_pct, rvol, move_30m_pct, etc.)
- `{prefix}stream:quote:{SYM}` — written by `stream_bars.py` (not read by PHP yet)
- `market:quote:{SYM}` — legacy write-through cache (15s TTL)
- `rt_candidate_watch:{ID}` — per-candidate lock (5s)

**Tier 3 — MySQL (source of truth, ms-scale)**
- `latest_stock_quotes` — PK on symbol, writes via `REPLACE INTO`
- `one_minute_prices` — 14.6M rows, indexed on `(symbol, ts_est)`

---

## Layer 6 — TradeAlert Creation (`RealtimeTradeAlertFactoryService`)

When an entry triggers, the factory creates a `trade_alerts` row with:

### Core fields:
| Field | Value |
|---|---|
| `realtime_candidate_id` | FK to realtime_trade_candidates |
| `symbol`, `asset_type` | From candidate |
| `signal_type` | `REALTIME_MOMENTUM` |
| `entry_type` | `RealTime` |
| `version` | `rt-v1.0` |
| `pipeline_run` | `H` (from `TRADING_REALTIME_PIPELINE_RUN`) |
| `dedupe_key` | `rt:{SYMBOL}:{YYYYMMDDHHII}` (minute-level dedup) |

### Entry pricing:
| Field | Value |
|---|---|
| `entry_price` | Current ask |
| `entry` | Same as entry_price |
| `price` | Same as entry_price |
| `stop` | entry × 0.99 (1% stop) |
| `score` | early_score + 5 |

### Trade sizing:
| Field | Value |
|---|---|
| `risk_pct` | 1.0% |
| `risk_per_share` | entry × 0.01 |
| `time_of_day` | `H:i:s` EST |
| `avg_dollar_volume_per_minute` | Daily avg from `one_minute_prices` (falls back to single-bar) |
| `calculated_position_size` | Dynamic: `min(25K, max(500, daily_avg × 10%))` or fixed 5000 |

### ATR/Stop metrics (real ATR — same formula as pipelines):
| Field | Value |
|---|---|
| `atr` | `entry × (candidate.atr_pct / 100)` — real ATR from bar_buffer.py |
| `atr_pct` | From candidate's `atr_pct` field (computed by Python stream) |
| `suggested_trailing_stop` | `entry × (clamp(ATR% × multiplier, minPct, maxPct) / 100)` |
| `suggested_trailing_stop_pct` | `clamp(ATR% × multiplier, minPct, maxPct)` |
| `risk_pct` | Same as `suggested_trailing_stop_pct` |
| `risk_per_share` | Same as `suggested_trailing_stop` (in dollars) |
| `stop` | `entry - suggested_trailing_stop` |

**ATR multiplier** comes from `AUTO_ALPACA_STOP_LOSS_ATR_MULTIPLIER` (default 3.0).
**Bounds** from `AUTO_ALPACA_STOP_LOSS_ATR_MIN_PCT` (default 0.75%) and `_MAX_PCT` (default 2.0%).
Falls back to 1.0% if candidate has no real ATR data.

This matches the exact same formula used by pipeline entry finders (`OneMinuteEntryFinderV17_0`, `V400_0`, etc.).

### Entry quality context:
| Field | Source |
|---|---|
| `hod` | entry price |
| `above_vwap_entry_pct` | candidate `vwap_dist_pct` |
| `entry_volume_ratio` | candidate `volume_ratio` |
| `entry_notional_1m` | candidate `dollar_volume_1m` |
| `entry_spread_strength` | `1 - (spread_pct / 0.5)` clamped |

### Live quote snapshot:
| Field | Source |
|---|---|
| `current_bid` | Live bid |
| `current_ask` | Live ask |
| `current_bid_qty` | Live bid size |
| `current_ask_qty` | Live ask size |
| `current_spread_pct` | Live spread |
| `move_since_candidate_pct` | % move from detected_price to ask |
| `move_since_entry_pct` | % move from entry_price to ask |

### Meta (JSON, auto-encoded by model cast):
Contains full source trace: `source`, `candidate_id`, `early_score`, `candidate_detected_price`, `candidate_return_1m_pct`, `candidate_return_3m_pct`, `candidate_volume_ratio`, `candidate_vwap_dist_pct`, `entry_finder`, `entry_reason`, `raw_entry`.

**Deduplication**: Uses `firstOrCreate` with `dedupe_key` to handle the rare race condition where inline `evaluateCandidate()` and batch `watch()` both attempt to insert the same candidate.

---

## Layer 7 — Last-Mile Validation (`FreshTradeGateService`)

Called by the Alpaca order listener before submitting any order. **Not part of the watcher loop itself** — this is the final safety check between alert creation and order execution.

Gates:
| # | Check | Config Key | Default |
|---|---|---|---|
| 1 | Entry timestamp exists | — | — |
| 2 | Entry age ≤ 60s | `max_entry_age_seconds` | 60s |
| 3 | Quote exists | — | — |
| 4 | Quote age ≤ 5s | `max_quote_age_seconds` | 5s |
| 5 | Bid/ask valid | — | — |
| 6 | Spread ≤ 0.35% | `max_spread_pct` | 0.35% |
| 7 | Entry price > 0 | — | — |
| 8 | Move since entry ≤ 0.35% | `max_move_since_entry_pct` | 0.35% |

On pass: updates the alert with live quote snapshot data. On fail: writes rejection reason to alert meta.

---

## Layer 8 — ML Scoring Dispatch (`RealtimeIntegrationDispatcherService`)

After a TradeAlert is created:
1. Checks `TRADING_REALTIME_SCORE_JOB_CLASS` (`ScoreTradeAlertWithMl`)
2. Dispatches `ScoreTradeAlertWithMl::dispatch(alertId, 'trade_alerts', 'H')`
3. Pipeline H resolves to `winner_model_pipeline_hid.joblib` (AUC 0.6939)
4. Calls Python scoring daemon via Unix socket (`storage/ml-scoring.sock`, ~10ms)
5. Re-evaluates `passed_ml` using `AUTO_ALPACA_ML_THRESHOLD_PIPELINE_H=0.65`

---

## Performance

| Phase | Before (2026-06-24) | After (2026-06-25) |
|---|---|---|
| warmUpBars (quotes) | ~2 queries | 1 query |
| warmUpBars (bars) | 14.6M-row correlated subquery (67s) | **Removed** — Redis instead |
| Per-symbol scan | ~20ms avg | <1ms avg |
| Total loop time | **67,000ms - 104,000ms** | **500-600ms** |
| Redis bar reads | 0 | Lazy (only symbols that pass early gates) |
| MySQL bar queries per loop | 1 massive scan | 0 (only fallback per-symbol if Redis misses) |
| Candidate scoring | Momentum + volume only | Pipeline H/K/A quality metrics (RVOL, 30m move, ATR%, EMAs) |
| Inline trigger | Not supported | Immediate evaluation via `evaluateCandidate()` |

---

## Configuration

All realtime settings in `config/trading_realtime.php` with `.env` overrides:

### Core settings:
| .env Key | Default | Purpose |
|---|---|---|
| `TRADING_REALTIME_ENABLED` | true | Master toggle |
| `TRADING_REALTIME_LOOP_SLEEP_MS` | 1000 | Loop interval (currently 500) |
| `TRADING_WATCH_SYMBOLS_LIMIT` | 500 | Max symbols (currently 5000) |
| `TRADING_CANDIDATE_TTL_SECONDS` | 180 | Candidate lifetime (currently 300) |

### Quote freshness:
| .env Key | Default | Purpose |
|---|---|---|
| `TRADING_MAX_QUOTE_AGE_SECONDS` | 5 | Max quote staleness for entry |
| `TRADING_MAX_CANDIDATE_QUOTE_AGE_SECONDS` | 60 | Looser limit for candidate detection |
| `TRADING_QUOTE_CACHE_TTL_SECONDS` | 15 | Redis quote cache TTL |
| `TRADING_PARTIAL_BAR_CACHE_TTL_SECONDS` | 90 | Redis bar cache TTL |

### Candidate detection gates:
| .env Key | Default | Purpose |
|---|---|---|
| `TRADING_EARLY_SCORE_MIN` | 55 | Minimum early score |
| `TRADING_REALTIME_MIN_PRICE` | 5.0 | Minimum stock price |
| `TRADING_MIN_DOLLAR_VOLUME_1M` | 50000 | Minimum $-volume/min |
| `TRADING_MAX_SPREAD_PCT` | 0.35 | Max bid-ask spread |
| `TRADING_MAX_VWAP_EXTENSION_PCT` | 2.0 | Max VWAP distance |

### Pipeline H/K/A quality gates:
| .env Key | Default | Purpose |
|---|---|---|
| `TRADING_REALTIME_MIN_ATR_PCT` | 0.25 | Min ATR% (volatility) |
| `TRADING_REALTIME_MIN_RVOL` | 1.5 | Min relative volume |
| `TRADING_REALTIME_MIN_MOVE_30M_PCT` | 0.5 | Min 30-minute momentum |

### Entry freshness / no-chase:
| .env Key | Default | Purpose |
|---|---|---|
| `TRADING_MAX_ENTRY_AGE_SECONDS` | 60 | Max entry age for orders |
| `TRADING_MAX_MOVE_SINCE_ENTRY_PCT` | 0.35 | No-chase (entry) |
| `TRADING_MAX_MOVE_SINCE_CANDIDATE_PCT` | 0.75 | No-chase (candidate) |

### Entry trigger thresholds:
| .env Key | Default | Purpose |
|---|---|---|
| `TRADING_ENTRY_VOLUME_RATIO_MIN` | 1.50 | Entry volume multiplier |
| `TRADING_ENTRY_RETURN_1M_MIN_PCT` | 0.10 | Entry 1m return minimum (currently 0.20) |
| `TRADING_ENTRY_ABOVE_CANDIDATE_MIN_PCT` | -0.10 | Entry must be above candidate |

### Pipeline & integration:
| .env Key | Default | Purpose |
|---|---|---|
| `TRADING_REALTIME_PIPELINE_RUN` | A | Pipeline for ML scoring (currently H) |
| `TRADING_REALTIME_ENTRY_FINDER_CLASS` | null | Custom entry finder (commented out) |
| `TRADING_REALTIME_SCORE_JOB_CLASS` | null | ML scoring job class |
| `TRADING_REALTIME_CREATED_EVENT_CLASS` | null | Event to fire on alert creation |

### Pipeline H-specific (from `.env`):
| Setting | Value |
|---|---|
| ML model | `winner_model_pipeline_hid.joblib` (AUC 0.6939) |
| ML threshold | 0.65 |
| Scorer script | `python_ml/v2/score_single_alert_v2.py` |

---

## Log Interpretation

```
[Loop 1] Watching 3828 symbols...           ← symbolsToWatch() result
[Loop 1] Done in 500ms — 0 candidates       ← scan complete, duration + count
[Loop 1] Top skip reasons: quote_stale=2665 ← 2,665 symbols had old quotes
           no_quote_or_partial=1163          ← 1,163 had no quote/bar data
[Loop 1] Running entry watcher...           ← checking watching candidates
```

### Key log patterns:

**Candidate detection:**
```
[CandidateDetect] Score too low (sample)     ← Sampled every 50 score_too_low skips
Realtime candidate created                   ← New watching candidate
```

**Inline evaluation (NEW):**
```
[EntryWatcher] Candidate triggered inline   ← Entry fired during scan loop
[EntryWatcher] Candidate gate fail (inline) ← Gate failure during inline check
```

**Entry triggers:**
```
[EntryTrigger] Gate fail: {reason}          ← Which gate blocked entry
[EntryTrigger] ✅ ALL GATES PASSED          ← Entry signal generated
[EntryTrigger] ✅ TRADE ALERT CREATED       ← TradeAlert inserted into DB
```

**Gate fail summaries (every ~5 seconds):**
```
[EntryWatcher] Trigger gate fail summary (last ~5s)
  watching_now=5 triggered=1 expired=2 total_gate_fails=12
  top_reasons=find_entry_null=8 no_quote=3 moved_too_far=1
```

When the market is open and `stream_bars.py` is running:
- `quote_stale` should be near 0
- `candidates_found` should be > 0
- `[EntryTrigger] ✅ TRADE ALERT CREATED` will appear in INFO logs

---

## Dependencies

1. **Redis** — must be running. Stores bar/indicator cache and candidate locks.
2. **MySQL** — stores quotes and bars. `latest_stock_quotes` and `one_minute_prices` tables must exist.
3. **`stream_bars.py`** — must be running during market hours. Feeds quotes + bars + computed indicators to both MySQL and Redis.
4. **`bar_buffer.py`** — computes RVOL, ATR%, 30m move, EMAs, VWAP and writes to Redis for sub-ms PHP reads.
5. **`scoring_daemon.py`** — Unix socket at `storage/ml-scoring.sock`. ML scoring for triggered alerts.
6. **Laravel Queue** — processes `ScoreTradeAlertWithMl` jobs asynchronously.

---

## Quick Debugging

```bash
# Check loop performance
tail -f storage/logs/laravel.log | grep '\[RealtimeWatch\]'

# Check candidate detection
tail -f storage/logs/laravel.log | grep '\[CandidateDetect\]'

# Check entry triggers
tail -f storage/logs/laravel.log | grep '\[EntryTrigger\]'

# Check entry watcher (inline + batch)
tail -f storage/logs/laravel.log | grep '\[EntryWatcher\]'

# Verify Redis bar cache is populated (with computed indicators)
redis-cli KEYS "tikrtracker-database-stream:bar:*" | head -10
redis-cli HGETALL "tikrtracker-database-stream:bar:AAPL"
# Check specific indicators:
redis-cli HGET "tikrtracker-database-stream:bar:AAPL" rvol
redis-cli HGET "tikrtracker-database-stream:bar:AAPL" atr_pct
redis-cli HGET "tikrtracker-database-stream:bar:AAPL" move_30m_pct

# Check quote freshness
mysql -e "SELECT symbol, quote_ts_utc, TIMESTAMPDIFF(SECOND, quote_ts_utc, NOW()) AS age_sec FROM latest_stock_quotes ORDER BY age_sec ASC LIMIT 10;"

# Run single loop (for testing)
php artisan trading:realtime-watch --once
```

---

## Key Files

| File | Purpose |
|---|---|
| `app/Console/Commands/TradingRealtimeWatchCommand.php` | **Entry point** — loop orchestration, config setup, progress logging |
| `app/Services/Trading/Realtime/RealtimeMarketDataService.php` | **Data layer** — Quote + bar caching (Redis/MySQL), 3-tier cache |
| `app/Services/Trading/Realtime/EarlyCandidateDetectorService.php` | **Candidate detection** — 15-gate scanner, Pipeline H/K/A quality scoring |
| `app/Services/Trading/Realtime/RealtimeEntryWatcherService.php` | **Entry watcher** — TTL, locks, inline + batch trigger evaluation, gate fail summaries |
| `app/Services/Trading/Realtime/RealtimeEntryTriggerService.php` | **Entry triggers** — Default momentum gates + custom OneMinuteEntryFinder path |
| `app/Services/Trading/Realtime/RealtimeTradeAlertFactoryService.php` | **Alert factory** — Creates trade_alerts with full sizing/snapshot/quality data |
| `app/Services/Trading/Realtime/FreshTradeGateService.php` | **Last-mile validation** — Final quote/age/spread checks before Alpaca order placement |
| `app/Services/Trading/Realtime/RealtimeIntegrationDispatcherService.php` | **ML dispatch** — Queues ScoreTradeAlertWithMl after alert creation |
| `config/trading_realtime.php` | All config keys + defaults + .env mappings |
| `alpaca_python_api/stream_bars.py` | Alpaca WebSocket → MySQL + Redis (quotes + bar signals) |
| `alpaca_python_api/bar_buffer.py` | Bar buffering + MySQL upsert + Redis cache with computed indicators |
| `app/Jobs/ScoreTradeAlertWithMl.php` | ML scoring job (Pipeline H → hid model) |
| `app/Models/RealtimeTradeCandidate.php` | Eloquent model for `realtime_trade_candidates` table |
| `app/Models/TradeAlert.php` | Eloquent model for `trade_alerts` table |

