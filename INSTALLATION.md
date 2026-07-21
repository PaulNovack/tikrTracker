# Installation Guide

## Docker (Recommended — 5 minutes)

The easiest way to run TikrTracker. Everything is containerized — PHP, Python, Node, MySQL, Redis, Supervisor, Reverb — no need to install anything except Docker.

### Prerequisites

- Docker Engine 24+ and Docker Compose v2
- An Alpaca Markets account (paper trading is free)

### Step 1: Configure your secrets

Copy the example secret file and fill in your Alpaca API keys:

```bash
cp example.secret .secret
```

Then edit `.secret`. Replace the placeholder values in the **commented-out sections** with your Alpaca keys:

```
#PROD
#PROD_ALPACA_KEY_ID=your_prod_key_id         ← replace with your production key
#PROD_ALPACA_SECRET_KEY=your_prod_secret      ← replace with your production secret

#PAPER TRADING
#PAPER_ALPACA_KEY_ID=your_paper_key_id        ← replace with your paper trading key
#PAPER_ALPACA_SECRET_KEY=your_paper_secret    ← replace with your paper trading secret
```

The `#ACTIVE TRADING` section below is managed automatically by the Trade Settings 2 page — you don't need to edit it. Also set your mail and OpenAI credentials if using those features:

```
MAIL_USERNAME=your_mailtrap_username
MAIL_PASSWORD=your_mailtrap_password
OPENAI_API_KEY=your_openai_key               # optional, Sentiments
```

The `#PROD` and `#PAPER TRADING` sections remain commented out as a safeguard — the app reads them automatically and the Trade Settings 2 page controls which keys are active. Keys are stored this way because both the Laravel PHP application and the Python scripts (ML scoring, Alpaca streaming) read from `.env` and `.secret` files.

### Step 2: Start the containers

```bash
docker compose -f docker/docker-compose.yml up --build
```

That's it. On first run the entrypoint will:

1. Set up the Python virtual environment and install ML + Alpaca API dependencies
2. Install Composer dependencies
3. Install npm packages and build frontend assets
4. Run database migrations and seed the admin user
5. Backfill 10 days of 1-minute and 5-minute market data
6. Start Apache, queue workers, Reverb, scheduler, and the real-time bar streamer

The app is available at **http://127.0.0.1:8080**. Login with **admin@admin.com / password**.

> **⚠️ First-run data loading:** On initial container creation, the entrypoint will backfill 10 days of 1-minute and 5-minute market data from Alpaca. This runs in the background and can take **10–30 minutes** depending on your connection. The site will be accessible immediately, but charts and analysis pages will populate as data loads. You can monitor progress with:
>
> ```bash
> docker compose -f docker/docker-compose.yml logs -f app
> ```

### Stopping and restarting

```bash
# Stop (keeps data)
docker compose -f docker/docker-compose.yml down

# Stop and delete all data (fresh start)
docker compose -f docker/docker-compose.yml down -v
```

### Docker Desktop visibility

If you use Docker Desktop and the containers don't appear in the GUI, switch contexts:

```bash
docker context use desktop-linux
```

Then rebuild.

### More details

See [docker/README.md](docker/README.md) for a complete reference of all container services, ports, common commands, and troubleshooting.

---

## Local Installation (Manual Setup)

For development on bare metal without Docker.

### Required Packages

**nix packages:**
```
htop lm-sensors supervisor
```

**Runtimes:**
- PHP 8.4 with extensions: `redis`, `pdo_mysql`, `mbstring`, `xml`, `zip`, `bcmath`, `intl`, `gd`, `curl`
- Composer 2
- Node.js 22 + npm
- Python 3.12 with pip

**Servers:**
- MySQL 8.0
- Redis 7+

### Python Dependencies

Create a virtual environment and install all Python dependencies:

```bash
python3 -m venv .venv
source .venv/bin/activate
pip install -r python_ml/requirements.txt
pip install -r alpaca_python_api/requirements.txt
```

Then set the `PYTHON_PATH` in your `.env`:

```
PYTHON_PATH=/var/www/html/laravel-invest/.venv/bin/python3
```

### Step 1: Configure secrets and environment

Edit `.secret` with your Alpaca API keys (same format as the Docker section above).

Edit `.env` with your local database credentials, Redis connection, and Python interpreter path:

