import logging
import os
from datetime import datetime

import pandas as pd
import talib
from flask import Flask, request, render_template, jsonify

from db_config import get_engine
from patterns import candlestick_patterns
from redis_cache import cached

app = Flask(__name__)
engine = get_engine()

# ── Logging ──
log_dir = os.path.join(os.path.dirname(os.path.dirname(os.path.abspath(__file__))), 'storage', 'logs')
os.makedirs(log_dir, exist_ok=True)
log_file = os.path.join(log_dir, f'flask-screener-{datetime.now().strftime("%Y-%m-%d")}.log')

logging.basicConfig(
    level=logging.INFO,
    format='%(asctime)s [%(levelname)s] %(message)s',
    handlers=[
        logging.FileHandler(log_file),
        logging.StreamHandler(),
    ],
)
logger = logging.getLogger(__name__)

MAX_SYMBOLS = 750


@cached(ttl_seconds=28800)
def get_all_symbols() -> list[str]:
    """Get symbols from intraday_universe, ordered by quality score."""
    df = pd.read_sql(
        "SELECT symbol FROM intraday_universe "
        "WHERE asset_type='stock' ORDER BY universe_score DESC LIMIT %(limit)s",
        engine,
        params={'limit': MAX_SYMBOLS},
    )
    return df['symbol'].tolist()


@cached(ttl_seconds=28800)
def get_ohlc_data(symbol: str) -> pd.DataFrame:
    return pd.read_sql(
        "SELECT date, open, high, low, price AS close, volume "
        "FROM daily_prices WHERE symbol = %(symbol)s ORDER BY date ASC",
        engine,
        params={'symbol': symbol},
    )


@cached(ttl_seconds=3600)
def get_intraday_ohlc_data(symbol: str) -> pd.DataFrame:
    """Get 5-minute OHLC bars aggregated from five_minute_prices for the last 24 hours."""
    return pd.read_sql(
        "SELECT "
        "  DATE_FORMAT(ts, '%%Y-%%m-%%d %%H:%%i') AS date, "
        "  open, high, low, price AS close, volume "
        "FROM five_minute_prices "
        "WHERE symbol = %(symbol)s "
        "  AND ts >= (SELECT MAX(ts) FROM five_minute_prices WHERE symbol = %(symbol)s) - INTERVAL 1 DAY "
        "ORDER BY ts ASC",
        engine,
        params={'symbol': symbol},
    )




def scan_symbols(pattern: str, limit: int, data_fn: callable) -> list[dict]:
    """Run TA-Lib pattern scan across symbols using the provided data function."""
    results = []
    symbols = get_all_symbols()[:limit]

    # Pre-fetch asset_id mapping for all symbols
    asset_ids = {}
    if symbols:
        quoted_ids = ','.join(f"'{s}'" for s in symbols)
        id_df = pd.read_sql(
            f"SELECT symbol, id FROM asset_info WHERE symbol IN ({quoted_ids})",
            engine,
        )
        asset_ids = dict(zip(id_df['symbol'], id_df['id']))

    for symbol in symbols:
        try:
            df = data_fn(symbol)
            if df.empty or len(df) < 10:
                continue

            pattern_function = getattr(talib, pattern)
            pattern_results = pattern_function(
                df['open'], df['high'], df['low'], df['close']
            )
            last = int(pattern_results.tail(1).values[0])

            if last != 0:
                results.append({
                    'symbol': symbol,
                    'asset_id': asset_ids.get(symbol),
                    'signal': 'bullish' if last > 0 else 'bearish',
                    'signal_value': last,
                    'last_date': str(df['date'].iloc[-1]),
                    'ohlc': df[['date', 'open', 'high', 'low', 'close', 'volume']]
                        .tail(60)
                        .assign(date=lambda x: x['date'].astype(str))
                        .to_dict(orient='records'),
                })
        except Exception as e:
            logger.error(f'failed on symbol: {symbol} - {e}')

    return results


@app.route('/snapshot')
def snapshot():
    return {'code': 'success', 'message': 'Data is now sourced from the daily_prices database table'}


@app.route('/api/scan')
def api_scan():
    pattern = request.args.get('pattern', '')
    limit = int(request.args.get('limit', 750))

    if not pattern or pattern not in candlestick_patterns:
        return jsonify({'error': 'Invalid pattern', 'available': list(candlestick_patterns.keys())}), 400

    results = scan_symbols(pattern, limit, get_ohlc_data)

    return jsonify({
        'pattern': pattern,
        'pattern_name': candlestick_patterns.get(pattern, ''),
        'total_scanned': min(len(get_all_symbols()), limit),
        'hits': len(results),
        'results': results,
    })


@app.route('/api/scan-intraday')
def api_scan_intraday():
    pattern = request.args.get('pattern', '')
    limit = int(request.args.get('limit', 750))

    if not pattern or pattern not in candlestick_patterns:
        return jsonify({'error': 'Invalid pattern', 'available': list(candlestick_patterns.keys())}), 400

    results = scan_symbols(pattern, limit, get_intraday_ohlc_data)

    return jsonify({
        'pattern': pattern,
        'pattern_name': candlestick_patterns.get(pattern, ''),
        'total_scanned': min(len(get_all_symbols()), limit),
        'hits': len(results),
        'results': results,
    })


@app.route('/api/patterns')
def api_patterns():
    return jsonify(candlestick_patterns)


@app.route('/')
def index():
    pattern = request.args.get('pattern', False)
    stocks = {}

    symbols = get_all_symbols()
    for symbol in symbols:
        stocks[symbol] = {'company': symbol}

    if pattern:
        for symbol in symbols:
            try:
                df = get_ohlc_data(symbol)
                if df.empty or len(df) < 10:
                    continue

                pattern_function = getattr(talib, pattern)
                results = pattern_function(
                    df['open'], df['high'], df['low'], df['close']
                )
                last = results.tail(1).values[0]

                if last > 0:
                    stocks[symbol][pattern] = 'bullish'
                elif last < 0:
                    stocks[symbol][pattern] = 'bearish'
                else:
                    stocks[symbol][pattern] = None
            except Exception as e:
                logger.error(f'failed on symbol: {symbol} - {e}')

    return render_template(
        'index.html',
        candlestick_patterns=candlestick_patterns,
        stocks=stocks,
        pattern=pattern,
    )


if __name__ == '__main__':
    app.run(debug=True, host='0.0.0.0', port=5000)
