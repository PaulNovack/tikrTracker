import pandas as pd
import talib
from flask import Flask, request, render_template, jsonify

from db_config import get_engine
from patterns import candlestick_patterns
from redis_cache import cached

app = Flask(__name__)
engine = get_engine()

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
                    'signal': 'bullish' if last > 0 else 'bearish',
                    'signal_value': last,
                    'last_date': str(df['date'].iloc[-1]),
                    'ohlc': df[['date', 'open', 'high', 'low', 'close', 'volume']]
                        .tail(60)
                        .assign(date=lambda x: x['date'].astype(str))
                        .to_dict(orient='records'),
                })
        except Exception as e:
            print(f'failed on symbol: {symbol}', e)

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


@app.route('/api/scan-valid-entry')
def api_scan_valid_entry():
    limit = int(request.args.get('limit', 750))

    symbols = get_all_symbols()[:limit]
    quoted = ','.join(f"'{s}'" for s in symbols)

    # Single query: get the latest 5-minute bar and the latest 1-minute bar per symbol
    # using a join on latest timestamps — much faster than window functions
    sql = (
        "SELECT "
        "  f.symbol, "
        "  f.ts AS ts_5m, f.open AS open_5m, f.high AS high_5m, f.low AS low_5m, "
        "  f.price AS close_5m, f.volume AS vol_5m, "
        "  o.ts AS ts_1m, o.open AS open_1m, o.high AS high_1m, o.low AS low_1m, "
        "  o.price AS close_1m, o.volume AS vol_1m, "
        "  o.vwap, o.ema9, o.ema21 "
        "FROM five_minute_prices f "
        "JOIN one_minute_prices_full o ON o.symbol = f.symbol "
        "JOIN ("
        "  SELECT symbol, MAX(ts) AS max_ts "
        "  FROM five_minute_prices "
        f"  WHERE symbol IN ({quoted}) "
        "  GROUP BY symbol"
        ") f_max ON f.symbol = f_max.symbol AND f.ts = f_max.max_ts "
        "JOIN ("
        "  SELECT symbol, MAX(ts) AS max_ts "
        "  FROM one_minute_prices_full "
        f"  WHERE symbol IN ({quoted}) "
        "  GROUP BY symbol"
        ") o_max ON o.symbol = o_max.symbol AND o.ts = o_max.max_ts"
    )
    df_latest = pd.read_sql(sql, engine)

    results = []

    for _, row in df_latest.iterrows():
        symbol = row['symbol']
        try:
            # Step 1: Get context bars — 60 five-minute bars before the latest
            df_5m = pd.read_sql(
                "SELECT open, high, low, price AS close, volume, "
                "  DATE_FORMAT(ts, '%%Y-%%m-%%d %%H:%%i') AS date "
                "FROM five_minute_prices "
                "WHERE symbol = %(symbol)s AND ts <= %(max_ts)s "
                "ORDER BY ts DESC LIMIT 60",
                engine,
                params={'symbol': symbol, 'max_ts': str(row['ts_5m'])},
            )
            if len(df_5m) < 10:
                continue
            df_5m = df_5m.iloc[::-1].reset_index(drop=True)  # reverse to ascending

            # Step 2: Detect engulfing
            engulfing_5m = talib.CDLENGULFING(
                df_5m['open'], df_5m['high'], df_5m['low'], df_5m['close']
            )
            if int(engulfing_5m.iloc[-1]) <= 0:
                continue

            engulfing_high = float(df_5m['high'].iloc[-1])

            # Step 3: Get 21 one-minute context bars
            df_1m = pd.read_sql(
                "SELECT open, high, low, price AS close, volume, "
                "  vwap, ema9, ema21 "
                "FROM one_minute_prices_full "
                "WHERE symbol = %(symbol)s AND ts <= %(max_ts)s "
                "ORDER BY ts DESC LIMIT 21",
                engine,
                params={'symbol': symbol, 'max_ts': str(row['ts_1m'])},
            )
            if len(df_1m) < 21:
                continue
            df_1m = df_1m.iloc[::-1].reset_index(drop=True)

            latest = df_1m.iloc[-1]
            vol_avg = df_1m['volume'].iloc[:-1].mean()
            vol_ratio = float(latest['volume']) / max(float(vol_avg), 1)

            close_val = float(latest['close'])
            vwap_val = float(latest.get('vwap', 0) or 0)
            ema9_val = float(latest.get('ema9', 0) or 0)
            ema21_val = float(latest.get('ema21', 0) or 0)

            if (close_val > engulfing_high and vol_ratio >= 1.5
                    and (vwap_val == 0 or close_val > vwap_val)
                    and (ema9_val == 0 or ema9_val > ema21_val)):
                results.append({
                    'symbol': symbol,
                    'signal': 'bullish',
                    'signal_value': 100,
                    'last_date': str(row['ts_5m']),
                    'engulfing_high': engulfing_high,
                    'engulfing_low': float(row['low_5m']),
                    'engulfing_close': float(row['close_5m']),
                    'volume_ratio': round(vol_ratio, 2),
                    'entry_price': close_val,
                    'ohlc': df_5m[['date', 'open', 'high', 'low', 'close', 'volume']]
                        .tail(60)
                        .assign(date=lambda x: x['date'].astype(str))
                        .to_dict(orient='records'),
                })
        except Exception as e:
            print(f'valid-entry failed on {symbol}: {e}')

    return jsonify({
        'total_scanned': len(symbols),
        'hits': len(results),
        'results': sorted(results, key=lambda r: r['volume_ratio'], reverse=True),
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
                print(f'failed on symbol: {symbol}', e)

    return render_template(
        'index.html',
        candlestick_patterns=candlestick_patterns,
        stocks=stocks,
        pattern=pattern,
    )


if __name__ == '__main__':
    app.run(debug=True)
