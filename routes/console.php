<?php

use App\Services\TradingSettingService;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Record CPU temperature every minute for the temp-chart page
Schedule::command('cpu:record-temperature')
    ->everyMinute()
    ->withoutOverlapping()
    ->runInBackground();

// ML scoring catch-up: scores any today alerts the concurrent backtests inserted before the live pipeline ran
Schedule::command('trade:dispatch-ml-scoring --age=10 --limit=50 --no-interaction')
    ->everyThirtySeconds()
    ->withoutOverlapping()
    ->runInBackground();

// Calculate 5-minute indicators before market open
Schedule::command('indicators:calculate-5m --days=2 --chunk=100 --no-interaction')
    ->dailyAt('08:00')
    ->timezone('America/New_York')
    ->name('calculate-5m-indicators-premarket')
    ->withoutOverlapping()
    ->description('Calculate RSI & Bollinger Bands before market open (8:00 AM EST)')
    ->before(function () {
        Log::channel('scheduled')->info('[Scheduler] Starting indicators:calculate-5m premarket');
    })
    ->after(function () {
        Log::channel('scheduled')->info('[Scheduler] Completed indicators:calculate-5m premarket');
    })
    ->onFailure(function () {
        Log::channel('scheduled')->error('[Scheduler] FAILED indicators:calculate-5m premarket');
    });

// Run at 4:30 PM (after market close) to calculate RSI & Bollinger Bands for the day
// This avoids deadlocks with yfinance sync during market hours
Schedule::command('indicators:calculate-5m --days=1 --chunk=200 --no-interaction')
    ->dailyAt('16:30')
    ->timezone('America/New_York')
    ->name('calculate-5m-indicators-postmarket')
    ->withoutOverlapping()
    ->description('Calculate RSI & Bollinger Bands after market close (4:30 PM EST)')
    ->before(function () {
        Log::channel('scheduled')->info('[Scheduler] Starting indicators:calculate-5m postmarket');
    })
    ->after(function () {
        Log::channel('scheduled')->info('[Scheduler] Completed indicators:calculate-5m postmarket');
    });

// Populate market movers data daily at 5:00 PM CST (6:00 PM EST)
Schedule::command('market-movers:populate --days=1 --no-interaction')
    ->dailyAt('18:00')
    ->timezone('America/New_York')
    ->weekdays()
    ->name('populate-market-movers')
    ->withoutOverlapping()
    ->description('Populate market movers data at 5:00 PM CST (6:00 PM EST)')
    ->before(function () {
        Log::channel('scheduled')->info('[Scheduler] Starting market-movers:populate');
    })
    ->after(function () {
        Log::channel('scheduled')->info('[Scheduler] Completed market-movers:populate');
    })
    ->onFailure(function () {
        Log::channel('scheduled')->error('[Scheduler] FAILED market-movers:populate');
    });

// Generate quality trading universe daily at 4:15 PM EST (after market close, before indicator calc)
Schedule::command('universe:generate-quality --limit=750 --no-interaction')
    ->dailyAt('16:15')
    ->timezone('America/New_York')
    ->weekdays()
    ->name('generate-quality-universe')
    ->withoutOverlapping()
    ->description('Generate 60-day quality-scored trading universe in Redis after market close')
    ->before(function () {
        Log::channel('scheduled')->info('[Scheduler] Starting universe:generate-quality');
    })
    ->after(function () {
        Log::channel('scheduled')->info('[Scheduler] Completed universe:generate-quality');
    })
    ->onFailure(function () {
        Log::channel('scheduled')->error('[Scheduler] FAILED universe:generate-quality');
    });

// Recalculate the stable per-pipeline ML baseline after post-market data is available.
// Skipped when "Nightly Analyze Thresholds" is OFF in /trading-settings Risk Controls.
Schedule::command('analyze:ml-thresholds --days=120 --min-trades=1 --top=5 --max_picks --min_win_rate=80 --no-interaction')
    ->dailyAt('18:00')
    ->timezone('America/New_York')
    ->when(fn () => TradingSettingService::isNightlyAnalyzeThresholdsEnabled())
    ->name('analyze-ml-thresholds-postmarket')
    ->withoutOverlapping()
    ->description('Recalculate the stable ML baseline after market close (6:00 PM EST)')
    ->before(function () {
        Log::channel('scheduled')->info('[Scheduler] Starting analyze:ml-thresholds postmarket');
    })
    ->after(function () {
        Log::channel('scheduled')->info('[Scheduler] Completed analyze:ml-thresholds postmarket');
    })
    ->onFailure(function () {
        Log::channel('scheduled')->error('[Scheduler] FAILED analyze:ml-thresholds postmarket');
    });

Schedule::command('analyze:ml-thresholds --days=7 --min-trades=1 --top=5 --max_picks --min_win_rate=65 --no-interaction --lower-only')
    ->dailyAt('18:20')
    ->timezone('America/New_York')
    ->when(fn () => TradingSettingService::isNightlyAnalyzeThresholdsEnabled())
    ->name('analyze-ml-thresholds-postmarket-7d')
    ->withoutOverlapping()
    ->description('Recalculate the 7-day ML baseline after market close (6:20 PM EST)')
    ->before(function () {
        Log::channel('scheduled')->info('[Scheduler] Starting analyze:ml-thresholds (7d)');
    })
    ->after(function () {
        Log::channel('scheduled')->info('[Scheduler] Completed analyze:ml-thresholds (7d)');
    })
    ->onFailure(function () {
        Log::channel('scheduled')->error('[Scheduler] FAILED analyze:ml-thresholds (7d)');
    });

// Backfill one_minute_prices_full daily at 8:00 PM EST
Schedule::command('trading:backfill-one-minute-prices-full --no-interaction')
    ->dailyAt('20:00')
    ->timezone('America/New_York')
    ->weekdays()
    ->name('backfill-one-minute-prices-full')
    ->withoutOverlapping()
    ->description('Backfill one_minute_prices_full table nightly at 8:00 PM EST');

// Backfill five_minute_prices_full daily at 9:00 PM EST
Schedule::command('trading:backfill-five-minute-prices-full --no-interaction')
    ->dailyAt('21:00')
    ->timezone('America/New_York')
    ->weekdays()
    ->name('backfill-five-minute-prices-full')
    ->withoutOverlapping()
    ->description('Backfill five_minute_prices_full table nightly at 9:00 PM EST');

