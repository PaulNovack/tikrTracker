import os
import json
from dotenv import load_dotenv
from alpaca.trading.client import TradingClient

load_dotenv(dotenv_path=os.path.join(os.path.dirname(__file__), "..", ".env"))

api_key = os.getenv("ALPACA_KEY_ID")
api_secret = os.getenv("ALPACA_SECRET_KEY")
if not api_key or not api_secret:
    raise ValueError("ALPACA_KEY_ID and ALPACA_SECRET_KEY must be set in .env file")

# Read paper trading setting from .env
paper_trading = os.getenv('ALPACA_PAPER_TRADING', 'True').lower() in ('true', '1', 'yes')
trading_client = TradingClient(api_key, api_secret, paper=paper_trading)

positions = trading_client.get_all_positions()  # <-- capture the result

# Each position is a pydantic model in newer SDK versions
print(json.dumps([p.model_dump() for p in positions], indent=2, default=str))
