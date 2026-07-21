# Backfill Guide — `one_minute_prices` & `five_minute_prices`

---

## Quick Reference

| Command | Table(s) | Source | Scope |
|---------|----------|--------|-------|
| `alpaca:backfill-range` | one or both | Alpaca REST API | Date range (historical) |
| `alpaca:backfill-day` | one | Alpaca REST API | Single day |
| `alpaca:sync-1m` | `one_minute_prices` | Alpaca REST API | Last N minutes (live) |
| `alpaca:sync-5m` | `five_minute_prices` | Alpaca REST API | Last N hours (live) |

---

## Primary Command: `alpaca:backfill-range`

Fills `one_minute_prices`, `five_minute_prices`, or both from the Alpaca API
using the same data format as `stream_bars.py`.

### Usage

```bash
php artisan alpaca:backfill-range <start-date> <end-date> <timeframe> [options]
```

### Arguments

| Argument | Description |
|----------|-------------|
| `start-date` | Start date in `YYYY-MM-DD` (EST timezone) |
| `end-date` | End date in `YYYY-MM-DD` (EST timezone) |
| `timeframe` | `1m` = `one_minute_prices`, `5m` = `five_minute_prices`, `both` = both tables |

### Options

| Option | Default | Description |
|--------|---------|-------------|
| `--chunk=` | `200` | Symbols per Alpaca API request chunk |
| `--feed=` | `sip` | `iex` or `sip` (use `sip` for historical) |
| `--symbols=` | all `1_min=1` | Comma-separated list (e.g. `AAPL,TSLA,MSFT`) |
| `--skip-existing` | off | Skip dates that already have rows in the target table |

### Examples

```bash
# Backfill 1-minute data for July 1–10, 2026
php artisan alpaca:backfill-range "2026-07-01" "2026-07-10" 1m

# Backfill 5-minute data for specific symbols only
php artisan alpaca:backfill-range "2026-06-01" "2026-06-30" 5m --symbols=AAPL,MSFT,GOOGL

# Backfill BOTH 1m and 5m, skipping dates with existing data, IEX feed
php artisan alpaca:backfill-range "2026-07-01" "2026-07-17" both --skip-existing --feed=iex

# Smaller chunk size for slower connections
php artisan alpaca:backfill-range "2026-07-01" "2026-07-10" 1m --chunk=100
```

### Features

- **Auto-skips weekends** (non-trading days)
- **Progress reporting** — per-day row counts, running total, per-chunk progress, ETA
- **Deadlock retry** — 3 attempts with progressive backoff (100ms, 200ms, 300ms)
- **Indicator calculation** — EMA9/21, ATR14 for 1m; plus RSI14, BB(20,2) for 5m
- **Data format** — identical column layout to `stream_bars.py`, `source='alpaca'`

### Example Output

```
╔════════════════════════════════════════════════╗
║       Alpaca Backfill Range                    ║
╠════════════════════════════════════════════════╣
║ Timeframe : 1m                                  ║
║ From      : 2026-07-01                          ║
║ To        : 2026-07-10                          ║
║ Days      : 10                                  ║
║ Symbols   : 523                                 ║
║ Table     : one_minute_prices                   ║
║ Feed      : sip                                 ║
╚════════════════════════════════════════════════╝

  ⏭ 2026-07-04 — skipping (weekend)
  ⏭ 2026-07-05 — skipping (weekend)
├─ 2026-07-01 [1/8]
    … chunk 5/34 (12,500 rows so far)
  ✓ 195,000 rows in 47s
  Total: 195,000 rows | Avg/day: 195,000 | ETA: 4m 30s
├─ 2026-07-02 [2/8]
  ...

╔════════════════════════════════════════════════╗
║       Backfill Complete                        ║
╠════════════════════════════════════════════════╣
║ Days processed : 8                              ║
║ Days skipped   : 2                              ║
║ Total rows     : 1,560,000                      ║
║ Errors         : 0                              ║
║ Duration       : 5m 12s                         ║
╚════════════════════════════════════════════════╝
```

---

## Other Commands

### Single-Day Backfill

```bash
php artisan alpaca:backfill-day "2026-07-16" 1m    # → one_minute_prices
php artisan alpaca:backfill-day "2026-07-16" 5m    # → five_minute_prices
```

### Live/Recent Data (Alpaca API)

```bash
# 1-minute bars — last N minutes (for gap-filling during market hours)
php artisan alpaca:sync-1m --minutes=5 --feed=sip --chunk=150

# 5-minute bars — last N hours
php artisan alpaca:sync-5m --hours=24 --feed=sip --chunk=200
```

---

## Production Scheduler (from `routes/console.php`)

| Active | Command | Schedule | Table |
|--------|---------|----------|-------|
| ✅ | `alpaca:sync-5m --hours=1 --feed=iex` | Every 5 min (market hours) | `five_minute_prices` |
| ❌ | `alpaca:sync-1m` | Disabled (replaced by `stream_bars.py` WebSocket) | `one_minute_prices` |

---

## Data Format

Both tables share the same core columns. The `source` column indicates origin:

| Source | Meaning |
|--------|---------|
| `alpaca` | Alpaca REST API (backfill/sync commands) |
| `alpaca_stream` | Alpaca WebSocket (`stream_bars.py`) |

Generated columns (`ts_est`, `trading_date_est`, `trading_time_est`) are computed
automatically by MySQL from `ts` — never included in INSERT/UPSERT statements.

### `one_minute_prices`

`symbol`, `asset_type`, `ts`, `source`, `price`, `open`, `high`, `low`, `volume`,
`vwap`, `vwap_dist`, `vwap_dist_pct`, `above_vwap`,
`ema9`, `ema21`, `ema9_ema21_spread`, `ema9_above_ema21`,
`atr`, `atr_pct`, `created_at`, `updated_at`

### `five_minute_prices`

All of the above **plus**: `rsi_14`, `bb_upper`, `bb_middle`, `bb_lower`