// Restart all Supervisor-managed processes nightly at 2:00 AM EST
Schedule::exec('supervisorctl restart all')
    ->dailyAt('02:00')
    ->timezone('America/New_York')
    ->name('restart-supervisor-all')
    ->withoutOverlapping()
    ->description('Restart all Supervisor-managed processes nightly at 2:00 AM EST')
    ->before(function () {
        Log::channel('scheduled')->info('[Scheduler] Starting supervisorctl restart all');
    })
    ->after(function () {
        Log::channel('scheduled')->info('[Scheduler] Completed supervisorctl restart all');
    })
    ->onFailure(function () {
        Log::channel('scheduled')->error('[Scheduler] FAILED supervisorctl restart all');
    });

// Intraday risk check: every 15 minutes from 9:45 AM – 2:30 PM ET.
// Disables orders immediately (via TradingSettingService) if today's actual closed P&L
// falls below trading.intraday_loss_halt_limit (default -$500).
Schedule::command('trading:intraday-risk-check --no-interaction')
    ->everyFifteenMinutes()
    ->between('09:45', '14:30')
    ->timezone('America/New_York')
    ->weekdays()
    ->name('trading-intraday-risk-check')
    ->withoutOverlapping()
    ->description('Intraday P&L check: disable orders if cumulative closed loss exceeds halt limit')
    ->before(function () {
        Log::channel('scheduled')->info('[Scheduler] Starting trading:intraday-risk-check');
    })
    ->after(function () {
        Log::channel('scheduled')->info('[Scheduler] Completed trading:intraday-risk-check');
    })
    ->onFailure(function () {
        Log::channel('scheduled')->error('[Scheduler] FAILED trading:intraday-risk-check');
    });

// Auto risk management: after market close (4:15 PM ET), check if live trading hit loss thresholds.
// Switches .secret to paper trading credentials if DAILY_LOSS_LIMIT or CONSECUTIVE_LOSS_DAYS is breached.
Schedule::command('trading:auto-risk-check --mode=risk --no-interaction')
    ->dailyAt('16:15')
    ->timezone('America/New_York')
    ->weekdays()
    ->name('trading-auto-risk-check-post-close')
    ->withoutOverlapping()
    ->description('Auto risk check after close: switch to paper on bad days')
    ->before(function () {
        Log::channel('scheduled')->info('[Scheduler] Starting trading:auto-risk-check (risk mode)');
    })
    ->after(function () {
        Log::channel('scheduled')->info('[Scheduler] Completed trading:auto-risk-check (risk mode)');
    })
    ->onFailure(function () {
        Log::channel('scheduled')->error('[Scheduler] FAILED trading:auto-risk-check (risk mode)');
    });

// Auto risk resume check: before market open (9:00 AM ET), if in paper mode and yesterday's paper was profitable,
// switch .secret back to live trading credentials.
// Disabled when AUTO_TRADING_RESUME_ENABLED=false — use `php artisan trading:auto-risk-check --force-live` to resume manually.
Schedule::command('trading:auto-risk-check --mode=resume --no-interaction')
    ->dailyAt('09:00')
    ->skip(fn () => ! config('trading.auto_alpaca_orders.auto_risk.resume_enabled', false))
    ->timezone('America/New_York')
    ->weekdays()
    ->name('trading-auto-risk-check-pre-open')
    ->withoutOverlapping()
    ->description('Auto risk check before open: resume live trading when paper is profitable')
    ->before(function () {
        Log::channel('scheduled')->info('[Scheduler] Starting trading:auto-risk-check (resume mode)');
    })
    ->after(function () {
        Log::channel('scheduled')->info('[Scheduler] Completed trading:auto-risk-check (resume mode)');
    })
    ->onFailure(function () {
        Log::channel('scheduled')->error('[Scheduler] FAILED trading:auto-risk-check (resume mode)');
    });

// Scan for stocks with 4 consecutive up 1-minute bars
Schedule::command('scan:last-4-1min-up --no-interaction')
    ->everyMinute()
    ->withoutOverlapping()
    ->runInBackground()
    ->name('scan-last-4-1min-up')
    ->description('Scan for stocks with 4 consecutive up 1-min bars');

// Scan for CDL3WHITESOLDIERS (Three Advancing White Soldiers) candlestick pattern
// Calls the same Flask API as the /ta-lib-analysis/five-minute page
// Runs 24/7 on weekdays to catch pre-market and after-hours patterns
Schedule::command('scan:three-white-soldiers-live --no-interaction')
    ->everyMinute()
    ->weekdays()
    ->withoutOverlapping()
    ->runInBackground()
    ->name('scan-three-white-soldiers-live')
    ->description('Scan for Three Advancing White Soldiers patterns every minute (24/7 weekdays)')
    ->before(function () {
        Log::channel('scheduled')->info('[Scheduler] Starting scan:three-white-soldiers-live');
    })
    ->after(function () {
        Log::channel('scheduled')->info('[Scheduler] Completed scan:three-white-soldiers-live');
    })
    ->onFailure(function () {
        Log::channel('scheduled')->error('[Scheduler] FAILED scan:three-white-soldiers-live');
    });

// Fetch FinBERT-scored news for all intraday_universe symbols nightly at 2 AM EST.
Schedule::command('news:fetch-stock --no-interaction')
    ->dailyAt('02:00')
    ->timezone('America/New_York')
    ->name('fetch-stock-news-nightly')
    ->withoutOverlapping()
    ->description('Fetch FinBERT-scored news for all intraday_universe symbols nightly at 2:00 AM EST')
    ->before(function () {
        Log::channel('scheduled')->info('[Scheduler] Starting news:fetch-stock');
    })
    ->after(function () {
        Log::channel('scheduled')->info('[Scheduler] Completed news:fetch-stock');
    })
    ->onFailure(function () {
        Log::channel('scheduled')->error('[Scheduler] FAILED news:fetch-stock');
    });