```
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=tikrTrackerTest
DB_USERNAME=laravel
DB_PASSWORD=laravel

REDIS_HOST=127.0.0.1
REDIS_PORT=6379

PYTHON_PATH=/var/www/html/laravel-invest/.venv/bin/python3
```

### Step 2: Install dependencies and build

```bash
composer install
npm install
php artisan migrate --seed
npm run build
```

### Step 3: Set up Supervisor

Update the paths in `laravel-invest-worker.conf` to match your system, then copy it:

```bash
sudo cp laravel-invest-worker.conf /etc/supervisor/conf.d/
sudo supervisorctl reread
sudo supervisorctl update
```

See `SUPERVISOR.md` for detailed instructions.

### Step 4: Set up the cron scheduler

```bash
crontab -e
```

Add:

```
* * * * * cd /var/www/html/laravel-invest && php artisan schedule:run >> /dev/null 2>&1
```

### Step 5: Verify your setup

Go to `/trading-settings-2` and confirm your Alpaca keys are correct and paper trading is enabled.

### Step 6: Backfill market data

Backfilling one-minute and five-minute data may take several hours for large date ranges.

```bash
php artisan alpaca:backfill-range \
  "$(date -d '10 days ago' +%Y-%m-%d)" \
  "$(date +%Y-%m-%d)" \
  1m

php artisan alpaca:backfill-range \
  "$(date -d '10 days ago' +%Y-%m-%d)" \
  "$(date +%Y-%m-%d)" \
  5m
```

For backtests and ML model training, you'll likely want 6 months to 1 year of data.

### Important note about settings pages

