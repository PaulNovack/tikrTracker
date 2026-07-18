#!/bin/bash

# Deploy optimized rotating crontab configuration
# Usage: ./deploy-rotating-cron.sh

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_PATH="$(dirname "$SCRIPT_DIR")"

# Colors
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m'

echo -e "${BLUE}=== Deploying Optimized Rotating Crontab ===${NC}"
echo "Project: $PROJECT_PATH"

# Create environment-specific crontab
TEMP_CRONTAB=$(mktemp)

# Read template and substitute variables
sed "s|/var/www/html/laravel-invest|$PROJECT_PATH|g" "$SCRIPT_DIR/optimized-crontab-rotating" > "$TEMP_CRONTAB"

# Add environment detection header
cat << EOF > "${TEMP_CRONTAB}.final"
# ==========================================
# Laravel Invest - Optimized Rotating Crontab
# Auto-generated on $(date)
# Project: $PROJECT_PATH
# ==========================================

$(cat "$TEMP_CRONTAB")
EOF

echo -e "${YELLOW}Preview of crontab to be installed:${NC}"
echo "=========================="
cat "${TEMP_CRONTAB}.final"
echo "=========================="

echo ""
read -p "Deploy this crontab? (y/N): " -n 1 -r
echo ""

if [[ $REPLY =~ ^[Yy]$ ]]; then
    # Backup existing crontab
    crontab -l > "$SCRIPT_DIR/crontab.backup.$(date +%Y%m%d_%H%M%S)" 2>/dev/null || true
    
    # Install new crontab
    crontab "${TEMP_CRONTAB}.final"
    
    echo -e "${GREEN}✅ Optimized rotating crontab deployed successfully!${NC}"
    echo ""
    echo -e "${YELLOW}Key Features:${NC}"
    echo "  • 5-minute rotating sync (200 symbols per chunk)"
    echo "  • Complete coverage in ~55 minutes"
    echo "  • High-priority watched symbols every 2 minutes"
    echo "  • Automatic rotation reset at market open"
    echo "  • Intelligent rate limit management"
    echo ""
    echo -e "${BLUE}Monitor with:${NC} ./cron-status.sh"
else
    echo "Deployment cancelled."
fi

# Cleanup
rm -f "$TEMP_CRONTAB" "${TEMP_CRONTAB}.final"