# External Application Buy API

A simple HTTP API to place buy orders on Alpaca from external applications. Authenticated via a token set in `.env`.

## Endpoint

```
POST /api/external/buy?token={API_TOKEN}
```

## Authentication

Pass your API token as a query parameter `token`. The token is configured in `.env`:

```env
EXTERNAL_BUY_API_TOKEN=your-secret-token-here
```

## Request

### Headers

```
Content-Type: application/json
```

### Body (JSON)

| Field | Type | Required | Description |
|---|---|---|---|
| `symbol` | `string` | ✅ | Stock ticker (e.g. `AAPL`, `NVDA`) |
| `shares` | `int` | ✅ | Number of shares to buy |
| `entry_price` | `float` | ✅ | Limit price for the entry order |
| `stop_price` | `float` | ❌ | Override for ATR-calculated stop loss |
| `notes` | `string` | ❌ | Custom notes attached to the order |

## Example Usage

### cURL

```bash
curl -X POST 'http://127.0.0.1:8080/api/external/buy?token=my-secret-token' \
  -H 'Content-Type: application/json' \
  -d '{
    "symbol": "AAPL",
    "shares": 10,
    "entry_price": 150.00
  }'
```

### Python

```python
import requests

resp = requests.post(
    'http://127.0.0.1:8080/api/external/buy',
    params={'token': 'my-secret-token'},
    json={'symbol': 'AAPL', 'shares': 10, 'entry_price': 150.00},
)
print(resp.json())
```

## Responses

### 200 — Success

```json
{
    "success": true,
    "alert_id": 12345,
    "alpaca_order_id": "904837e3-3b76-47e8-9aac-7cb1ce476fa5",
    "symbol": "AAPL",
    "shares": 10,
    "entry_price": 150.00,
    "stop_price": 148.50,
    "total_cost": 1500.00,
    "message": "Buy order placed for 10 shares of AAPL"
}
```

### 400 — Validation Error

```json
{
    "error": "Symbol is required"
}
```

### 401 — Unauthorized

```json
{
    "error": "Invalid or missing API token"
}
```

### 409 — Duplicate Alert

```json
{
    "error": "Duplicate alert exists for AAPL at ..."
}
```

## What Happens Behind the Scenes

1. **Token validation** — checks `?token=` against `EXTERNAL_BUY_API_TOKEN` in `.env`
2. **Liquidity check** — verifies recent 1-minute dollar volume meets minimum threshold
3. **Position sizing** — caps shares to max position size setting
4. **Stop loss calculation** — ATR-based (2x multiplier, 1–2.5% bounds) unless `stop_price` is provided
5. **Trade alert created** — `pipeline_run = EXTERNAL`
6. **ML scoring** — runs the ML model and enforces the MANUAL pipeline threshold
7. **Order placed** — marketable limit buy on Alpaca
8. **Stop loss monitoring** — dispatches `MonitorAlpacaOrderFillAndPlaceStopLoss`
