#!/usr/bin/env bash
# Place a buy order via the External Buy API.
# Reads the API token from .env.

set -euo pipefail

PROJECT_ROOT=/var/www/html/laravel-invest
TOKEN=$(grep '^EXTERNAL_BUY_API_TOKEN=' "$PROJECT_ROOT/.env" | head -1 | cut -d= -f2-)

if [ -z "$TOKEN" ]; then
    echo "Error: EXTERNAL_BUY_API_TOKEN not found in .env" >&2
    exit 1
fi

APP_URL="${APP_URL:-http://127.0.0.1:8080}"

echo "Placing buy order for 1 share of AAPL..."
curl -s -X POST "${APP_URL}/api/external/buy?token=${TOKEN}" \
    -H 'Content-Type: application/json' \
    -d '{
        "symbol": "AAPL",
        "shares": 1,
        "entry_price": 220.00
    }' | python3 -m json.tool 2>/dev/null || cat
echo
