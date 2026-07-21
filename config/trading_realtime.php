<?php

return [
    'enabled' => env('TRADING_REALTIME_ENABLED', true),

    /**
     * Long-running watcher loop speed. 1000ms is a safe starting point.
     * If your Redis/DB load is fine, try 500ms later.
     */
    'loop_sleep_ms' => (int) env('TRADING_REALTIME_LOOP_SLEEP_MS', 1000),

    /**
     * Candidate rows should be short-lived. If the move does not produce
     * a fresh entry quickly, it is probably no longer the same trade.
     */
    'candidate_ttl_seconds' => (int) env('TRADING_CANDIDATE_TTL_SECONDS', 180),

    /**
     * After a candidate for a symbol is triggered or rejected, suppress new
     * candidates for the same symbol for this many seconds. Prevents the same
     * momentum burst from producing one alert per loop iteration.
     * Set to 0 to disable.
     */
    'candidate_cooldown_seconds' => (int) env('TRADING_CANDIDATE_COOLDOWN_SECONDS', 300),

    /**
     * How many symbols to scan each loop. Use your asset_info 1_min universe.
     */
    'watch_symbols_limit' => (int) env('TRADING_WATCH_SYMBOLS_LIMIT', 500),

    /**
     * Redis/cache freshness.
     */
    'max_quote_age_seconds' => (int) env('TRADING_MAX_QUOTE_AGE_SECONDS', 5),

    /**
     * Looser quote age limit used only during candidate detection (not order entry).
     * Many stocks only push a quote tick every 30-90s even during market hours.
     * The strict max_quote_age_seconds gate applies at actual order entry.
     */
    'max_candidate_quote_age_seconds' => (int) env('TRADING_MAX_CANDIDATE_QUOTE_AGE_SECONDS', 60),
    'quote_cache_ttl_seconds' => (int) env('TRADING_QUOTE_CACHE_TTL_SECONDS', 15),
    'partial_bar_cache_ttl_seconds' => (int) env('TRADING_PARTIAL_BAR_CACHE_TTL_SECONDS', 90),

    /**
     * Early candidate scoring.
     * score_min lowered from 70 to 55 — new scorer uses better metrics
     * so the threshold is more meaningful.
     */
    'early_score_min' => (float) env('TRADING_EARLY_SCORE_MIN', 65),
    'min_price' => (float) env('TRADING_REALTIME_MIN_PRICE', 5.0),
    'min_dollar_volume_1m' => (float) env('TRADING_MIN_DOLLAR_VOLUME_1M', 50000),
    'max_spread_pct' => (float) env('TRADING_MAX_SPREAD_PCT', 0.35),
    'max_vwap_extension_pct' => (float) env('TRADING_MAX_VWAP_EXTENSION_PCT', 2.0),

    /**
     * Pipeline H/K/A quality gates applied to every realtime candidate.
     * These use indicators computed by stream_bars.py and stored in Redis.
     */
    'min_atr_pct' => (float) env('TRADING_REALTIME_MIN_ATR_PCT', 0.25),
    'min_rvol' => (float) env('TRADING_REALTIME_MIN_RVOL', 1.5),
    'min_move_30m_pct' => (float) env('TRADING_REALTIME_MIN_MOVE_30M_PCT', 0.5),

    /**
     * Entry freshness / no-chase protection.
     */
    'max_entry_age_seconds' => (int) env('TRADING_MAX_ENTRY_AGE_SECONDS', 60),
    'max_move_since_entry_pct' => (float) env('TRADING_MAX_MOVE_SINCE_ENTRY_PCT', 0.35),
    'max_move_since_candidate_pct' => (float) env('TRADING_MAX_MOVE_SINCE_CANDIDATE_PCT', 0.75),

    /**
     * Default realtime entry trigger thresholds used if you do not configure
     * a custom OneMinuteEntryFinder class.
     */
    'entry_volume_ratio_min' => (float) env('TRADING_ENTRY_VOLUME_RATIO_MIN', 1.50),
    'entry_return_1m_min_pct' => (float) env('TRADING_ENTRY_RETURN_1M_MIN_PCT', 0.10),
    'entry_above_candidate_min_pct' => (float) env('TRADING_ENTRY_ABOVE_CANDIDATE_MIN_PCT', -0.10),
    /**
     * Additional realtime entry gates — DB-backed via TradingSettingService.
     *
     * @see \App\Services\TradingSettingService for the service-layer methods.
     */
    'entry_candidate_max_age_seconds' => (int) env('RT_ENTRY_CANDIDATE_MAX_AGE_SECONDS', 180),
    'entry_final_score_min' => (float) env('RT_ENTRY_FINAL_SCORE_MIN', 70),
    'entry_min_price' => (float) env('RT_ENTRY_MIN_PRICE', 1.00),
    'entry_max_price' => (float) env('RT_ENTRY_MAX_PRICE', 200.00),
    'entry_require_vwap' => (bool) env('RT_ENTRY_REQUIRE_VWAP', true),
    'entry_return_3m_min_pct' => (float) env('RT_ENTRY_RETURN_3M_MIN_PCT', 0.20),
    'entry_min_dollar_volume_1m' => (float) env('RT_ENTRY_MIN_DOLLAR_VOLUME_1M', 10000),
    'entry_close_position_min' => (float) env('RT_ENTRY_CLOSE_POSITION_MIN', 0.60),
    'entry_upper_wick_max' => (float) env('RT_ENTRY_UPPER_WICK_MAX', 0.45),
    'entry_bid_ask_imbalance_min' => (float) env('RT_ENTRY_BID_ASK_IMBALANCE_MIN', -0.20),
    'entry_require_ema9_above_ema21' => (bool) env('RT_ENTRY_REQUIRE_EMA9_ABOVE_EMA21', true),

    /**
     * Skip-first-minutes: blocks Pipeline R orders within X minutes of 9:30 AM EST.
     * Overrides the realtime time slots for the opening minutes. Set to 0 to disable.
     * Example: 4 = first order can enter at 9:34 AM or later.
     */
    'skip_first_minutes' => (int) env('RT_SKIP_FIRST_MINUTES', 0),
    /**
     * Optional: set this to your existing entry finder class. The class should
     * have findBestLong(string $symbol, string $assetType, string $signalTsEst, string $asOfTsEst, ...).
     * Example:
     * TRADING_REALTIME_ENTRY_FINDER_CLASS=App\\Services\\Trading\\OneMinuteEntryFinderV2000_1
     */
    'entry_finder_class' => env('TRADING_REALTIME_ENTRY_FINDER_CLASS'),

    /**
     * Optional integration hooks after a trade_alert is created.
     * Use one of these to trigger your current scoring pipeline.
     *
     * If score_job_class is set and has a static dispatch method, this code calls:
     *   YourJob::dispatch($alert->id, 'trade_alerts', $pipelineRun)
     *
     * If created_event_class is set, this code calls:
     *   event(new YourEvent($alert))
     */
    'pipeline_run' => env('TRADING_REALTIME_PIPELINE_RUN', 'A'),
    'score_job_class' => env('TRADING_REALTIME_SCORE_JOB_CLASS'),
    'created_event_class' => env('TRADING_REALTIME_CREATED_EVENT_CLASS'),

    /**
     * RealtimeMomentumContinuationFinder settings.
     * These are configurable via the /trading-settings UI (Realtime tab).
     */
    'consolidation_max_range_pct' => (float) env('TRADING_REALTIME_CONSOLIDATION_MAX_RANGE_PCT', 0.8),
    'breakout_min_vol_ratio' => (float) env('TRADING_REALTIME_BREAKOUT_MIN_VOL_RATIO', 1.30),
    'max_vwap_extension_pct_finder' => (float) env('TRADING_REALTIME_MAX_VWAP_EXTENSION_FINDER_PCT', 1.75),
    'structure_lookback_bars' => (int) env('TRADING_REALTIME_STRUCTURE_LOOKBACK_BARS', 5),
    'consolidation_bar_count' => (int) env('TRADING_REALTIME_CONSOLIDATION_BAR_COUNT', 3),

    /**
     * Additional entry finders to run alongside the primary (configured) one.
     * Each class must implement findBestLong(…) with the same signature and
     * return ['ok' => 1, 'best_entry' => [...], 'pipeline_run' => 'S', …].
     *
     * Add class names here (not via env) to avoid redeclaration issues in
     * long-running daemon processes.
     */
    'additional_entry_finders' => [
        // Uncomment to enable VWAP reversal finder (Pipeline S):
        \App\Services\Trading\Realtime\RealtimeVwapReversalFinder::class,
    ],

    /**
     * VWAP Reversal Finder (Pipeline S) settings.
     */
    'vwap_reversal' => [
        'vwap_extension_pct' => (float) env('TRADING_VWAP_REVERSAL_EXTENSION_PCT', 2.0),
        'min_extension_bars' => (int) env('TRADING_VWAP_REVERSAL_MIN_EXTENSION_BARS', 3),
        'volume_ratio_min' => (float) env('TRADING_VWAP_REVERSAL_VOLUME_RATIO_MIN', 1.5),
        'stop_buffer_atr' => (float) env('TRADING_VWAP_REVERSAL_STOP_BUFFER_ATR', 1.5),
        'lookback_bars' => (int) env('TRADING_VWAP_REVERSAL_LOOKBACK_BARS', 15),
    ],

    /**
     * Internal ingest endpoints are optional. If your Alpaca stream writes
     * directly to Redis, you do not need the controller/routes.
     */
    'internal_ingest_secret' => env('INTERNAL_MARKET_DATA_SECRET'),

    /**
     * Table names. Kept configurable so this can fit existing projects.
     */
    'tables' => [
        'asset_info' => env('TRADING_ASSET_INFO_TABLE', 'asset_info'),
        'one_minute_prices' => env('TRADING_ONE_MINUTE_PRICES_TABLE', 'one_minute_prices'),
        'trade_alerts' => env('TRADING_TRADE_ALERTS_TABLE', 'trade_alerts'),
    ],

    /**
     * Your asset_info column for symbols that have 1-minute data enabled.
     * Your project has used a `1_min` flag, so that is the default.
     */
    'asset_info_one_min_column' => env('TRADING_ASSET_INFO_ONE_MIN_COLUMN', '1_min'),
];
