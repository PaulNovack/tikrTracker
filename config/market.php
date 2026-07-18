<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Market Analysis Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration options for market analysis and filtering
    |
    */

    /**
     * Minimum daily volume threshold for stock analysis
     * Stocks below this average daily volume will be filtered out
     * Default: 1,000,000 shares
     */
    'min_daily_volume' => (int) env('MARKET_MIN_DAILY_VOLUME', 1000000),

    /**
     * Lookback period in minutes for technical analysis
     * How far back to analyze price action for rising stock detection
     * Default: 90 minutes
     */
    'lookback_minutes' => (int) env('MARKET_LOOKBACK_MINUTES', 90),

    /**
     * Consecutive bars downward tolerance percentage
     * Allows for slight downward movement in bars while still considering the trend as upward
     * This helps filter out minor market noise while maintaining trend integrity
     * Example: 0.1 means allow up to 0.1% downward movement in any single bar
     * Default: 0.0% (strict upward movement only)
     */
    'consecutive_bars_downward_tolerance_pct' => (float) env('MARKET_CONSECUTIVE_BARS_DOWNWARD_TOLERANCE_PCT', 0.0),

    /*
    |--------------------------------------------------------------------------
    | Pre-Analysis Filtering Configuration
    |--------------------------------------------------------------------------
    |
    | Filters based on patterns observed before the analysis time to improve
    | success rate by filtering out likely negative performers
    |
    */

    /**
     * Enable pre-analysis filtering based on historical patterns
     * When enabled, applies volume and volatility filters to improve accuracy
     * Default: false (disabled)
     */
    'enable_pre_analysis_filtering' => (bool) env('MARKET_ENABLE_PRE_ANALYSIS_FILTERING', false),

    /**
     * Minimum volume ratio for pre-analysis filtering
     * Early volume (first 15 min) / Later volume (last 15 min) must be >= this value
     * Higher ratios indicate stronger early interest which correlates with better performance
     * Default: 1.3 (early volume must be 30% higher than later volume)
     */
    'pre_analysis_min_volume_ratio' => (float) env('MARKET_PRE_ANALYSIS_MIN_VOLUME_RATIO', 1.3),

    /*
    |--------------------------------------------------------------------------
    | Buy Signals Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for buy signal generation and stop loss calculation
    |
    */

    /**
     * Stop loss buffer percentage below the candle low
     * This percentage is subtracted from the candle low to create a looser stop loss
     * Example: 0.5 means stop loss is 0.5% below the candle low
     * Default: 0.5%
     */
    'buy_signals' => [
        'stop_loss_buffer_pct' => (float) env('BUY_SIGNAL_STOP_LOSS_BUFFER_PCT', 0.5),
    ],

];
