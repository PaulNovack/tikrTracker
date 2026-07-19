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

    # Fetch the last 60 five-minute bars for all symbols
    sql_5m = (
        "SELECT symbol, ts, open, high, low, price AS close, volume "
        "FROM ("
        "  SELECT symbol, ts, open, high, low, price, volume, "
        "    ROW_NUMBER() OVER (PARTITION BY symbol ORDER BY ts DESC) AS rn "
        "  FROM five_minute_prices "
        f"  WHERE symbol IN ({quoted})"
        ") AS ranked "
        "WHERE rn <= 60 "
        "ORDER BY symbol, ts ASC"
    )
    df_5m_all = pd.read_sql(sql_5m, engine)
    df_5m_all['date'] = df_5m_all['ts'].dt.strftime('%Y-%m-%d %H:%M')
    df_5m_all = df_5m_all.drop(columns=['ts'])

    # Fetch the last 120 one-minute bars for all symbols
    sql_1m = (
        "SELECT symbol, ts, open, high, low, price AS close, volume, "
        "  vwap, ema9, ema21 "
        "FROM ("
        "  SELECT symbol, ts, open, high, low, price, volume, vwap, ema9, ema21, "
        "    ROW_NUMBER() OVER (PARTITION BY symbol ORDER BY ts DESC) AS rn "
        "  FROM one_minute_prices_full "
        f"  WHERE symbol IN ({quoted})"
        ") AS ranked "
        "WHERE rn <= 120 "
        "ORDER BY symbol, ts ASC"
    )
    df_1m_all = pd.read_sql(sql_1m, engine)
    df_1m_all['date'] = df_1m_all['ts'].dt.strftime('%Y-%m-%d %H:%M')
    df_1m_all = df_1m_all.drop(columns=['ts'])

    results = []

    for symbol in symbols:
        df_5m = df_5m_all[df_5m_all['symbol'] == symbol].drop(columns=['symbol'])
        df_1m = df_1m_all[df_1m_all['symbol'] == symbol].drop(columns=['symbol'])

        if df_5m.empty or len(df_5m) < 10 or df_1m.empty or len(df_1m) < 21:
            continue

        try:
            # Step 1: Detect engulfing on completed five-minute bars
            engulfing_5m = talib.CDLENGULFING(
                df_5m['open'], df_5m['high'], df_5m['low'], df_5m['close']
            )
            bullish_engulfing_5m = int(engulfing_5m.iloc[-1]) > 0

            if not bullish_engulfing_5m:
                continue

            # Step 2: Save the five-minute pattern levels
            engulfing_high = float(df_5m['high'].iloc[-1])
            engulfing_low = float(df_5m['low'].iloc[-1])
            engulfing_close = float(df_5m['close'].iloc[-1])

            # Step 3: Use one-minute bars to confirm entry
            latest_1m = df_1m.iloc[-1]
            volume_col = df_1m.columns[-6] if df_1m.shape[1] >= 6 else df_1m.columns[-1]

            volume_average_20 = df_1m['volume'].iloc[-21:-1].mean()
            volume_ratio = float(latest_1m['volume']) / max(float(volume_average_20), 1)

            vwap_val = float(latest_1m.get('vwap', 0))
            ema9_val = float(latest_1m.get('ema9', 0))
            ema21_val = float(latest_1m.get('ema21', 0))
            close_val = float(latest_1m['close'])

            valid_entry = (
                close_val > engulfing_high
                and (vwap_val == 0 or close_val > vwap_val)
                and (ema9_val == 0 or ema9_val > ema21_val)
                and volume_ratio >= 1.5
            )

            if valid_entry:
                results.append({
                    'symbol': symbol,
                    'signal': 'bullish',
                    'signal_value': 100,
                    'last_date': str(df_5m['date'].iloc[-1]),
                    'engulfing_high': engulfing_high,
                    'engulfing_low': engulfing_low,
                    'engulfing_close': engulfing_close,
                    'volume_ratio': round(volume_ratio, 2),
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
