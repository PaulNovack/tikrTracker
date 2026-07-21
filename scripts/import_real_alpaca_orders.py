#!/usr/bin/env python3
"""Import real Alpaca orders TSV from stdin into MySQL."""
import sys
import re
from datetime import datetime
from decimal import Decimal, InvalidOperation
import mysql.connector
import os

# Read DB config from .env
env = {}
with open('.env') as f:
    for line in f:
        line = line.strip()
        if not line or line.startswith('#'):
            continue
        if '=' in line:
            k, v = line.split('=', 1)
            env[k.strip()] = v.strip()

db = mysql.connector.connect(
    host=env.get('DB_HOST', '127.0.0.1'),
    port=int(env.get('DB_PORT', 3306)),
    user=env.get('DB_USERNAME', 'root'),
    password=env.get('DB_PASSWORD', ''),
    database=env.get('DB_DATABASE', 'laravel_invest'),
    charset='utf8mb4',
)
cursor = db.cursor()

def parse_decimal(s):
    s = s.replace(',', '').replace('$', '').strip()
    if s == '' or s == '-':
        return None
    try:
        return str(Decimal(s))
    except InvalidOperation:
        return None

def parse_ts(s):
    s = s.strip()
    if not s or s == '-':
        return None
    for fmt in ('%b %d, %Y, %I:%M:%S %p', '%b %d, %Y, %I:%M %p'):
        try:
            return datetime.strptime(s, fmt).strftime('%Y-%m-%d %H:%M:%S')
        except ValueError:
            continue
    return None

# Skip header line
header = sys.stdin.readline()

rows = []
count = 0
for line in sys.stdin:
    line = line.rstrip('\n').rstrip('\r')
    if not line:
        continue
    cols = line.split('\t')
    if len(cols) < 14:
        continue

    # Strip extra blank cols at end (Alpaca CSV has trailing empty columns)
    cols = [c for c in cols if c.strip() != '']

    try:
        row = (
            cols[0],                        # id
            cols[1],                        # symbol
            cols[2] if len(cols) > 2 else None,  # order_description
            cols[3] if len(cols) > 3 else None,  # type
            cols[4] if len(cols) > 4 else None,  # side
            parse_decimal(cols[5]) if len(cols) > 5 else None,  # qty
            parse_decimal(cols[6]) if len(cols) > 6 else None,  # filled_qty
            cols[7] if len(cols) > 7 else 'USD',  # currency
            parse_decimal(cols[8]) if len(cols) > 8 else None,  # avg_fill_price
            parse_decimal(cols[9]) if len(cols) > 9 else None,  # limit_price
            parse_decimal(cols[10]) if len(cols) > 10 else None,  # stop_price
            parse_decimal(cols[11]) if len(cols) > 11 else None,  # total_amount
            cols[12] if len(cols) > 12 else None,  # status
            cols[13] if len(cols) > 13 else None,  # source
            parse_ts(cols[14]) if len(cols) > 14 else None,  # submitted_at
            parse_ts(cols[15]) if len(cols) > 15 else None,  # filled_at
            parse_ts(cols[16]) if len(cols) > 16 else None,  # expires_at
        )
        rows.append(row)
        count += 1

        if len(rows) >= 100:
            cursor.executemany(
                """INSERT INTO real_alpaca_orders
                (id, symbol, order_description, type, side, qty, filled_qty,
                 currency, avg_fill_price, limit_price, stop_price, total_amount,
                 status, source, submitted_at, filled_at, expires_at)
                VALUES (%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s)""",
                rows
            )
            db.commit()
            rows = []

    except Exception as e:
        print(f"Error on line: {cols[0] if cols else '?'}: {e}", file=sys.stderr)

if rows:
    cursor.executemany(
        """INSERT INTO real_alpaca_orders
        (id, symbol, order_description, type, side, qty, filled_qty,
         currency, avg_fill_price, limit_price, stop_price, total_amount,
         status, source, submitted_at, filled_at, expires_at)
        VALUES (%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s)""",
        rows
    )
    db.commit()

cursor.close()
db.close()
print(f"Inserted {count} rows into real_alpaca_orders.", file=sys.stderr)
