#!/usr/bin/env python3
import os
import json
import argparse
from dotenv import load_dotenv

from alpaca.trading.client import TradingClient

"""
close_position.py

Closes (sells) the ENTIRE position for a given symbol using Alpaca's close_position().
This submits a market order that closes your position for that symbol.

Usage:
  python close_position.py --symbol AAPL
  python close_position.py --symbol SPY --live

Notes:
- Default is paper trading. Use --live for live trading.
- If you do NOT have a position in the symbol, Alpaca will return an error.
"""


def parse_args():
    p = argparse.ArgumentParser(description="Close an entire position by symbol via Alpaca.")
    p.add_argument("--symbol", required=True, help="Ticker symbol to close (e.g., AAPL)")
    p.add_argument(
        "--live",
        action="store_true",
        help="Use live trading (default is paper trading).",
    )
    return p.parse_args()


def dump_json(obj):
    if hasattr(obj, "model_dump"):
        return obj.model_dump()
    if hasattr(obj, "dict"):
        return obj.dict()
    if hasattr(obj, "__dict__"):
        return obj.__dict__
    return obj


def main():
    args = parse_args()

    # Load environment variables from .env in parent directory
    load_dotenv(dotenv_path=os.path.join(os.path.dirname(__file__), "..", ".env"))

    api_key = os.getenv("ALPACA_KEY_ID")
    api_secret = os.getenv("ALPACA_SECRET_KEY")
    if not api_key or not api_secret:
        raise ValueError("ALPACA_KEY_ID and ALPACA_SECRET_KEY must be set in .env file")

    # Read paper trading setting from .env (use --live flag as override if provided)
    paper_trading = os.getenv('ALPACA_PAPER_TRADING', 'True').lower() in ('true', '1', 'yes')
    if args.live:
        paper_trading = False

    trading_client = TradingClient(api_key, api_secret, paper=paper_trading)

    symbol = args.symbol.upper().strip()

    # Close the entire position (market order by default)
    result = trading_client.close_position(symbol)

    print(json.dumps(
        {
            "symbol": symbol,
            "closed_position": dump_json(result),
        },
        indent=2,
        default=str
    ))


if __name__ == "__main__":
    main()
