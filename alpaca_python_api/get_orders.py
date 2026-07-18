#!/usr/bin/env python3
import argparse
import json
import os
from pathlib import Path
from dotenv import load_dotenv
from alpaca.trading.client import TradingClient
from alpaca.trading.requests import GetOrdersRequest
from alpaca.trading.enums import QueryOrderStatus


def parse_args():
    p = argparse.ArgumentParser(description="Get orders from Alpaca API")
    p.add_argument("--status", help="Filter by status: open, closed, all (default: all)")
    p.add_argument("--limit", type=int, default=100, help="Maximum number of orders to return")
    p.add_argument("--start-date", help="Filter orders after this date (YYYY-MM-DD)")
    p.add_argument("--end-date", help="Filter orders before this date (YYYY-MM-DD)")
    p.add_argument("--live", action="store_true", help="Use live trading (default is paper)")
    return p.parse_args()


def dump_json(obj):
    """Serialize pydantic models (v2) or fallback objects to JSON."""
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
    dotenv_path = Path(__file__).parent.parent / ".env"
    load_dotenv(dotenv_path=dotenv_path)

    api_key = os.getenv("ALPACA_KEY_ID")
    api_secret = os.getenv("ALPACA_SECRET_KEY")

    if not api_key or not api_secret:
        raise ValueError("ALPACA_KEY_ID and ALPACA_SECRET_KEY must be set in .env")

    # Read paper trading setting from .env (use --live flag as override if provided)
    paper_trading = os.getenv('ALPACA_PAPER_TRADING', 'True').lower() in ('true', '1', 'yes')
    if args.live:
        paper_trading = False

    # Initialize trading client
    trading_client = TradingClient(
        api_key=api_key,
        secret_key=api_secret,
        paper=paper_trading,
    )

    # Build request
    request_params = {
        "limit": args.limit,
    }

    # Add status filter if provided
    if args.status:
        if args.status.lower() == "open":
            request_params["status"] = QueryOrderStatus.OPEN
        elif args.status.lower() == "closed":
            request_params["status"] = QueryOrderStatus.CLOSED
        elif args.status.lower() == "all":
            request_params["status"] = QueryOrderStatus.ALL

    # Add date filters if provided
    if args.start_date:
        request_params["after"] = args.start_date
    if args.end_date:
        request_params["until"] = args.end_date

    get_orders_req = GetOrdersRequest(**request_params)

    # Get orders
    orders = trading_client.get_orders(filter=get_orders_req)

    # Convert to JSON
    orders_list = [dump_json(order) for order in orders]

    result = {
        "success": True,
        "count": len(orders_list),
        "orders": orders_list,
    }

    print(json.dumps(result, indent=2, default=str))


if __name__ == "__main__":
    main()