// Runs at 5:00 PM ET — price data should be fully synced by then.
// Populates exit_price and pnl_percent on trade_alerts for the backtest vs actual page.
// Analyze today's trade alerts with ATR exits after market close for all active pipelines.
// Runs at 5:00 PM ET — price data should be fully synced by then.
// Populates exit_price and pnl_percent on trade_alerts for the backtest vs actual page.
Schedule::call(function () {
    $pipelines = [
        'A' => config('app.trade_alert_a_version', 'v1100.0'),
        'B' => config('app.trade_alert_b_version', 'v1100.0'),
        'C' => config('app.trade_alert_c_version', 'v1100.0'),
        'F' => config('app.trade_alert_f_version', 'v1100.0'),
        'H' => config('app.trade_alert_h_version', 'v1100.0'),
        'K' => config('app.trade_alert_k_version', 'v1100.0'),
        'N' => config('app.trade_alert_n_version', 'v1200.0'),
        'O' => config('app.trade_alert_o_version', 'v1500.0'),
        'P' => config('app.trade_alert_p_version', 'v2100.0'),
    ];

    foreach ($pipelines as $pipeline => $version) {
        Log::channel('scheduled')->info("[Scheduler] Analyzing Pipeline {$pipeline} alerts", ['version' => $version]);
        Artisan::call('analyze:trade-alerts-atr-immediate', [
            '--algo-version' => $version,
            '--pipeline' => $pipeline,
            '--write-results' => true,
            '--only-unanalyzed' => true,
            '--no-interaction' => true,
        ]);
    }

    Log::channel('scheduled')->info('[Scheduler] Completed analyze:trade-alerts-atr-immediate post-market for all pipelines');
})
    ->dailyAt('16:15')
    ->timezone('America/New_York')
    ->weekdays()
    ->name('analyze-trade-alerts-all-pipelines-postmarket')
    ->withoutOverlapping()
    ->description('Analyze all pipeline trade alerts with ATR exits after market close (5:00 PM ET)')
    ->before(function () {
        Log::channel('scheduled')->info('[Scheduler] Starting analyze:trade-alerts-atr-immediate post-market for all pipelines');
    })
    ->onFailure(function () {
        Log::channel('scheduled')->error('[Scheduler] FAILED analyze:trade-alerts-atr-immediate post-market');
    });

// STREAMING MODE: Pipelines fired by stream:watch-and-run-pipelines daemon instead of cron
// Schedule::command('trade:pipeline-a stock --top=50 --lookback=15 --stale=8 --before=6 --no-interaction')
//     ->everyMinute()
//     ->name('trade-pipeline-a-live')
//     ->withoutOverlapping()
//     ->runInBackground()
//     ->between('13:30', '20:00')
//     ->weekdays()
//     ->description('Pipeline A live scanning (market hours 9:30 AM - 4:00 PM EDT in UTC: 13:30-20:00)')
//     ->before(function () {
//         Log::channel('scheduled')->info('[Scheduler] Starting trade:pipeline-a command');
//     })
//     ->after(function () {
//         Log::channel('scheduled')->info('[Scheduler] Completed trade:pipeline-a command');
//     });

// Schedule::command('trade:pipeline-b stock --top=50 --lookback=15 --stale=8 --before=6 --no-interaction')
//     ->everyMinute()
//     ->before(function () {
//         Log::channel('scheduled')->info('[Scheduler] Starting trade:pipeline-b command');
//     })
//     ->after(function () {
//         Log::channel('scheduled')->info('[Scheduler] Completed trade:pipeline-b command');
//     })
//     ->name('trade-pipeline-b-live')
//     ->withoutOverlapping()
//     ->runInBackground()
//     ->between('13:30', '20:00')
//     ->weekdays()
//     ->description('Pipeline B live scanning (market hours 9:30 AM - 4:00 PM EDT in UTC: 13:30-20:00)');

// Schedule::command('trade:pipeline-c stock --top=50 --lookback=15 --stale=8 --before=6 --no-interaction')
//     ->everyMinute()
//     ->name('trade-pipeline-c-live')
//     ->withoutOverlapping()
//     ->runInBackground()
//     ->between('13:30', '20:00')
//     ->weekdays()
//     ->description('Pipeline C live scanning (market hours 9:30 AM - 4:00 PM EDT in UTC: 13:30-20:00)')
//     ->before(function () {
//         Log::channel('scheduled')->info('[Scheduler] Starting trade:pipeline-c command');
//     })
//     ->after(function () {
//         Log::channel('scheduled')->info('[Scheduler] Completed trade:pipeline-c command');
//     });

// Schedule::command('trade:pipeline-d stock --top=50 --lookback=15 --stale=8 --before=6 --no-interaction')
//     ->everyMinute()
//     ->name('trade-pipeline-d-live')
//     ->withoutOverlapping()
//     ->runInBackground()
//     ->between('13:30', '20:00')
//     ->weekdays()
//     ->description('Pipeline D live scanning (market hours 9:30 AM - 4:00 PM EDT in UTC: 13:30-20:00)')
//     ->before(function () {
//         Log::channel('scheduled')->info('[Scheduler] Starting trade:pipeline-d command');
//     })
//     ->after(function () {
//         Log::channel('scheduled')->info('[Scheduler] Completed trade:pipeline-d command');
//     });

// Schedule::command('trade:pipeline-e stock --top=50 --lookback=15 --stale=8 --before=6 --no-interaction')
//     ->everyMinute()
//     ->name('trade-pipeline-e-live')
//     ->withoutOverlapping()
//     ->runInBackground()
//     ->between('13:30', '20:00')
//     ->weekdays()
//     ->description('Pipeline E live scanning (market hours 9:30 AM - 4:00 PM EDT in UTC: 13:30-20:00)')
//     ->before(function () {
//         Log::channel('scheduled')->info('[Scheduler] Starting trade:pipeline-e command');
//     })
//     ->after(function () {
//         Log::channel('scheduled')->info('[Scheduler] Completed trade:pipeline-e command');
//     });

// Schedule::command('trade:pipeline-f stock --top=25 --lookback=15 --before=8 --after=10 --stale=8 --no-interaction')
//     ->everyMinute()
//     ->name('trade-pipeline-f-live')
//     ->withoutOverlapping()
//     ->runInBackground()
//     ->between('13:30', '20:00')
//     ->weekdays()
//     ->description('Pipeline F live scanning (market hours 9:30 AM - 4:00 PM EDT in UTC: 13:30-20:00)')
//     ->before(function () {
//         Log::channel('scheduled')->info('[Scheduler] Starting trade:pipeline-f command');
//     })
//     ->after(function () {
//         Log::channel('scheduled')->info('[Scheduler] Completed trade:pipeline-f command');
//     });

