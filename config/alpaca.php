<?php

return [
    'key_id' => env('ALPACA_KEY_ID'),
    'secret_key' => env('ALPACA_SECRET_KEY'),
    'data_base' => rtrim(env('ALPACA_DATA_BASE_URL', 'https://data.alpaca.markets'), '/'),
    'feed' => env('ALPACA_DATA_FEED', 'iex'), // iex | sip
    'paper_trading' => env('ALPACA_PAPER_TRADING', true), // Paper trading mode (true) or live trading (false)
    'unfilled_order_cancel_minutes' => env('UNFILLED_ORDER_CANCEL_MINUTES', 0), // Auto-cancel unfilled buy limit orders after this many minutes (0 = disabled)
];
