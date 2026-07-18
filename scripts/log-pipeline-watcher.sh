#!/usr/bin/env bash
# Wrapper: runs pipeline-watcher and writes to a dated log file.
LOG_DIR="/var/www/html/laravel-invest/storage/logs"
exec php /var/www/html/laravel-invest/artisan stream:watch-and-run-pipelines \
    >> "$LOG_DIR/pipeline-watcher-$(date +%Y-%m-%d).log" 2>&1
