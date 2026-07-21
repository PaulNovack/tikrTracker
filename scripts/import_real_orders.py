#!/usr/bin/env python3
"""Import real Alpaca orders directly into MySQL."""
import mysql.connector
import os
from datetime import datetime
from decimal import Decimal, InvalidOperation

def parse_dec(s):
    s = s.replace(',', '').replace('$', '').strip()
    if s == '' or s == '-':
        return None
    try: return str(Decimal(s))
    except InvalidOperation: return None

def parse_ts(s):
    s = s.strip()
    if not s or s == '-': return None
    for fmt in ('%b %d, %Y, %I:%M:%S %p', '%b %d, %Y, %I:%M %p'):
        try: return datetime.strptime(s, fmt).strftime('%Y-%m-%d %H:%M:%S')
        except ValueError: continue
    return None

env = {}
with open('.env') as f:
    for line in f:
        line = line.strip()
        if not line or line.startswith('#'): continue
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
cur = db.cursor()

# Read TSV from stdin
import sys
header = sys.stdin.readline()  # skip header
rows = []; count = 0
for line in sys.stdin:
    line = line.rstrip('\n\r')
    if not line: continue
    cols = line.split('\t')
    if len(cols) < 14: continue
    try:
        r = (
            cols[0], cols[1], cols[2], cols[3], cols[4],
            parse_dec(cols[5]), parse_dec(cols[6]), cols[7] if len(cols)>7 else 'USD',
            parse_dec(cols[8]), parse_dec(cols[9]), parse_dec(cols[10]),
            parse_dec(cols[11]), cols[12], cols[13] if len(cols)>13 else None,
            parse_ts(cols[14]) if len(cols)>14 else None,
            parse_ts(cols[15]) if len(cols)>15 else None,
            parse_ts(cols[16]) if len(cols)>16 else None,
        )
        rows.append(r); count += 1
        if len(rows) >= 100:
            cur.executemany("""INSERT INTO real_alpaca_orders
                (id,symbol,order_description,type,side,qty,filled_qty,currency,
                avg_fill_price,limit_price,stop_price,total_amount,status,source,
                submitted_at,filled_at,expires_at)
                VALUES (%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s)""", rows)
            db.commit(); rows = []
    except Exception as e:
        print(f"ERR line {count}: {e}", file=sys.stderr)

if rows:
    cur.executemany("""INSERT INTO real_alpaca_orders
        (id,symbol,order_description,type,side,qty,filled_qty,currency,
        avg_fill_price,limit_price,stop_price,total_amount,status,source,
        submitted_at,filled_at,expires_at)
        VALUES (%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s)""", rows)
    db.commit()
cur.close(); db.close()
print(f"{count} rows", file=sys.stderr)
