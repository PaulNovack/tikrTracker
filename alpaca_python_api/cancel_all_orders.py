#!/usr/bin/env python3
"""
Cancel all open orders for a specific symbol via Alpaca API
"""

import argparse
import json
import os
import sys
from pathlib import Path

from alpaca.trading.client import TradingClient
from alpaca.trading.requests import CancelOrderResponse
from dotenv import load_dotenv


def parse_args():
    p = argparse.ArgumentParser(description="Cancel all orders for a symbol")
    p.add_argument("--symbol", type=str, required=True, help="Stock symbol")
    p.add_argument("--live", action="store_true", help="Use live trading (default is paper)")
    return p.parse_args()


def main():
    args = parse_args()

    # Load environment variables
    load_dotenv(dotenv_path=os.path.join(os.path.dirname(__file__), "..", ".env"))

    api_key = os.getenv("ALPACA_KEY_ID")
    api_secret = os.getenv("ALPACA_SECRET_KEY")
    if not api_key or not api_secret:
        print(json.dumps({"success": False, "error": "API credentials not found"}))
        sys.exit(1)

    # Read paper trading setting from .env (use --live flag as override if provided)
    paper_trading = os.getenv('ALPACA_PAPER_TRADING', 'True').lower() in ('true', '1', 'yes')
    if args.live:
        paper_trading = False

    trading_client = TradingClient(api_key, api_secret, paper=paper_trading)

    try:
        # Get all open orders
        from alpaca.trading.requests import GetOrdersRequest
        from alpaca.trading.enums import QueryOrderStatus
        
        request_params = GetOrdersRequest(
            status=QueryOrderStatus.OPEN,
            symbols=[args.symbol.upper()]
        )
        
        orders = trading_client.get_orders(filter=request_params)
        
        canceled_count = 0
        for order in orders:
            try:
                trading_client.cancel_order_by_id(order.id)
                canceled_count += 1
            except Exception as e:
                print(f"Failed to cancel order {order.id}: {e}", file=sys.stderr)
        
        out = {
            "success": True,
            "symbol": args.symbol.upper(),
            "canceled_count": canceled_count,
        }
        print(json.dumps(out, indent=2, default=str))
    except Exception as e:
        out = {
            "success": False,
            "error": str(e),
        }
        print(json.dumps(out, indent=2))
        sys.exit(1)


if __name__ == "__main__":
    main()
