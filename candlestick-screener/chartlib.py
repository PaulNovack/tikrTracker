import pandas as pd

from db_config import get_engine


def is_consolidating(df: pd.DataFrame, percentage: float = 2) -> bool:
    recent_candlesticks = df[-15:]

    max_close = recent_candlesticks['close'].max()
    min_close = recent_candlesticks['close'].min()

    threshold = 1 - (percentage / 100)
    if min_close > (max_close * threshold):
        return True

    return False


def is_breaking_out(df: pd.DataFrame, percentage: float = 2.5) -> bool:
    last_close = df[-1:]['close'].values[0]

    if is_consolidating(df[:-1], percentage=percentage):
        recent_closes = df[-16:-1]

        if last_close > recent_closes['close'].max():
            return True

    return False


if __name__ == '__main__':
    engine = get_engine()

    symbols_df = pd.read_sql(
        "SELECT DISTINCT symbol FROM daily_prices WHERE asset_type='stock' ORDER BY symbol",
        engine,
    )

    for _, row in symbols_df.iterrows():
        symbol = row['symbol']
        df = pd.read_sql(
            "SELECT date, open, high, low, price AS close, volume "
            "FROM daily_prices WHERE symbol = %(symbol)s ORDER BY date ASC",
            engine,
            params={'symbol': symbol},
        )

        if df.empty or len(df) < 16:
            continue

        if is_consolidating(df, percentage=2.5):
            print(f"{symbol} is consolidating")

        if is_breaking_out(df):
            print(f"{symbol} is breaking out")