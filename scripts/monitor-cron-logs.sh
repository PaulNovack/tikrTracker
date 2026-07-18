#!/bin/bash

# Log monitoring script for Laravel Invest cron jobs
PROJECT_PATH="/var/www/html/laravel-invest"
LOG_PATH="$PROJECT_PATH/storage/logs"

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

echo -e "${BLUE}=== Laravel Invest Cron Job Log Monitor ===${NC}"
echo -e "Log directory: $LOG_PATH"
echo ""

# Function to show recent errors
show_errors() {
    echo -e "${RED}=== Recent Errors (last 24 hours) ===${NC}"
    find "$LOG_PATH" -name "*.log" -mtime -1 -exec grep -l "ERROR\|FAILED\|Exception" {} \; 2>/dev/null | while read -r file; do
        echo -e "${YELLOW}File: $file${NC}"
        grep -n "ERROR\|FAILED\|Exception" "$file" | tail -5
        echo ""
    done
}

# Function to show log sizes
show_log_sizes() {
    echo -e "${GREEN}=== Log File Sizes ===${NC}"
    du -h "$LOG_PATH"/*.log 2>/dev/null | sort -hr
    echo ""
}

# Function to show recent activity
show_recent_activity() {
    echo -e "${BLUE}=== Recent Job Activity (last hour) ===${NC}"
    find "$LOG_PATH" -name "cron-*.log" -mtime -0.042 -exec bash -c 'echo "=== $1 ==="; tail -10 "$1"; echo ""' _ {} \; 2>/dev/null
}

# Main menu
case "${1:-menu}" in
    "errors")
        show_errors
        ;;
    "sizes")
        show_log_sizes
        ;;
    "activity")
        show_recent_activity
        ;;
    "tail")
        if [ -n "$2" ]; then
            tail -f "$LOG_PATH/$2"
        else
            echo "Usage: $0 tail <log-filename>"
            echo "Available logs:"
            ls "$LOG_PATH"/*.log 2>/dev/null | xargs -n 1 basename
        fi
        ;;
    "menu"|*)
        echo "Usage: $0 [command]"
        echo ""
        echo "Commands:"
        echo "  errors    - Show recent error messages"
        echo "  sizes     - Show log file sizes"
        echo "  activity  - Show recent job activity"
        echo "  tail <log> - Follow a specific log file"
        echo ""
        echo "Examples:"
        echo "  $0 errors"
        echo "  $0 tail cron-stocks-5min-watched.log"
        ;;
esac