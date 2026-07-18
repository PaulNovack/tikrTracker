# Profit Protection Trailing Stop

A tiered stop-loss system that automatically ratchets your stop upward as a trade moves in your favour. It replaces the legacy single-threshold trailing stop when enabled.

---

## How It Works

Once a trade is live (paper or real), the system evaluates the current profit % from entry on every scheduled tick (`alpaca:update-trailing-stops`) and on every bar during a backtest simulation. **Stops only ever move up — they never retract.**

### Tiers

| Profit from entry | What happens to the stop |
|-------------------|--------------------------|
| < +0.75%          | Stop stays at initial ATR-based stop (no change) |
| ≥ +0.75%          | Stop raised to **−0.25% from entry** (caps max loss at 0.25%) |
| ≥ +1.25%          | Stop raised to **+0.50% from entry** (locks in at least half a percent of gain) |
| ≥ +2.00%          | Stop raised to **+1.00% from entry** (locks in 1% gain floor) |
| > +2.00% trailing | Stop trails at **max(1.0%, 2× ATR)** below the session high, always respecting the +1% floor |

### Example (entry $100, ATR 0.8%)

The trail % above +2% is `max(1.0%, 2 × ATR%) = max(1.0%, 1.6%) = **1.6%**` below the session high.

| Price hits | Session high | Profit % | Stop moves to | Notes |
|------------|-------------|----------|---------------|-------|
| $100.50    | $100.50     | +0.50%   | $98.50        | Below +0.75% — initial stop unchanged |
| $100.75    | $100.75     | +0.75%   | **$99.75**    | Tier 1: −0.25% from entry |
| $101.25    | $101.25     | +1.25%   | **$100.50**   | Tier 2: lock +0.50% from entry |
| $102.00    | $102.00     | +2.00%   | **$101.00**   | Tier 3: lock +1.00% from entry |
| $103.00    | $103.00     | +3.00%   | **$101.35**   | Tier 4: 103 - 1.6% = 101.35 (above 101 floor ✓) |
| $104.00    | $104.00     | +4.00%   | **$102.34**   | Trail follows: 104 - 1.6% = 102.34 |
| $105.00    | $105.00     | +5.00%   | **$103.32**   | Trail follows: 105 - 1.6% = 103.32 |
| $104.50    | $105.00     | —        | **$103.32**   | Price dips — stop stays at 103.32 (never retracts) |
| $106.00    | $106.00     | +6.00%   | **$104.30**   | New high: 106 - 1.6% = 104.30 |
| $108.00    | $108.00     | +8.00%   | **$106.27**   | New high: 108 - 1.6% = 106.27 |
| $110.00    | $110.00     | +10.00%  | **$108.24**   | New high: 110 - 1.6% = 108.24 — locking in +8%+ |

#### What this means in practice

- Every new high **ratchets the stop up** by 1.6% below that new high.
- A pullback of **less than 1.6%** from the high does not trigger an exit — it just holds the stop where it is.
- A pullback of **more than 1.6%** from the high triggers the stop and exits the trade.
- At 110, the stop at 108.24 means you'd exit with **+8.24%** profit even if the stock then drops sharply — the tiered system has been continuously protecting gains the whole way up.

---

## Activation Threshold

- **Profit protection ON** → activation at **+0.75%** (earlier than legacy)
- **Profit protection OFF** → activation at **+1.00%** (legacy behaviour unchanged)

---

## Affected Systems

| System | Behaviour |
|--------|-----------|
| **Backtesting** (`AtrPerformanceService`) | Bar-by-bar simulation uses tiered stops when enabled |
| **Live / paper trading** (`alpaca:update-trailing-stops`) | Called every minute; updates Alpaca stop order when tier triggers |

---

## .env Settings

```dotenv
# Master switch — flip to true to enable profit-protection stops
# false = legacy ATR/fixed trailing stop logic (default)
AUTO_ALPACA_PROFIT_PROTECTION_ENABLED=true

# These settings still apply when profit protection is OFF:
AUTO_ALPACA_STOP_LOSS_MODE=atr           # 'atr' or 'fixed'
AUTO_ALPACA_STOP_LOSS_PCT=0.80           # Fixed % used when mode=fixed
AUTO_ALPACA_STOP_LOSS_ATR_MULTIPLIER=4.0 # ATR × multiplier = initial stop %
AUTO_ALPACA_STOP_LOSS_ATR_MIN_PCT=1.00   # Minimum initial stop %
AUTO_ALPACA_STOP_LOSS_ATR_MAX_PCT=2.50   # Maximum initial stop %
```

> **Note:** The initial stop placed at fill time (in `MonitorAlpacaOrderFillAndPlaceStopLoss`) is **not** affected by this switch. Profit protection only governs how the stop is *updated* after the initial placement.

---

## Key Files

| File | Role |
|------|------|
| `app/Services/Trading/ProfitProtectionStopCalculator.php` | Core tier logic — pure service, no side effects |
| `app/Console/Commands/UpdateTrailingStopLosses.php` | Live/paper trading: calls calculator each minute |
| `app/Services/AtrPerformanceService.php` | Backtesting: applies tiers bar-by-bar in simulation |
| `config/trading.php` → `auto_alpaca_orders.profit_protection_enabled` | Config bridge to env |
