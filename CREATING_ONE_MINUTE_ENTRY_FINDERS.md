# Creating One-Minute Entry Finders

This guide explains how to create a new one-minute entry finder following the established pattern.

## Architecture Overview

```
Signal Scanner (5-min bars)
    │  Generates trade signals: symbol, signal_ts_est, score, ATR, meta
    ▼
One-Minute Entry Finder (1-min bars)
    │  Refines the entry: entry_price, stop_loss, entry_type
    ▼
TradeAlertWriter
    │  Writes the final trade alert with entry + stop
    ▼
Alpaca Order Placement
```

## Abstract Base Class

All entry finders extend `AbstractOneMinuteEntryFinder` which implements `OneMinuteEntryFinderContract`.

### Required Methods

Every entry finder must implement:

| Method | Return | Purpose |
|--------|--------|---------|
| `getVersion()` | `string` | Version identifier (e.g. `'v25.2'`) |
| `getName()` | `string` | Human-readable name (e.g. `'Quality-First Entry v25.2'`) |
| `entryConfig()` | `array` | Configuration array with all gates/thresholds |
| `doFindBestLong()` | `?array` | **Core logic** — returns entry data or null |

### Template Method

`findBestLong()` is `final` in the abstract class. It calls your `doFindBestLong()` and wraps the result:

```php
public function findBestLong(...): ?array  // FINAL — do not override
protected function doFindBestLong(...): ?array  // Implement this
```

### Required Return Shape

`doFindBestLong()` must return an array with these keys:

```php
[
    'entry_price' => float,   // Recommended entry price
    'stop_loss'   => float,   // Recommended stop-loss price
    'entry_type'  => string,  // e.g. 'VWAP_RECLAIM', 'ORB_RETEST', 'EMA9_PULLBACK'
]
```

Additional keys (vwap, ema9, hod, body_pct, vol_ratio, atr, etc.) are recommended but not required.

### Inherited Helpers

| Method | Purpose |
|--------|---------|
| `fetchOneMinuteBars()` | Get 1-min bars from market open to as-of time |
| `fetchFiveMinuteBarsForAnalysis()` | Get 5-min bars for choppiness detection |
| `isAllowedTime()` | Check if time is outside lunch chop window |
| `hasValidPriceData()` | Detect reverse splits / bad data |
| `calculate5MinChoppiness()` | Body % and overlap metrics |
| `validateEntry()` | Validates required keys exist in return |
| `dbSelect()` | Raw SQL query helper |
| `maybeLogDebug()` / `isDebugEnabled()` | Periodic debug counter logging |

## Step-by-Step: Creating V25.2

### 1. Create the class

```php
namespace App\Services\Trading;

class OneMinuteEntryFinderV25_2 extends AbstractOneMinuteEntryFinder
{
    public function __construct()
    {
        $this->version = 'v25.2';
    }
    // ...
}
```

### 2. Implement required methods

```php
public function getVersion(): string { return $this->version; }
public function getName(): string { return 'Quality-First Entry v25.2'; }

public function entryConfig(): array
{
    return [
        'version' => $this->version,
        'min_notional_1m' => (int) config('trading.v25_2.min_notional_1m', 100000),
        'min_vol_ratio_1m' => (float) config('trading.v25_2.min_vol_ratio_1m', 2.5),
        // ... more gates
    ];
}
```

### 3. Implement entry logic

```php
protected function doFindBestLong(
    string $symbol,
    string $assetType,
    string $signalTsEst,
    string $asOfTsEst,
): ?array {
    // 1. Load config
    $cfg = $this->entryConfig();

    // 2. Time window check
    if (! $this->isAllowedTime($asOfTsEst, $cfg['allow_lunch'])) {
        return null;
    }

    // 3. Fetch bars
    $marketOpen = substr($signalTsEst, 0, 10) . ' 09:30:00';
    $bars = $this->fetchOneMinuteBars($symbol, $assetType, $marketOpen, $asOfTsEst);

    // 4. Validate data
    if (! $bars || count($bars) < $cfg['min_bars']) return null;
    if (! $this->hasValidPriceData($bars)) return null;

    // 5. Compute indicators (VWAP, EMA, ATR, HOD)
    // 6. Apply gates (notional, volume ratio, VWAP extension, room to run)
    // 7. Determine entry type
    // 8. Calculate stop price

    return [
        'entry_price'  => round($lastClose, 2),
        'stop_loss'    => round($stopPrice, 2),
        'entry_type'   => $entryType,
        // Additional info...
    ];
}
```

### 4. Wire to pipeline

Edit `.env` and set the entry finder version for the pipeline:

```
TRADE_ALERT_H_ENTRY_FINDER_VERSION=v25.2
```

Or in `config/trading.php`:

```php
'pipeline_h' => [
    'entry_finder_version' => 'v25.2',
],
```

## Configuration Reference

All gates should be configurable via `config/trading.php` or `.env`:

| Setting | Default | Purpose |
|---------|---------|---------|
| `min_notional_1m` | 100000 | Minimum 1-min bar notional (price × volume) |
| `min_vol_ratio_1m` | 2.5 | Volume surge vs 20-bar average |
| `max_above_vwap_entry_pct` | 0.60 | Max % above VWAP before rejecting as extended |
| `min_room_to_run_pct` | 0.8 | Minimum % room to HOD or ATR runway |
| `room_atr_mult` | 2.5 | ATR multiplier for room-to-run alternative |
| `allow_lunch` | false | Allow entries during 11:30-13:30 EST |
| `min_bars` | 90 | Minimum 1-min bars required since market open |
| `min_body_pct` | 0.40 | Minimum body % for VWAP reclaim confirmation |
| `require_trend_align` | false | Require EMA9 > EMA21 for trend confirmation |
| `atr_multiplier` | 2.0 | Stop-loss = entry - (ATR × multiplier) |

## Debugging

Enable debug logging:

```
ENTRYFINDER_V25_2_DEBUG=1
```

Or:

```php
'trading' => ['entry_finder_debug' => true]
```

Debug counters are logged every 20,000 calls and include: `called`, `not_enough_bars`, `time_blocked`, `fail_notional_1m`, `reject_extended`, `reject_no_room`, `returned`.

## Checklist

- [ ] Extends `AbstractOneMinuteEntryFinder`
- [ ] Implements `getVersion()`, `getName()`, `entryConfig()`, `doFindBestLong()`
- [ ] Return array has `entry_price`, `stop_loss`, `entry_type` keys
- [ ] All gates are configurable via `config()` or `env()`
- [ ] Time window check uses `isAllowedTime()`
- [ ] Data quality check uses `hasValidPriceData()`
- [ ] Piped through pipeline's entry finder resolution
