#!/usr/bin/env python3
"""
Check if a position exists for a specific symbol
Returns position details as JSON if it exists, or empty JSON if not

Usage:
    python check_position.py --symbol AAPL
"""

import os
import sys
import json
import argparse
from dotenv import load_dotenv
from alpaca.trading.client import TradingClient

load_dotenv(dotenv_path=os.path.join(os.path.dirname(__file__), "..", ".env"))

api_key = os.getenv("ALPACA_KEY_ID")
api_secret = os.getenv("ALPACA_SECRET_KEY")
if not api_key or not api_secret:
    print(json.dumps({"error": "ALPACA_KEY_ID and ALPACA_SECRET_KEY must be set in .env file"}))
    sys.exit(1)

# Read paper trading setting from .env
paper_trading = os.getenv('ALPACA_PAPER_TRADING', 'True').lower() in ('true', '1', 'yes')
trading_client = TradingClient(api_key, api_secret, paper=paper_trading)

parser = argparse.ArgumentParser(description="Check position for a symbol")
parser.add_argument("--symbol", type=str, required=True, help="Stock symbol to check")
args = parser.parse_args()

try:
    # Get the specific position
    position = trading_client.get_open_position(args.symbol)
    
    # Return position details as JSON
    print(json.dumps({"position": position.model_dump()}, indent=2, default=str))
except Exception as e:
    # Position doesn't exist or error occurred
    error_msg = str(e)
    if "position does not exist" in error_msg.lower() or "404" in error_msg:
        # Position doesn't exist - return empty position
        print(json.dumps({"position": None}, indent=2))
    else:
        # Other error
        print(json.dumps({"error": error_msg}, indent=2))
        sys.exit(1)
