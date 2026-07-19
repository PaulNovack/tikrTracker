#!/bin/bash
set -e

# ────────────────────────────────────────────────────────────
# Load secrets from .secret file (guarantees keys are available)
# ────────────────────────────────────────────────────────────
if [ -f .secret ]; then
    set -a
    # shellcheck disable=SC1091
    source .secret
    set +a
    echo "  ✓ .secret loaded"
    echo "     ALPACA_KEY_ID: ${ALPACA_KEY_ID:0:8}... (${#ALPACA_KEY_ID} chars)"
else
    echo "  ⚠ .secret file not found — Alpaca/OpenAI keys will be missing"
fi

# ────────────────────────────────────────────────────────────
# Laravel Invest — Docker Entrypoint
# ────────────────────────────────────────────────────────────

echo ""
echo "╔══════════════════════════════════════════════════════════╗"
echo "║        Laravel Invest — Docker Container Start           ║"
echo "╠══════════════════════════════════════════════════════════╣"
echo "║  PHP:    $(php -v 2>/dev/null | head -1 | sed 's/PHP //' | awk '{print $1}')                                          ║"
echo "║  Python: $(/var/www/html/laravel-invest/.venv/bin/python3 --version 2>&1 | awk '{print $2}')                                      ║"
echo "║  Node:   $(node -v 2>/dev/null || echo '...')                                         ║"
echo "║  npm:    v$(npm -v 2>/dev/null || echo '...')                                        ║"
echo "║  App:    ${APP_NAME:-TikrTracker} (${APP_ENV:-local})                              ║"
echo "╚══════════════════════════════════════════════════════════╝"
echo ""

# Fix permissions early — before any artisan command writes to storage
chown -R www-data:www-data /var/www/html/laravel-invest/storage /var/www/html/laravel-invest/bootstrap/cache 2>/dev/null || true
chmod 666 /var/www/html/laravel-invest/.env /var/www/html/laravel-invest/.secret 2>/dev/null || true

# ────────────────────────────────────────────────────────────
# Wait for dependent services
# ────────────────────────────────────────────────────────────
echo "──────────────────────────────────────────────────────────"
echo "  Step 1/7 — Waiting for services"
echo "──────────────────────────────────────────────────────────"

echo -n "  MySQL (${DB_HOST:-mysql}:${DB_PORT:-3306}) ... "
until mysql -h"${DB_HOST:-mysql}" -P"${DB_PORT:-3306}" \
    -u"${DB_USERNAME:-laravel}" -p"${DB_PASSWORD:-laravel}" \
    --connect-timeout=5 -e "SELECT 1" --silent 2>/dev/null; do
    echo -n "."
    sleep 2
done
echo " ✓ ready"

echo -n "  Redis (${REDIS_HOST:-redis}:${REDIS_PORT:-6379}) ... "
until php -r "
try {
    \$redis = new Redis();
    \$redis->connect('${REDIS_HOST:-redis}', (int)'${REDIS_PORT:-6379}', 2);
} catch (Exception \$e) {
    exit(1);
}
" 2>/dev/null; do
    echo -n "."
    sleep 2
done
echo " ✓ ready"
echo ""

# ────────────────────────────────────────────────────────────
# Composer dependencies
# ────────────────────────────────────────────────────────────
echo "──────────────────────────────────────────────────────────"
echo "  Step 2/7 — PHP dependencies (Composer)"
echo "──────────────────────────────────────────────────────────"

if [ -f "vendor/autoload.php" ]; then
    echo "  ✓ vendor/autoload.php found — skipping composer install"
else
    echo "  Installing packages (this may take a minute)..."
    composer install --no-interaction --prefer-dist --optimize-autoloader 2>&1 | while IFS= read -r line; do
        echo "  │ $line"
    done
    echo "  ✓ Composer install complete"
    echo "  ✓ Composer install complete"
fi
echo ""

# ────────────────────────────────────────────────────────────
# Node.js dependencies + frontend build
# ────────────────────────────────────────────────────────────
echo "──────────────────────────────────────────────────────────"
echo "  Step 3/7 — Node.js dependencies & frontend build"
echo "──────────────────────────────────────────────────────────"

NODE_MODULES_POPULATED=false
if [ -d "node_modules" ] && [ "$(ls -A node_modules 2>/dev/null)" ]; then
    NODE_MODULES_POPULATED=true
fi

if $NODE_MODULES_POPULATED && [ -f "public/build/manifest.json" ]; then
    echo "  ✓ node_modules/ and manifest.json found — skipping"
else
    if ! $NODE_MODULES_POPULATED; then
        echo "  Installing npm packages (this may take a minute)..."
        npm install 2>&1 | while IFS= read -r line; do
            echo "  │ $line"
        done
        echo "  ✓ npm install complete"
    else
        echo "  ✓ node_modules/ found — skipping npm install"
    fi

    echo "  Building frontend assets (this step takes 2–5 minutes)..."
    echo "  Started at $(date +%H:%M:%S)"
    npm run build
    echo ""

    if [ -f "public/build/manifest.json" ]; then
        echo "  ✓ Frontend build complete (finished at $(date +%H:%M:%S))"
    else
        echo "  ⚠ Frontend build may have failed — continuing anyway"
    fi
fi
echo ""

# ────────────────────────────────────────────────────────────
# App key
# ────────────────────────────────────────────────────────────
echo "──────────────────────────────────────────────────────────"
echo "  Step 4/7 — Application key"
echo "──────────────────────────────────────────────────────────"

