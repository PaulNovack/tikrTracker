# Docker Quick Start

## Prerequisites

- Docker Engine 24+ and Docker Compose v2
- No conflicting services on ports 3306 (MySQL), 6379 (Redis), 8080, 8081, 5173

## Getting Started

Your existing `.env` file works as-is — `docker-compose.yml` overrides
`DB_HOST`, `REDIS_HOST`, and `REVERB_HOST` to Docker service names automatically.

```bash
# Build and start all containers in detached mode
docker compose -f docker/docker-compose.yml up -d --build

# Watch logs (use -f to follow live progress during setup)
docker compose -f docker/docker-compose.yml logs -f app

# Stop everything (keeps data)
docker compose -f docker/docker-compose.yml down

# Stop AND delete all persistent volumes (fresh start from scratch)
docker compose -f docker/docker-compose.yml down -v
```

## Port Map

| Host Port | Container | Service | Purpose |
|-----------|-----------|---------|---------|
| 8080      | app       | Apache  | HTTP web interface |
| 8081      | app       | Reverb  | WebSocket broadcasting |
| 5173      | app       | Vite    | Dev server + HMR (`npm run dev`) |
| 3306      | mysql     | MySQL   | Database (connect from host) |
| 6379      | redis     | Redis   | Cache/queue (connect from host) |

Override any port via `.env`: `APP_PORT=9090`, `DB_PORT=3307`, `REDIS_PORT=6380`, etc.

---

## Services

| Service | Image | Description |
|---------|-------|-------------|
| **app** | `ubuntu:24.04` (custom) | Apache 2.4 + PHP 8.4 + Python 3.12 + Node 22 + Supervisor |
| **mysql** | `mysql:8.0` | MySQL with `utf8mb4_0900_ai_ci` collation, 512M buffer pool |
| **redis** | `redis:7-alpine` | Redis with AOF persistence, 256MB max memory, allkeys-lru eviction |

---

## Persistent Volumes

| Volume | Path | Contents |
|--------|------|----------|
| `laravel-invest-mysql-data` | `/var/lib/mysql` | MySQL data files — survives container rebuilds |
| `laravel-invest-redis-data` | `/data` | Redis AOF/RDB dump |
| `laravel-invest-app-storage` | `/var/www/html/laravel-invest/storage` | Laravel logs, framework cache, compiled views |

---

## Entrypoint — What Happens on Startup

The `entrypoint.sh` script runs automatically and:

1. **Waits** for MySQL and Redis health checks to pass
2. **`composer install`** — if `vendor/` is missing
3. **`npm install && npm run build`** — if `node_modules/` is missing
4. **`php artisan key:generate`** — if `APP_KEY` is empty
5. **`php artisan migrate:fresh --seed`** — first run only, resets DB and seeds admin user
6. **Initial backfill** — on first creation only, backfills 10 days of market data:
   ```
   php artisan alpaca:backfill-range "$(date -d '10 days ago' +%Y-%m-%d)" "$(date +%Y-%m-%d)" 1m
   php artisan alpaca:backfill-range "$(date -d '10 days ago' +%Y-%m-%d)" "$(date +%Y-%m-%d)" 5m
   ```
   Skipped on subsequent starts (marker file: `storage/.backfill-completed`).
7. **Caches config/routes/views** — if `APP_ENV=production`
8. **Starts Supervisor** — which manages all processes below

## Supervisor-Managed Processes

| Process | Count | Command |
|---------|-------|---------|
| Apache | 1 | `apache2ctl -D FOREGROUND` |
| Default queue worker | 7 | `queue:work redis --sleep=1 --tries=3 --max-time=3600` |
| ML scoring worker | 3 | `queue:work redis --queue=ml-scoring` |
| ML catchup worker | 1 | `queue:work redis --queue=ml-scoring-catchup` |
| Reverb | 1 | `reverb:start` |
| Scheduler | 1 | `schedule:work` |
| Vite Dev Server | 1 | `npm run dev -- --host 0.0.0.0` (HMR on :5173) |
| Bar Stream | 1 | `scripts/log-bar-stream.sh` → `stream_bars.py` |
| Pipeline Watcher | 1 | `scripts/log-pipeline-watcher.sh` → `stream:watch-and-run-pipelines` |
| ML Scoring Daemon | 1 | `ml:scoring-daemon` |