Some settings pages update the `.secret` file so that Python scripts (which don't have access to PHP settings) can read them. The "Trade Settings 2" page updates both `.secret` and the database.

---

## Understanding Signal Scanners & Entry Finders

TikrTracker's automated trading system has a two-stage architecture that scans for opportunities and finds precise entry points. Together, they can automatically place buy orders with trailing stop-losses.

### Five-Minute Signal Scanners

A **signal scanner** monitors 5-minute price bars across a universe of stocks and generates "signals" — candidates showing explosive intraday momentum. Each scanner:

- Builds a trading universe (top performers, market movers, etc.)
- Runs SQL queries against `five_minute_prices` to compute momentum, volume, ATR, and relative strength
- Applies configurable gates (minimum notional, volume surge, benchmark relative strength)
- Scores each candidate and returns structured signal data

Signals are identified by type (e.g. `MOMO_5M_V25`) and piped into a pipeline letter (A–Q), which processes them through the entry finder below.

**→ [Guide: Creating a Five-Minute Signal Scanner](CREATING_FIVE_MINUTE_SCANNERS.md)**

### One-Minute Entry Finders

An **entry finder** takes a signal from the scanner and refines it on 1-minute bars to find the optimal entry point. Each finder:

- Loads 1-minute OHLCV bars from `one_minute_prices` from market open to the signal time
- Computes VWAP, EMA (9/21), ATR, HOD, and OR (opening range) high
- Applies entry gates (notional floor, volume ratio, VWAP extension limit, room-to-run minimum)
- Determines the entry type (VWAP_RECLAIM, ORB_RETEST, EMA9_PULLBACK)
- Calculates a stop-loss price using ATR-based trailing logic

The result is a specific entry price and stop-loss that gets written to `trade_alerts`. If trading is enabled, the system automatically places a buy limit order at the entry price with a trailing stop-loss on Alpaca.

**→ [Guide: Creating a One-Minute Entry Finder](CREATING_ONE_MINUTE_ENTRY_FINDERS.md)**

### How They Work Together

```
Market Data (1m/5m bars)
    │
    ▼
Signal Scanner (5-minute)        ← Your scanner goes here
    │  Generates: symbol, score, ATR, signal type
    ▼
Entry Finder (1-minute)          ← Your entry finder goes here
    │  Refines: entry_price, stop_loss, entry_type
    ▼
Trade Alert Writer
    │  Writes to trade_alerts table
    ▼
Alpaca Order Placement           ← Automatic buy + trailing stop
```

### Wiring a Scanner to a Pipeline

Each pipeline letter (A–Q) resolves its scanner and entry finder by version number from `.env`. This is configured on the **Trade Settings 2** page (`/trading-settings-2`) or directly in `.env`:

```
TRADE_ALERT_H_VERSION=v25.0          # Scanner version for pipeline H
TRADE_ALERT_H_ENTRY_FINDER=v25.0     # Entry finder version for pipeline H
```

The pipeline command (e.g. `trade:pipeline-h stock --top=50`) automatically instantiates the correct classes based on these settings. You can also set ML score thresholds, position sizing, and enable/disable auto-trading per pipeline on the **Trading Settings** page (`/trading-settings`).

### Pipeline Scheduling

Pipelines run on configurable schedules via Laravel's scheduler (`routes/console.php`). Each pipeline has time slots, day-of-week filters, and can be gated by RSI, VWAP, or benchmark conditions. The scheduler dispatches pipeline commands throughout the trading day — no manual intervention needed once configured.

---

## ML Scoring & Trade Alert Filtering

Beyond the scanner and entry finder gates, TikrTracker uses **machine learning models** to score trade alerts based on their probability of being a winning trade. This adds a final quality filter before any order is placed.

### How ML Scoring Works

1. **Feature Extraction** — For each trade alert, the system extracts multi-timeframe features:
   - Daily price changes (1D, 5D, 20D), volume trends, RSI, and ATR
   - Intraday momentum from 5-minute bars (move %, volume surge, VWAP distance)
   - Scanner metadata (signal score, universe size, benchmark relative strength)
   - Entry finder quality metrics (body %, vol ratio, above VWAP %, room-to-run)

2. **Model Scoring** — A Python XGBoost model (`python_ml/v2/score_single_alert_v2.py`) scores each alert, returning a **win probability** (0.0–1.0). The model is trained on historical trade outcomes — alerts that resulted in profitable vs. unprofitable exits.

3. **Threshold Gating** — Each pipeline has a configurable minimum ML score threshold. Alerts below the threshold are filtered out before reaching order placement. Thresholds are set on the **Trading Settings** page (`/trading-settings`) per pipeline:

   ```
   TRADE_ALERT_H_ML_THRESHOLD=0.65    # Only place orders on ≥65% win probability
   ```

### Training ML Models

Models are trained from historical trade data using the scripts in `python_ml/`:

- **From backtest results**: `python_ml/train_from_backtest.py` — trains on thousands of simulated trades across all pipelines to build a broad model
- **From live trades**: `python_ml/train_from_live_trades.py` — refines the model using real filled orders with actual P&L outcomes
- **Full pipeline training**: `python_ml/full_train_stock_winner_model.py` — end-to-end training with feature engineering and hyperparameter tuning

Training produces a `.joblib` model file that is loaded by the scoring daemon (`ml:scoring-daemon`) for low-latency inference via a Unix socket.

### Scoring in the Pipeline

When a pipeline runs, the flow is:

```
Scanner → Entry Finder → ML Scorer → Threshold Gate → Order Placement
                              │
                              ▼
                    python_ml/v2/score_single_alert_v2.py
                              │
                    Returns: win_probability (0-1)
                              │
                    ┌─────────▼──────────┐
                    │ ≥ threshold?        │
                    │ Yes → place order   │
                    │ No  → skip alert    │
                    └────────────────────┘
```

ML scoring is dispatched to a dedicated queue (`ml-scoring`) and processed by the scoring daemon, keeping the main pipeline fast. Scored alerts appear on the **Trade Alerts** page (`/trade-alerts`) with their win probability, so you can review the model's confidence before trades execute.

### Analyzing ML Thresholds

The **ML Threshold P/L** page (`/analysis/ml-threshold-profit-loss`) shows how different ML score thresholds would have performed historically. It analyzes past alerts grouped by score ranges (0.5–0.6, 0.6–0.7, 0.7–0.8, 0.8+) and shows aggregate P&L, win rate, and trade count for each range. Use this to find the optimal threshold for each pipeline — balancing trade frequency against win rate.

Scheduled threshold analysis runs daily via the scheduler:
```
Schedule::command('analyze:ml-thresholds --days=120 --min-trades=1')
    ->dailyAt('04:05');
```

> **Note:** The pages under the **Training** menu (`/training/analyze-trade-alerts`, `/training/retrain-models`, `/training/rescore-alert`) will allow ML model training, rescoring, and analysis to be done entirely through the web UI — no need to run the Python training commands manually. These pages are currently under development.
