# Supervisor Configuration

Supervisor manages all long-running processes for this application — queue workers, Reverb WebSocket server, and continuous backtest loops for each active pipeline.

## Installation & Boot Setup

```bash
sudo apt install supervisor
sudo systemctl enable supervisor   # start on boot
sudo systemctl start supervisor
```

## Config File Location

The canonical config lives in the project repo:

```
/var/www/html/laravel-invest/laravel-invest-worker.conf
```

It is **copied** (not symlinked) to the supervisor conf.d directory:

```
/etc/supervisor/conf.d/laravel-invest-worker.conf
```

> **Important:** Because it is a copy, any changes to `laravel-invest-worker.conf` in the repo must be re-deployed manually (see Updating Config below).

## Apache / www-data Access

Since the app runs under Apache mod_php (`www-data`), both `www-data` and `pnovack` must be in the `supervisor` group so they can reach the supervisor socket without sudo. The socket is configured as `chmod=0770 chown=root:supervisor` in `/etc/supervisor/supervisord.conf`.

```bash
# One-time setup (already done)
sudo groupadd supervisor
sudo usermod -aG supervisor www-data
sudo usermod -aG supervisor pnovack

# Restart Apache to pick up the new group
sudo systemctl reload apache2
```

The `/etc/supervisor/supervisord.conf` unix_http_server section should look like:

```ini
[unix_http_server]
file=/var/run/supervisor.sock
chmod=0770
chown=root:supervisor
```

---

### Switching to a Symlink (recommended)

A symlink means repo changes take effect after a single `reread`/`update`, no manual copy needed:

```bash
sudo rm /etc/supervisor/conf.d/laravel-invest-worker.conf
sudo ln -s /var/www/html/laravel-invest/laravel-invest-worker.conf \
           /etc/supervisor/conf.d/laravel-invest-worker.conf
sudo supervisorctl reread && sudo supervisorctl update
```

---

## Managed Programs

### `laravel-invest-worker` (7 instances)

```
command: php artisan queue:work redis --sleep=1 --tries=3 --max-time=3600
log:     storage/logs/worker.log
```

Processes jobs from the `default` Redis queue. Runs 7 parallel workers (`_00` through `_06`). Each worker recycles after 3600 seconds to prevent memory bloat. Queue driver changed from `database` to `redis`.

---

### `laravel-invest-ml-scoring-worker` (3 instances)

```
command: php artisan queue:work redis --queue=ml-scoring --sleep=1 --tries=3 --max-time=3600
log:     storage/logs/ml-scoring-worker.log
```

Dedicated workers for the `ml-scoring` queue. Runs 3 parallel workers.

---

### `laravel-invest-ml-scoring-catchup-worker` (1 instance)

```
command: php artisan queue:work redis --queue=ml-scoring-catchup --sleep=1 --tries=3 --max-time=3600
log:     storage/logs/ml-scoring-catchup-worker.log
```

Single worker for the `ml-scoring-catchup` queue (backfill/catchup ML scoring jobs).

---

### `laravel-invest-backtest-r-workers` (12 instances)

```
command: php artisan queue:work redis --queue=default --sleep=1 --tries=3 --max-time=7200
log:     storage/logs/backtest-r-workers.log
```

High-volume parallel workers for backtest processing on the `default` queue. Runs 12 parallel workers (`_00` through `_11`), each recycling after 7200 seconds.

---

### `laravel-invest-reverb` (1 instance)

```
command: php artisan reverb:start
log:     storage/logs/reverb.log
```

Laravel Reverb WebSocket server for real-time broadcasting.

---

### `laravel-invest-ml-scoring-daemon` (1 instance)

```
command: php artisan ml:scoring-daemon
log:     storage/logs/ml-scoring-daemon.log
```

Continuous ML scoring daemon that polls for unscored alerts.

---

### `trading-realtime-watch` (1 instance)

```
command: php artisan trading:realtime-watch
log:     storage/logs/trading-realtime-watch.log
```

Real-time trading watch process for live market monitoring.

---

### `laravel-invest-bar-stream` (1 instance)

```
command: scripts/log-bar-stream.sh
```

