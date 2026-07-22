<?php

use Illuminate\Support\Facades\Route;

// Routes that don't require disclaimer acceptance
Route::get('/contact', [\App\Http\Controllers\ContactController::class, 'index'])->name('contact');
Route::post('/contact', [\App\Http\Controllers\ContactController::class, 'store'])->name('contact.store');
Route::get('/disclaimer', [\App\Http\Controllers\DisclaimerController::class, 'index'])->name('disclaimer');
Route::post('/disclaimer/accept', [\App\Http\Controllers\DisclaimerController::class, 'accept'])->name('disclaimer.accept');
Route::post('/disclaimer/delete-data', [\App\Http\Controllers\DisclaimerController::class, 'deletePersonalData'])->name('disclaimer.delete-data');

// Routes that require disclaimer acceptance
Route::middleware(['disclaimer'])->group(function () {
    Route::get('/', [\App\Http\Controllers\HomeController::class, 'index'])->name('home');
    Route::get('/trade-alerts', [\App\Http\Controllers\TradeAlertsController::class, 'index'])->name('trade-alerts');
    Route::get('/trade-alerts-unfiltered', [\App\Http\Controllers\TradeAlertsController::class, 'unfilteredIndex'])->name('trade-alerts-unfiltered');
    Route::get('/backtest-results-unfiltered', [\App\Http\Controllers\BacktestResultsController::class, 'unfilteredIndex'])->name('backtest-results-unfiltered');
    Route::get('/backtest-results', [\App\Http\Controllers\BacktestResultsController::class, 'index'])->name('backtest-results');
    Route::get('/alpaca-orders', [\App\Http\Controllers\AlpacaOrderController::class, 'index'])->name('alpaca-orders');
    Route::post('/alpaca-orders/{order}/sell', [\App\Http\Controllers\AlpacaOrderController::class, 'sell'])->name('alpaca-orders.sell');
    Route::post('/alpaca-orders/{order}/cancel-buy', [\App\Http\Controllers\AlpacaOrderController::class, 'cancelBuyOrder'])->name('alpaca-orders.cancel-buy');
    Route::get('/alpaca-orders-api', [\App\Http\Controllers\AlpacaOrdersApiController::class, 'index'])->name('alpaca-orders-api');
    Route::get('/alpaca-place-order', [\App\Http\Controllers\AlpacaPlaceOrderController::class, 'index'])->name('alpaca-place-order');
    Route::post('/alpaca-place-order', [\App\Http\Controllers\AlpacaPlaceOrderController::class, 'placeOrder'])->name('alpaca-place-order.place');
    Route::post('/alpaca-place-order/lookup', [\App\Http\Controllers\AlpacaPlaceOrderController::class, 'lookup'])->name('alpaca-place-order.lookup');
    Route::get('/alpaca-buy-slippage', [\App\Http\Controllers\AlpacaBuySlippageController::class, 'index'])->name('alpaca-buy-slippage');
    Route::get('/alpaca-sell-slippage', [\App\Http\Controllers\AlpacaSellSlippageController::class, 'index'])->name('alpaca-sell-slippage');
    Route::get('/alpaca-daily-performance', [\App\Http\Controllers\AlpacaDailyPerformanceController::class, 'index'])->name('alpaca-daily-performance');
    Route::get('/alpaca-calendar', [\App\Http\Controllers\AlpacaCalendarController::class, 'index'])->name('alpaca-calendar');
    Route::get('/alpaca-capital-invested', [\App\Http\Controllers\AlpacaCapitalInvestedController::class, 'index'])->name('alpaca-capital-invested');
    Route::get('/alpaca-capital-invested/trades/{date}', [\App\Http\Controllers\AlpacaCapitalInvestedController::class, 'tradesForDay'])->name('alpaca-capital-invested.trades');
    Route::get('/alpaca-pl-by-entry-time', [\App\Http\Controllers\AlpacaPLByEntryTimeController::class, 'index'])->name('alpaca-pl-by-entry-time');
    Route::get('/backtest-vs-actual', [\App\Http\Controllers\BacktestVsActualController::class, 'index'])->name('backtest-vs-actual');
    Route::get('/webull-positions', [\App\Http\Controllers\WebullAccountController::class, 'positions'])->name('webull-positions');
    Route::get('/webull-orders', [\App\Http\Controllers\WebullAccountController::class, 'ordersToday'])->name('webull-orders');
    Route::get('/webull-open-orders', [\App\Http\Controllers\WebullAccountController::class, 'openOrders'])->name('webull-open-orders');
    Route::get('/webull-trading', [\App\Http\Controllers\WebullTradingController::class, 'index'])->name('webull-trading');
    Route::post('/webull-trading/buy-market', [\App\Http\Controllers\WebullTradingController::class, 'buyMarket'])->name('webull-trading.buy-market');
    Route::post('/webull-trading/sell-all', [\App\Http\Controllers\WebullTradingController::class, 'sellAll'])->name('webull-trading.sell-all');
    Route::post('/webull-trading/set-stop-loss', [\App\Http\Controllers\WebullTradingController::class, 'setStopLoss'])->name('webull-trading.set-stop-loss');
});

