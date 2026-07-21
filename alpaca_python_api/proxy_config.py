"""
proxy_config.py — Configures Alpaca SDK to use a WebSocket proxy.

The alpaca-py SDK reads the DATA_PROXY_WS environment variable to redirect
its WebSocket connection away from Alpaca's servers and through a local
proxy (e.g. shlomik/alpaca-proxy-agent).

Simply call `apply_proxy_config()` at the top of any script (after loading
.env) and the SDK will automatically connect via the proxy.

Usage:
    from proxy_config import apply_proxy_config
    apply_proxy_config()  # call once after env is loaded

.env:
    ALPACA_PROXY_ENABLED=true
    ALPACA_PROXY_URL=ws://127.0.0.1:8765

When disabled or URL is empty, DATA_PROXY_WS is not set and the SDK
connects directly to Alpaca as usual.
"""

import os


def apply_proxy_config() -> None:
    """Set DATA_PROXY_WS env var so the alpaca-py SDK connects through the proxy.

    Must be called AFTER loading .env / .secret (so ALPACA_PROXY_ENABLED
    and ALPACA_PROXY_URL are already in os.environ).

    If ALPACA_PROXY_ENABLED != true, or ALPACA_PROXY_URL is empty/unset,
    this function does nothing (direct connection).
    """
    raw_enabled = os.environ.get("ALPACA_PROXY_ENABLED", "").strip().lower()
    if raw_enabled not in ("true", "1", "yes", "on"):
        return

    proxy_url = os.environ.get("ALPACA_PROXY_URL", "").strip()
    if not proxy_url:
        return

    # Convert ws:// → ws://, http:// → ws:// (in case user passed REST URL)
    if proxy_url.startswith("http://"):
        proxy_url = proxy_url.replace("http://", "ws://", 1)
    elif proxy_url.startswith("https://"):
        proxy_url = proxy_url.replace("https://", "ws://", 1)

    # Ensure it ends with /ws or has no path
    if not proxy_url.rstrip("/").endswith("/ws"):
        if not proxy_url.endswith("/"):
            proxy_url += "/"
        proxy_url += "ws"

    os.environ["DATA_PROXY_WS"] = proxy_url
