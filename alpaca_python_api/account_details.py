import os
import json
from dotenv import load_dotenv
from alpaca.trading.client import TradingClient
from alpaca.trading.requests import MarketOrderRequest
from alpaca.trading.enums import OrderSide, TimeInForce

# Load environment variables from .env in parent directory
load_dotenv(dotenv_path=os.path.join(os.path.dirname(__file__), '..', '.env'))

# Get credentials from environment variables
api_key = os.getenv('ALPACA_KEY_ID')
api_secret = os.getenv('ALPACA_SECRET_KEY')

if not api_key or not api_secret:
    raise ValueError("ALPACA_API_KEY and ALPACA_API_SECRET must be set in .env file")

# Read paper trading setting from .env
paper_trading = os.getenv('ALPACA_PAPER_TRADING', 'True').lower() in ('true', '1', 'yes')
trading_client = TradingClient(api_key, api_secret, paper=paper_trading)

account = trading_client.get_account()

# simplest
#print(account)

# nicer (if the object supports it)
try:
    print(json.dumps(account.model_dump(), indent=2, default=str))
except Exception:
    # fallback if .dict() isn't available on your version
    try:
        print(json.dumps(account.__dict__, indent=2, default=str))
    except Exception:
        print(repr(account))