// Guest Login
Route::get('/guest-login', \App\Http\Controllers\Auth\GuestLoginController::class)->name('guest-login');

// Process Monitor routes - accessible to guests with disclaimer
Route::middleware(['disclaimer'])->group(function () {
    Route::get('processes-running', [\App\Http\Controllers\ProcessMonitorController::class, 'index'])->name('processes-running.index');
    // Note: Kill process functionality still requires full auth
});

// MySQL Health Monitor routes - accessible to guests with disclaimer
Route::middleware(['disclaimer'])->group(function () {
    Route::get('mysql-health', [\App\Http\Controllers\MySqlHealthController::class, 'index'])->name('mysql-health.index');
    Route::get('api/mysql-health', [\App\Http\Controllers\MySqlHealthController::class, 'api'])->name('mysql-health.api');
    Route::post('api/mysql-health/kill-query', [\App\Http\Controllers\MySqlHealthController::class, 'killQuery'])->name('mysql-health.kill-query');
});

// Redis Keys Explorer routes - accessible to guests with disclaimer
Route::middleware(['disclaimer'])->group(function () {
    Route::get('redis-keys', [\App\Http\Controllers\RedisKeysController::class, 'index'])->name('redis-keys.index');
    Route::get('redis-keys/show', [\App\Http\Controllers\RedisKeysController::class, 'show'])->name('redis-keys.show');
    Route::get('redis-keys/search', [\App\Http\Controllers\RedisKeysController::class, 'search'])->name('redis-keys.search');
    Route::delete('redis-keys/destroy', [\App\Http\Controllers\RedisKeysController::class, 'destroy'])->name('redis-keys.destroy');
});

// Settings Snapshots — admin only
Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('settings-snapshots', [\App\Http\Controllers\SettingsSnapshotsController::class, 'index'])->name('settings-snapshots.index');
    Route::post('settings-snapshots', [\App\Http\Controllers\SettingsSnapshotsController::class, 'store'])->name('settings-snapshots.store');
    Route::get('settings-snapshots/{snapshot}', [\App\Http\Controllers\SettingsSnapshotsController::class, 'show'])->name('settings-snapshots.show');
    Route::post('settings-snapshots/{snapshot}/restore', [\App\Http\Controllers\SettingsSnapshotsController::class, 'restore'])->name('settings-snapshots.restore');
    Route::delete('settings-snapshots/{snapshot}', [\App\Http\Controllers\SettingsSnapshotsController::class, 'destroy'])->name('settings-snapshots.destroy');
});

