#!/usr/bin/env python3
import os
import json
import argparse
from dotenv import load_dotenv

from alpaca.trading.client import TradingClient
from alpaca.trading.requests import GetOrderByIdRequest, ReplaceOrderRequest

"""
update_stop_order.py

Updates (moves) the STOP-LOSS leg of a BRACKET order by:
1) Fetching the parent order with nested legs
2) Finding the stop-loss leg (the leg that has stop_price set)
3) Replacing that leg with a new stop_price

CLI args:
  --parent-id   The parent BRACKET order UUID (alpaca order id)
  --stop-price  The new stop loss stop_price (e.g., 1.23)

Example:
  python update_stop_order.py --parent-id c4f4a7c1-87ef-499b-aead-ce04493211ad --stop-price 1.23

Notes:
- This updates the STOP leg, not the parent order.
- Alpaca sides: 'buy' and 'sell' (OrderSide enum). Here we're only updating stop_price.
"""


def parse_args():
    p = argparse.ArgumentParser(description="Update the stop-loss leg of a bracket order in Alpaca.")
    p.add_argument("--parent-id", required=True, help="Parent BRACKET order UUID (alpaca order id)")
    p.add_argument("--stop-price", required=True, type=float, help="New stop-loss stop_price (e.g., 1.23)")
    p.add_argument(
        "--live",
        action="store_true",
        help="Use live trading (default is paper trading).",
    )
    p.add_argument(
        "--debug",
        action="store_true",
        help="Print parent order and legs for debugging.",
    )
    return p.parse_args()


def dump_json(obj):
    """Serialize pydantic models (v2) or fallback objects to JSON."""
    if hasattr(obj, "model_dump"):
        return obj.model_dump()
    if hasattr(obj, "dict"):  # older pydantic
        return obj.dict()
    if hasattr(obj, "__dict__"):
        return obj.__dict__
    return obj


def main():
    args = parse_args()

    # Load environment variables from .env in parent directory (same as your prior example)
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

    # Fetch parent order with nested legs
    parent = trading_client.get_order_by_id(
        args.parent_id,
        filter=GetOrderByIdRequest(nested=True),
    )

    legs = parent.legs or []

    if args.debug:
        print(json.dumps({"parent": dump_json(parent)}, indent=2, default=str))

    if not legs:
        raise RuntimeError("Parent order has no legs. Is this definitely a BRACKET order with a stop-loss leg?")

    # Find the stop-loss leg by presence of stop_price
    stop_leg = next((leg for leg in legs if getattr(leg, "stop_price", None) is not None), None)

    if not stop_leg:
        # Some SDK versions store stop price differently; provide helpful debug output
        raise RuntimeError(
            "Could not find stop-loss leg (no leg with stop_price). "
            "Run with --debug to inspect the legs payload."
        )

    # Replace stop leg stop_price
    updated_stop = trading_client.replace_order_by_id(
        stop_leg.id,
        ReplaceOrderRequest(stop_price=args.stop_price),
    )

    out = {
        "parent_id": args.parent_id,
        "stop_leg_id": stop_leg.id,
        "old_stop_price": getattr(stop_leg, "stop_price", None),
        "new_stop_price": args.stop_price,
        "updated_stop_leg": dump_json(updated_stop),
    }

    print(json.dumps(out, indent=2, default=str))


if __name__ == "__main__":
    main()
