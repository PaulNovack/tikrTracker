# Alpaca Python API - Order Placement

This directory contains scripts for placing orders through the Alpaca Trading API.

## Setup

Ensure your `.env` file in the parent directory contains:
```
ALPACA_API_KEY=your_api_key_here
ALPACA_API_SECRET=your_api_secret_here
```

## place_order.py (order_cli.py)

Command-line interface for placing orders with various configurations.

### Examples

**Market buy (no stop):**
```bash
python order_cli.py --symbol AAPL --qty 1 --side buy
```

**Market buy with attached stop loss (bracket):**
```bash
python order_cli.py --symbol AAPL --qty 1 --side buy --stop-price 185
```

**Market buy with stop-limit style stop loss:**
```bash
python order_cli.py --symbol AAPL --qty 1 --side buy --stop-price 185 --stop-limit 184.5
```

**Stop-only order (no entry) — protect an existing long:**
```bash
python order_cli.py --symbol AAPL --qty 1 --side sell --stop-price 185 --stop-only
```

## Other Scripts

- **account_details.py**: Retrieve account information and positions