// Trading Settings (admin only)
Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('trading-settings', [\App\Http\Controllers\TradingSettingsController::class, 'edit'])->name('trading-settings.edit');
    Route::patch('trading-settings', [\App\Http\Controllers\TradingSettingsController::class, 'update'])->name('trading-settings.update');
    Route::patch('trading-settings/pipelines', [\App\Http\Controllers\TradingSettingsController::class, 'updatePipelines'])->name('trading-settings.pipelines');
    Route::patch('trading-settings/max-age', [\App\Http\Controllers\TradingSettingsController::class, 'updateMaxAgeSettings'])->name('trading-settings.max-age');
    Route::patch('trading-settings/ml-thresholds', [\App\Http\Controllers\TradingSettingsController::class, 'updateMlThresholds'])->name('trading-settings.ml-thresholds');
    Route::patch('trading-settings/time-slots', [\App\Http\Controllers\TradingSettingsController::class, 'updateTimeSlots'])->name('trading-settings.time-slots');
    Route::patch('trading-settings/realtime-slots', [\App\Http\Controllers\TradingSettingsController::class, 'updateRealtimeSlots'])->name('trading-settings.realtime-slots');
    Route::patch('trading-settings/stop-loss', [\App\Http\Controllers\TradingSettingsController::class, 'updateStopLoss'])->name('trading-settings.stop-loss');
    Route::patch('trading-settings/limit-orders', [\App\Http\Controllers\TradingSettingsController::class, 'updateLimitOrders'])->name('trading-settings.limit-orders');
    Route::patch('trading-settings/trading-hours', [\App\Http\Controllers\TradingSettingsController::class, 'updateTradingHours'])->name('trading-settings.trading-hours');
    Route::patch('trading-settings/stale-rescore', [\App\Http\Controllers\TradingSettingsController::class, 'updateStaleRescore'])->name('trading-settings.stale-rescore');
    Route::patch('trading-settings/benchmark-vwap-gate', [\App\Http\Controllers\TradingSettingsController::class, 'updateBenchmarkVwapGate'])->name('trading-settings.benchmark-vwap-gate');
    Route::patch('trading-settings/realtime', [\App\Http\Controllers\TradingSettingsController::class, 'updateRealtime'])->name('trading-settings.realtime');
    Route::patch('trading-settings/pipeline-ml-gates', [\App\Http\Controllers\TradingSettingsController::class, 'updatePipelineMlGates'])->name('trading-settings.pipeline-ml-gates');
});

// Trade Settings 2 (admin only, additional settings)
Route::middleware(['auth', 'verified'])->prefix('trading-settings-2')->name('trading-settings-2.')->group(function () {
    Route::get('/', [\App\Http\Controllers\TradingSettings2Controller::class, 'edit'])->name('edit');
    Route::patch('/', [\App\Http\Controllers\TradingSettings2Controller::class, 'update'])->name('update');
    Route::patch('/pipelines', [\App\Http\Controllers\TradingSettings2Controller::class, 'updatePipelines'])->name('pipelines');
    Route::patch('/max-age', [\App\Http\Controllers\TradingSettings2Controller::class, 'updateMaxAgeSettings'])->name('max-age');
    Route::patch('/ml-thresholds', [\App\Http\Controllers\TradingSettings2Controller::class, 'updateMlThresholds'])->name('ml-thresholds');
    Route::patch('/time-slots', [\App\Http\Controllers\TradingSettings2Controller::class, 'updateTimeSlots'])->name('time-slots');
    Route::patch('/realtime-slots', [\App\Http\Controllers\TradingSettings2Controller::class, 'updateRealtimeSlots'])->name('realtime-slots');
    Route::patch('/stop-loss', [\App\Http\Controllers\TradingSettings2Controller::class, 'updateStopLoss'])->name('stop-loss');
    Route::patch('/limit-orders', [\App\Http\Controllers\TradingSettings2Controller::class, 'updateLimitOrders'])->name('limit-orders');
    Route::patch('/trading-hours', [\App\Http\Controllers\TradingSettings2Controller::class, 'updateTradingHours'])->name('trading-hours');
    Route::patch('/stale-rescore', [\App\Http\Controllers\TradingSettings2Controller::class, 'updateStaleRescore'])->name('stale-rescore');
    Route::patch('/benchmark-vwap-gate', [\App\Http\Controllers\TradingSettings2Controller::class, 'updateBenchmarkVwapGate'])->name('benchmark-vwap-gate');
    Route::patch('/realtime', [\App\Http\Controllers\TradingSettings2Controller::class, 'updateRealtime'])->name('realtime');
    Route::patch('/other', [\App\Http\Controllers\TradingSettings2Controller::class, 'updateOther'])->name('other');
});

// Pipeline Observability routes - accessible to guests with disclaimer
Route::middleware(['disclaimer'])->group(function () {
    Route::get('pipeline-observability', [\App\Http\Controllers\PipelineObservabilityController::class, 'index'])->name('pipeline-observability.index');
    Route::get('api/pipeline-observability', [\App\Http\Controllers\PipelineObservabilityController::class, 'api'])->name('pipeline-observability.api');
}); // Conditionally apply middleware based on environment setting
$middleware = config('app.disable_auth_middleware', false)
    ? ['disclaimer']  // Only disclaimer for testing
    : ['auth', 'verified', 'disclaimer'];  // Full middleware stack for production

