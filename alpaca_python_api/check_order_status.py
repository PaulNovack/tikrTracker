import argparse
import json
import os
from pathlib import Path
from dotenv import load_dotenv
from alpaca.trading.client import TradingClient


def parse_args():
    p = argparse.ArgumentParser(description="Check Alpaca order status")
    p.add_argument("--order-id", required=True, help="Alpaca order ID to check")
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

    # Get order status
    order = trading_client.get_order_by_id(order_id=args.order_id)

    # Return order details as JSON
    result = {
        "success": True,
        "order": dump_json(order),
    }
    
    print(json.dumps(result, indent=2, default=str))


if __name__ == "__main__":
    main()
