#!/usr/bin/env python3
import os
import sys
import json
import argparse
from dotenv import load_dotenv

from alpaca.trading.client import TradingClient
from alpaca.trading.requests import (
    LimitOrderRequest,
    MarketOrderRequest,
    StopLossRequest,
    TakeProfitRequest,
    StopOrderRequest,
)
from alpaca.trading.enums import OrderSide, TimeInForce, OrderClass


"""
CLI Order Script (Alpaca Trading API)

Required arguments:
  --symbol   e.g. AAPL
  --qty      e.g. 1 or 0.023
  --side     buy|sell

Side options (Alpaca OrderSide enum):
  - buy
  - sell

Stop loss options:

A) Attached stop loss (BRACKET order) (recommended)
   Provide --stop-price and the script will submit the ENTRY + attached stop-loss exit leg.

   Example:
     python order_cli.py --symbol AAPL --qty 1 --side buy --stop-price 185.00

B) Standalone stop order (STOP order)
   Use --stop-only to submit only a stop order (no entry).
   Commonly used after you already hold a position.

   Example (protect a long position):
     python order_cli.py --symbol AAPL --qty 1 --side sell --stop-price 185.00 --stop-only

Notes:
- For a LONG position stop loss, you typically place a SELL stop.
- For a SHORT position stop loss, you typically place a BUY stop.
- If you attach a stop loss via BRACKET on the entry, Alpaca will create the exit leg appropriately.
"""


