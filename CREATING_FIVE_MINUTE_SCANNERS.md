# Creating a Five-Minute Signal Scanner

This guide explains how to create a new five-minute signal scanner that extends `AbstractSignalScanner`. By the end, you'll have a working scanner wired into a pipeline, with automatic validation of the return shape.

---

## 1. Overview: How Scanners Fit In

A **scanner** watches 5-minute price bars across a universe of symbols and produces "signals" — candidates that might move 3%+ intraday. An **entry finder** then refines each signal on 1-minute bars to find the best entry point. Together they feed alerts into a **pipeline** (lettered A–O), which writes to `trade_alerts`.

```
Universe → Scanner (5m bars) → Signals → Entry Finder (1m bars) → Entry → TradeAlertWriter → DB
```

`AbstractSignalScanner` enforces the contract every scanner must follow. It also provides shared helpers like table resolution (`setFullTable`, `resolveSql`, `dbSelect`) and benchmark movement calculation (`getSpyMovement30m`).

---

## 2. The Contract: 4 Methods You Must Implement

Every scanner extending `AbstractSignalScanner` must implement these four methods:

| Method | Returns | Purpose |
|---|---|---|
| `getVersion()` | `string` | Version identifier, e.g. `'v30.0'` |
| `getName()` | `string` | Human-readable name, e.g. `'Momentum Breakout'` |
| `scanConfig()` | `array` | Scanner + entry finder config as key-value pairs |
| `doScan(...)` | `array` | **The core logic** — query 5m bars, apply gates, score, return signal rows |

### `doScan()` Signature

```php
protected function doScan(
    string $assetType,       // 'stock' or 'crypto'
    string $asOfTsEst,       // Timestamp to scan as-of, e.g. '2026-07-17 10:30:00'
    int $lookbackMinutes,    // How far back to query data
    float $minMovePct,       // Minimum % move threshold
    float $volMult,          // Volume multiplier (legacy param)
    int $limit,              // Max signals to return
    bool $skipCache          // Skip Redis cache (true for backtests)
): array
```

> **Important:** Do NOT give `doScan()` default parameter values. The defaults are handled by the parent's `scan()` method — giving `doScan()` defaults will cause a PHP signature mismatch.

---

## 3. Required Return Shape

