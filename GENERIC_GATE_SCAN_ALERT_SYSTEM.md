# Generic Gate Scan Alert System

> **Status:** IMPLEMENTED — `app/Services/TradingV2/`, 15 files, ~1,500 lines
>
> **Artisan:** `php artisan trading:consume-bars`
>
> **Database:** `alert_versions` (20 rows), `alert_version_gates` (~200 rows), `entry_type_rules` (5 rows)
>
> **UI:** `/generic-ta-gate-versions`

---

## 1. Architecture

```
                    ┌──────────────────────────────┐
                    │     BarSourceInterface        │
                    │  ┌──────────┐ ┌───────────┐  │
                    │  │  Redis   │ │  MySQL    │  │
                    │  │ (live)   │ │ (backtest)│  │
                    │  └──────────┘ └───────────┘  │
                    └──────────────┬───────────────┘
                                   │
                    ┌──────────────▼───────────────┐
                    │      GateEvaluator            │
                    │  Computes ALL 40+ gates       │
                    │  from Redis or MySQL bars     │
                    └──────────────┬───────────────┘
                                   │
                    ┌──────────────▼───────────────┐
                    │      BarEventConsumer         │
                    │  XREADGROUP rt:events:bars    │
                    │  → evaluate → dispatch job    │
                    └──────────────┬───────────────┘
                                   │
                    ┌──────────────▼───────────────┐
                    │      EvaluateBarJob           │
                    │  foreach alert_version:       │
                    │    check gates → alert        │
                    └──────────────────────────────┘
```

---

## 2. Bar Source Abstraction

`GateEvaluator` takes a `BarSourceInterface`:

| Implementation | Source | Use Case |
|---------------|--------|----------|
| `RedisBarSource` | `rt:bars:5m:*` / `rt:bars:1m:*` sorted sets | Live/realtime |
| `MySqlBarSource` | `five_minute_prices` / `one_minute_prices` tables | Backtests |

Swap via Laravel's service container:

```php
// Live: Redis
app()->bind(BarSourceInterface::class, RedisBarSource::class);

// Backtest: MySQL with _full tables
app()->bind(BarSourceInterface::class, fn () => new MySqlBarSource(fullTable: true));
```

---

## 3. Directory Structure

```
app/Services/TradingV2/
├── Contracts/
│   └── BarSourceInterface.php      # getBars(timeframe, symbol, asOf, lookback, limit)
├── GateEvaluator.php               # All 40+ gate formulas
├── BarEventConsumer.php            # Stream reader
├── Jobs/
│   └── EvaluateBarJob.php          # Per-version threshold check
├── Repositories/
│   ├── RedisBarSource.php          # Redis implementation
│   ├── MySqlBarSource.php          # MySQL implementation (+ _full table support)
│   ├── RedisBarRepository.php      # (copied, legacy)
│   ├── AlertVersionRepository.php  # DB→Redis cache
│   └── BarRepositoryInterface.php  # (copied)
├── DTOs/
│   ├── BarGates.php                # Gate values
│   ├── AlertVersionConfig.php      # Version config
│   └── MarketBar.php               # (copied)
├── Traits/
│   └── HasPriceTables.php          # (copied)
└── Commands/
    └── ConsumeBarEvents.php        # php artisan trading:consume-bars
```

---

## 4. Backtest Support

```php
// Run a backtest using MySQL data
$mysqlSource = new MySqlBarSource(fullTable: true);
$evaluator = new GateEvaluator($mysqlSource);

// Iterate historical timestamps
foreach ($timestamps as $ts) {
    $gates = $evaluator->evaluate5m($symbol, $ts);
    // ... check gates, store candidates, find entries ...
}
```

---

## 5. Supervisor (Live Only)

```
[program:laravel-invest-tradingv2-consumer]
command: php artisan trading:consume-bars
numprocs: 3

[program:laravel-invest-tradingv2-workers]
command: php artisan queue:work redis --queue=gate-check --sleep=0 --tries=1
numprocs: 18
```

---

## 6. Files Summary

| File | Lines | Purpose |
|------|-------|---------|
| `GateEvaluator.php` | ~570 | All gate formulas |
| `BarEventConsumer.php` | ~115 | Stream consumer |
| `Jobs/EvaluateBarJob.php` | ~235 | Per-version check + alert |
| `Repositories/RedisBarSource.php` | ~60 | Redis data |
| `Repositories/MySqlBarSource.php` | ~70 | MySQL data (+ backtests) |
| `Repositories/AlertVersionRepository.php` | ~80 | DB config cache |
| `Contracts/BarSourceInterface.php` | ~15 | Abstraction |
| `DTOs/*` | ~110 | Data containers |
| Migration + Seeder | ~200 | DB setup |
| **Total** | **~1,455** | |