Route::middleware($middleware)->group(function () {
    Route::get('dashboard', [\App\Http\Controllers\DashboardController::class, 'index'])->name('dashboard');

    Route::prefix('training')->name('training.')->group(function () {
        Route::get('analyze-trade-alerts', [\App\Http\Controllers\TrainingController::class, 'analyzeTradeAlerts'])->name('analyze-trade-alerts.index');
        Route::get('retrain-models', [\App\Http\Controllers\TrainingController::class, 'retrainModels'])->name('retrain-models.index');
        Route::get('rescore-alert', [\App\Http\Controllers\TrainingController::class, 'rescoreAlert'])->name('rescore-alert.index');
    });

    // Orders routes
    Route::middleware('not_guest')->group(function () {
        Route::get('orders/buy', [\App\Http\Controllers\Orders\BuyOrderController::class, 'index'])->name('orders.buy');
        Route::post('orders/calculate-shares', [\App\Http\Controllers\Orders\BuyOrderController::class, 'calculateShares'])->name('orders.calculate-shares');
        Route::post('orders/place', [\App\Http\Controllers\Orders\BuyOrderController::class, 'placeOrder'])->name('orders.place');
        Route::get('orders/stop-loss', [\App\Http\Controllers\Orders\StopLossController::class, 'index'])->name('orders.stop-loss');
        Route::get('orders/webull-account', [\App\Http\Controllers\Orders\WebullAccountController::class, 'index'])->name('orders.webull-account');
        Route::post('orders/webull-account', [\App\Http\Controllers\Orders\WebullAccountController::class, 'getAccountId'])->name('orders.webull-account.get');
        Route::post('orders/webull-token', [\App\Http\Controllers\Orders\WebullAccountController::class, 'createToken'])->name('orders.webull-token.create');
    });

    // Notification routes - disabled for guest users
    Route::middleware('not_guest')->group(function () {
        Route::get('notifications', [\App\Http\Controllers\NotificationController::class, 'index'])->name('notifications.index');
        Route::get('notifications/settings', [\App\Http\Controllers\NotificationController::class, 'settings'])->name('notifications.settings');
        Route::post('notifications/mark-all-as-read', [\App\Http\Controllers\NotificationController::class, 'markAllAsRead'])->name('notifications.mark-all-as-read');
        Route::delete('notifications/delete-all', [\App\Http\Controllers\NotificationController::class, 'deleteAll'])->name('notifications.delete-all');
        Route::post('notifications/{notification}/mark-as-read', [\App\Http\Controllers\NotificationController::class, 'markAsRead'])->name('notifications.mark-as-read');
        Route::delete('notifications/{notification}', [\App\Http\Controllers\NotificationController::class, 'destroy'])->name('notifications.destroy');
        Route::get('api/notifications/counts', [\App\Http\Controllers\NotificationController::class, 'counts'])->name('notifications.counts');

        // Price Alert routes
        Route::post('price-alerts', [\App\Http\Controllers\PriceAlertController::class, 'store'])->name('price-alerts.store');
        Route::post('price-alerts/store-all', [\App\Http\Controllers\PriceAlertController::class, 'storeAll'])->name('price-alerts.store-all');
        Route::patch('price-alerts/{priceAlert}', [\App\Http\Controllers\PriceAlertController::class, 'update'])->name('price-alerts.update');
        Route::patch('price-alerts/{priceAlert}/toggle', [\App\Http\Controllers\PriceAlertController::class, 'toggle'])->name('price-alerts.toggle');
        Route::delete('price-alerts/{priceAlert}', [\App\Http\Controllers\PriceAlertController::class, 'destroy'])->name('price-alerts.destroy');
        Route::delete('price-alerts', [\App\Http\Controllers\PriceAlertController::class, 'destroyAll'])->name('price-alerts.destroy-all');

        // Alert Log routes
        Route::get('alert-logs', [\App\Http\Controllers\AlertLogController::class, 'index'])->name('alert-logs.index');

        // Queue Monitor route
        Route::get('queue-monitor', [\App\Http\Controllers\QueueMonitorController::class, 'index'])->name('queue-monitor.index');

        // Process Monitor routes
        Route::get('processes-running', [\App\Http\Controllers\ProcessMonitorController::class, 'index'])->name('processes-running.index');
        Route::post('processes-running/kill', [\App\Http\Controllers\ProcessMonitorController::class, 'killProcess'])->name('processes-running.kill');

        // Alert Logs routes
        Route::get('alert-logs', [\App\Http\Controllers\AlertLogController::class, 'index'])->name('alert-logs.index');

        // Log Viewer routes
        Route::get('logs/laravel', [\App\Http\Controllers\LogViewerController::class, 'laravel'])->name('logs.laravel');
        Route::get('logs/scheduler', [\App\Http\Controllers\LogViewerController::class, 'scheduler'])->name('logs.scheduler');
        Route::get('logs/htop', [\App\Http\Controllers\LogViewerController::class, 'htop'])->name('logs.htop');
        Route::get('logs/cpu-temp', [\App\Http\Controllers\LogViewerController::class, 'cpuTemp'])->name('logs.cpu-temp');
        Route::get('logs/temp-chart', [\App\Http\Controllers\LogViewerController::class, 'tempChart'])->name('logs.temp-chart');
        Route::get('logs/continuous-bt', [\App\Http\Controllers\LogViewerController::class, 'continuousBacktest'])->name('logs.continuous-bt');
        Route::get('logs/realtime-alerts', [\App\Http\Controllers\RealtimeAlertController::class, 'index'])->name('logs.realtime-alerts');
        Route::get('api/logs/laravel', [\App\Http\Controllers\LogViewerController::class, 'getLaravelLog'])->name('logs.laravel.api');
        Route::get('api/logs/laravel/search', [\App\Http\Controllers\LogViewerController::class, 'searchLaravelLog'])->name('logs.laravel.search');
        Route::get('api/logs/scheduler', [\App\Http\Controllers\LogViewerController::class, 'getSchedulerLog'])->name('logs.scheduler.api');
        Route::get('api/logs/scheduler/search', [\App\Http\Controllers\LogViewerController::class, 'searchSchedulerLog'])->name('logs.scheduler.search');
        Route::get('api/logs/htop', [\App\Http\Controllers\LogViewerController::class, 'getHtopOutput'])->name('logs.htop.api');
        Route::get('api/logs/cpu-temp', [\App\Http\Controllers\LogViewerController::class, 'getCpuTempOutput'])->name('logs.cpu-temp.api');
        Route::get('api/logs/temp-chart', [\App\Http\Controllers\LogViewerController::class, 'getTempChartData'])->name('logs.temp-chart.api');
        Route::get('api/logs/continuous-bt', [\App\Http\Controllers\LogViewerController::class, 'getContinuousBacktestLog'])->name('logs.continuous-bt.api');
        Route::get('logs/streaming', [\App\Http\Controllers\LogViewerController::class, 'streaming'])->name('logs.streaming');
        Route::get('api/logs/streaming', [\App\Http\Controllers\LogViewerController::class, 'getStreamingLog'])->name('logs.streaming.api');
        Route::get('logs/stale-entries', [\App\Http\Controllers\LogViewerController::class, 'staleEntries'])->name('logs.stale-entries');
        Route::get('api/logs/stale-entries', [\App\Http\Controllers\LogViewerController::class, 'getStaleEntriesLog'])->name('logs.stale-entries.api');
        Route::get('api/logs/stale-entries/search', [\App\Http\Controllers\LogViewerController::class, 'searchStaleEntriesLog'])->name('logs.stale-entries.search');

        // Watch routes (restricted for guest users)
        Route::middleware(\App\Http\Middleware\NotGuest::class)->group(function () {
            Route::get('watches', [\App\Http\Controllers\WatchController::class, 'index'])->name('watches.index');
            Route::get('watches/settings', [\App\Http\Controllers\WatchController::class, 'settings'])->name('watches.settings');
            Route::get('watches/csv', [\App\Http\Controllers\WatchController::class, 'csvShow'])->name('watches.csv');
            Route::post('watches/csv', [\App\Http\Controllers\WatchController::class, 'csvStore'])->name('watches.csv.store');
            Route::post('watches', [\App\Http\Controllers\WatchController::class, 'store'])->name('watches.store');
            Route::delete('watches/{watch}', [\App\Http\Controllers\WatchController::class, 'destroy'])->name('watches.destroy');
            Route::get('watches/{watch}/max-chart-data', [\App\Http\Controllers\WatchController::class, 'getMaxChartData'])->name('watches.max-chart-data');
            Route::get('watches/{watch}/candlestick-data', [\App\Http\Controllers\WatchController::class, 'getCandlestickData'])->name('watches.candlestick-data');
        });

        // Watched Analysis routes
        Route::get('watched-analysis', [\App\Http\Controllers\StagnationController::class, 'index'])->name('stagnation.index');
        Route::get('notable-assets', [\App\Http\Controllers\NotableAssetController::class, 'index'])->name('notable-assets.index');

        // Daily Analysis route
        Route::get('ta-lib-analysis', [\App\Http\Controllers\CandlestickScreenerController::class, 'index'])->name('ta-lib-analysis.index');

        // 5-Minute Analysis route
        Route::get('ta-lib-analysis/five-minute', [\App\Http\Controllers\CandlestickScreenerController::class, 'fiveMinute'])->name('ta-lib-analysis.five-minute');

        // Moving stocks route
        Route::get('rising', [\App\Http\Controllers\RisingController::class, 'index'])->name('rising.index');

        // Rising stocks in hour route
        Route::get('rising-hour', [\App\Http\Controllers\RisingHourController::class, 'index'])->name('rising-hour.index');

        // My watchlist hour route
        Route::get('my-hour', [\App\Http\Controllers\MyHourController::class, 'index'])->name('my-hour.index');

        // Check Top route - Check if rising stocks have topped out
        Route::get('check-top', [\App\Http\Controllers\CheckTopController::class, 'index'])->name('check-top.index');

        // Risers Not Topped route - Show rising stocks that haven't topped out
        Route::get('risers-not-topped', [\App\Http\Controllers\RisersNotToppedController::class, 'index'])->name('risers-not-topped.index');

        // Last 4 Bars Up route - Show stocks with 4 consecutive increasing bars
        Route::get('last-4-bars-up', [\App\Http\Controllers\Last4BarsUpController::class, 'index'])->name('last-4-bars-up.index');

        // Clean 2H Uptrend route - Pipeline M v1400.0 clean 2-hour uptrend scanner
        Route::get('clean-2h', [\App\Http\Controllers\PaulsPicksController::class, 'index'])->name('clean-2h.index');

        // Analysis routes group
        Route::prefix('analysis')->group(function () {
            // Best Gains 7 Days route - Top performers over the last 7 days using 5-minute data
            Route::get('best-gains-7d', [\App\Http\Controllers\Analysis\BestGains7DaysController::class, 'index'])->name('best-gains-7d.index');
            Route::get('pipeline-counts', [\App\Http\Controllers\Analysis\PipelineCountsController::class, 'index'])->name('pipeline-counts.index');
            Route::get('rising-since-close', [\App\Http\Controllers\Analysis\RisingSinceCloseController::class, 'index'])->name('rising-since-close.index');
            Route::get('upward-pressure', [\App\Http\Controllers\Analysis\UpwardPressureController::class, 'index'])->name('upward-pressure.index');
            Route::get('good-long-buy', [\App\Http\Controllers\Analysis\GoodLongBuyController::class, 'index'])->name('good-long-buy.index');
            Route::get('ml-threshold-profit-loss', [\App\Http\Controllers\Analysis\MlThresholdProfitLossController::class, 'index'])->name('ml-threshold-profit-loss.index');

            // Buy Zone Top Performers route - Filtered buy zone candidates from top performers
            Route::get('buy-zone-top-performers', [\App\Http\Controllers\Analysis\BuyZoneTopPerformersController::class, 'index'])->name('buy-zone-top-performers.index');

            // Gainers and Losers route - Daily top gainers and losers analysis
            Route::get('gainers-losers', [\App\Http\Controllers\GainersLosersController::class, 'index'])->name('gainers-losers.index');

            // Breakout route - Breakout pattern analysis
            Route::get('breakout', [\App\Http\Controllers\BreakoutController::class, 'index'])->name('breakout.index');

            // 5-Min VWAP Status — benchmark symbol's intraday VWAP position by 5-min bar
            Route::get('vwap-status', [\App\Http\Controllers\Analysis\VwapStatusController::class, 'index'])->name('vwap-status.index');

            // Breakout Confirmed route - Confirmed breakout analysis
            Route::get('breakout-confirmed', [\App\Http\Controllers\BreakoutConfirmedController::class, 'index'])->name('breakout-confirmed.index');

            // Bottom Detect route - Technical analysis for potential stock bottoms
            Route::get('bottom-detect', [\App\Http\Controllers\Analysis\BottomDetectionController::class, 'index'])->name('bottom-detect.index');

            // Score Symbol route - Analyze and score individual symbols
            Route::get('score-symbol', [\App\Http\Controllers\Analysis\ScoreSymbolController::class, 'index'])->name('score-symbol.index');
            Route::post('score-symbol', [\App\Http\Controllers\Analysis\ScoreSymbolController::class, 'score'])->name('score-symbol.score');

            // Score Symbol List route - Batch score multiple symbols with auto-refresh
            Route::get('score-symbol-list', [\App\Http\Controllers\Analysis\ScoreSymbolListController::class, 'index'])->name('score-symbol-list.index');
            Route::post('score-symbol-list', [\App\Http\Controllers\Analysis\ScoreSymbolListController::class, 'score'])->name('score-symbol-list.score');
            Route::get('score-symbol-list/status/{batchId}', [\App\Http\Controllers\Analysis\ScoreSymbolListController::class, 'status'])->name('score-symbol-list.status');
            Route::get('score-symbol-list/top-movers', [\App\Http\Controllers\Analysis\ScoreSymbolListController::class, 'topMovers'])->name('score-symbol-list.top-movers');

            // Pick Formula - Reference for entry criteria
            Route::get('pick-formula', fn () => \Inertia\Inertia::render('analysis/PickFormula'))->name('pick-formula.index');
        });

        // Buy Signals route - Advanced trading signals and buy recommendations
        Route::get('buy-signals', [\App\Http\Controllers\BuySignalController::class, 'index'])->name('buy-signals.index');

        // Sentiments route - Market sentiment analysis for major stocks
        Route::get('sentiments', [\App\Http\Controllers\SentimentController::class, 'index'])->name('sentiments.index');

        // Buy Predictor route - AI-powered buy prediction analysis
        Route::get('buy-predictor', [\App\Http\Controllers\BuyPredictorController::class, 'index'])->name('buy-predictor.index');
        Route::post('buy-predictor/analyze', [\App\Http\Controllers\BuyPredictorController::class, 'analyze'])->name('buy-predictor.analyze');

        // Hybrid Momentum Scan route - Multi-timeframe momentum analysis
        Route::get('hybrid-momentum-scan', [\App\Http\Controllers\HybridMomentumScanController::class, 'index'])->name('hybrid-momentum-scan.index');
        Route::post('hybrid-momentum-scan/scan', [\App\Http\Controllers\HybridMomentumScanController::class, 'scan'])->name('hybrid-momentum-scan.scan');

        // Buy Window Scanner route - Optimal buy window analysis (10:00-11:30 AM ET)
        Route::get('buy-window', [\App\Http\Controllers\BuyWindowController::class, 'index'])->name('buy-window.index');
        Route::post('buy-window/scan', [\App\Http\Controllers\BuyWindowController::class, 'scan'])->name('buy-window.scan');
        Route::get('buy-window/scan', function () {
            return redirect()->route('buy-window.index');
        })->name('buy-window.scan-redirect');

        // Upload Webull Data route
        Route::get('upload-webull-data', [\App\Http\Controllers\UploadWebullDataController::class, 'index'])->name('upload-webull-data.index');
        Route::post('upload-webull-data', [\App\Http\Controllers\UploadWebullDataController::class, 'upload'])->name('upload-webull-data.upload');

        // Webull Transactions route
        Route::get('webull-transactions', [\App\Http\Controllers\WebullTransactionController::class, 'index'])->name('webull-transactions.index');
        Route::patch('webull-transactions/{transaction}/notes', [\App\Http\Controllers\WebullTransactionController::class, 'updateNotes'])->name('webull-transactions.update-notes');
    });

    // Quick Import Routes - require authentication
    Route::middleware('not_guest')->group(function () {
        Route::get('quick-import', [\App\Http\Controllers\QuickImportController::class, 'index'])->name('quick-import.index');
        Route::post('quick-import/parse', [\App\Http\Controllers\QuickImportController::class, 'parse'])->name('quick-import.parse');
    });

    Route::resource('deposits', \App\Http\Controllers\DepositController::class);
    Route::resource('stock-transactions', \App\Http\Controllers\StockTransactionController::class);

    // Market Data Routes
    Route::get('market-data/assets', [\App\Http\Controllers\AssetInfoController::class, 'index'])->name('asset-info.index');
    Route::get('market-data/assets/add', function () {
        if (! auth()->user()?->isAdmin()) {
            abort(403, 'Only administrators can add new symbols.');
        }

        return \Inertia\Inertia::render('market-data/asset-info/add');
    })->name('asset-info.create');
    Route::post('market-data/assets', [\App\Http\Controllers\AssetInfoController::class, 'store'])->name('asset-info.store');
    Route::get('market-data/assets/search', [\App\Http\Controllers\AssetInfoController::class, 'search'])->name('asset-info.search');
    // No longer needed - descriptions are now included in the initial asset list query to eliminate N+1 requests
    // Route::get('api/asset-descriptions', [\App\Http\Controllers\AssetInfoController::class, 'getDescriptions'])->name('asset-info.descriptions');
    Route::get('market-data/assets/{assetInfo}/max-chart-data', [\App\Http\Controllers\AssetInfoController::class, 'getMaxChartData'])->name('asset-info.max-chart-data');
    Route::get('market-data/assets/{assetInfo}/custom-date-chart', [\App\Http\Controllers\AssetInfoController::class, 'getCustomDateChartData'])->name('asset-info.custom-date-chart');
    Route::get('market-data/assets/{assetInfo}/candlestick-chart', [\App\Http\Controllers\AssetInfoController::class, 'getCandlestickChartData'])->name('asset-info.candlestick-chart');
    Route::get('market-data/assets/{assetInfo}/live-quote', [\App\Http\Controllers\AssetInfoController::class, 'getLiveQuote'])->name('asset-info.live-quote');
    Route::get('market-data/assets/{assetInfo}', [\App\Http\Controllers\AssetInfoController::class, 'show'])->name('asset-info.show');
    Route::get('market-data/daily-prices', [\App\Http\Controllers\DailyPriceController::class, 'index'])->name('daily-prices.index');
    Route::get('market-data/daily-prices/symbols', [\App\Http\Controllers\DailyPriceController::class, 'symbols'])->name('daily-prices.symbols');
    Route::get('market-data/hourly-prices', [\App\Http\Controllers\HourlyPriceController::class, 'index'])->name('hourly-prices.index');
    Route::get('market-data/hourly-prices/symbols', [\App\Http\Controllers\HourlyPriceController::class, 'symbols'])->name('hourly-prices.symbols');
    Route::get('market-data/technical-analysis', [\App\Http\Controllers\Market\TechnicalAnalysisController::class, 'index'])->name('technical-analysis.index');

    // Price Data Routes
    Route::get('price-data/one-minute', [\App\Http\Controllers\PriceDataController::class, 'oneMinute'])->name('price-data.one-minute');
    Route::get('price-data/five-minute', [\App\Http\Controllers\PriceDataController::class, 'fiveMinute'])->name('price-data.five-minute');
    Route::get('price-data/daily', [\App\Http\Controllers\PriceDataController::class, 'daily'])->name('price-data.daily');
    Route::get('price-data/latest-quotes', [\App\Http\Controllers\PriceDataController::class, 'latestQuotes'])->name('price-data.latest-quotes');

    // Market Strength Route
    Route::get('market-strength', [\App\Http\Controllers\MarketStrengthController::class, 'index'])->name('market-strength');
    Route::get('market-strength/export', [\App\Http\Controllers\MarketStrengthController::class, 'export'])->name('market-strength.export');

    // Market Movers Route
    Route::get('market-movers', [\App\Http\Controllers\MarketMoversController::class, 'index'])->name('market-movers');
    Route::get('market-movers/export', [\App\Http\Controllers\MarketMoversController::class, 'export'])->name('market-movers.export');

    // Support Routes
    Route::get('support/help-desk', [\App\Http\Controllers\Support\HelpDeskController::class, 'index'])->name('support.help-desk');
    Route::post('support/help-desk', [\App\Http\Controllers\Support\HelpDeskController::class, 'store'])->name('support.help-desk.store');
    Route::get('support/feature-request', [\App\Http\Controllers\Support\FeatureRequestController::class, 'index'])->name('support.feature-request');
    Route::post('support/feature-request', [\App\Http\Controllers\Support\FeatureRequestController::class, 'store'])->name('support.feature-request.store');
    Route::get('support/careers', [\App\Http\Controllers\Support\CareerController::class, 'index'])->name('support.careers');
    Route::post('support/careers', [\App\Http\Controllers\Support\CareerController::class, 'store'])->name('support.careers.store');
});

require __DIR__.'/settings.php';