`scan()` (the parent's `final` method) automatically validates every row returned by `doScan()`. Each row **must** contain:

### Top-Level Keys

| Key | Type | Description |
|---|---|---|
| `symbol` | `string` | Ticker symbol, e.g. `'AAPL'` |
| `asset_type` | `string` | `'stock'` or `'crypto'` |
| `signal_type` | `string` | Signal category, e.g. `'MOMO_5M_V30'` |
| `signal_ts_est` | `string` | Timestamp of the signal bar (EST) |
| `score` | `float` | Composite score (higher = better) |
| `atr` | `float\|null` | ATR in dollars |
| `atr_pct` | `float` | ATR as % of price |
| `meta` | `array` | Additional metadata (see below) |

### `meta` Sub-Keys

| Key | Type | Description |
|---|---|---|
| `move_30m_pct` | `float` | 30-minute price move % |
| `rvol_5m` | `float` | Relative volume (last vol / avg vol) |
| `atr_pct_5m` | `float` | 5-minute ATR as % of price |
| `notional_last5m` | `float` | Last 5m bar notional ($ volume) |
| `pct_nd` | `float\|null` | Percent near day high (can be `null`) |
| `spy_move_30m_pct` | `float` | Benchmark (QQQM/SPY) 30m move % |
| `universe_size` | `int` | Number of symbols in the scan universe |
| `signal_age_seconds` | `int` | Age of the signal bar in seconds |
| `version` | `string` | Scanner version string |
| `current_price` | `float\|null` | Current/recent price |

If any key is missing, a `RuntimeException` is thrown at runtime with the class name, row index, and missing key name.

---

## 4. Inherited Helpers

These are available for free — no need to re-implement:

| Method | Visibility | Purpose |
|---|---|---|
| `setFullTable(bool)` | `public` | Switch between live (`five_minute_prices`) and full (`five_minute_prices_full`) tables. Override to propagate to dependent services. |
| `resolveSql(string)` | `protected` | Replace `five_minute_prices` / `one_minute_prices` placeholders in SQL with the active table name. |
| `dbSelect(string, array)` | `protected` | Execute a raw SQL query with automatic table name resolution. |
| `getSpyMovement30m(string, string, int)` | `protected` | Calculate the benchmark symbol's 30-minute % movement for relative-strength filtering. |

---

## 5. Step-by-Step: Creating a New Scanner

### Step 1: Create the Class

Create `app/Services/Trading/FiveMinuteSignalScannerV30_0.php`:

```php
<?php

namespace App\Services\Trading;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class FiveMinuteSignalScannerV30_0 extends AbstractSignalScanner
{
    private string $version = 'v30.0';
    private string $name = 'My Scanner Name';

    // ── Scanner Configuration ──
    public int $topDays = 5;
    public int $topLimit = 500;
    public int $losersLimit = 75;
    public float $minNotional5m = 75000;
    public float $minAtrPct5m = 0.35;
    public float $minRvol5m = 2.0;
    public float $minMove30m = 1.2;
    public int $activeWindowMinutes = 6;
    public int $analysisLookbackMinutes = 90;

    // ── Entry Finder Configuration ──
    public float $entryMinNotional1m = 80000;
    public float $entryMinVolRatio1m = 1.0;
    public float $entryMinBodyPct1m = 0.05;
    public float $entryMaxAboveVwapPct = 0.90;
    public float $entryMinRoomToRunPct = 0.6;
    public float $entryRoomAtrMult = 1.5;
    public int $entryAllowLunch = 0;
    public int $entryMinBars = 15;
    public int $entryMaxAgeMinutes = 10;
```

### Step 2: Implement the Required Methods

```php
    public function getVersion(): string
    {
        return $this->version;
    }

    public function getName(): string
    {
        return $this->name;
    }

    /** @return array<string, mixed> */
    public function scanConfig(): array
    {
        return [
            'top_days'                 => $this->topDays,
            'top_limit'                => $this->topLimit,
            'losers_limit'             => $this->losersLimit,
            'min_notional_5m'          => $this->minNotional5m,
            'min_atr_pct_5m'           => $this->minAtrPct5m,
            'min_rvol_5m'              => $this->minRvol5m,
            'min_move_30m_pct'         => $this->minMove30m,
            'active_window_minutes'    => $this->activeWindowMinutes,
            'analysis_lookback_minutes'=> $this->analysisLookbackMinutes,
            'entry_min_notional_1m'    => $this->entryMinNotional1m,
            'entry_min_vol_ratio_1m'   => $this->entryMinVolRatio1m,
            'entry_min_body_pct_1m'    => $this->entryMinBodyPct1m,
            'entry_max_above_vwap_pct' => $this->entryMaxAboveVwapPct,
            'entry_min_room_to_run_pct'=> $this->entryMinRoomToRunPct,
            'entry_room_atr_mult'      => $this->entryRoomAtrMult,
            'entry_allow_lunch'        => $this->entryAllowLunch,
            'entry_min_bars'           => $this->entryMinBars,
            'entry_max_age_minutes'    => $this->entryMaxAgeMinutes,
        ];
    }
```

### Step 3: Implement `doScan()` — The Core Logic

A typical `doScan()` follows this pattern:

1. **Build the universe** — which symbols to scan (cached from `intraday_universe`, market movers, Redis streaks)
2. **Query 5-minute bars** — use a CTE-based SQL query joining the universe
3. **Apply gates** — filter by notional, ATR%, RVOL, move%, and optionally relative strength vs benchmark
4. **Score** — compute a composite score from move, RVOL, and ATR%
5. **Sort by score, slice to limit**

```php
    protected function doScan(
        string $assetType,
        string $asOfTsEst,
        int $lookbackMinutes,
        float $minMovePct,
        float $volMult,
        int $limit,
        bool $skipCache
    ): array {
        // ── 1. Universe ──
        $universeCacheKey = "scan_v30_0:universe_symbols:{$assetType}";
        $symbols = Cache::get($universeCacheKey);
        if ($symbols === null) {
            $symbols = DB::table('intraday_universe')
                ->select('symbol')
                ->where('asset_type', $assetType)
                ->orderBy('symbol')
                ->pluck('symbol')
                ->all();

            // Add market movers if enabled
            $moversLimit = (int) config('trading.market_movers.pipeline_h', 0);
            if ($moversLimit > 0) {
                $movers = app(\App\Services\MarketMoversService::class)
                    ->getTodaysTopMoversFromCache(null, $moversLimit);
                $symbols = array_values(array_unique(array_merge($symbols, $movers)));
            }

            // Cache for 8 hours
            Cache::put($universeCacheKey, $symbols, 28800);
        }

        if (empty($symbols)) {
            return [];
        }

        // ── 2. Build CTE query ──
        $placeholders = implode(',', array_fill(0, count($symbols), '?'));

        $moveBars = 6;   // 6 × 5m = 30m
        $atrPeriod = 14; // 14-bar ATR
        $rvolLookback = 20;

        $sql = "
WITH universe AS (
  SELECT ? AS asset_type, symbol
  FROM (SELECT 1) t
  CROSS JOIN (
    SELECT DISTINCT symbol
    FROM five_minute_prices
    WHERE asset_type = ?
      AND symbol IN ($placeholders)
  ) s
),
base AS (
  SELECT
    f.symbol, f.asset_type, f.ts_est,
    f.price AS close, f.high, f.low, f.volume,
    LAG(f.price, 1) OVER (PARTITION BY f.symbol, f.asset_type ORDER BY f.ts_est) AS prev_close,
    ROW_NUMBER() OVER (PARTITION BY f.symbol, f.asset_type ORDER BY f.ts_est DESC) AS rn_desc
  FROM five_minute_prices f
  JOIN universe u ON u.symbol = f.symbol AND u.asset_type = f.asset_type
  WHERE f.ts_est <= ?
    AND f.ts_est >= DATE_SUB(?, INTERVAL ? MINUTE)
),
-- ... continue with agg_last, rvol, atr, activity CTEs (see V25_2 for full reference)
";

        $params = array_merge(
            [$assetType, $assetType], $symbols,
            [$asOfTsEst, $asOfTsEst, $lookbackMinutes]
        );

        // ── 3. Cache the result (skip in backtest mode) ──
        $rows = null;
        if (! $skipCache) {
            $bucketTs = date('Y-m-d H:i', strtotime(floor(strtotime($asOfTsEst) / 300) * 300));
            $cacheKey = "scan_v30_0:{$assetType}:{$bucketTs}:{$lookbackMinutes}";
            $rows = Cache::get($cacheKey);
        }
        if ($rows === null) {
            if ($skipCache) {
                $rows = $this->dbSelect($sql, $params);
            } else {
                $lock = Cache::lock("lock:{$cacheKey}", 60);
                if ($lock->get()) {
                    try {
                        $rows = $this->dbSelect($sql, $params);
                        Cache::put($cacheKey, $rows, 240);
                    } finally {
                        $lock->release();
                    }
                } else {
                    $rows = Cache::get($cacheKey) ?? $this->dbSelect($sql, $params);
                }
            }
        }

        // ── 4. Get benchmark movement ──
        $spyMove30m = $this->getSpyMovement30m($asOfTsEst, $assetType, $moveBars);

        $asOfEpoch = strtotime(date('Y-m-d H:i:00', strtotime($asOfTsEst)));
        $maxSignalAgeSeconds = max(1, $this->activeWindowMinutes) * 60;

        // ── 5. Apply gates & score ──
        $out = [];
        foreach ($rows as $r) {
            // Age gate
            $signalAgeSeconds = $asOfEpoch - strtotime((string) $r->signal_ts_est);
            if ($signalAgeSeconds < 0 || $signalAgeSeconds > $maxSignalAgeSeconds) {
                continue;
            }

            $lastClose = (float) $r->last_close;
            if ($lastClose <= 0) {
                continue;
            }

            $atrPct = (float) $r->atr_pct;
            $rvolRatio = (float) $r->rvol_ratio;
            $move30m = (float) $r->move_30m_pct;
            $notional = (float) $r->notional_last5m;

            // Notional gate
            if ($notional < $this->minNotional5m) {
                continue;
            }
            // ATR gate
            if ($atrPct < $this->minAtrPct5m) {
                continue;
            }
            // Activity gate
            if (! ($rvolRatio >= $this->minRvol5m || $move30m >= $this->minMove30m)) {
                continue;
            }
            // Move floor
            if ($move30m < $minMovePct) {
                continue;
            }

            // Score
            $rvolCapped = min(6.0, $rvolRatio);
            $score = ($move30m * 1.2) + ($rvolCapped * 1.0) + ($atrPct * 0.8);

            $atr = ($atrPct && $r->last_close)
                ? round(($atrPct / 100) * $r->last_close, 6)
                : null;

            // ── 6. Build the signal row (must match the required shape) ──
            $out[] = [
                'symbol'        => (string) $r->symbol,
                'asset_type'    => (string) $r->asset_type,
                'signal_type'   => 'MOMO_5M_V30',
                'signal_ts_est' => (string) $r->signal_ts_est,
                'score'         => round($score, 3),
                'atr'           => $atr,
                'atr_pct'       => $atrPct,
                'meta'          => [
                    'move_30m_pct'       => round($move30m, 3),
                    'rvol_5m'            => round($rvolRatio, 3),
                    'atr_pct_5m'         => round($atrPct, 3),
                    'notional_last5m'    => round($notional, 2),
                    'pct_nd'             => null,
                    'spy_move_30m_pct'   => round($spyMove30m, 3),
                    'universe_size'      => count($symbols),
                    'signal_age_seconds' => $signalAgeSeconds,
                    'version'            => $this->version,
                    'current_price'      => $r->last_close ?? null,
                ],
            ];
        }

        usort($out, fn ($a, $b) => ($b['score'] <=> $a['score']));

        return array_slice($out, 0, max(1, $limit));
    }
}
```

### Step 4: Wire to a Pipeline

Add a version entry in `config/app.php`:

```php
// config/app.php
'trade_alert_h_version' => env('TRADE_ALERT_H_VERSION', 'v30.0'),
```

Then set it in `.env`:

```env
TRADE_ALERT_H_VERSION=v30.0
```

The pipeline command (`TradePipelineRunH`) converts `v30.0` to `V30_0` and resolves:
- Scanner: `App\Services\Trading\FiveMinuteSignalScannerV30_0`
- Finder: `App\Services\Trading\OneMinuteEntryFinderV30_0`

> **Important:** You must also create a matching `OneMinuteEntryFinderV30_0` entry finder. The pipeline requires both classes to exist.

### Step 5: Run Pint

```bash
vendor/bin/pint --dirty
```

---

## 6. Validation Is Automatic

Every signal row returned by `doScan()` is validated against the required shape. If a key is missing, you get a clear error:

```
RuntimeException: App\Services\Trading\FiveMinuteSignalScannerV30_0::doScan() row 3 missing required key "signal_type".
```

No need to manually check return shapes — the abstract class handles it.

---

## 7. Common Patterns

### Caching

- **Universe cache:** 8 hours (`Cache::put($key, $symbols, 28800)`). The universe changes slowly.
- **Result cache:** 4 minutes (`Cache::put($key, $rows, 240)`). 5m bars update every 5 minutes.
- **Lock:** Use `Cache::lock()` to prevent cache stampedes on cold cache.
- **Skip in backtest:** Pass `$skipCache` through so each historical time slot gets its own query.

### Table Resolution

Always use the literal table names `five_minute_prices` and `one_minute_prices` in your SQL. The `resolveSql()` helper (called by `dbSelect()`) automatically swaps them for the `_full` variants when `setFullTable(true)` is called.

### Dependent Services

If your scanner injects services via the constructor (like `BestPerformers5mService`), override `setFullTable()` to propagate the table mode:

```php
public function setFullTable(bool $full): void
{
    parent::setFullTable($full);
    $this->myService->setFullTable($full);
}
```

### Relative Strength vs Benchmark

Use `$this->getSpyMovement30m($asOfTsEst, $assetType, $moveBars)` to get the benchmark's 30m move. Apply a relative-strength gate:

```php
$enableRsFilter = (bool) config('trading.enable_relative_strength_filter', false);
$minRsMult = (float) config('trading.v25.scanner.min_rs_mult_vs_spy', 1.10);
if ($enableRsFilter && $spyMove30m > 0.10 && $move30m < $spyMove30m * $minRsMult) {
    continue; // Candidate is just riding the market tide
}
```

---

## 8. Checklist

- [ ] Class extends `AbstractSignalScanner`
- [ ] Implements `getVersion()`, `getName()`, `scanConfig()`, `doScan()`
- [ ] `doScan()` has **no default parameter values**
- [ ] Every signal row includes all required top-level keys (`symbol`, `asset_type`, `signal_type`, `signal_ts_est`, `score`, `atr`, `atr_pct`, `meta`)
- [ ] `meta` includes all required sub-keys (9 keys)
- [ ] Signals are sorted by score descending
- [ ] Result is sliced to `$limit` (minimum 1)
- [ ] Matching `OneMinuteEntryFinderV{version}` exists
- [ ] `.env` or `config/app.php` points the pipeline version at the new scanner
- [ ] Run `vendor/bin/pint --dirty`