// Schedule::command('trade:pipeline-g stock --top=25 --lookback=15 --stale=8 --before=6 --no-interaction')
//     ->everyMinute()
//     ->name('trade-pipeline-g-live')
//     ->withoutOverlapping()
//     ->runInBackground()
//     ->between('13:30', '20:00')
//     ->weekdays()
//     ->description('Pipeline G live scanning (market hours 9:30 AM - 4:00 PM EDT in UTC: 13:30-20:00)')
//     ->before(function () {
//         Log::channel('scheduled')->info('[Scheduler] Starting trade:pipeline-g command');
//     })
//     ->after(function () {
//         Log::channel('scheduled')->info('[Scheduler] Completed trade:pipeline-g command');
//     });

// Schedule::command('trade:pipeline-h stock --top=30 --lookback=60 --stale=8 --before=6 --no-interaction')
//     ->everyMinute()
//     ->name('trade-pipeline-h-live')
//     ->withoutOverlapping(60)
//     ->runInBackground()
//     ->between('13:30', '20:00')
//     ->weekdays()
//     ->when(fn () => TradingSettingService::isPipelineRunCronEnabled('H'))
//     ->description('Pipeline H live scanning (top 10, market hours 9:30 AM - 4:00 PM EDT in UTC: 13:30-20:00)')
//     ->before(function () {
//         Log::channel('scheduled')->info('[Scheduler] Starting trade:pipeline-h command');
//     })
//     ->after(function () {
//         Log::channel('scheduled')->info('[Scheduler] Completed trade:pipeline-h command');
//     })
//     ->onFailure(function () {
//         Log::channel('scheduled')->error('[Scheduler] Failed trade:pipeline-h command', [
//             'failed_at' => now()->toISOString(),
//         ]);
//     });

// Schedule::command('trade:pipeline-i stock --top=50 --lookback=15 --stale=8 --before=6 --no-interaction')
//     ->everyMinute()
//     ->name('trade-pipeline-i-live')
//     ->withoutOverlapping()
//     ->runInBackground()
//     ->between('13:30', '20:00')
//     ->weekdays()
//     ->description('Pipeline I live scanning (market hours 9:30 AM - 4:00 PM EDT in UTC: 13:30-20:00)')
//     ->before(function () {
//         Log::channel('scheduled')->info('[Scheduler] Starting trade:pipeline-i command');
//     })
//     ->after(function () {
//         Log::channel('scheduled')->info('[Scheduler] Completed trade:pipeline-i command');
//     });

// Pipeline K: Scarcity Leaders (v1100.0) - enabled when TRADE_ALERT_K_RUN_CRON=true
// Schedule::command('trade:pipeline-k stock --top=50 --stale=8 --fill=close --before=8 --no-interaction')
//     ->everyMinute()
//     ->name('trade-pipeline-k-live')
//     ->withoutOverlapping(90)
//     ->runInBackground()
//     ->between('13:30', '20:00')
//     ->weekdays()
//     ->when(fn () => TradingSettingService::isPipelineRunCronEnabled('K'))
//     ->description('Pipeline K live scanning (V1100.0 RS Leaders, top 15, every 5min, market hours 9:30 AM - 4:00 PM EDT in UTC: 13:30-20:00)')
//     ->before(function () {
//         Log::channel('scheduled')->info('[Scheduler] Starting trade:pipeline-k command');
//     })
//     ->after(function () {
//         Log::channel('scheduled')->info('[Scheduler] Completed trade:pipeline-k command');
//     })
//     ->onFailure(function () {
//         Log::channel('scheduled')->error('[Scheduler] Failed trade:pipeline-k command', [
//             'failed_at' => now()->toISOString(),
//         ]);
//     });

// Pipeline M: Tight Stops Clean Trend (v1400.0)
// Schedule::command('trade:pipeline-m stock --stale=8 --no-interaction')
//     ->everyMinute()
//     ->name('trade-pipeline-m-live')
//     ->withoutOverlapping(60)
//     ->runInBackground()
//     ->between('13:30', '20:00')
//     ->weekdays()
//     ->when(fn () => TradingSettingService::isPipelineRunCronEnabled('M'))
//     ->description('Pipeline M live scanning (v1400.0 Tight Stops Clean Trend, market hours 9:30 AM - 4:00 PM EDT)')
//     ->before(function () {
//         Log::channel('scheduled')->info('[Scheduler] Starting trade:pipeline-m command');
//     })
//     ->after(function () {
//         Log::channel('scheduled')->info('[Scheduler] Completed trade:pipeline-m command');
//     })
//     ->onFailure(function () {
//         Log::channel('scheduled')->error('[Scheduler] Failed trade:pipeline-m command', [
//             'failed_at' => now()->toISOString(),
//         ]);
//     });

// Pipeline N - Market Movers Momentum (v1200.0)
// Schedule::command('trade:pipeline-n stock --stale=8 --before=6 --no-interaction')
//     ->everyMinute()
//     ->name('trade-pipeline-n-live')
//     ->withoutOverlapping(60)
//     ->runInBackground()
//     ->between('13:30', '20:00')
//     ->weekdays()
//     ->when(fn () => TradingSettingService::isPipelineRunCronEnabled('N'))
//     ->description('Pipeline N live scanning (v1200.0 Market Movers Momentum: two-bar momentum on 4%+ intraday gainers)')
//     ->before(function () {
//         Log::channel('scheduled')->info('[Scheduler] Starting trade:pipeline-n command');
//     })
//     ->after(function () {
//         Log::channel('scheduled')->info('[Scheduler] Completed trade:pipeline-n command');
//     })
//     ->onFailure(function () {
//         Log::channel('scheduled')->error('[Scheduler] Failed trade:pipeline-n command', [
//             'failed_at' => now()->toISOString(),
//         ]);
//     });