Bar streaming service for real-time market data.

---

### `laravel-invest-pipeline-watcher` (1 instance)

```
command: scripts/log-pipeline-watcher.sh
```

Pipeline watcher for monitoring pipeline execution.

---

### Continuous Backtest Loops (1 instance each)

Each active pipeline has a continuous backtest script that runs a rolling `-15min` to `+12min` window against today's date, sleeping 10 seconds between iterations. These run 24/7 alongside the live cron jobs so that backtest-found alerts can be compared against live cron alerts via the Pipeline Observability page.

| Program | Script | Log |
|---------|--------|-----|
| `laravel-invest-backtest-a` | `scripts/continuous-back/a-backtest_comparison.sh` | `/dev/null` |
| `laravel-invest-backtest-b` | `scripts/continuous-back/b-backtest_comparison.sh` | `/dev/null` |
| `laravel-invest-backtest-c` | `scripts/continuous-back/c-backtest_comparison.sh` | `/dev/null` |
| `laravel-invest-backtest-d` | `scripts/continuous-back/d-backtest_comparison.sh` | `/dev/null` |
| `laravel-invest-backtest-e` | `scripts/continuous-back/e-backtest_comparison.sh` | `/dev/null` |
| `laravel-invest-backtest-f` | `scripts/continuous-back/f-backtest_comparison.sh` | `/dev/null` |
| `laravel-invest-backtest-g` | `scripts/continuous-back/g-backtest_comparison.sh` | `/dev/null` |
| `laravel-invest-backtest-h` | `scripts/continuous-back/h-backtest_comparison.sh` | `/dev/null` |
| `laravel-invest-backtest-i` | `scripts/continuous-back/i-backtest_comparison.sh` | `/dev/null` |
| `laravel-invest-backtest-j` | `scripts/continuous-back/j-backtest_comparison.sh` | `/dev/null` |
| `laravel-invest-backtest-k` | `scripts/continuous-back/k-backtest_comparison.sh` | `/dev/null` |
| `laravel-invest-backtest-l` | `scripts/continuous-back/l-backtest_comparison.sh` | `/dev/null` |
| `laravel-invest-backtest-m` | `scripts/continuous-back/m-backtest_comparison.sh` | `/dev/null` |
| `laravel-invest-backtest-n` | `scripts/continuous-back/n-backtest_comparison.sh` | `/dev/null` |
| `laravel-invest-backtest-o` | `scripts/continuous-back/o-backtest_comparison.sh` | `/dev/null` |
| `laravel-invest-backtest-p` | `scripts/continuous-back/p-backtest_comparison.sh` | `/dev/null` |
| `laravel-invest-backtest-q` | `scripts/continuous-back/q-backtest_comparison.sh` | `/dev/null` |

All backtest programs use `stopasgroup=true` / `killasgroup=true` and `stopwaitsecs=30` to ensure the current artisan invocation finishes cleanly on stop.

---

## Common Commands

```bash
# View all process statuses
sudo supervisorctl status

# Reload config after changes to laravel-invest-worker.conf
sudo cp /var/www/html/laravel-invest/laravel-invest-worker.conf \
        /etc/supervisor/conf.d/laravel-invest-worker.conf
sudo supervisorctl reread
sudo supervisorctl update

# Restart a single program (example: Pipeline L)
sudo supervisorctl restart laravel-invest-backtest-l

# Restart all main workers
sudo supervisorctl restart laravel-invest-worker:*

# Restart all ML scoring workers
sudo supervisorctl restart laravel-invest-ml-scoring-worker:*

# Restart all backtest R workers
sudo supervisorctl restart laravel-invest-backtest-r-workers:*

# Tail a log (example: Pipeline L)
tail -f /var/www/html/laravel-invest/storage/logs/backtest-l.log

# Tail ML scoring daemon log
tail -f /var/www/html/laravel-invest/storage/logs/ml-scoring-daemon.log

# Tail realtime watch log
tail -f /var/www/html/laravel-invest/storage/logs/trading-realtime-watch.log

# Stop everything (does not disable on boot)
sudo supervisorctl stop all

# Start everything
sudo supervisorctl start all
```
