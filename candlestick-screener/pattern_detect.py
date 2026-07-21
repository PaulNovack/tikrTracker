import pandas as pd
import talib

from db_config import get_engine

SYMBOL = "SPY"

engine = get_engine()
df = pd.read_sql(
    "SELECT date, open, high, low, price AS close, volume "
    "FROM daily_prices WHERE symbol = %(symbol)s ORDER BY date ASC",
    engine,
    params={'symbol': SYMBOL},
)

morning_star = talib.CDLMORNINGSTAR(df['open'], df['high'], df['low'], df['close'])

engulfing = talib.CDLENGULFING(df['open'], df['high'], df['low'], df['close'])

df['Morning Star'] = morning_star
df['Engulfing'] = engulfing

engulfing_days = df[df['Engulfing'] != 0]

print(engulfing_days)