// Pipeline O - Opening Range Breakout (v1500.0) - ENABLED
// Opening Range Breakout strategy: 30min range (9:30-10:00 AM), signals after 10:05 AM
// Bypasses ML scoring - ORB performs better with raw signals (ML model trained on momentum patterns)
// Schedule::command('trade:pipeline-o stock --stale=8 --before=6 --no-interaction')
//     ->everyMinute()
//     ->name('trade-pipeline-o-live')
//     ->withoutOverlapping(60)
//     ->runInBackground()
//     ->between('13:30', '20:00')
//     ->weekdays()
//     ->when(fn () => TradingSettingService::isPipelineRunCronEnabled('O'))
//     ->description('Pipeline O live scanning (v1500.0 Opening Range Breakout: 30min range breakouts with volume confirmation)')
//     ->before(function () {
//         Log::channel('scheduled')->info('[Scheduler] Starting trade:pipeline-o command');
//     })
//     ->after(function () {
//         Log::channel('scheduled')->info('[Scheduler] Completed trade:pipeline-o command');
//     })
//     ->onFailure(function () {
//         Log::channel('scheduled')->error('[Scheduler] Failed trade:pipeline-o command', [
//             'failed_at' => now()->toISOString(),
//         ]);
//     });

// Pipeline Q - Volume-First (v27.0) - ENABLED
// Volume-First strategy: wider scanner net + tighter entry quality for more trades
// Same predictability as H, I, D with higher volume
Schedule::command('trade:pipeline-q stock --top=60 --lookback=15 --stale=12 --before=6 --no-interaction')
    ->everyMinute()
    ->name('trade-pipeline-q-live')
    ->withoutOverlapping(60)
    ->runInBackground()
    ->between('13:30', '20:00')
    ->weekdays()
    ->when(fn () => TradingSettingService::isPipelineRunCronEnabled('Q'))
    ->description('Pipeline Q live scanning (v27.0 Volume-First: wider net, tighter entry quality)')
    ->before(function () {
        Log::channel('scheduled')->info('[Scheduler] Starting trade:pipeline-q command');
    })
    ->after(function () {
        Log::channel('scheduled')->info('[Scheduler] Completed trade:pipeline-q command');
    })
    ->onFailure(function () {
        Log::channel('scheduled')->error('[Scheduler] Failed trade:pipeline-q command', [
            'failed_at' => now()->toISOString(),
        ]);
    });

// Clean up old notifications daily at 2 AM EST
Schedule::command('notification:cleanup --days=7 --keep-per-user=200')
    ->dailyAt('02:00')
    ->timezone('America/New_York')
    ->description('Clean up old notifications to maintain performance (2:00 AM EST)')
    ->before(function () {
        Log::channel('scheduled')->info('[Scheduler] Starting notification:cleanup command');
    })
    ->after(function () {
        Log::channel('scheduled')->info('[Scheduler] Completed notification:cleanup command');
    });

// Import market sentiment data twice daily
Schedule::command('sentiments:import')
    ->dailyAt('07:00')
    ->timezone('America/New_York')
    ->description('Import morning market sentiment analysis (7:00 AM EST)')
    ->before(function () {
        Log::channel('scheduled')->info('[Scheduler] Starting sentiments:import command (morning)');
    })
    ->after(function () {
        Log::channel('scheduled')->info('[Scheduler] Completed sentiments:import command (morning)');
    });

// Schedule trading day price cache refresh after market close
Schedule::command('cache:trading-day-prices --asset-type=stock')
    ->dailyAt('17:00')
    ->timezone('America/New_York')
    ->description('Cache trading day prices for efficient rising stock calculations (5:00 PM EST)')
    ->before(function () {
        Log::channel('scheduled')->info('[Scheduler] Starting cache:trading-day-prices --asset-type=stock command');
    })
    ->after(function () {
        Log::channel('scheduled')->info('[Scheduler] Completed cache:trading-day-prices --asset-type=stock command');
    });

// Schedule database backups at 5 PM EST on Fridays only
Schedule::command('database:backup')
    ->dailyAt('17:00')
    ->timezone('America/New_York')
    ->fridays()
    ->description('Create database backup (5:00 PM EST, Fridays only)')
    ->before(function () {
        Log::channel('scheduled')->info('[Scheduler] Starting 5 PM EST database backup');
    })
    ->after(function () {
        Log::channel('scheduled')->info('[Scheduler] Completed 5 PM EST database backup');
    })
    ->onFailure(function () {
        Log::channel('scheduled')->error('[Scheduler] Failed 5 PM EST database backup');
    });

// Schedule hourly continuous sync at 15 minutes past every hour on weekdays
// Equivalent to crontab: 15 * * * 1-5
Schedule::command('market:yfinance-hourly-continuous-sync --hours=48 --batch-size=300')
    ->cron('15 * * * 1-5')
    ->name('hourly-continuous-sync')
    ->withoutOverlapping()
    ->description('Hourly continuous sync at :15 past every hour (weekdays only)')
    ->before(function () {
        Log::channel('scheduled')->info('[Scheduler] Starting hourly continuous sync', [
            'scheduled_at' => now()->toISOString(),
            'command' => 'market:yfinance-hourly-continuous-sync --hours=48 --batch-size=300',
            'cron_pattern' => '15 * * * 1-5',
        ]);
    })
    ->after(function () {
        Log::channel('scheduled')->info('[Scheduler] Completed hourly continuous sync', [
            'completed_at' => now()->toISOString(),
        ]);
    })
    ->onFailure(function () {
        Log::channel('scheduled')->error('[Scheduler] Failed hourly continuous sync', [
            'failed_at' => now()->toISOString(),
            'command' => 'market:yfinance-hourly-continuous-sync --hours=48 --batch-size=300',
        ]);
    });

