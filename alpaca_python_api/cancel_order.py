#!/usr/bin/env python3
"""
Cancel a specific order by Alpaca order ID
"""

import argparse
import json
import os
import sys

from alpaca.trading.client import TradingClient
from dotenv import load_dotenv


def parse_args():
    p = argparse.ArgumentParser(description="Cancel a specific order by ID")
    p.add_argument("--order-id", type=str, required=True, help="Alpaca order UUID")
    p.add_argument("--live", action="store_true", help="Use live trading (default is paper)")
    return p.parse_args()


def main():
    args = parse_args()

    load_dotenv(dotenv_path=os.path.join(os.path.dirname(__file__), "..", ".env"))

    api_key = os.getenv("ALPACA_KEY_ID")
    api_secret = os.getenv("ALPACA_SECRET_KEY")
    if not api_key or not api_secret:
        print(json.dumps({"success": False, "error": "API credentials not found"}))
        sys.exit(1)

    paper_trading = os.getenv("ALPACA_PAPER_TRADING", "True").lower() in ("true", "1", "yes")
    if args.live:
        paper_trading = False

    trading_client = TradingClient(api_key, api_secret, paper=paper_trading)

    try:
        trading_client.cancel_order_by_id(args.order_id)
        print(json.dumps({"success": True, "order_id": args.order_id}))
    except Exception as e:
        print(json.dumps({"success": False, "error": str(e)}))
        sys.exit(1)


if __name__ == "__main__":
    main()