if [ -n "$APP_KEY" ] && [ "$APP_KEY" != "" ]; then
    echo "  ✓ APP_KEY already set"
else
    echo "  Generating APP_KEY..."
    php artisan key:generate --force --no-interaction
    echo "  ✓ APP_KEY generated"
fi
echo ""

# ────────────────────────────────────────────────────────────
# Database migrations
# ────────────────────────────────────────────────────────────
echo "──────────────────────────────────────────────────────────"
echo "  Step 5/7 — Database (migrate:fresh --seed)"
echo "──────────────────────────────────────────────────────────"

FRESH_MARKER="/var/www/html/laravel-invest/storage/.db-fresh-completed"
if [ -f "$FRESH_MARKER" ]; then
    echo "  ✓ Database already initialized — skipping"
else
    php artisan migrate:fresh --seed --force --no-interaction 2>&1 | while IFS= read -r line; do
        echo "  │ $line"
    done
    touch "$FRESH_MARKER"

    # Sync .secret values into settings table (replaces dummy placeholders with real credentials)
    echo ""
    echo "  ── Syncing secrets to settings ──"
    php artisan settings:sync-secrets 2>&1 | while IFS= read -r line; do
        echo "  │ $line"
    done

    echo "  ✓ Database ready (admin@admin.com / password)"
fi
echo ""

# ────────────────────────────────────────────────────────────
# Start supervisor (Apache up immediately, backfill runs async)
# ────────────────────────────────────────────────────────────
echo "╔══════════════════════════════════════════════════════════╗"
echo "║  Starting services — site is now live!                  ║"
echo "╠══════════════════════════════════════════════════════════╣"
echo "║  :8080  Apache (HTTP)                                   ║"
echo "║  :8081  Reverb (WebSocket)                              ║"
echo "║  :5173  Vite dev server (if running)                    ║"
echo "║  7×     default queue workers                           ║"
echo "║  3×     ml-scoring queue workers                        ║"
echo "║  1×     ml-scoring-catchup worker                       ║"
echo "║  1×     task scheduler                                  ║"
echo "║  1×     Flask TA-Lib screener (port 5000)               ║"
echo "╚══════════════════════════════════════════════════════════╝"
echo ""

# Launch supervisor in background so the entrypoint can continue
supervisord -c /etc/supervisor/supervisord.conf &
SUPERVISOR_PID=$!

# ────────────────────────────────────────────────────────────
# Initial data backfill (runs async — site is already live)
# ────────────────────────────────────────────────────────────
echo "──────────────────────────────────────────────────────────"
echo "  Step 6/7 — Initial market data backfill (runs in background)"
echo "──────────────────────────────────────────────────────────"

BACKFILL_MARKER="/var/www/html/laravel-invest/storage/.backfill-completed"
if [ -f "$BACKFILL_MARKER" ]; then
    echo "  ✓ Already completed — skipping"
else
    FROM_DATE=$(date -d '10 days ago' +%Y-%m-%d)
    TO_DATE=$(date +%Y-%m-%d)
    echo "  Date range: ${FROM_DATE} → ${TO_DATE}"
    echo ""

    echo "  ── 1-minute bars ──"
    START_1M=$(date +%s)
    php -d output_buffering=off artisan alpaca:backfill-range "$FROM_DATE" "$TO_DATE" 1m --feed=iex
    END_1M=$(date +%s)
    echo "  ✓ 1m backfill done in $(( (END_1M - START_1M) / 60 ))m $(( (END_1M - START_1M) % 60 ))s"
    echo ""

    echo "  ── 5-minute bars ──"
    START_5M=$(date +%s)
    php -d output_buffering=off artisan alpaca:backfill-range "$FROM_DATE" "$TO_DATE" 5m --feed=iex
    END_5M=$(date +%s)
    echo "  ✓ 5m backfill done in $(( (END_5M - START_5M) / 60 ))m $(( (END_5M - START_5M) % 60 ))s"

    touch "$BACKFILL_MARKER"
    echo ""
    echo "  ✓ Backfill complete"

    # Generate daily prices and hourly prices from intraday data (required for charts)
    echo ""
    echo "  ── Daily prices ──"
    START_DP=$(date +%s)
    php -d output_buffering=off artisan market:generate-daily-prices --days=10
    END_DP=$(date +%s)
    echo "  ✓ Daily prices done in $(( (END_DP - START_DP) / 60 ))m $(( (END_DP - START_DP) % 60 ))s"

    echo ""
    echo "  ── Hourly prices ──"
    echo "  ⚠ Skipped — hourly prices not generated on initial setup (populated by scheduled sync)"
fi
echo ""

# ────────────────────────────────────────────────────────────
# Production optimizations
# ────────────────────────────────────────────────────────────
if [ "${APP_ENV}" = "production" ]; then
    echo "──────────────────────────────────────────────────────────"
    echo "  Step 7/7 — Production caching"
    echo "──────────────────────────────────────────────────────────"
    php artisan config:cache  && echo "  ✓ config cached"
    php artisan route:cache   && echo "  ✓ routes cached"
    php artisan view:cache    && echo "  ✓ views cached"
    echo ""
fi

# ────────────────────────────────────────────────────────────
# Set permissions
# ────────────────────────────────────────────────────────────
chown -R www-data:www-data /var/www/html/laravel-invest/storage /var/www/html/laravel-invest/bootstrap/cache 2>/dev/null || true
chmod 666 /var/www/html/laravel-invest/.env /var/www/html/laravel-invest/.secret 2>/dev/null || true

# Wait for supervisor to exit (keeps the container alive)
wait $SUPERVISOR_PID