// Schedule hourly cache warming at 20 minutes past every hour on weekdays
// Equivalent to crontab: 20 * * * 1-5
/*
Schedule::command('market:warm-hourly-prices-cache')
    ->cron('20 * * * 1-5')
    ->name('hourly-cache-warming')
    ->withoutOverlapping()
    ->description('Warm hourly prices cache at :20 past every hour (weekdays only)')
    ->before(function () {
        Log::channel('scheduled')->info('[Scheduler] Starting hourly cache warming', [
            'scheduled_at' => now()->toISOString(),
            'command' => 'market:warm-hourly-prices-cache',
            'cron_pattern' => '20 * * * 1-5',
        ]);
    })
    ->after(function () {
        Log::channel('scheduled')->info('[Scheduler] Completed hourly cache warming', [
            'completed_at' => now()->toISOString(),
        ]);
    })
    ->onFailure(function () {
        Log::channel('scheduled')->error('[Scheduler] Failed hourly cache warming', [
            'failed_at' => now()->toISOString(),
            'command' => 'market:warm-hourly-prices-cache',
        ]);
    });
*/
// Schedule price alerts check every 5 minutes (offset by 3 minutes)
// Equivalent to crontab: 3-58/5 * * * *
// This runs at :03, :08, :13, :18, :23, :28, :33, :38, :43, :48, :53, :58 every hour
Schedule::command('app:check-price-alerts')
    ->cron('3-58/5 * * * *')
    ->name('price-alerts-check')
    ->withoutOverlapping()
    ->description('Check price alerts every 5 minutes with 3-minute offset')
    ->before(function () {
        Log::channel('scheduled')->info('[Scheduler] Starting price alerts check', [
            'scheduled_at' => now()->toISOString(),
            'command' => 'app:check-price-alerts',
            'cron_pattern' => '3-58/5 * * * *',
        ]);
    })
    ->after(function () {
        Log::channel('scheduled')->info('[Scheduler] Completed price alerts check', [
            'completed_at' => now()->toISOString(),
        ]);
    })
    ->onFailure(function () {
        Log::channel('scheduled')->error('[Scheduler] Failed price alerts check', [
            'failed_at' => now()->toISOString(),
            'command' => 'app:check-price-alerts',
        ]);
    });

// Generate daily prices from Alpaca 5-minute data at 4:15 PM EST (after market close)
Schedule::command('market:generate-daily-prices --days=3')
    ->dailyAt('16:15')
    ->timezone('America/New_York')
    ->weekdays()
    ->name('daily-prices-generation')
    ->withoutOverlapping()
    ->description('Generate daily_prices from Alpaca 5-minute data (4:15 PM EST)')
    ->before(function () {
        Log::channel('scheduled')->info('[Scheduler] Starting daily prices generation', [
            'scheduled_at' => now()->toISOString(),
            'command' => 'market:generate-daily-prices --days=1',
        ]);
    })
    ->after(function () {
        Log::channel('scheduled')->info('[Scheduler] Completed daily prices generation', [
            'completed_at' => now()->toISOString(),
        ]);
    })
    ->onFailure(function () {
        Log::channel('scheduled')->error('[Scheduler] Failed daily prices generation', [
            'failed_at' => now()->toISOString(),
            'command' => 'market:generate-daily-prices --days=1',
        ]);
    });

// Generate daily prices from Alpaca 5-minute data at 4:15 PM EST (after market close)
Schedule::command('market:generate-daily-prices --days=3')
    ->dailyAt('9:37')
    ->timezone('America/New_York')
    ->weekdays()
    ->name('daily-prices-generation')
    ->withoutOverlapping()
    ->description('Generate daily_prices from Alpaca 5-minute data (4:15 PM EST)')
    ->before(function () {
        Log::channel('scheduled')->info('[Scheduler] Starting daily prices generation', [
            'scheduled_at' => now()->toISOString(),
            'command' => 'market:generate-daily-prices --days=1',
        ]);
    })
    ->after(function () {
        Log::channel('scheduled')->info('[Scheduler] Completed daily prices generation', [
            'completed_at' => now()->toISOString(),
        ]);
    })
    ->onFailure(function () {
        Log::channel('scheduled')->error('[Scheduler] Failed daily prices generation', [
            'failed_at' => now()->toISOString(),
            'command' => 'market:generate-daily-prices --days=1',
        ]);
    });

// Schedule page cache warming every 10 minutes (offset by 8 minutes)
// Equivalent to crontab: 8-58/10 * * * *
// This runs at :08, :18, :28, :38, :48, :58 every hour
/*
Schedule::command('market:warm-page-caches')
    ->cron('8-58/10 * * * *')
    ->name('page-cache-warming')
    ->withoutOverlapping()
    ->description('Warm page caches every 10 minutes with 8-minute offset')
    ->before(function () {
        Log::channel('scheduled')->info('[Scheduler] Starting page cache warming', [
            'scheduled_at' => now()->toISOString(),
            'command' => 'market:warm-page-caches',
            'cron_pattern' => '8-58/10 * * * *',
        ]);
    })
    ->after(function () {
        Log::channel('scheduled')->info('[Scheduler] Completed page cache warming', [
            'completed_at' => now()->toISOString(),
        ]);
    })
    ->onFailure(function () {
        Log::channel('scheduled')->error('[Scheduler] Failed page cache warming', [
            'failed_at' => now()->toISOString(),
            'command' => 'market:warm-page-caches',
        ]);
    });
*/

// Runs Monday-Friday 9:30 AM - 4:00 PM EST (14:30 - 21:00 UTC)
// Uses parallel processing for faster updates - runs every minute for minimal latency
// DISABLED: Using Alpaca for 1-minute data instead

// Schedule::command('market:yfinance-1min-continuous-sync --parallel-jobs=5 --batch-size=50 --hours=1 --detailed')
//     ->everyMinute()
//     ->timezone('UTC')
//     ->between('14:30', '21:00')
//     ->weekdays()
//
//     ->name('1min-continuous-sync')
//     ->withoutOverlapping(420)      // lock slightly > runtime
//     ->description('1-minute data for all high-volume symbols (runs every minute for low latency)')
//     ->before(function () {
//         Log::channel('scheduled')->info('[Scheduler] Starting all high-volume symbols 1-minute parallel sync', [
//             'scheduled_at' => now()->toISOString(),
//             'parallel_jobs' => 3,
//             'batch_size' => 50,
//         ]);
//     })
//     ->after(function () {
//         Log::channel('scheduled')->info('[Scheduler] Completed high-volume 1-minute sync', [
//             'completed_at' => now()->toISOString(),
//         ]);
//     })
//     ->onFailure(function () {
//         Log::channel('scheduled')->error('[Scheduler] Failed high-volume 1-minute sync', [
//             'failed_at' => now()->toISOString(),
//         ]);
//         Log::channel('rate-limits')->error('[Rate Limit Alert] 1-minute sync failed - possible Yahoo Finance rate limiting', [
//             'sync_type' => '1-minute',
//             'failed_at' => now()->toISOString(),
//             'parallel_jobs' => 3,
//             'batch_size' => 150,
//             'recommendation' => 'Consider reducing parallel jobs or batch size if failures persist',
//         ]);
//     });