---

## Common Commands

Run these from the project root (`/var/www/html/laravel-invest`):

```bash
# Exec into the app container
docker compose -f docker/docker-compose.yml exec app bash

# Run Artisan commands
docker compose -f docker/docker-compose.yml exec app php artisan inspire
docker compose -f docker/docker-compose.yml exec app php artisan migrate:fresh --seed
docker compose -f docker/docker-compose.yml exec app php artisan queue:restart

# Composer
docker compose -f docker/docker-compose.yml exec app composer require some/package
docker compose -f docker/docker-compose.yml exec app composer update

# npm / Node
docker compose -f docker/docker-compose.yml exec app npm install some-package
docker compose -f docker/docker-compose.yml exec app npm run build

# Vite dev server with HMR (start inside container)
docker compose -f docker/docker-compose.yml exec app npm run dev -- --host 0.0.0.0

# Python (runs inside the container's .venv)
docker compose -f docker/docker-compose.yml exec app /var/www/html/laravel-invest/.venv/bin/python some_script.py

# MySQL — connect from host
mysql -h 127.0.0.1 -P 3306 -u laravel -plaravel tikrTrackerTest

# Redis — connect from host
redis-cli -h 127.0.0.1 -p 6379

# Run tests
docker compose -f docker/docker-compose.yml exec app php artisan test
docker compose -f docker/docker-compose.yml exec app php artisan test --filter=SomeTest
```

---

## Rebuilding After Dependency Changes

If you modify `composer.json`, `package.json`, `python_ml/requirements.txt`,
or `alpaca_python_api/requirements.txt`, the cached layers are stale. Rebuild:

```bash
docker compose -f docker/docker-compose.yml build --no-cache app
docker compose -f docker/docker-compose.yml up -d app
```

Alternatively, exec into the container and install manually (faster for one-off changes):

```bash
docker compose -f docker/docker-compose.yml exec app composer install
docker compose -f docker/docker-compose.yml exec app npm install
docker compose -f docker/docker-compose.yml exec app /var/www/html/laravel-invest/.venv/bin/pip install -r python_ml/requirements.txt
```

---

## Development vs Production

| Setting | Development | Production |
|---------|-------------|------------|
| `APP_ENV` | `local` | `production` |
| `APP_DEBUG` | `true` | `false` |
| Vite | `npm run dev` (HMR) | `npm run build` (static) |
| Config/Route caching | Skipped | Cached on startup |

Set these via `.env` or by passing environment variables to `docker compose up`:

```bash
APP_ENV=production APP_DEBUG=false docker compose -f docker/docker-compose.yml up -d
```

---

## Troubleshooting

**Port already in use:**
Set alternate ports in `.env`: `DB_PORT=3307`, `REDIS_PORT=6380`, `APP_PORT=9090`

**Container keeps restarting:**
```bash
docker compose -f docker/docker-compose.yml logs app
```

**MySQL connection refused from app:**
The entrypoint waits for MySQL's health check — if it times out, check:
```bash
docker compose -f docker/docker-compose.yml logs mysql
```

**Vite manifest not found (blank page):**
Run `npm run build` inside the container:
```bash
docker compose -f docker/docker-compose.yml exec app npm run build
```

**Re-run the initial data backfill:**
The backfill runs once on first creation. To force it again:
```bash
docker compose -f docker/docker-compose.yml exec app rm /var/www/html/laravel-invest/storage/.backfill-completed
docker compose -f docker/docker-compose.yml restart app
```

**Re-run database seed (reset all data):**
```bash
docker compose -f docker/docker-compose.yml exec app rm /var/www/html/laravel-invest/storage/.db-fresh-completed
docker compose -f docker/docker-compose.yml exec app rm /var/www/html/laravel-invest/storage/.backfill-completed
docker compose -f docker/docker-compose.yml restart app
```
The backfill runs once on first creation. To force it again:
```bash
docker compose -f docker/docker-compose.yml exec app rm /var/www/html/laravel-invest/storage/.backfill-completed
docker compose -f docker/docker-compose.yml restart app
```
