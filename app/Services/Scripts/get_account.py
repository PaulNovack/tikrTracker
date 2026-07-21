#!/usr/bin/env python3
"""
Get Alpaca account information including shorting settings
"""
import json
import os
from alpaca.trading.client import TradingClient

def get_account():
    """Get account configuration and settings"""
    api_key = os.environ.get('ALPACA_API_KEY')
    api_secret = os.environ.get('ALPACA_API_SECRET')
    paper = os.environ.get('ALPACA_PAPER_TRADING', 'true').lower() == 'true'
    
    client = TradingClient(api_key, api_secret, paper=paper)
    
    account = client.get_account()
    
    return {
        'account_number': account.account_number,
        'status': account.status,
        'buying_power': float(account.buying_power),
        'cash': float(account.cash),
        'portfolio_value': float(account.portfolio_value),
        'equity': float(account.equity),
        'shorting_enabled': account.shorting_enabled,
        'trading_blocked': account.trading_blocked,
        'transfers_blocked': account.transfers_blocked,
        'account_blocked': account.account_blocked,
        'pattern_day_trader': account.pattern_day_trader,
    }

if __name__ == '__main__':
    try:
        account_data = get_account()
        print(json.dumps(account_data, indent=2))
    except Exception as e:
        print(json.dumps({'error': str(e)}))
        exit(1)