// STREAMING MODE: 1-minute bars delivered via WebSocket (stream_bars.py daemon)
// Schedule::command('alpaca:sync-1m --minutes=3 --chunk=150 --retry=2 --feed=iex')
//     ->everyMinute()
//     ->between('13:35', '20:05')
//     ->weekdays()
//     ->timezone('UTC')
//     ->name('alpaca-1min-sync')
//     ->withoutOverlapping(60)
//     ->description('Alpaca 1-minute data sync for all enabled symbols (market hours 9:30 AM - 4:00 PM EDT)')
//     ->before(function () {
//         Log::channel('scheduled')->info('[Scheduler] Starting Alpaca 1-minute sync', [
//             'scheduled_at' => now()->toISOString(),
//         ]);
//     })
//     ->after(function () {
//         Log::channel('scheduled')->info('[Scheduler] Completed Alpaca 1-minute sync', [
//             'completed_at' => now()->toISOString(),
//         ]);
//     })
//     ->onFailure(function () {
//         Log::channel('scheduled')->error('[Scheduler] Failed Alpaca 1-minute sync', [
//             'failed_at' => now()->toISOString(),
//         ]);
//     });

// DISABLED: Switched to Alpaca for 5-minute data (more reliable, no rate limits)
// Previous issues with yfinance: rate limits, deadlocks with parallel processing
//
// Schedule::command('market:yfinance-5min-continuous-sync --hours=1 --parallel-jobs=1 --batch-size=150 --stagger-delay=1.0 --detailed')
//     ->everyFiveMinutes()
//     ->between('14:30', '21:00') // 9:30 AM - 4:00 PM EST in UTC (market hours only)
//     ->weekdays()                // Monday-Friday only (proper trading days)
//     ->name('5min-continuous-sync')
//     ->withoutOverlapping(600)   // Increased timeout for longer sync
//     ->description('Keep all stock 5-minute data up-to-date (runs at separate times from 1-min to avoid overlap)');

// Alpaca 5-minute sync - runs every 5 minutes during market hours
// Fetches 5-minute bars from Alpaca for all 1_min enabled symbols
// More reliable than yfinance with better rate limits and no deadlock issues
// Run at :01, :06, :11, :16, :21, :26, :31, :36, :41, :46, :51, :56 past each hour
// Market hours: 13:30-20:00 UTC (9:30 AM - 4:00 PM EDT during DST)
//              14:30-21:00 UTC (9:30 AM - 4:00 PM EST during standard time)
// Using America/New_York timezone to auto-handle DST transitions
Schedule::command('alpaca:sync-5m --hours=1 --chunk=200 --feed=iex')
    ->cron('1-56/5 9-16 * * 1-5')
    ->timezone('America/New_York')
    ->name('alpaca-5min-sync')
    ->withoutOverlapping(600)
    ->description('Alpaca 5-minute data sync (runs 1 minute after each 5-min bar closes)')
    ->before(function () {
        Log::channel('scheduled')->info('[Scheduler] Starting Alpaca 5-minute sync', [
            'scheduled_at' => now()->toISOString(),
        ]);
    })
    ->after(function () {
        Log::channel('scheduled')->info('[Scheduler] Completed Alpaca 5-minute sync', [
            'completed_at' => now()->toISOString(),
        ]);

        // Immediately calculate change_from_open / relative_volume after bars are synced
        Log::channel('scheduled')->info('[Scheduler] Starting indicators:calculate-momentum (chained)');
        try {
            Artisan::call('indicators:calculate-momentum', ['--days' => 1, '--no-interaction' => true]);
            Log::channel('scheduled')->info('[Scheduler] Completed indicators:calculate-momentum (chained)');
        } catch (\Throwable $e) {
            Log::channel('scheduled')->error('[Scheduler] FAILED indicators:calculate-momentum (chained)', [
                'error' => $e->getMessage(),
            ]);
        }

        // Run Pipeline E immediately after 5-min data is fresh — v400 scans five_minute_prices
        // so it must wait for the bar to close and sync before it can detect signals.
        Log::channel('scheduled')->info('[Scheduler] Starting trade:pipeline-e (chained after 5m sync)');
        try {
            Artisan::call('trade:pipeline-e', ['assetType' => 'stock', '--top' => 50, '--lookback' => 15, '--stale' => 8, '--before' => 6, '--no-interaction' => true]);
            Log::channel('scheduled')->info('[Scheduler] Completed trade:pipeline-e (chained after 5m sync)');
        } catch (\Throwable $e) {
            Log::channel('scheduled')->error('[Scheduler] FAILED trade:pipeline-e (chained)', [
                'error' => $e->getMessage(),
            ]);
        }

        // Run Pipeline O immediately after 5-min data is fresh — avoids the 5-7min latency
        // caused by waiting for the next stream watcher tick after the sync completes.
        Log::channel('scheduled')->info('[Scheduler] Starting trade:pipeline-o (chained after 5m sync)');
        try {
            Artisan::call('trade:pipeline-o', ['assetType' => 'stock', '--stale' => 8, '--before' => 8, '--no-interaction' => true]);
            Log::channel('scheduled')->info('[Scheduler] Completed trade:pipeline-o (chained after 5m sync)');
        } catch (\Throwable $e) {
            Log::channel('scheduled')->error('[Scheduler] FAILED trade:pipeline-o (chained)', [
                'error' => $e->getMessage(),
            ]);
        }
    })
    ->onFailure(function () {
        Log::channel('scheduled')->error('[Scheduler] Failed Alpaca 5-minute sync', [
            'failed_at' => now()->toISOString(),
        ]);
    });