def parse_args():
    p = argparse.ArgumentParser(description="Submit market orders and optional stop-loss via Alpaca.")
    p.add_argument("--symbol", required=True, help="Ticker symbol (e.g., AAPL, SPY)")
    p.add_argument("--qty", required=True, type=float, help="Quantity of shares (e.g., 1 or 0.023)")
    p.add_argument(
        "--side",
        required=True,
        choices=["buy", "sell"],
        help="Order side. Allowed values: buy, sell",
    )

    # Stop loss args
    p.add_argument(
        "--stop-price",
        type=float,
        default=None,
        help="Stop loss trigger price. If provided, will attach stop-loss (bracket) unless --stop-only is set.",
    )
    p.add_argument(
        "--stop-limit",
        type=float,
        default=None,
        help="Optional. If provided WITH --stop-price, creates a stop-limit style stop-loss (limit_price).",
    )
    p.add_argument(
        "--take-profit",
        type=float,
        default=None,
        help="Take profit price for bracket orders. Required when using --stop-price for bracket orders.",
    )
    p.add_argument(
        "--limit-price",
        type=float,
        default=None,
        help="Entry limit price. If provided, submits a LIMIT order instead of MARKET order.",
    )
    p.add_argument(
        "--stop-only",
        action="store_true",
        help="Submit ONLY a standalone stop order (no entry). Useful if you already have a position.",
    )
    p.add_argument(
        "--fractional",
        action="store_true",
        help="Allow fractional shares (default is whole shares only). Use for end-of-day liquidation.",
    )

    # Paper/live
    p.add_argument(
        "--live",
        action="store_true",
        help="Use live trading (default is paper trading).",
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


def check_buying_power(trading_client, symbol: str, qty: float, side: str, limit_price: float | None):
    """
    Fetch live account info from Alpaca and verify sufficient buying power
    BEFORE submitting the order. Returns (ok: bool, message: str, details: dict).
    """
    try:
        account = trading_client.get_account()
        buying_power = float(account.buying_power)

        # Estimate cost: for buy orders, use limit_price if available, otherwise
        # fetch the latest trade price for the symbol from the API.
        if side == "buy":
            if limit_price:
                ref_price = limit_price
            else:
                # Fetch latest trade to get an accurate price for this symbol
                try:
                    asset = trading_client.get_latest_trade(symbol)
                    ref_price = float(asset.price) if asset.price else float(account.last_equity)
                except Exception:
                    ref_price = float(account.last_equity)
            est_cost = qty * ref_price
        else:
            est_cost = 0  # sells don't consume buying power

        details = {
            "buying_power": round(buying_power, 2),
            "estimated_cost": round(est_cost, 2),
            "qty": qty,
            "ref_price": ref_price if side == "buy" else None,
        }

        if side == "buy" and buying_power < est_cost:
            return (False,
                f"insufficient buying power: need ${est_cost:,.2f}, have ${buying_power:,.2f}",
                details)

        return (True, "", details)

    except Exception as exc:
        return (False, f"failed to fetch account info: {exc}", {})


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

    # --- BUYING POWER PRE-CHECK (before any order submission) ---
    if args.side == "buy":
        ok, msg, bp_details = check_buying_power(
            trading_client, args.symbol, args.qty, args.side,
            args.limit_price if hasattr(args, 'limit_price') else None
        )
        if not ok:
            out = {
                "mode": "entry_rejected",
                "status": "rejected",
                "reason": "insufficient_buying_power",
                "message": msg,
                "details": bp_details,
            }
            print(json.dumps(out, indent=2, default=str))
            sys.exit(1)

    side_enum = OrderSide.BUY if args.side == "buy" else OrderSide.SELL

    # --- MODE B: Stop-only (standalone stop order) ---
    if args.stop_only:
        if args.stop_price is None:
            raise ValueError("--stop-only requires --stop-price")

        # Convert to whole shares unless --fractional flag is set
        if not args.fractional:
            args.qty = int(float(args.qty))

        # Fractional orders must use DAY time-in-force, whole shares use GTC
        time_in_force = TimeInForce.DAY if args.fractional else TimeInForce.GTC

        stop_req = StopOrderRequest(
            symbol=args.symbol.upper(),
            qty=args.qty,
            side=side_enum,
            time_in_force=time_in_force,
            stop_price=args.stop_price,
        )

        stop_order = trading_client.submit_order(order_data=stop_req)

        out = {
            "mode": "stop_only",
            "order": dump_json(stop_order),  # Changed key to "order" for consistency
        }
        print(json.dumps(out, indent=2, default=str))
        return

    # --- MODE A: Entry order (optionally with attached stop-loss via BRACKET) ---
    # Convert to whole shares unless --fractional flag is set
    if not args.fractional:
        args.qty = int(float(args.qty))

    # Fractional orders must use DAY time-in-force, whole shares use GTC
    time_in_force = TimeInForce.DAY if args.fractional else TimeInForce.GTC

    # --- MODE C: Limit entry order (no attached stop - stop placed separately after fill) ---
    if args.limit_price is not None and args.stop_price is None:
        order_req = LimitOrderRequest(
            symbol=args.symbol.upper(),
            qty=args.qty,
            side=side_enum,
            time_in_force=TimeInForce.DAY,
            limit_price=args.limit_price,
        )
        order = trading_client.submit_order(order_data=order_req)
        out = {
            "mode": "entry_limit",
            "order": dump_json(order),
        }
        print(json.dumps(out, indent=2, default=str))
        return

    if args.stop_price is not None:
        # Attach stop loss as a bracket order to the entry.
        # Bracket orders require take_profit
        if args.take_profit is None:
            raise ValueError("--stop-price requires --take-profit for bracket orders")
            
        # (Optional stop-limit style: include limit_price)
        stop_loss = StopLossRequest(
            stop_price=args.stop_price,
            limit_price=args.stop_limit,
        )
        
        take_profit = TakeProfitRequest(
            limit_price=args.take_profit,
        )

        order_req = MarketOrderRequest(
            symbol=args.symbol.upper(),
            qty=args.qty,
            side=side_enum,
            time_in_force=time_in_force,
            order_class=OrderClass.BRACKET,
            stop_loss=stop_loss,
            take_profit=take_profit,
        )
    else:
        # Plain market order
        order_req = MarketOrderRequest(
            symbol=args.symbol.upper(),
            qty=args.qty,
            side=side_enum,
            time_in_force=time_in_force,
        )

    order = trading_client.submit_order(order_data=order_req)

    out = {
        "mode": "entry" if args.stop_price is None else "entry_with_attached_stop_loss",
        "order": dump_json(order),
    }
    print(json.dumps(out, indent=2, default=str))


if __name__ == "__main__":
    main()
