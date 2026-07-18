#!/bin/bash

# Laravel Invest - Crontab Deployment Script
# This script sets up the crontab with the correct paths for any environment

# Colors for output
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
RED='\033[0;31m'
NC='\033[0m'

# Get the script directory (works regardless of where script is called from)
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_PATH="$(dirname "$SCRIPT_DIR")"

echo -e "${BLUE}=== Laravel Invest Crontab Deployment ===${NC}"
echo "Detected project path: ${PROJECT_PATH}"
echo ""

# Detect environment
if [ -f "${PROJECT_PATH}/.env" ]; then
    APP_ENV=$(grep "^APP_ENV=" "${PROJECT_PATH}/.env" | cut -d'=' -f2 | tr -d '"' | tr -d "'")
    echo "Detected environment: ${APP_ENV:-local}"
else
    echo -e "${YELLOW}Warning: .env file not found, assuming local environment${NC}"
    APP_ENV="local"
fi

# Create the crontab content with dynamic paths
create_crontab_content() {
    cat << EOF
# ==========================================
# Laravel Invest - Auto-generated Crontab
# Environment: ${APP_ENV}
# Generated: $(date)
# ==========================================

# Environment Variables
SHELL=/bin/bash
PATH=/usr/local/sbin:/usr/local/bin:/sbin:/bin:/usr/sbin:/usr/bin:/usr/local/bin
PROJECT_PATH=${PROJECT_PATH}
LOG_PATH=${PROJECT_PATH}/storage/logs

# Email for cron failures (set to your admin email)
# MAILTO=admin@yourdomain.com

# ==========================================
# MARKET DATA SYNCHRONIZATION
# ==========================================

# Real-time watched symbols (every 15 minutes during market hours)
*/15 9-16 * * 1-5 \$PROJECT_PATH/scripts/cron-wrapper.sh "stocks-5min-watched" "market:yfinance-stocks-5min 8 --watched-only"
*/15 * * * * \$PROJECT_PATH/scripts/cron-wrapper.sh "crypto-5min-watched" "market:yfinance-crypto-5min 8 --watched-only"

# Full stock updates (every 2 hours, staggered batches to avoid API limits)
0 */2 * * * \$PROJECT_PATH/scripts/cron-wrapper.sh "stocks-5min-batch1" "market:yfinance-stocks-5min 8 --limit=1000 --offset=0"
20 */2 * * * \$PROJECT_PATH/scripts/cron-wrapper.sh "stocks-5min-batch2" "market:yfinance-stocks-5min 8 --limit=1000 --offset=1000"
40 */2 * * * \$PROJECT_PATH/scripts/cron-wrapper.sh "stocks-5min-batch3" "market:yfinance-stocks-5min 8 --limit=1000 --offset=2000"

# Crypto updates (every 30 minutes, staggered)
0,30 * * * * \$PROJECT_PATH/scripts/cron-wrapper.sh "crypto-5min-batch1" "market:yfinance-crypto-5min 1 --limit=50 --offset=0"
15,45 * * * * \$PROJECT_PATH/scripts/cron-wrapper.sh "crypto-5min-batch2" "market:yfinance-crypto-5min 1 --limit=50 --offset=50"

# Hourly price data (staggered to distribute load)
0 * * * * \$PROJECT_PATH/scripts/cron-wrapper.sh "stocks-hourly-batch1" "market:yfinance-stocks-hourly --limit=1030 --offset=0"
30 * * * * \$PROJECT_PATH/scripts/cron-wrapper.sh "stocks-hourly-batch2" "market:yfinance-stocks-hourly --limit=1030 --offset=1030"
10 * * * * \$PROJECT_PATH/scripts/cron-wrapper.sh "crypto-hourly" "market:yfinance-crypto-hourly 24"

# Daily price data (end of trading day)
0 17 * * 1-5 \$PROJECT_PATH/scripts/cron-wrapper.sh "stocks-daily" "market:yfinance-stocks-daily 7"

# Price alerts check (every 15 minutes during market hours)
*/15 9-16 * * 1-5 \$PROJECT_PATH/scripts/cron-wrapper.sh "price-alerts" "app:check-price-alerts"

# Technical analysis update (twice daily)
0 10,16 * * 1-5 \$PROJECT_PATH/scripts/cron-wrapper.sh "technical-analysis" "market:technical-analysis"

# Weekly maintenance (Sunday early morning)
0 4 * * 0 \$PROJECT_PATH/scripts/cron-wrapper.sh "weekly-stocks-hourly" "market:yfinance-stocks-hourly 720"
0 6 * * 0 \$PROJECT_PATH/scripts/cron-wrapper.sh "weekly-stocks-daily" "market:yfinance-stocks-daily 30"

# Cache warming and cleanup
0 18 * * 1-5 \$PROJECT_PATH/scripts/cron-wrapper.sh "cache-warm" "cache:warm-assets"
0 2 * * 0 \$PROJECT_PATH/scripts/cron-wrapper.sh "cache-clear" "cache:clear"

# Laravel Scheduler (handles other scheduled tasks defined in routes/console.php)
* * * * * cd \$PROJECT_PATH && php artisan schedule:run >> \$LOG_PATH/laravel-scheduler.log 2>&1

EOF
}

# Function to backup existing crontab
backup_crontab() {
    if crontab -l >/dev/null 2>&1; then
        echo -e "${YELLOW}Backing up existing crontab...${NC}"
        crontab -l > "${PROJECT_PATH}/storage/logs/crontab-backup-$(date +%Y%m%d-%H%M%S).txt"
        echo "Backup saved to storage/logs/"
    fi
}

# Function to install new crontab
install_crontab() {
    echo -e "${BLUE}Installing new crontab...${NC}"
    create_crontab_content | crontab -
    if [ $? -eq 0 ]; then
        echo -e "${GREEN}✓ Crontab installed successfully!${NC}"
    else
        echo -e "${RED}✗ Failed to install crontab${NC}"
        exit 1
    fi
}

# Function to show what would be installed
preview_crontab() {
    echo -e "${YELLOW}=== Preview of crontab content ===${NC}"
    create_crontab_content
    echo -e "${YELLOW}=== End of preview ===${NC}"
}

# Main execution
case "${1:-install}" in
    "preview")
        preview_crontab
        ;;
    "install")
        echo "This will replace your current crontab with Laravel Invest job schedules."
        echo "Project path: ${PROJECT_PATH}"
        echo ""
        read -p "Continue? (y/N): " -n 1 -r
        echo
        if [[ $REPLY =~ ^[Yy]$ ]]; then
            backup_crontab
            install_crontab
            echo ""
            echo -e "${GREEN}Deployment complete!${NC}"
            echo ""
            echo "Next steps:"
            echo "1. Verify crontab: crontab -l"
            echo "2. Monitor logs: ./scripts/monitor-cron-logs.sh"
            echo "3. Check job status: ./scripts/cron-status.sh"
        else
            echo "Deployment cancelled."
        fi
        ;;
    "backup")
        backup_crontab
        echo -e "${GREEN}Crontab backed up successfully!${NC}"
        ;;
    *)
        echo "Usage: $0 [command]"
        echo ""
        echo "Commands:"
        echo "  install   - Install the crontab (default)"
        echo "  preview   - Preview what would be installed"
        echo "  backup    - Backup current crontab only"
        echo ""
        echo "Examples:"
        echo "  $0                  # Install with confirmation prompt"
        echo "  $0 preview          # See what would be installed"
        echo "  $0 backup           # Backup existing crontab"
        ;;
esac