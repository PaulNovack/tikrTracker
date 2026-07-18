#!/usr/bin/env bash
# Wrapper: runs bar-stream and writes to a dated log file.
LOG_DIR="/var/www/html/laravel-invest/storage/logs"
exec /var/www/html/laravel-invest/.venv/bin/python3 \
    /var/www/html/laravel-invest/alpaca_python_api/stream_bars.py \
    >> "$LOG_DIR/bar-stream-$(date +%Y-%m-%d).log" 2>&1