// Alpaca 5-minute sync catch-up - runs 2 minutes after each bar closes (1 min after primary)
// Safety net in case the first run didn't fetch all symbols due to chunking/rate limits
Schedule::command('alpaca:sync-5m --hours=1 --chunk=200 --feed=iex')
    ->cron('2-57/5 9-16 * * 1-5')
    ->timezone('America/New_York')
    ->name('alpaca-5min-sync-catchup')
    ->withoutOverlapping(600)
    ->description('Alpaca 5-minute data catch-up sync (runs 2 minutes after each 5-min bar closes)')
    ->before(function () {
        Log::channel('scheduled')->info('[Scheduler] Starting Alpaca 5-minute catch-up sync', [
            'scheduled_at' => now()->toISOString(),
        ]);
    })
    ->after(function () {
        Log::channel('scheduled')->info('[Scheduler] Completed Alpaca 5-minute catch-up sync', [
            'completed_at' => now()->toISOString(),
        ]);
    })
    ->onFailure(function () {
        Log::channel('scheduled')->error('[Scheduler] Failed Alpaca 5-minute catch-up sync', [
            'failed_at' => now()->toISOString(),
        ]);
    });

// Update trailing stop losses every minute during market hours
Schedule::command('alpaca:update-trailing-stops')
    ->everyMinute()
    ->between('13:30', '20:00')
    ->weekdays()
    ->name('update-trailing-stop-losses')
    ->withoutOverlapping()
    ->description('Update trailing stop losses to 1% below current price')
    ->before(function () {
        Log::channel('scheduled')->info('[Scheduler] Starting alpaca:update-trailing-stops');
    })
    ->after(function () {
        Log::channel('scheduled')->info('[Scheduler] Completed alpaca:update-trailing-stops');
    })
    ->onFailure(function () {
        Log::channel('scheduled')->error('[Scheduler] Failed alpaca:update-trailing-stops', [
            'failed_at' => now()->toISOString(),
        ]);
    });

// Sell all positions at 3:45 PM EDT (19:45 UTC) - PRIMARY
Schedule::command('alpaca:sell-all-positions')
    ->dailyAt('19:45')
    ->weekdays()
    ->name('sell-all-positions-eod')
    ->withoutOverlapping()
    ->description('Sell all Alpaca positions at end of day (3:45 PM EDT)')
    ->before(function () {
        Log::channel('scheduled')->info('[Scheduler] Starting alpaca:sell-all-positions (primary)');
    })
    ->after(function () {
        Log::channel('scheduled')->info('[Scheduler] Completed alpaca:sell-all-positions (primary)');
    })
    ->onFailure(function () {
        Log::channel('scheduled')->error('[Scheduler] Failed alpaca:sell-all-positions (primary)', [
            'failed_at' => now()->toISOString(),
        ]);
    });

// BACKUP: Retry at 3:50 PM EDT (19:50 UTC) if primary failed
Schedule::command('alpaca:sell-all-positions')
    ->dailyAt('19:50')
    ->weekdays()
    ->name('sell-all-positions-eod-backup1')
    ->withoutOverlapping()
    ->description('Sell all Alpaca positions - BACKUP 1 (3:50 PM EDT)')
    ->before(function () {
        Log::channel('scheduled')->info('[Scheduler] Starting alpaca:sell-all-positions (backup 1)');
    })
    ->after(function () {
        Log::channel('scheduled')->info('[Scheduler] Completed alpaca:sell-all-positions (backup 1)');
    });

// BACKUP: Final retry at 3:55 PM EDT (19:55 UTC)
Schedule::command('alpaca:sell-all-positions')
    ->dailyAt('19:55')
    ->weekdays()
    ->name('sell-all-positions-eod-backup2')
    ->withoutOverlapping()
    ->description('Sell all Alpaca positions - BACKUP 2 (3:55 PM EDT)')
    ->before(function () {
        Log::channel('scheduled')->info('[Scheduler] Starting alpaca:sell-all-positions (backup 2)');
    })
    ->after(function () {
        Log::channel('scheduled')->info('[Scheduler] Completed alpaca:sell-all-positions (backup 2)');
    });

// Generate eligible symbols daily at 5:00 PM EST - 1 hour after market close
Schedule::command('eligible:generate')
    ->dailyAt('17:00')
    ->timezone('America/New_York')
    ->weekdays()
    ->name('generate-eligible-symbols')
    ->withoutOverlapping()
    ->description('Generate eligible symbols with money filters for next trading day (5:00 PM EST)')
    ->before(function () {
        Log::channel('scheduled')->info('[Scheduler] Starting eligible:generate command');
    })
    ->after(function () {
        Log::channel('scheduled')->info('[Scheduler] Completed eligible:generate command');
    })
    ->onFailure(function () {
        Log::channel('scheduled')->error('[Scheduler] Failed eligible:generate command', [
            'failed_at' => now()->toISOString(),
        ]);
    });

// Prune dated log files older than 10 days (bar-stream, pipeline-watcher, scheduled, etc.)
Schedule::call(function () {
    $patterns = [
        'bar-stream-*.log',
        'pipeline-watcher-*.log',
        'laravel-scheduled-*.log',
    ];
    $cutoff = now()->subDays(10);

    foreach ($patterns as $pattern) {
        foreach (glob(storage_path('logs/'.$pattern)) as $file) {
            if (filemtime($file) < $cutoff->timestamp) {
                @unlink($file);
            }
        }
    }
})
    ->dailyAt('03:00')
    ->name('prune-dated-logs')
    ->description('Delete bar-stream, pipeline-watcher, and scheduled log files older than 10 days');

// Sync all order statuses from Alpaca API every minute during market hours
Schedule::command('alpaca:sync-stop-loss-orders --all-orders')
    ->everyMinute()
    ->timezone('America/New_York')
    ->between('09:30', '16:30')
    ->weekdays()
    ->name('sync-alpaca-stop-loss-orders')
    ->withoutOverlapping(5)
    ->runInBackground()
    ->description('Check and update status of all open orders with Alpaca API (market hours)')
    ->before(function () {
        Log::channel('scheduled')->info('[Scheduler] Starting alpaca:sync-stop-loss-orders --all-orders');
    })
    ->after(function () {
        Log::channel('scheduled')->info('[Scheduler] Completed alpaca:sync-stop-loss-orders --all-orders');
    })
    ->onFailure(function () {
        Log::channel('scheduled')->error('[Scheduler] Failed alpaca:sync-stop-loss-orders --all-orders', [
            'failed_at' => now()->toISOString(),
        ]);
    });
