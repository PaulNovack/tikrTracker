#!/usr/bin/env bash



current=$(date -d "2026-07-15 09:30:00" +%s)
end=$(date -d "2026-07-15 16:00:00" +%s)

while (( current <= end )); do
    lookback_from=$(date -d "@$current" "+%Y-%m-%d %H:%M:%S")

    echo "Running scan with lookback_from=$lookback_from"

    php artisan market:scan-valid-entries --lookback_from="$lookback_from"

    current=$((current + 300))
done