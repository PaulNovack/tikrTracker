<?php

/*
|------------------------------------------------------------------------------
| ACTIVE PIPELINE CONFIGURATIONS (June 2026)
|------------------------------------------------------------------------------
|
| Currently Used:
| - v25:  Pipeline H (v25.2)
| - v60:  Pipeline D (v60.3)
| - v90:  Pipeline A (v90.1)
| - v120: Pipeline B (v120.0)
| - v600: Pipeline C (v600.0)
| - v400: Pipeline E (v400.0)
| - v900: Pipeline F (v900.0) - Momentum Continuation Scanner
| - v100: (not actively used in pipelines but referenced)
| - v17:  Pipeline I (v17.0) - uses active_window_minutes config
| - v210: Pipeline G (v210.0) - uses config values
| - v2000: Pipeline J (v2000.0) - Recent 4 Percent Plus Movers
|
| Unused/Available for Testing:
| - v140: Institutional Follow-Through (NEW - not yet assigned to pipeline)
| - v26, v70, v80: No pipeline uses these
| - v130: Explicitly disabled in .env ("generates too much noise")
| - v110, v200, v300: No pipeline uses these
| - v14: Replaced by v900 in Pipeline F
|
| All pipelines now use AUTO_ALPACA_STOP_LOSS_ATR_MULTIPLIER=2.0 for consistent
| trailing stops (61.1% win rate, 0.71% avg P&L, 2.85 profit factor)
|
*/

return [
    /*
    |--------------------------------------------------------------------------
    | Global Trading Configuration
    |--------------------------------------------------------------------------
    */

    // Token for C++ scanner daemon to authenticate trade signal posts
    'cpp_signal_token' => env('CPP_TRADE_TOKEN', ''),

    // Market benchmark symbol for relative strength filters
    'market_benchmark_symbol' => env('TRADING_MARKET_BENCHMARK_SYMBOL', 'QQQM'),

    // Enable/disable relative strength filtering globally (can be overridden per version)
    'enable_relative_strength_filter' => (bool) env('TRADING_ENABLE_RS_FILTER', false),

    // Position sizing configuration
    'position_size_mode' => env('AUTO_ALPACA_POSITION_SIZE_MODE', 'fixed'), // 'fixed' or 'dynamic'

    /*
    |--------------------------------------------------------------------------
    | Scanner Configuration
    |--------------------------------------------------------------------------
    */

    'scanner' => [
        // Enable/disable the CDL3WHITESOLDIERS candlestick scanner command (scan:three-white-soldiers-live)
        'three_white_soldiers_enabled' => (bool) env('TRADING_SCANNER_THREE_WHITE_SOLDIERS_ENABLED', false),
    ],
    'max_position_pct_of_liquidity' => (float) env('AUTO_ALPACA_MAX_POSITION_PCT_OF_LIQUIDITY', 10.0),
    'min_position_size' => (float) env('AUTO_ALPACA_MIN_POSITION_SIZE', 500),
    'max_position_size' => (float) env('AUTO_ALPACA_MAX_POSITION_SIZE', 5000),
    'position_size_slippage_rule' => [
        'enabled' => (bool) env('AUTO_ALPACA_POSITION_SIZE_SLIPPAGE_RULE_ENABLED', false),
        'window_days' => (int) env('AUTO_ALPACA_POSITION_SIZE_SLIPPAGE_RULE_WINDOW_DAYS', 30),
        'min_samples' => (int) env('AUTO_ALPACA_POSITION_SIZE_SLIPPAGE_RULE_MIN_SAMPLES', 80),
        'cache_seconds' => (int) env('AUTO_ALPACA_POSITION_SIZE_SLIPPAGE_RULE_CACHE_SECONDS', 300),
        'include_paper_orders' => (bool) env('AUTO_ALPACA_POSITION_SIZE_SLIPPAGE_RULE_INCLUDE_PAPER', true),
        'low_liquidity_pct' => (float) env('AUTO_ALPACA_POSITION_SIZE_SLIPPAGE_RULE_LOW_PCT', 10.0),
        'medium_liquidity_pct' => (float) env('AUTO_ALPACA_POSITION_SIZE_SLIPPAGE_RULE_MEDIUM_PCT', 12.5),
        'high_liquidity_pct' => (float) env('AUTO_ALPACA_POSITION_SIZE_SLIPPAGE_RULE_HIGH_PCT', 15.0),
        'medium_risk_avg_slippage_pct' => (float) env('AUTO_ALPACA_POSITION_SIZE_SLIPPAGE_RULE_MEDIUM_RISK_AVG_SLIPPAGE_PCT', 0.06),
        'medium_risk_worst_slippage_pct' => (float) env('AUTO_ALPACA_POSITION_SIZE_SLIPPAGE_RULE_MEDIUM_RISK_WORST_SLIPPAGE_PCT', 0.80),
        'high_risk_avg_slippage_pct' => (float) env('AUTO_ALPACA_POSITION_SIZE_SLIPPAGE_RULE_HIGH_RISK_AVG_SLIPPAGE_PCT', 0.12),
        'high_risk_worst_slippage_pct' => (float) env('AUTO_ALPACA_POSITION_SIZE_SLIPPAGE_RULE_HIGH_RISK_WORST_SLIPPAGE_PCT', 1.50),
        'min_liquidity_pct' => (float) env('AUTO_ALPACA_POSITION_SIZE_SLIPPAGE_RULE_MIN_PCT', 10.0),
        'max_liquidity_pct' => (float) env('AUTO_ALPACA_POSITION_SIZE_SLIPPAGE_RULE_MAX_PCT', 20.0),
    ],

    /*
    |--------------------------------------------------------------------------
    | Market Regime Configuration
    |--------------------------------------------------------------------------
    |
    | Use market strength data (from market_movers table) to adjust trading
    | behavior based on market conditions (STRONG, MODERATE, WEAK)
    |
    */
    'market_regime' => [
        // Enable market regime-based trading adjustments
        'enabled' => (bool) env('TRADING_MARKET_REGIME_ENABLED', false),

        // Minimum market label required to trade (WEAK, MODERATE, STRONG)
        'min_label' => env('TRADING_MARKET_REGIME_MIN_LABEL', 'WEAK'),

        // Trade when no regime data is available?
        'trade_without_data' => (bool) env('TRADING_MARKET_REGIME_TRADE_WITHOUT_DATA', true),

        // Position size multipliers by regime
        'strong_multiplier' => (float) env('TRADING_MARKET_REGIME_STRONG_MULTIPLIER', 1.5),
        'moderate_multiplier' => (float) env('TRADING_MARKET_REGIME_MODERATE_MULTIPLIER', 1.0),
        'weak_multiplier' => (float) env('TRADING_MARKET_REGIME_WEAK_MULTIPLIER', 0.5),

        // Filter strictness multipliers (higher = stricter)
        'strong_filter_multiplier' => (float) env('TRADING_MARKET_REGIME_STRONG_FILTER', 0.8),
        'moderate_filter_multiplier' => (float) env('TRADING_MARKET_REGIME_MODERATE_FILTER', 1.0),
        'weak_filter_multiplier' => (float) env('TRADING_MARKET_REGIME_WEAK_FILTER', 1.5),

        // Signal processing multipliers
        'strong_signal_multiplier' => (float) env('TRADING_MARKET_REGIME_STRONG_SIGNALS', 1.5),
        'weak_signal_multiplier' => (float) env('TRADING_MARKET_REGIME_WEAK_SIGNALS', 0.5),
    ],

    /*
    |--------------------------------------------------------------------------
    | Market Movers Universe Expansion
    |--------------------------------------------------------------------------
    |
    | Add explosive movers (4%+ intraday gains) to pipeline universes
    | Set to 0 to disable, or specify number of top movers to add (e.g., 100)
    | Data sourced from market_movers table (updated daily at 5 PM CST)
    | Automatically uses most recent trading day data
    |
    */
    'market_movers' => [
        'pipeline_a' => (int) env('PIPELINE_A_ADD_MOVERS', 100),
        'pipeline_b' => (int) env('PIPELINE_B_ADD_MOVERS', 100),
        'pipeline_c' => (int) env('PIPELINE_C_ADD_MOVERS', 0),
        'pipeline_d' => (int) env('PIPELINE_D_ADD_MOVERS', 0),
        'pipeline_e' => (int) env('PIPELINE_E_ADD_MOVERS', 100),
        'pipeline_f' => (int) env('PIPELINE_F_ADD_MOVERS', 100),
        'pipeline_g' => (int) env('PIPELINE_G_ADD_MOVERS', 0),
        'pipeline_h' => (int) env('PIPELINE_H_ADD_MOVERS', 0),
        'pipeline_i' => (int) env('PIPELINE_I_ADD_MOVERS', 0),
        'pipeline_j' => (int) env('PIPELINE_J_ADD_MOVERS', 100),
        'pipeline_j_add_intraday_universe' => (bool) env('PIPELINE_J_ADD_INTRADAY_UNIVERSE', false),
        'pipeline_k' => (int) env('PIPELINE_K_ADD_MOVERS', 0),
        'pipeline_l' => (int) env('PIPELINE_L_ADD_MOVERS', 100),
        'pipeline_m' => (int) env('PIPELINE_M_ADD_MOVERS', 0),
        'pipeline_biased1' => (int) env('PIPELINE_BIASED1_ADD_MOVERS', 0),
    ],

    // Global liquidity filter: minimum average dollar volume per minute
    // Set to 0 to disable. Can be lowered when using dynamic sizing (e.g., 5000)
    'min_dollar_volume_per_minute' => (int) env('AUTO_ALPACA_MIN_DOLLAR_VOLUME_PER_MIN', 0),

    /*
    |--------------------------------------------------------------------------
    | Trading Version 25.0 Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for the V25.0 trading algorithm (quality-first)
    |
    */
    'v25' => [
        'debug' => (bool) env('ENTRYFINDER_V25_DEBUG', false),

        'scanner' => [
            'enable_rs_filter' => (bool) env('TRADING_V25_ENABLE_RS_FILTER', false),
            'active_window_minutes' => (int) env('TRADING_V25_ACTIVE_WINDOW_MINUTES', 20),
            'top_days' => (int) env('TRADING_V25_TOP_DAYS', 5),
            'top_limit' => (int) env('TRADING_V25_TOP_LIMIT', 500),
            'losers_limit' => (int) env('TRADING_V25_LOSERS_LIMIT', 75),
            'min_notional_5m' => (float) env('TRADING_V25_MIN_NOTIONAL_5M', 300000.0),
            'min_atr_pct_5m' => (float) env('TRADING_V25_MIN_ATR_PCT_5M', 1.00),
            'min_rvol_5m' => (float) env('TRADING_V25_MIN_RVOL_5M', 2.0),
            'min_move_30m_pct' => (float) env('TRADING_V25_MIN_MOVE_30M_PCT', 1.2),
            'min_rs_mult_vs_spy' => (float) env('TRADING_V25_MIN_RS_MULT_VS_SPY', 1.20),
        ],

        'entry' => [
            'min_bars' => (int) env('TRADING_V25_ENTRY_MIN_BARS', 15),
            'analysis_lookback_minutes' => (int) env('TRADING_V25_ANALYSIS_LOOKBACK_MINUTES', 90),
            'max_entry_age_minutes' => (int) env('TRADING_V25_MAX_ENTRY_AGE_MINUTES', 20),
            'min_notional_1m' => (float) env('TRADING_V25_MIN_NOTIONAL_1M', 80000),
            'min_vol_ratio_1m' => (float) env('TRADING_V25_MIN_VOL_RATIO_1M', 1.0),
            'min_body_pct_1m' => (float) env('TRADING_V25_MIN_BODY_PCT_1M', 0.05),
            'max_above_vwap_entry_pct' => (float) env('TRADING_V25_MAX_ABOVE_VWAP_ENTRY_PCT', 0.90),
            'min_room_to_run_pct' => (float) env('TRADING_V25_MIN_ROOM_TO_RUN_PCT', 1.0),
            'room_atr_mult' => (float) env('TRADING_V25_ROOM_ATR_MULT', 2.0),
            'allow_lunch' => (bool) env('TRADING_V25_ALLOW_LUNCH', false),
            // trail_atr_mult removed - now uses AUTO_ALPACA_STOP_LOSS_ATR_MULTIPLIER
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Trading Version 27.0 Configuration (Pipeline Q - Volume-First)
    |--------------------------------------------------------------------------
    |
    | Configuration for the V27.0 trading algorithm
    | Volume-First scanner - wider net, tighter entry quality
    |
    */
    'v27' => [
        'debug' => (bool) env('ENTRYFINDER_V27_DEBUG', false),

        'scanner' => [
            'enable_rs_filter' => (bool) env('TRADING_V27_ENABLE_RS_FILTER', false),
            'active_window_minutes' => (int) env('TRADING_V27_ACTIVE_WINDOW_MINUTES', 8),
            'top_days' => (int) env('TRADING_V27_TOP_DAYS', 5),
            'top_limit' => (int) env('TRADING_V27_TOP_LIMIT', 600),
            'losers_limit' => (int) env('TRADING_V27_LOSERS_LIMIT', 100),
            'min_price' => (float) env('TRADING_V27_MIN_PRICE', 2.0),
            'min_notional_5m' => (float) env('TRADING_V27_MIN_NOTIONAL_5M', 30000.0),
            'min_atr_pct_5m' => (float) env('TRADING_V27_MIN_ATR_PCT_5M', 0.15),
            'min_rvol_5m' => (float) env('TRADING_V27_MIN_RVOL_5M', 1.2),
            'min_move_30m_pct' => (float) env('TRADING_V27_MIN_MOVE_30M_PCT', 0.4),
            'max_rvol_spike' => (float) env('TRADING_V27_MAX_RVOL_SPIKE', 10.0),
            'min_green_days' => (int) env('TRADING_V27_MIN_GREEN_DAYS', 1),
            'min_rs_mult_vs_spy' => (float) env('TRADING_V27_MIN_RS_MULT_VS_SPY', 1.05),
        ],

        'entry' => [
            'min_bars' => (int) env('TRADING_V27_ENTRY_MIN_BARS', 15),
            'analysis_lookback_minutes' => (int) env('TRADING_V27_ANALYSIS_LOOKBACK_MINUTES', 90),
            'max_entry_age_minutes' => (int) env('TRADING_V27_MAX_ENTRY_AGE_MINUTES', 12),
            'min_notional_1m' => (float) env('TRADING_V27_MIN_NOTIONAL_1M', 100000.0),
            'min_vol_ratio_1m' => (float) env('TRADING_V27_MIN_VOL_RATIO_1M', 1.5),
            'min_body_pct_1m' => (float) env('TRADING_V27_MIN_BODY_PCT_1M', 0.10),
            'max_above_vwap_entry_pct' => (float) env('TRADING_V27_MAX_ABOVE_VWAP_ENTRY_PCT', 0.75),
            'min_room_to_run_pct' => (float) env('TRADING_V27_MIN_ROOM_TO_RUN_PCT', 0.6),
            'room_atr_mult' => (float) env('TRADING_V27_ROOM_ATR_MULT', 1.5),
            'allow_lunch' => (bool) env('TRADING_V27_ALLOW_LUNCH', false),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Trading Version 60.X Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for the V60.X trading algorithms (v60.0, v60.1, v60.2, v60.3)
    | Hybrid scanner with EntryScore confirmation
    |
    */
    'v60' => [
        // 5m scanner signal score range (multi-day momentum scoring system)
        'entry_score_min' => (float) env('V60_ENTRY_SCORE_MIN', 40),
        'entry_score_max' => (float) env('V60_ENTRY_SCORE_MAX', 100),
        'entry_score_limit' => (int) env('V60_ENTRY_SCORE_LIMIT', 60),
        // Composite (move_pct × vol_ratio) minimum - prevents weak signals where both barely pass individually
        'min_composite' => (float) env('V60_MIN_COMPOSITE', 2.0),
    ],

    /*
    |--------------------------------------------------------------------------
    | Trading Version 90.0 Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for the V90.0 trading algorithm
    | Momentum Continuation Scanner - catches multi-day runners
    |
    */
    'v90' => [
        'entry_score_min' => (float) env('V90_ENTRY_SCORE_MIN', 85),
        'entry_score_max' => (float) env('V90_ENTRY_SCORE_MAX', 100),
        'entry_score_limit' => (int) env('V90_ENTRY_SCORE_LIMIT', 80),
        'min_yesterday_move' => (float) env('V90_MIN_YESTERDAY_MOVE', 5.0),
        'min_vol_mult' => (float) env('V90_MIN_VOL_MULT', 1.5),
    ],

    /*
    |--------------------------------------------------------------------------
    | Trading Version 600.0 Configuration - 3-5% Feasible Momentum Entries
    |--------------------------------------------------------------------------
    |
    | Configuration for the V600.0 trading algorithm
    | Targets entries with realistic 3-5%+ potential using quality gates
    |
    | Quality Gates:
    | - ATR% minimum: ensures stock can move big enough (0.25%+ default)
    | - Volume confirmation: strong volume required (1.80x+ default)
    | - Time filter: avoid late-day low-probability entries (before 2pm)
    | - Big-move readiness: composite score of ATR, volume, trend (0.48+ default)
    |
    | Scanner pairs with FiveMinuteSignalScannerV600_0 for yesterday's big movers
    |
    */
    'v600' => [
        // Relative strength filter
        'enable_rs_filter' => (bool) env('V600_ENABLE_RS_FILTER', false),

        // Entry score filtering (very relaxed to get picks daily)
        'entry_score_min' => (float) env('V600_ENTRY_SCORE_MIN', 40),
        'entry_score_max' => (float) env('V600_ENTRY_SCORE_MAX', 100),
        'entry_score_limit' => (int) env('V600_ENTRY_SCORE_LIMIT', 120),

        // Feasibility gates (relaxed - moderate volatility can hit 3%+)
        'entry_min_atr_pct' => (float) env('V600_ENTRY_MIN_ATR_PCT', 0.10),
        'entry_min_vol_ratio' => (float) env('V600_ENTRY_MIN_VOL_RATIO', 1.25),
        'finder_entry_min_vol_ratio' => (float) env('V600_FINDER_ENTRY_MIN_VOL_RATIO', 2.0),
        'max_entry_hour' => (int) env('V600_MAX_ENTRY_HOUR', 15),
        'min_big_move_score' => (float) env('V600_MIN_BIG_MOVE_SCORE', 0.20),

        // Scanner parameters (FiveMinuteSignalScannerV600_0) - relaxed for more picks
        'min_yesterday_move' => (float) env('V600_MIN_YESTERDAY_MOVE', 3.0),
        'min_vol_mult' => (float) env('V600_MIN_VOL_MULT', 1.25),
        'min_move_from_open' => (float) env('V600_MIN_MOVE_FROM_OPEN', 0.80),
        'min_vol_ratio' => (float) env('V600_MIN_VOL_RATIO', 1.25),
        'min_atr_pct' => (float) env('V600_MIN_ATR_PCT', 0.25),
        'max_vwap_dist_below' => (float) env('V600_MAX_VWAP_DIST_BELOW', 0.50),
    ],

    /*
    |--------------------------------------------------------------------------
    | Trading Version 700.0 Configuration - Risk-Off Winners / 4-Green-Days Style
    |--------------------------------------------------------------------------
    |
    | Configuration for the V700.0 LONG algorithm
    | Identifies stocks showing relative strength during market weakness
    |
    | Signal Requirements:
    | - Persistently above VWAP (time-above-VWAP tracking)
    | - 5m uptrend (EMA9 > EMA21)
    | - Relative Strength vs market proxy (QQQ/ONEQ)
    | - Controlled pullbacks / net progress (not choppy)
    |
    | Entry Triggers:
    | - VWAP pullback hold: dips to VWAP/EMA9 but holds and reclaims
    | - Higher-low breakout: forms higher low then breaks micro base
    | - 1m base breakout: tight consolidation breaks above VWAP with volume
    |
    | Similar to stocks with 4 consecutive green days (IONZ, PLTZ style)
    |
    */
    'v700' => [
        // Entry score filtering
        'entry_score_min' => (float) env('V700_ENTRY_SCORE_MIN', 80),
        'entry_score_max' => (float) env('V700_ENTRY_SCORE_MAX', 100),
        'entry_score_limit' => (int) env('V700_ENTRY_SCORE_LIMIT', 15),

        // Market proxy and gates
        'market_proxy_symbol' => env('V700_MARKET_PROXY_SYMBOL', 'ONEQ'),
        'require_market_risk_off' => env('V700_REQUIRE_MARKET_RISK_OFF', false),
        'allow_leveraged_inverse' => env('V700_ALLOW_LEVERAGED_INVERSE', true),

        // 5m scanner parameters - relative strength filters
        'min_above_vwap_bars' => (int) env('V700_MIN_ABOVE_VWAP_BARS', 7),
        'min_rs_pct' => (float) env('V700_MIN_RS_PCT', 0.80),
        'min_vol_ratio' => (float) env('V700_MIN_VOL_RATIO', 1.10),
        'min_atr_pct' => (float) env('V700_MIN_ATR_PCT', 0.20),
        'min_rsi' => (float) env('V700_MIN_RSI', 50),
        'min_net_progress' => (float) env('V700_MIN_NET_PROGRESS', 0.12),

        // Active stock filters
        'min_vol_mult' => (float) env('V700_MIN_VOL_MULT', 0.5),
        'min_price' => (float) env('V700_MIN_PRICE', 3.0),
        'max_price' => (float) env('V700_MAX_PRICE', 500.0),

        // 1m entry finder parameters (OneMinuteEntryFinderV700_0)
        'entry_min_atr_pct' => (float) env('V700_ENTRY_MIN_ATR_PCT', 0.20),
        'entry_min_vol_ratio' => (float) env('V700_ENTRY_MIN_VOL_RATIO', 1.3),
        'max_entry_hour' => (int) env('V700_MAX_ENTRY_HOUR', 14),
        'entry_min_rsi' => (float) env('V700_ENTRY_MIN_RSI', 50),
        'entry_max_rsi' => (float) env('V700_ENTRY_MAX_RSI', 78),

        // Stop loss for longs (below entry)
        'stop_loss_atr_multiplier' => (float) env('V700_STOP_LOSS_ATR_MULTIPLIER', 1.2),
    ],

    /*
    |--------------------------------------------------------------------------
    | Trading Version 120.0 Configuration - Elite Multi-Day Momentum
    |--------------------------------------------------------------------------
    |
    | Configuration for V120.0 - Enhanced multi-day momentum continuation
    | Focus on 40-59 score sweet spot with 5 key enhancements:
    | 1. Multi-day win streak detection (2+ consecutive days)
    | 2. Gap-up continuation filter (holding pre-market gap)
    | 3. Volume pattern analysis (increasing volume trend)
    | 4. Score range optimization (40-59 sweet spot: 5.40% avg P&L)
    | 5. Catalyst awareness metadata
    |
    */
    'v120' => [
        // 5m scanner signal score range (multi-day momentum scoring system)
        'entry_score_min' => (float) env('V120_ENTRY_SCORE_MIN', 40),
        'entry_score_max' => (float) env('V120_ENTRY_SCORE_MAX', 59),
        'entry_score_limit' => (int) env('V120_ENTRY_SCORE_LIMIT', 80),
        // 1m entry finder internal score range (VWAP/EMA/ATR based - different scale)
        'finder_entry_score_min' => (float) env('V120_FINDER_ENTRY_SCORE_MIN', 40),
        'finder_entry_score_max' => (float) env('V120_FINDER_ENTRY_SCORE_MAX', 75),
        'min_consecutive_days' => (int) env('V120_MIN_CONSECUTIVE_DAYS', 2),
        'min_gap_pct' => (float) env('V120_MIN_GAP_PCT', 2.0),
        'require_vol_increase' => (bool) env('V120_REQUIRE_VOL_INCREASE', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Trading Version 130.0 Configuration - High-Probability Patterns
    |--------------------------------------------------------------------------
    |
    | Configuration for V130.0 non-momentum pattern scanner
    | Hunts three proven high-probability patterns:
    | 1. VWAP_BOUNCE: Dips to VWAP, volume confirmation bounce (75%+ WR expected)
    | 2. BULL_FLAG_BREAKOUT: Tight consolidation break with volume (70%+ WR expected)
    | 3. FAILED_BREAKDOWN: Support break reclaim with surge (75%+ WR expected)
    |
    | Designed to complement v120.0 momentum scanner with different setups.
    |
    */
    'v130' => [
        // 5-minute scanner parameters
        'min_score' => (float) env('V130_MIN_SCORE', 45.0),
        'max_score' => (float) env('V130_MAX_SCORE', 100.0),
        'top_n' => (int) env('V130_TOP_N', 50),

        // VWAP Bounce pattern
        'min_vwap_bounce_vol_mult' => (float) env('V130_MIN_VWAP_BOUNCE_VOL_MULT', 2.0),
        'max_vwap_distance_pct' => (float) env('V130_MAX_VWAP_DISTANCE_PCT', 0.2),

        // Bull Flag pattern
        'min_flag_consolidation_bars' => (int) env('V130_MIN_FLAG_CONSOLIDATION_BARS', 5),
        'max_flag_consolidation_bars' => (int) env('V130_MAX_FLAG_CONSOLIDATION_BARS', 10),
        'max_flag_range_pct' => (float) env('V130_MAX_FLAG_RANGE_PCT', 0.3),
        'min_flag_breakout_vol_mult' => (float) env('V130_MIN_FLAG_BREAKOUT_VOL_MULT', 2.5),
        'min_pole_move_pct' => (float) env('V130_MIN_POLE_MOVE_PCT', 3.0),

        // Failed Breakdown pattern
        'min_breakdown_reclaim_vol' => (float) env('V130_MIN_BREAKDOWN_RECLAIM_VOL', 3.0),

        // 1-minute entry finder parameters
        'before_minutes' => (int) env('V130_BEFORE_MINUTES', 10),
        'after_minutes' => (int) env('V130_AFTER_MINUTES', 0),
        'vol_lookback' => (int) env('V130_VOL_LOOKBACK', 20),
        'atr_stop_mult' => (float) env('V130_ATR_STOP_MULT', 2.5),
        // trail_atr_mult removed - now uses AUTO_ALPACA_STOP_LOSS_ATR_MULTIPLIER
        'min_trail_pct' => (float) env('V130_MIN_TRAIL_PCT', 0.60),
    ],

    /*
    |--------------------------------------------------------------------------
    | Trading Version 140.0 Configuration - Institutional Follow-Through
    |--------------------------------------------------------------------------
    |
    | Configuration for V140.0 institutional momentum follow-through strategy
    | Focuses on stocks showing BOTH retail activity AND institutional support.
    |
    | Key Differentiators:
    | - Multi-day consistency (3+ green days in last 5)
    | - Higher price floor ($5+) for institutional liquidity
    | - Sustained elevated volume (not panic spikes)
    | - Stricter quality gates than v25.2
    |
    | Scanner Gates:
    | - Price >= $5 (institutional trading floor)
    | - Notional >= $100k (institutions need size)
    | - ATR >= 0.40% (expansion potential)
    | - Volume 1.5x-5.0x average (sustained, not retail panic)
    | - 30m move >= 1.5% (visible accumulation)
    | - 3+ green days in last 5 (multi-day consistency)
    |
    | Entry Types:
    | - VWAP_HOLD_CONTINUATION: Pullback hold and bounce
    | - HIGHER_LOW_BREAK: Structure breakout with volume
    | - INSTITUTIONAL_ACCUMULATION: Tight consolidation with sustained volume
    |
    | Entry Filters (vs v25.2):
    | - Higher volume floor: 1.3x vs 1.0x (institutions show size)
    | - Stricter VWAP limit: 1.0% vs 1.2% (accumulate on dips)
    | - More room needed: 0.8% vs 0.6% (target bigger moves)
    | - Higher ATR runway: 1.8x vs 1.5x (expansion potential)
    | - More bars required: 20 vs 15 (better structure)
    |
    | Target: 40-60 high-quality institutional continuation picks per day
    */
    'v140' => [
        'debug' => (bool) env('ENTRYFINDER_V140_DEBUG', false),

        'scanner' => [
            'top_days' => (int) env('TRADING_V140_TOP_DAYS', 5),
            'top_limit' => (int) env('TRADING_V140_TOP_LIMIT', 600),
            'gainers_limit' => (int) env('TRADING_V140_GAINERS_LIMIT', 100),
            'min_price' => (float) env('TRADING_V140_MIN_PRICE', 5.0),
            'min_notional_5m' => (float) env('TRADING_V140_MIN_NOTIONAL_5M', 100000.0),
            'min_atr_pct_5m' => (float) env('TRADING_V140_MIN_ATR_PCT_5M', 0.40),
            'min_sustained_vol' => (float) env('TRADING_V140_MIN_SUSTAINED_VOL', 1.5),
            'max_rvol_spike' => (float) env('TRADING_V140_MAX_RVOL_SPIKE', 5.0),
            'min_move_30m_pct' => (float) env('TRADING_V140_MIN_MOVE_30M_PCT', 1.5),
            'min_green_days' => (int) env('TRADING_V140_MIN_GREEN_DAYS', 3),
            'active_window_minutes' => (int) env('TRADING_V140_ACTIVE_WINDOW_MINUTES', 8),
            'analysis_lookback_minutes' => (int) env('TRADING_V140_ANALYSIS_LOOKBACK_MINUTES', 90),
        ],

        'entry' => [
            'min_bars' => (int) env('TRADING_V140_ENTRY_MIN_BARS', 20),
            'analysis_lookback_minutes' => (int) env('TRADING_V140_ANALYSIS_LOOKBACK_MINUTES', 90),
            'max_entry_age_minutes' => (int) env('TRADING_V140_MAX_ENTRY_AGE_MINUTES', 12),
            'min_notional_1m' => (float) env('TRADING_V140_MIN_NOTIONAL_1M', 90000),
            'min_vol_ratio_1m' => (float) env('TRADING_V140_MIN_VOL_RATIO_1M', 1.3),
            'min_body_pct_1m' => (float) env('TRADING_V140_MIN_BODY_PCT_1M', 0.08),
            'max_above_vwap_entry_pct' => (float) env('TRADING_V140_MAX_ABOVE_VWAP_ENTRY_PCT', 1.0),
            'min_room_to_run_pct' => (float) env('TRADING_V140_MIN_ROOM_TO_RUN_PCT', 0.8),
            'room_atr_mult' => (float) env('TRADING_V140_ROOM_ATR_MULT', 1.8),
            'allow_lunch' => (bool) env('TRADING_V140_ALLOW_LUNCH', false),
            'vol_lookback_1m' => (int) env('TRADING_V140_VOL_LOOKBACK_1M', 20),
            'ema_fast' => (int) env('TRADING_V140_EMA_FAST', 9),
            'ema_slow' => (int) env('TRADING_V140_EMA_SLOW', 21),
            'atr_period_1m' => (int) env('TRADING_V140_ATR_PERIOD_1M', 14),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Trading Version 210.0 Configuration - Oversold Bounce Strategy
    |--------------------------------------------------------------------------
    |
    | Configuration for V210.0 oversold bounce strategy
    | For choppy/down markets, looks for:
    | - Stock down 1.5%+ from recent high
    | - Volume spike on selling (1.3x+)
    | - Reversal signal forming (hammer, doji, etc)
    | - Quick bounce setup with tight stops
    |
    | Target: High win rate on short-term bounces (2.5R profit target)
    |
    */
    'v210' => [
        // 5-minute scanner parameters
        'min_price' => (float) env('TRADING_V210_MIN_PRICE', 10.0),
        'max_price' => (float) env('TRADING_V210_MAX_PRICE', 500.0),
        'min_atr_pct_5m' => (float) env('TRADING_V210_MIN_ATR_PCT_5M', 0.10),
        'min_notional_5m' => (int) env('TRADING_V210_MIN_NOTIONAL_5M', 75000),
        'reversal_threshold' => (float) env('TRADING_V210_REVERSAL_THRESHOLD', 0.1),
        'min_signal_score' => (float) env('TRADING_V210_MIN_SIGNAL_SCORE', 2.0), // Filter at scanner level

        // 1-minute entry finder parameters
        'max_risk_pct' => (float) env('TRADING_V210_MAX_RISK_PCT', 2.0),
        'stop_loss_pct' => (float) env('TRADING_V210_STOP_LOSS_PCT', 1.0),
        'target_multiple' => (float) env('TRADING_V210_TARGET_MULTIPLE', 2.5),
        'min_vol_ratio_1m' => (float) env('TRADING_V210_MIN_VOL_RATIO_1M', 1.3),
        'max_vol_ratio_1m' => (float) env('TRADING_V210_MAX_VOL_RATIO_1M', 25.0),
        'min_rsi_14_1m' => (float) env('TRADING_V210_MIN_RSI_14_1M', 20.0),
        'max_rsi_14_1m' => (float) env('TRADING_V210_MAX_RSI_14_1M', 80.0),

        // Time restrictions - STRICT morning only (10-11am shows 1%+ avg P&L)
        'min_entry_hour' => (int) env('TRADING_V210_MIN_ENTRY_HOUR', 10),
        'max_entry_hour' => (int) env('TRADING_V210_MAX_ENTRY_HOUR', 11),

        // Quality filters - INCREASED to 145 (data shows 145+ score = 1%+ avg P&L, 60%+ win rate)
        'min_score' => (float) env('TRADING_V210_MIN_SCORE', 145.0),
    ],

    /*
    |--------------------------------------------------------------------------
    | Pipeline Alert Ignore Types & Table Routing
    |--------------------------------------------------------------------------
    |
    | Signal types to ignore for each pipeline. Comma-separated in .env,
    | converted to arrays here for efficient filtering.
    |
    | no_filter_finder: When true, alerts are written to trade_alerts_unfiltered
    | instead of trade_alerts for testing filter optimization.
    |
    */
    'pipelines' => [
        'a' => [
            'ignore_types' => array_filter(array_map('trim', explode(',', env('ALERT_A_IGNORE_TYPES') ?? ''))),
            'no_filter_finder' => (bool) env('ALERT_A_NO_FILTER_FINDER', false),
            'enable_rs_filter' => (bool) env('ALERT_A_ENABLE_RS_FILTER', false),
        ],
        'b' => [
            'ignore_types' => array_filter(array_map('trim', explode(',', env('ALERT_B_IGNORE_TYPES') ?? ''))),
            'no_filter_finder' => (bool) env('ALERT_B_NO_FILTER_FINDER', false),
            'enable_rs_filter' => (bool) env('ALERT_B_ENABLE_RS_FILTER', false),
        ],
        'c' => [
            'ignore_types' => array_filter(array_map('trim', explode(',', env('ALERT_C_IGNORE_TYPES') ?? ''))),
            'no_filter_finder' => (bool) env('ALERT_C_NO_FILTER_FINDER', false),
            'enable_rs_filter' => (bool) env('ALERT_C_ENABLE_RS_FILTER', false),
        ],
        'd' => [
            'ignore_types' => array_filter(array_map('trim', explode(',', env('ALERT_D_IGNORE_TYPES') ?? ''))),
            'no_filter_finder' => (bool) env('ALERT_D_NO_FILTER_FINDER', false),
            'enable_rs_filter' => (bool) env('ALERT_D_ENABLE_RS_FILTER', false),
        ],
        'e' => [
            'ignore_types' => array_filter(array_map('trim', explode(',', env('ALERT_E_IGNORE_TYPES') ?? ''))),
            'no_filter_finder' => (bool) env('ALERT_E_NO_FILTER_FINDER', false),
            'ml_prefilter' => (bool) env('ALERT_E_ML_PREFILTER', false),
            'enable_rs_filter' => (bool) env('ALERT_E_ENABLE_RS_FILTER', false),
        ],
        'f' => [
            'ignore_types' => array_filter(array_map('trim', explode(',', env('ALERT_F_IGNORE_TYPES') ?? ''))),
            'no_filter_finder' => (bool) env('ALERT_F_NO_FILTER_FINDER', false),
            'enable_rs_filter' => (bool) env('ALERT_F_ENABLE_RS_FILTER', false),
        ],
        'g' => [
            'ignore_types' => array_filter(array_map('trim', explode(',', env('ALERT_G_IGNORE_TYPES') ?? ''))),
            'no_filter_finder' => (bool) env('ALERT_G_NO_FILTER_FINDER', false),
            'enable_rs_filter' => (bool) env('ALERT_G_ENABLE_RS_FILTER', false),
        ],
        'h' => [
            'ignore_types' => array_filter(array_map('trim', explode(',', env('ALERT_H_IGNORE_TYPES') ?? ''))),
            'no_filter_finder' => (bool) env('ALERT_H_NO_FILTER_FINDER', false),
            'enable_rs_filter' => (bool) env('ALERT_H_ENABLE_RS_FILTER', false),
        ],
        'i' => [
            'ignore_types' => array_filter(array_map('trim', explode(',', env('ALERT_I_IGNORE_TYPES') ?? ''))),
            'no_filter_finder' => (bool) env('ALERT_I_NO_FILTER_FINDER', false),
            'enable_rs_filter' => (bool) env('ALERT_I_ENABLE_RS_FILTER', false),
        ],
        'j' => [
            'ignore_types' => array_filter(array_map('trim', explode(',', env('ALERT_J_IGNORE_TYPES') ?? ''))),
            'no_filter_finder' => (bool) env('ALERT_J_NO_FILTER_FINDER', false),
            'enable_rs_filter' => (bool) env('ALERT_J_ENABLE_RS_FILTER', false),
        ],
        'q' => [
            'ignore_types' => array_filter(array_map('trim', explode(',', env('ALERT_Q_IGNORE_TYPES') ?? ''))),
            'no_filter_finder' => (bool) env('ALERT_Q_NO_FILTER_FINDER', false),
            'enable_rs_filter' => (bool) env('ALERT_Q_ENABLE_RS_FILTER', false),
        ],
        'r' => [
            'ignore_types' => array_filter(array_map('trim', explode(',', env('ALERT_R_IGNORE_TYPES') ?? ''))),
            'no_filter_finder' => (bool) env('ALERT_R_NO_FILTER_FINDER', false),
            'enable_rs_filter' => (bool) env('ALERT_R_ENABLE_RS_FILTER', false),
        ],
        'j' => [
            'ignore_types' => array_filter(array_map('trim', explode(',', env('ALERT_J_IGNORE_TYPES') ?? ''))),
            'no_filter_finder' => (bool) env('ALERT_J_NO_FILTER_FINDER', false),
            'enable_rs_filter' => (bool) env('ALERT_J_ENABLE_RS_FILTER', false),
        ],
        'k' => [
            'ignore_types' => array_filter(array_map('trim', explode(',', env('ALERT_K_IGNORE_TYPES') ?? ''))),
            'no_filter_finder' => (bool) env('ALERT_K_NO_FILTER_FINDER', false),
            'enable_rs_filter' => (bool) env('ALERT_K_ENABLE_RS_FILTER', false),
        ],
        'm' => [
            'ignore_types' => array_filter(array_map('trim', explode(',', env('ALERT_M_IGNORE_TYPES') ?? ''))),
            'no_filter_finder' => (bool) env('ALERT_M_NO_FILTER_FINDER', false),
            'enable_rs_filter' => (bool) env('ALERT_M_ENABLE_RS_FILTER', false),
        ],
        'n' => [
            'ignore_types' => array_filter(array_map('trim', explode(',', env('ALERT_N_IGNORE_TYPES') ?? ''))),
            'no_filter_finder' => (bool) env('ALERT_N_NO_FILTER_FINDER', false),
            'enable_rs_filter' => (bool) env('ALERT_N_ENABLE_RS_FILTER', false),
        ],
        'p' => [
            'ignore_types' => array_filter(array_map('trim', explode(',', env('ALERT_P_IGNORE_TYPES') ?? ''))),
            'no_filter_finder' => (bool) env('ALERT_P_NO_FILTER_FINDER', false),
            'enable_rs_filter' => (bool) env('ALERT_P_ENABLE_RS_FILTER', false),
        ],
        'biased1' => [
            'ignore_types' => array_filter(array_map('trim', explode(',', env('ALERT_BIASED1_IGNORE_TYPES') ?? ''))),
            'no_filter_finder' => (bool) env('ALERT_BIASED1_NO_FILTER_FINDER', false), // Biased pipeline bypasses finder in code, not config
            'enable_rs_filter' => (bool) env('ALERT_BIASED1_ENABLE_RS_FILTER', false),
        ],
    ],

    // Quick accessors for pipeline configs (lowercase keys)
    'alert_a_no_filter_finder' => (bool) env('ALERT_A_NO_FILTER_FINDER', false),
    'alert_b_no_filter_finder' => (bool) env('ALERT_B_NO_FILTER_FINDER', false),
    'alert_c_no_filter_finder' => (bool) env('ALERT_C_NO_FILTER_FINDER', false),
    'alert_d_no_filter_finder' => (bool) env('ALERT_D_NO_FILTER_FINDER', false),
    'alert_e_no_filter_finder' => (bool) env('ALERT_E_NO_FILTER_FINDER', false),
    'alert_f_no_filter_finder' => (bool) env('ALERT_F_NO_FILTER_FINDER', false),
    'alert_g_no_filter_finder' => (bool) env('ALERT_G_NO_FILTER_FINDER', false),
    'alert_h_no_filter_finder' => (bool) env('ALERT_H_NO_FILTER_FINDER', false),
    'alert_i_no_filter_finder' => (bool) env('ALERT_I_NO_FILTER_FINDER', false),
    'alert_j_no_filter_finder' => (bool) env('ALERT_J_NO_FILTER_FINDER', false),
    'entry_score_min' => (float) env('ENTRY_SCORE_MIN', 80),
    'entry_score_max' => (float) env('ENTRY_SCORE_MAX', 100),
    'entry_score_limit' => (int) env('ENTRY_SCORE_LIMIT', 3),

    /*
    |--------------------------------------------------------------------------
    | Trading Version 17.0 Configuration - Multi-Pattern Scanner
    |--------------------------------------------------------------------------
    |
    | Experimental multi-pattern scanner with loosened filters.
    | Captures 10 different entry patterns: VWAP reclaim, pivot breaks,
    | bull flags, EMA bounces, opening range breakouts, etc.
    |
    | Key improvements in recent update:
    | - Removed overly strict product filter (move*vol >= 5.2)
    | - Made active window configurable (not hardcoded to 15 minutes)
    | - Fixed scoring bugs (unified 0-100 scale, added missing fields)
    | - Time-based volume thresholds (adapts to market hours)
    | - Clearer stop clamp logic
    |
    */
    'v17' => [
        // Active window: symbol must have bars in last N minutes to be considered
        // Default 30 minutes (was hardcoded to 15, which was too restrictive)
        'active_window_minutes' => (int) env('TRADING_V17_ACTIVE_WINDOW_MINUTES', 30),

        // ATR-based stop loss — now read from TradingSettingService (single DB-backed source)
        // These config keys remain as fallback defaults only
    ],

    /*
    |--------------------------------------------------------------------------
    | Trading Version 400.0 Configuration - Trend Continuation Strategy
    |--------------------------------------------------------------------------
    |
    | Finds stocks in confirmed uptrends with healthy pullbacks ready to continue.
    |
    | 5m Trend Gate: Price > VWAP, EMA9 > EMA21 (rising), HH/HL structure,
    | shallow pullbacks (20-60%), no VWAP breaks
    |
    | 1m Entry Confirmation: Higher low, volume expansion, VWAP/EMA reclaim,
    | bull flags, failed push downs
    |
    | Strong Filters (~10 picks/day): Relative volume, ATR/range filter
    |
    */
    'v400' => [
        // 5-Minute Scanner Configuration
        'min_atr_pct' => (float) env('TRADING_V400_MIN_ATR_PCT', 2.5),  // Minimum ATR % for tradeable movement
        'min_vol_ratio' => (float) env('TRADING_V400_MIN_VOL_RATIO', 1.5),  // Minimum volume vs average
        'max_pullback_pct' => (float) env('TRADING_V400_MAX_PULLBACK_PCT', 60.0),  // Maximum pullback depth
        'min_impulse_bars' => (int) env('TRADING_V400_MIN_IMPULSE_BARS', 1),  // Minimum green 5m bars (lowered from 2)
        'lookback_bars' => (int) env('TRADING_V400_LOOKBACK_BARS', 18),  // 18 bars = 90 minutes

        // 1-Minute Entry Finder Configuration
        'entry_min_vol_ratio' => (float) env('TRADING_V400_ENTRY_MIN_VOL_RATIO', 0.01),  // No vol requirement - already confirmed on 5m
        'entry_score_min' => (float) env('TRADING_V400_ENTRY_SCORE_MIN', 50),  // Minimum entry score (lowered from 60)
        'entry_score_max' => (float) env('TRADING_V400_ENTRY_SCORE_MAX', 95),  // Maximum entry score
        'entry_before_minutes' => (int) env('TRADING_V400_ENTRY_BEFORE_MINUTES', 10),  // Analysis window
        'entry_freshness_minutes' => (int) env('TRADING_V400_ENTRY_FRESHNESS_MINUTES', 5),  // Entry freshness
    ],

    /*
    |--------------------------------------------------------------------------
    | V900.0 Configuration - Explosive Momentum Strategy
    |--------------------------------------------------------------------------
    |
    | Strategy: Multi-day explosive momentum pattern (gap direction agnostic)
    | Pattern discovered from QURE (+37% gap down reversal) and IOT (+11% gap up continuation) on March 6, 2026
    |
    | Setup Requirements (5-min) - AGGRESSIVE THRESHOLDS:
    | - Stock up 5%+ yesterday (aggressive)
    | - Gap in either direction (allows -10% to +20%)
    | - EXPLOSIVE continuation (+2%+ in first 15 min) - KEY SIGNAL
    | - Strong RSI (60+) indicating momentum (aggressive)
    | - EMA9 > EMA21 (VWAP not required)
    | - Bollinger Band breakout (75%+ position, aggressive)
    | - Volume 2x+ recent average
    |
    | Entry Criteria (1-min) - AGGRESSIVE:
    | - Allows chase entries (no new breakout required)
    | - Volume confirmation (1.2x+ average)
    | - EMA9 > EMA21 (momentum confirmation)
    | - Position in candle >= 30% (not bottom wicking)
    |
    | Time Window: Early morning momentum (09:30-10:30)
    | Exit: Aggressive targets (3-10%+), tight stop (0.5 ATR)
    |
    */
    'v900' => [
        'entry_score_min' => (float) env('TRADING_V900_ENTRY_SCORE_MIN', 40),  // Lowered from 85 to catch explosive momentum
        'entry_score_max' => (float) env('TRADING_V900_ENTRY_SCORE_MAX', 100),
        'entry_score_limit' => (int) env('TRADING_V900_ENTRY_SCORE_LIMIT', 50),  // Increased from 10 to catch more signals
        'min_price' => (float) env('TRADING_V900_MIN_PRICE', 3.0),
        'max_price' => (float) env('TRADING_V900_MAX_PRICE', 500.0),
        'min_yesterday_move_pct' => (float) env('TRADING_V900_MIN_YESTERDAY_MOVE_PCT', -5.0),  // Aggressive: catches fresh breakouts (no prior setup needed)
        'min_opening_gap_pct' => (float) env('TRADING_V900_MIN_OPENING_GAP_PCT', -10.0),  // Allow gap downs
        'min_early_move_pct' => (float) env('TRADING_V900_MIN_EARLY_MOVE_PCT', 2.0),  // Aggressive: 2% early continuation
        'min_rsi' => (float) env('TRADING_V900_MIN_RSI', 60),  // Aggressive: catches strong momentum at 60+
        'min_bb_position' => (float) env('TRADING_V900_MIN_BB_POSITION', 65),  // Aggressive: catches early breakouts at 65%+
        'min_volume_mult' => (float) env('TRADING_V900_MIN_VOLUME_MULT', 2.0),
        'time_window_start' => env('TRADING_V900_TIME_WINDOW_START', '09:30:00'),
        'time_window_end' => env('TRADING_V900_TIME_WINDOW_END', '10:30:00'),  // Extended to 10:30 for post-10am entries
        'entry_min_rsi' => (float) env('TRADING_V900_ENTRY_MIN_RSI', 70),
    ],

    /*
    |--------------------------------------------------------------------------
    | V810.0 Configuration - EMA Momentum Pullback Strategy
    |--------------------------------------------------------------------------
    |
    | Strategy: Trade pullbacks to EMA9 in strong uptrends
    |
    | Setup Requirements (5-min):
    | - Stacked EMAs: EMA9 > EMA21 with widening spread
    | - Price above VWAP
    | - RSI 50-70 (momentum without overheating)
    | - Up 0.5-5% from day open
    | - Price 0.10-0.50% above EMA9 (pullback zone)
    |
    | Entry Requirements (1-min):
    | - Low touches EMA9 (within 0.30%)
    | - Close bounces above EMA9 (0.10-0.60%)
    | - Strong bar (close in upper 60% of range)
    | - Volume above average
    |
    | Time Window: All-day scanning (09:50-14:30)
    |
    */
    'v810' => [
        'entry_score_min' => (float) env('TRADING_V810_ENTRY_SCORE_MIN', 50),
        'entry_score_max' => (float) env('TRADING_V810_ENTRY_SCORE_MAX', 100),
        'entry_score_limit' => (int) env('TRADING_V810_ENTRY_SCORE_LIMIT', 25),
        'min_price' => (float) env('TRADING_V810_MIN_PRICE', 5.0),
        'max_price' => (float) env('TRADING_V810_MAX_PRICE', 300.0),
        'time_window_start' => env('TRADING_V810_TIME_WINDOW_START', '09:50:00'),
        'time_window_end' => env('TRADING_V810_TIME_WINDOW_END', '14:30:00'),
    ],

    /*
    |--------------------------------------------------------------------------
    | V820 - EMA Pullback Setup with Pattern Filters (Phase 1)
    |--------------------------------------------------------------------------
    |
    | Enhanced version of v810 with price pattern filters:
    | - Pump exhaustion detection (spike in 100-50m window)
    | - Inverted V rejection (pump-dump pattern)
    |
    */
    'v820' => [
        'entry_score_min' => (float) env('TRADING_V820_ENTRY_SCORE_MIN', 50),
        'entry_score_max' => (float) env('TRADING_V820_ENTRY_SCORE_MAX', 100),
        'entry_score_limit' => (int) env('TRADING_V820_ENTRY_SCORE_LIMIT', 25),
        'min_price' => (float) env('TRADING_V820_MIN_PRICE', 5.0),
        'max_price' => (float) env('TRADING_V820_MAX_PRICE', 300.0),
        'time_window_start' => env('TRADING_V820_TIME_WINDOW_START', '09:50:00'),
        'time_window_end' => env('TRADING_V820_TIME_WINDOW_END', '14:30:00'),

        // Pattern filters (Phase 1)
        'pattern_filters' => [
            'pump_exhaustion_threshold' => (float) env('TRADING_V820_PUMP_THRESHOLD', 1.020),  // 2% spike = reject
            'reject_inverted_v' => (bool) env('TRADING_V820_REJECT_INVERTED_V', true),         // Reject pump-dump
            'require_v_pattern' => (bool) env('TRADING_V820_REQUIRE_V_PATTERN', false),        // Phase 2 feature
            'reject_continuous_decline' => (bool) env('TRADING_V820_REJECT_DECLINE', false),   // Optional
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | V830.0 Configuration - Multi-Day Trend + Range Position Strategy
    |--------------------------------------------------------------------------
    |
    | Strategy: Filter v810 EMA pullback setups by multi-day trend and intraday position
    |
    | Based on analysis of 2,908 v810 trades showing:
    | - Strong 5-day trend (10-15%) + Top 20% range position = 59.76% win rate (82 trades)
    | - Very Strong trend (>15%) + Top 20% range position = 58.65% win rate (133 trades)
    |
    | Filters (applied to v810 base setup):
    | 1. Daily trend: Must be up 10%+ over prior 5 trading days
    | 2. Range position: Entry must be in top 20% of 60-minute range (0.80+)
    | 3. Maintains all v810 EMA/VWAP/RSI requirements
    |
    | This reduces noise from ~2,900 signals to ~200 highest-quality setups.
    |
    */
    'v830' => [
        'entry_score_min' => (float) env('TRADING_V830_ENTRY_SCORE_MIN', 50),
        'entry_score_max' => (float) env('TRADING_V830_ENTRY_SCORE_MAX', 100),
        'entry_score_limit' => (int) env('TRADING_V830_ENTRY_SCORE_LIMIT', 25),
        'min_price' => (float) env('TRADING_V830_MIN_PRICE', 5.0),
        'max_price' => (float) env('TRADING_V830_MAX_PRICE', 300.0),
        'time_window_start' => env('TRADING_V830_TIME_WINDOW_START', '09:50:00'),
        'time_window_end' => env('TRADING_V830_TIME_WINDOW_END', '14:30:00'),

        // Multi-day trend filter
        'min_daily_trend_pct' => (float) env('TRADING_V830_MIN_DAILY_TREND_PCT', 10.0),

        // Intraday range position filter
        'min_range_position' => (float) env('TRADING_V830_MIN_RANGE_POSITION', 0.80),
    ],

    /*
    |--------------------------------------------------------------------------
    | V1100.0 Configuration - Relative Strength Scarcity Leader Scanner
    |--------------------------------------------------------------------------
    |
    | Strategy: Find the few gainers ignoring market weakness
    |
    | Purpose: In weak/risk-off markets, identify stocks that are:
    | - Holding above VWAP with clean 5m trend
    | - Showing relative strength vs SPY (1.35x+ move ratio)
    | - Staying near recent highs (within 1 ATR)
    | - Not fading despite market weakness
    |
    | Entry patterns (1m):
    | - VWAP_PULLBACK_HOLD: Pullback to VWAP, holds, breaks higher
    | - EMA9_BOUNCE: Pullback to EMA9, bounces with volume
    | - TIGHT_FLAG_BREAK: 2-6 bar consolidation breakout
    | - OR_BREAKOUT_RETEST: Opening range high retest and break
    |
    | This is NOT a broad momentum scanner - it's specifically for
    | "scarcity leaders" that defy market weakness.
    |
    */
    /*
    |--------------------------------------------------------------------------
    | Pipeline M: v1400.0 - Tight Stops Clean Trend Strategy
    |--------------------------------------------------------------------------
    |
    | Scanner: Identifies smooth 2-hour uptrends with minimal drawdowns
    |
    | Requirements:
    | - Lookback: 120 minutes (clean trend analysis)
    | - Min Trend: 0.5% price gain from start to end
    | - Max Drawdown: 1.0% peak-to-trough
    | - Scoring: Risk-adjusted (Trend % ÷ Max Drawdown %)
    |
    | Entry: 1-minute continuation patterns suitable for tight stops
    | - Min Volume: 1.5x average
    | - Min Trend: 0.25% continuation
    | - Max Entry Drawdown: 0.5%
    |
    | Target: Stocks suitable for 0.5-1% tight stop loss management
    |
    | Target: Stocks suitable for 0.5-1% tight stop loss management
    |
    */
    'v1400' => [
        // 5-minute scanner parameters
        'lookback_minutes' => (int) env('TRADING_V1400_LOOKBACK_MINUTES', 120),
        'min_trend_pct' => (float) env('TRADING_V1400_MIN_TREND_PCT', 0.50),
        'max_drawdown_pct' => (float) env('TRADING_V1400_MAX_DRAWDOWN_PCT', 1.00),
        'min_bars' => (int) env('TRADING_V1400_MIN_BARS', 3),
        'min_price' => (float) env('TRADING_V1400_MIN_PRICE', 3.0),
        'max_price' => (float) env('TRADING_V1400_MAX_PRICE', 500.0),
        'top_n' => (int) env('TRADING_V1400_TOP_N', 50),
        'time_window_start' => env('TRADING_V1400_TIME_WINDOW_START', '09:40:00'),
        'time_window_end' => env('TRADING_V1400_TIME_WINDOW_END', '15:30:00'),

        // 1-minute entry finder parameters
        'entry_min_vol_ratio' => (float) env('TRADING_V1400_ENTRY_MIN_VOL_RATIO', 1.5),
        'entry_min_trend_pct' => (float) env('TRADING_V1400_ENTRY_MIN_TREND_PCT', 0.25),
        'entry_max_drawdown_pct' => (float) env('TRADING_V1400_ENTRY_MAX_DRAWDOWN_PCT', 0.50),

        // Quality filters (improves WR from 32.3% to 42-45%)
        'require_above_vwap' => (bool) env('TRADING_V1400_REQUIRE_ABOVE_VWAP', true),
        'require_ema9_above_ema21' => (bool) env('TRADING_V1400_REQUIRE_EMA9_ABOVE_EMA21', true),
        'max_atr_pct' => (float) env('TRADING_V1400_MAX_ATR_PCT', 0.20), // Tightened from 0.25
        'min_entry_score' => (float) env('TRADING_V1400_MIN_ENTRY_SCORE', 85),
        'entry_min_vol_ratio_filter' => (float) env('TRADING_V1400_ENTRY_MIN_VOL_RATIO_FILTER', 2.0), // Increased from 1.5
        'max_pct_below_intraday_high' => (float) env('TRADING_V1400_MAX_PCT_BELOW_INTRADAY_HIGH', 0.15),
        'max_minutes_since_high' => (int) env('TRADING_V1400_MAX_MINUTES_SINCE_HIGH', 5),
        'excluded_entry_types' => env('TRADING_V1400_EXCLUDED_ENTRY_TYPES', 'MICRO_PULLBACK_HOLD'), // Comma-separated
        'preferred_entry_types' => env('TRADING_V1400_PREFERRED_ENTRY_TYPES', 'TIGHT_CONSOLIDATION_BREAK'), // Comma-separated
    ],

    'v1200' => [
        // Universe: Market movers
        'top_movers' => (int) env('TRADING_V1200_TOP_MOVERS', 100),
        'min_gain_pct' => (float) env('TRADING_V1200_MIN_GAIN_PCT', 4.0),

        // 5-minute scanner parameters
        'min_price' => (float) env('TRADING_V1200_MIN_PRICE', 5.0),
        'max_price' => (float) env('TRADING_V1200_MAX_PRICE', 100.0),
        'min_vol_ratio' => (float) env('TRADING_V1200_MIN_VOL_RATIO', 1.2),
        'min_three_bar_gain_pct' => (float) env('TRADING_V1200_MIN_THREE_BAR_GAIN_PCT', 0.5),
        'time_window_start' => env('TRADING_V1200_TIME_START', '10:00:00'),
        'time_window_end' => env('TRADING_V1200_TIME_END', '15:30:00'),

        // 1-minute entry finder parameters
        'entry_min_vol_ratio' => (float) env('TRADING_V1200_ENTRY_MIN_VOL_RATIO', 1.5),
        'entry_pullback_min_pct' => (float) env('TRADING_V1200_ENTRY_PULLBACK_MIN_PCT', 0.15),
        'entry_pullback_max_pct' => (float) env('TRADING_V1200_ENTRY_PULLBACK_MAX_PCT', 1.5),
        'entry_consolidation_max_range_pct' => (float) env('TRADING_V1200_ENTRY_CONSOLIDATION_MAX_RANGE_PCT', 0.35),
        'entry_consolidation_min_bars' => (int) env('TRADING_V1200_ENTRY_CONSOLIDATION_MIN_BARS', 2),
    ],

    'v1500' => [
        // Pipeline O: Opening Range Breakout (ORB)
        // Universe: Market movers from market_movers table
        'top_movers' => (int) env('TRADING_V1500_TOP_MOVERS', 25),
        'min_gain_pct' => (float) env('TRADING_V1500_MIN_GAIN_PCT', 2.0),

        // 5-minute scanner parameters
        'min_price' => (float) env('TRADING_V1500_MIN_PRICE', 5.0),
        'max_price' => (float) env('TRADING_V1500_MAX_PRICE', 100.0),
        'min_vol_ratio' => (float) env('TRADING_V1500_MIN_VOL_RATIO', 1.5),  // Breakout volume vs OR average
        'min_range_pct' => (float) env('TRADING_V1500_MIN_RANGE_PCT', 1.0),  // Min opening range size
        'max_range_pct' => (float) env('TRADING_V1500_MAX_RANGE_PCT', 4.0),  // Max opening range size
        'time_window_start' => env('TRADING_V1500_TIME_START', '10:05:00'),  // After 30min OR + 5min confirmation
        'time_window_end' => env('TRADING_V1500_TIME_END', '15:30:00'),

        // 1-minute entry finder parameters
        'entry_min_vol_ratio' => (float) env('TRADING_V1500_ENTRY_MIN_VOL_RATIO', 1.5),
        'entry_min_breakout_confirmation' => (float) env('TRADING_V1500_ENTRY_MIN_BREAKOUT_CONFIRMATION', 0.01),  // $ above OR high
    ],

    'v1600' => [
        'debug' => (bool) env('ENTRYFINDER_V1600_DEBUG', false),

        'scanner' => [
            'active_window_minutes' => (int) env('TRADING_V1600_ACTIVE_WINDOW_MINUTES', 8),
            'top_days' => (int) env('TRADING_V1600_TOP_DAYS', 5),
            'top_limit' => (int) env('TRADING_V1600_TOP_LIMIT', 650),
            'losers_limit' => (int) env('TRADING_V1600_LOSERS_LIMIT', 120),
            'min_notional_5m' => (float) env('TRADING_V1600_MIN_NOTIONAL_5M', 150000.0),
            'min_atr_pct_5m' => (float) env('TRADING_V1600_MIN_ATR_PCT_5M', 0.55),
            'min_rvol_5m' => (float) env('TRADING_V1600_MIN_RVOL_5M', 1.25),
            'min_move_30m_pct' => (float) env('TRADING_V1600_MIN_MOVE_30M_PCT', 0.45),
            'min_rs_mult_vs_spy' => (float) env('TRADING_V1600_MIN_RS_MULT_VS_SPY', 1.05),
            'move_bars_5m' => (int) env('TRADING_V1600_MOVE_BARS_5M', 6),
            'rvol_lookback_5m' => (int) env('TRADING_V1600_RVOL_LOOKBACK_5M', 20),
            'atr_period_5m' => (int) env('TRADING_V1600_ATR_PERIOD_5M', 14),
            'pre_breakout_rvol_mult' => (float) env('TRADING_V1600_PRE_BREAKOUT_RVOL_MULT', 1.6),
            'priority_symbol_score_boost' => (float) env('TRADING_V1600_PRIORITY_SYMBOL_SCORE_BOOST', 0.75),
            'priority_symbols' => env('TRADING_V1600_PRIORITY_SYMBOLS', 'WOK,ABVE,HPE,SUPX,ADTX,VCIG,REPL,FLNC,FFAI,RCAT,SPCE,APPS,RGTX,NBIL,IONX,AAOI,AXTI,DELL,VSH,ERAS,USAR,BLNK,ORBS,HUMA,GRAL,ABCL,AMBA,OKLL,AMR,NUAI,IONZ,RR,GTM,QBTZ,INOD,RZLV,SMCI,AMC,CLS,ONDS,TNGX,VRRM,AIIO'),
        ],

        'entry' => [
            'min_bars' => (int) env('TRADING_V1600_ENTRY_MIN_BARS', 12),
            'analysis_lookback_minutes' => (int) env('TRADING_V1600_ANALYSIS_LOOKBACK_MINUTES', 120),
            'max_entry_age_minutes' => (int) env('TRADING_V1600_MAX_ENTRY_AGE_MINUTES', 10),
            'min_notional_1m' => (float) env('TRADING_V1600_MIN_NOTIONAL_1M', 35000),
            'min_vol_ratio_1m' => (float) env('TRADING_V1600_MIN_VOL_RATIO_1M', 0.85),
            'min_body_pct_1m' => (float) env('TRADING_V1600_MIN_BODY_PCT_1M', 0.03),
            'max_above_vwap_entry_pct' => (float) env('TRADING_V1600_MAX_ABOVE_VWAP_ENTRY_PCT', 3.5),
            'min_room_to_run_pct' => (float) env('TRADING_V1600_MIN_ROOM_TO_RUN_PCT', 0.20),
            'room_atr_mult' => (float) env('TRADING_V1600_ROOM_ATR_MULT', 0.6),
            'allow_lunch' => (bool) env('TRADING_V1600_ALLOW_LUNCH', true),
        ],
    ],

    'v1600_2' => [
        'debug' => (bool) env('ENTRYFINDER_V1600_2_DEBUG', env('ENTRYFINDER_V1600_DEBUG', false)),

        'scanner' => [
            'active_window_minutes' => (int) env('TRADING_V1600_2_ACTIVE_WINDOW_MINUTES', 8),
            'top_days' => (int) env('TRADING_V1600_2_TOP_DAYS', 5),
            'top_limit' => (int) env('TRADING_V1600_2_TOP_LIMIT', 350),
            'losers_limit' => (int) env('TRADING_V1600_2_LOSERS_LIMIT', 60),
            'min_notional_5m' => (float) env('TRADING_V1600_2_MIN_NOTIONAL_5M', 300000.0),
            'min_atr_pct_5m' => (float) env('TRADING_V1600_2_MIN_ATR_PCT_5M', 0.70),
            'min_rvol_5m' => (float) env('TRADING_V1600_2_MIN_RVOL_5M', 1.60),
            'min_move_30m_pct' => (float) env('TRADING_V1600_2_MIN_MOVE_30M_PCT', 0.80),
            'min_rs_mult_vs_spy' => (float) env('TRADING_V1600_2_MIN_RS_MULT_VS_SPY', 1.12),
            'move_bars_5m' => (int) env('TRADING_V1600_2_MOVE_BARS_5M', 6),
            'rvol_lookback_5m' => (int) env('TRADING_V1600_2_RVOL_LOOKBACK_5M', 20),
            'atr_period_5m' => (int) env('TRADING_V1600_2_ATR_PERIOD_5M', 14),
            'pre_breakout_rvol_mult' => (float) env('TRADING_V1600_2_PRE_BREAKOUT_RVOL_MULT', 2.0),
            'priority_symbol_score_boost' => (float) env('TRADING_V1600_2_PRIORITY_SYMBOL_SCORE_BOOST', 0.75),
            'priority_symbols' => env('TRADING_V1600_2_PRIORITY_SYMBOLS', env('TRADING_V1600_PRIORITY_SYMBOLS', 'WOK,ABVE,HPE,SUPX,ADTX,VCIG,REPL,FLNC,FFAI,RCAT,SPCE,APPS,RGTX,NBIL,IONX,AAOI,AXTI,DELL,VSH,ERAS,USAR,BLNK,ORBS,HUMA,GRAL,ABCL,AMBA,OKLL,AMR,NUAI,IONZ,RR,GTM,QBTZ,INOD,RZLV,SMCI,AMC,CLS,ONDS,TNGX,VRRM,AIIO')),
        ],

        'entry' => [
            'min_bars' => (int) env('TRADING_V1600_2_ENTRY_MIN_BARS', 15),
            'analysis_lookback_minutes' => (int) env('TRADING_V1600_2_ANALYSIS_LOOKBACK_MINUTES', 120),
            'max_entry_age_minutes' => (int) env('TRADING_V1600_2_MAX_ENTRY_AGE_MINUTES', 6),
            'min_notional_1m' => (float) env('TRADING_V1600_2_MIN_NOTIONAL_1M', 100000),
            'min_vol_ratio_1m' => (float) env('TRADING_V1600_2_MIN_VOL_RATIO_1M', 1.60),
            'min_body_pct_1m' => (float) env('TRADING_V1600_2_MIN_BODY_PCT_1M', 0.08),
            'max_above_vwap_entry_pct' => (float) env('TRADING_V1600_2_MAX_ABOVE_VWAP_ENTRY_PCT', 1.0),
            'min_room_to_run_pct' => (float) env('TRADING_V1600_2_MIN_ROOM_TO_RUN_PCT', 0.90),
            'room_atr_mult' => (float) env('TRADING_V1600_2_ROOM_ATR_MULT', 1.8),
            'allow_lunch' => (bool) env('TRADING_V1600_2_ALLOW_LUNCH', false),
        ],
    ],

    'v1100' => [
        // Scanner
        'entry_score_min' => 35,
        'entry_score_max' => 100,
        'entry_score_limit' => 15,
        'min_price' => 2.00,
        'max_price' => 80.00,
        'min_vol_ratio' => 1.8,
        'min_rel_strength_ratio' => 1.10,
        'min_market_weakness_pct' => -0.10,
        'max_distance_from_high_atr' => 1.0,
        'max_vwap_extension_pct' => 3.0,
        'min_ema_spread_pct' => 0.08,
        'min_dollar_volume_per_minute' => 2500,
        'require_spy_below_vwap' => false,
        'min_day_gain_pct' => 2.5,
        'lookback_bars_for_high' => 12,
        'require_green_close' => true,
        'min_range_contraction_bars' => 2,

        // Entry finder
        'entry_min_vol_ratio' => 1.8,
        'entry_max_vwap_extension_pct' => 2.5,
        'entry_max_extension_from_ema9_pct' => 1.5,
        'entry_min_pullback_depth_pct' => 0.20,
        'entry_max_pullback_depth_pct' => 2.00,
        'entry_min_flag_bars' => 2,
        'entry_max_flag_bars' => 6,
        'entry_min_breakout_vol_ratio' => 2.0,
        'entry_max_chase_bar_pct' => 1.25,
        'entry_min_5m_trend_spread_pct' => 0.08,
        'entry_require_5m_above_vwap' => true,
        'entry_require_5m_bull_trend' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Machine Learning Scoring Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for automatic ML scoring of trade alerts
    |
    */
    'ml_scoring' => [
        'enabled' => (bool) env('TRADING_ML_SCORING_ENABLED', true),
        // 'model_path' => env('TRADING_ML_MODEL_PATH', 'python_ml/models/winner_model_1_0_xgb.joblib'),
        // 'model_path' => env('TRADING_ML_MODEL_PATH', 'python_ml/models/winner_model_2_0_xgb.joblib'),
        // 'model_path' => env('TRADING_ML_MODEL_PATH', 'python_ml/models/winner_model_3_0_xgb.joblib'),
        // 'model_path' => env('TRADING_ML_MODEL_PATH', 'python_ml/models/winner_model_enhanced.joblib'),  // v1.0 with winner-focused features
        'model_path' => env('TRADING_ML_MODEL_PATH', 'python_ml/v2/models/winner_model_pipeline_hid.joblib'),  // PnL-weighted model for finding big winners

        // Pipeline-specific ML models (A-O) - allows each pipeline to use a dedicated model
        'pipeline_a_model_path' => env('TRADING_ML_PIPELINE_A_MODEL_PATH', null),
        'pipeline_b_model_path' => env('TRADING_ML_PIPELINE_B_MODEL_PATH', null),
        'pipeline_c_model_path' => env('TRADING_ML_PIPELINE_C_MODEL_PATH', null),
        'pipeline_d_model_path' => env('TRADING_ML_PIPELINE_D_MODEL_PATH', null),
        'pipeline_e_model_path' => env('TRADING_ML_PIPELINE_E_MODEL_PATH', null),
        'pipeline_f_model_path' => env('TRADING_ML_PIPELINE_F_MODEL_PATH', null),
        'pipeline_g_model_path' => env('TRADING_ML_PIPELINE_G_MODEL_PATH', null),
        'pipeline_h_model_path' => env('TRADING_ML_PIPELINE_H_MODEL_PATH', null),
        'pipeline_i_model_path' => env('TRADING_ML_PIPELINE_I_MODEL_PATH', null),
        'pipeline_j_model_path' => env('TRADING_ML_PIPELINE_J_MODEL_PATH', null),
        'pipeline_k_model_path' => env('TRADING_ML_PIPELINE_K_MODEL_PATH', null),
        'pipeline_l_model_path' => env('TRADING_ML_PIPELINE_L_MODEL_PATH', null),
        'pipeline_m_model_path' => env('TRADING_ML_PIPELINE_M_MODEL_PATH', null),
        'pipeline_n_model_path' => env('TRADING_ML_PIPELINE_N_MODEL_PATH', null),
        'pipeline_o_model_path' => env('TRADING_ML_PIPELINE_O_MODEL_PATH', null),
        'pipeline_r_model_path' => env('TRADING_ML_PIPELINE_R_MODEL_PATH', null),

        // Per-pipeline scorer scripts — override the default score_single_alert_v2.py
        // Set to score_single_alert_v3.py for pipelines with v3-trained models.
        'pipeline_a_scorer_script' => env('TRADING_ML_SCORER_SCRIPT_PIPELINE_A', null),
        'pipeline_b_scorer_script' => env('TRADING_ML_SCORER_SCRIPT_PIPELINE_B', null),
        'pipeline_c_scorer_script' => env('TRADING_ML_SCORER_SCRIPT_PIPELINE_C', null),
        'pipeline_d_scorer_script' => env('TRADING_ML_SCORER_SCRIPT_PIPELINE_D', null),
        'pipeline_e_scorer_script' => env('TRADING_ML_SCORER_SCRIPT_PIPELINE_E', null),
        'pipeline_f_scorer_script' => env('TRADING_ML_SCORER_SCRIPT_PIPELINE_F', null),
        'pipeline_g_scorer_script' => env('TRADING_ML_SCORER_SCRIPT_PIPELINE_G', null),
        'pipeline_h_scorer_script' => env('TRADING_ML_SCORER_SCRIPT_PIPELINE_H', null),
        'pipeline_i_scorer_script' => env('TRADING_ML_SCORER_SCRIPT_PIPELINE_I', null),
        'pipeline_j_scorer_script' => env('TRADING_ML_SCORER_SCRIPT_PIPELINE_J', null),
        'pipeline_k_scorer_script' => env('TRADING_ML_SCORER_SCRIPT_PIPELINE_K', null),
        'pipeline_l_scorer_script' => env('TRADING_ML_SCORER_SCRIPT_PIPELINE_L', null),
        'pipeline_m_scorer_script' => env('TRADING_ML_SCORER_SCRIPT_PIPELINE_M', null),
        'pipeline_n_scorer_script' => env('TRADING_ML_SCORER_SCRIPT_PIPELINE_N', null),
        'pipeline_o_scorer_script' => env('TRADING_ML_SCORER_SCRIPT_PIPELINE_O', null),
        'pipeline_r_scorer_script' => env('TRADING_ML_SCORER_SCRIPT_PIPELINE_R', null),
        'pipeline_s_scorer_script' => env('TRADING_ML_SCORER_SCRIPT_PIPELINE_S', null),

        'pipeline_external_scorer_script' => env('TRADING_ML_SCORER_SCRIPT_PIPELINE_EXTERNAL', null),
        'pipeline_x_scorer_script' => env('TRADING_ML_SCORER_SCRIPT_PIPELINE_X', 'python_ml/v2/score_single_alert_v2.py'),

        'live_rescore_enabled' => (bool) env('TRADING_ML_LIVE_RESCORE_ENABLED', true),
        'live_rescore_enabled_pipeline_a' => env('TRADING_ML_LIVE_RESCORE_ENABLED_PIPELINE_A') !== null ? (bool) env('TRADING_ML_LIVE_RESCORE_ENABLED_PIPELINE_A') : null,
        'live_rescore_enabled_pipeline_b' => env('TRADING_ML_LIVE_RESCORE_ENABLED_PIPELINE_B') !== null ? (bool) env('TRADING_ML_LIVE_RESCORE_ENABLED_PIPELINE_B') : null,
        'live_rescore_enabled_pipeline_c' => env('TRADING_ML_LIVE_RESCORE_ENABLED_PIPELINE_C') !== null ? (bool) env('TRADING_ML_LIVE_RESCORE_ENABLED_PIPELINE_C') : null,
        'live_rescore_enabled_pipeline_d' => env('TRADING_ML_LIVE_RESCORE_ENABLED_PIPELINE_D') !== null ? (bool) env('TRADING_ML_LIVE_RESCORE_ENABLED_PIPELINE_D') : null,
        'live_rescore_enabled_pipeline_e' => env('TRADING_ML_LIVE_RESCORE_ENABLED_PIPELINE_E') !== null ? (bool) env('TRADING_ML_LIVE_RESCORE_ENABLED_PIPELINE_E') : null,
        'live_rescore_enabled_pipeline_f' => env('TRADING_ML_LIVE_RESCORE_ENABLED_PIPELINE_F') !== null ? (bool) env('TRADING_ML_LIVE_RESCORE_ENABLED_PIPELINE_F') : null,
        'live_rescore_enabled_pipeline_g' => env('TRADING_ML_LIVE_RESCORE_ENABLED_PIPELINE_G') !== null ? (bool) env('TRADING_ML_LIVE_RESCORE_ENABLED_PIPELINE_G') : null,
        'live_rescore_enabled_pipeline_h' => env('TRADING_ML_LIVE_RESCORE_ENABLED_PIPELINE_H') !== null ? (bool) env('TRADING_ML_LIVE_RESCORE_ENABLED_PIPELINE_H') : null,
        'live_rescore_enabled_pipeline_i' => env('TRADING_ML_LIVE_RESCORE_ENABLED_PIPELINE_I') !== null ? (bool) env('TRADING_ML_LIVE_RESCORE_ENABLED_PIPELINE_I') : null,
        'live_rescore_enabled_pipeline_j' => env('TRADING_ML_LIVE_RESCORE_ENABLED_PIPELINE_J') !== null ? (bool) env('TRADING_ML_LIVE_RESCORE_ENABLED_PIPELINE_J') : null,
        'live_rescore_enabled_pipeline_k' => env('TRADING_ML_LIVE_RESCORE_ENABLED_PIPELINE_K') !== null ? (bool) env('TRADING_ML_LIVE_RESCORE_ENABLED_PIPELINE_K') : null,
        'live_rescore_enabled_pipeline_n' => env('TRADING_ML_LIVE_RESCORE_ENABLED_PIPELINE_N') !== null ? (bool) env('TRADING_ML_LIVE_RESCORE_ENABLED_PIPELINE_N') : null,
        'live_rescore_enabled_pipeline_o' => env('TRADING_ML_LIVE_RESCORE_ENABLED_PIPELINE_O') !== null ? (bool) env('TRADING_ML_LIVE_RESCORE_ENABLED_PIPELINE_O') : null,
        'python_bin' => env('TRADING_ML_PYTHON_BIN', 'python'),
        'python_path' => env('TRADING_ML_PYTHON_PATH', '/var/www/html/laravel-invest/.venv/bin/python3'),
        'timeout_seconds' => (int) env('TRADING_ML_TIMEOUT', 60),
        'max_retries' => (int) env('TRADING_ML_MAX_RETRIES', 3),
        'buy_threshold' => (float) env('TRADING_ML_BUY_THRESHOLD', 0.45),  // Calibrated: 61.3% win rate at this threshold
        'bell_threshold' => (float) env('SCORE_BELL', 0.60),  // Lowered from 0.70 to match new calibration
    ],

    /*
    |--------------------------------------------------------------------------
    | Automatic Alpaca Order Placement
    |
    | Automatically place Alpaca orders when trade alerts are ML scored >= 65%
    |
    */
    'auto_alpaca_orders' => [
        'enabled' => (bool) env('AUTO_ALPACA_ORDERS_ENABLED', false),
        'ml_threshold' => (float) env('AUTO_ALPACA_ML_THRESHOLD', 0.45),  // Calibrated: 61.3% win rate at this threshold
        'paper_bypass_ml_threshold' => (bool) env('AUTO_ALPACA_PAPER_BYPASS_ML_THRESHOLD', false),
        'nightly_analyze_thresholds' => (bool) env('AUTO_ALPACA_NIGHTLY_ANALYZE_THRESHOLDS', true),
        'max_age_minutes' => (int) env('AUTO_ALPACA_ORDERS_MAX_AGE_MINUTES', 10),
        'retrade_symbol_wait_minutes' => (int) env('RETRADE_SYMBOL_WAIT_MINUTES', 60),
        'skip_next_alert_after_ml_passed_minutes' => (int) env('SKIP_NEXT_ALERT_AFTER_ML_PASSED_MINUTES', 0),
        'stale_rescore_enabled' => (bool) env('AUTO_ALPACA_STALE_RESCORE_ENABLED', false),
        'stale_rescore_paper_only' => (bool) env('AUTO_ALPACA_STALE_RESCORE_PAPER_ONLY', true),
        'stale_rescore_max_age_minutes' => (int) env('AUTO_ALPACA_STALE_RESCORE_MAX_AGE_MINUTES', 60),

        // Temporary short-window override used to lower thresholds during a favorable regime.
        // Set AUTO_ALPACA_ML_THRESHOLD_REGIME_OVERRIDE_ENABLED=false in .env to disable it entirely.
        'ml_threshold_regime_override' => [
            'enabled' => (bool) env('AUTO_ALPACA_ML_THRESHOLD_REGIME_OVERRIDE_ENABLED', false),
            'lookback_days' => (int) env('AUTO_ALPACA_ML_THRESHOLD_REGIME_OVERRIDE_LOOKBACK_DAYS', 3),
            'min_trades' => (int) env('AUTO_ALPACA_ML_THRESHOLD_REGIME_OVERRIDE_MIN_TRADES', 5),
            'step' => (float) env('AUTO_ALPACA_ML_THRESHOLD_REGIME_OVERRIDE_STEP', 0.05),
            'min_win_lift' => (float) env('AUTO_ALPACA_ML_THRESHOLD_REGIME_OVERRIDE_MIN_WIN_LIFT', 10.0),
            'restore_drop' => (float) env('AUTO_ALPACA_ML_THRESHOLD_REGIME_OVERRIDE_RESTORE_DROP', 5.0),
            'max_age_days' => (int) env('AUTO_ALPACA_ML_THRESHOLD_REGIME_OVERRIDE_MAX_AGE_DAYS', 2),
            'min_pnl_per_day' => (float) env('AUTO_ALPACA_ML_THRESHOLD_REGIME_OVERRIDE_MIN_PNL_PER_DAY', 0.0),
            'floor' => (float) env('AUTO_ALPACA_ML_THRESHOLD_REGIME_OVERRIDE_FLOOR', 0.05),
        ],

        // Per-pipeline ML threshold overrides (falls back to ml_threshold if not set)
        'ml_threshold_pipeline_a' => env('AUTO_ALPACA_ML_THRESHOLD_PIPELINE_A') !== null ? (float) env('AUTO_ALPACA_ML_THRESHOLD_PIPELINE_A') : null,
        'ml_threshold_pipeline_b' => env('AUTO_ALPACA_ML_THRESHOLD_PIPELINE_B') !== null ? (float) env('AUTO_ALPACA_ML_THRESHOLD_PIPELINE_B') : null,
        'ml_threshold_pipeline_c' => env('AUTO_ALPACA_ML_THRESHOLD_PIPELINE_C') !== null ? (float) env('AUTO_ALPACA_ML_THRESHOLD_PIPELINE_C') : null,
        'ml_threshold_pipeline_d' => env('AUTO_ALPACA_ML_THRESHOLD_PIPELINE_D') !== null ? (float) env('AUTO_ALPACA_ML_THRESHOLD_PIPELINE_D') : null,
        'ml_threshold_pipeline_f' => env('AUTO_ALPACA_ML_THRESHOLD_PIPELINE_F') !== null ? (float) env('AUTO_ALPACA_ML_THRESHOLD_PIPELINE_F') : null,
        'ml_threshold_pipeline_g' => env('AUTO_ALPACA_ML_THRESHOLD_PIPELINE_G') !== null ? (float) env('AUTO_ALPACA_ML_THRESHOLD_PIPELINE_G') : null,
        'ml_threshold_pipeline_h' => env('AUTO_ALPACA_ML_THRESHOLD_PIPELINE_H') !== null ? (float) env('AUTO_ALPACA_ML_THRESHOLD_PIPELINE_H') : null,
        'ml_threshold_pipeline_i' => env('AUTO_ALPACA_ML_THRESHOLD_PIPELINE_I') !== null ? (float) env('AUTO_ALPACA_ML_THRESHOLD_PIPELINE_I') : null,
        'ml_threshold_pipeline_j' => env('AUTO_ALPACA_ML_THRESHOLD_PIPELINE_J') !== null ? (float) env('AUTO_ALPACA_ML_THRESHOLD_PIPELINE_J') : null,
        'ml_threshold_pipeline_k' => env('AUTO_ALPACA_ML_THRESHOLD_PIPELINE_K') !== null ? (float) env('AUTO_ALPACA_ML_THRESHOLD_PIPELINE_K') : null,
        'ml_threshold_pipeline_n' => env('AUTO_ALPACA_ML_THRESHOLD_PIPELINE_N') !== null ? (float) env('AUTO_ALPACA_ML_THRESHOLD_PIPELINE_N') : null,
        'ml_threshold_pipeline_o' => env('AUTO_ALPACA_ML_THRESHOLD_PIPELINE_O') !== null ? (float) env('AUTO_ALPACA_ML_THRESHOLD_PIPELINE_O') : null,
        'ml_threshold_pipeline_p' => env('AUTO_ALPACA_ML_THRESHOLD_PIPELINE_P') !== null ? (float) env('AUTO_ALPACA_ML_THRESHOLD_PIPELINE_P') : null,

        'auto_risk' => [
            'daily_loss_limit' => (float) env('AUTO_TRADING_DAILY_LOSS_LIMIT', -500),
            'consecutive_loss_days' => (int) env('AUTO_TRADING_CONSECUTIVE_LOSS_DAYS', 3),
            'resume_enabled' => (bool) env('AUTO_TRADING_RESUME_ENABLED', false),
        ],

        // Pipelines A-D, F, G, I use --lookback=15: bar can be 15 min old + ~3 min ML queue = ~18 min by listener time
        'max_age_minutes_pipeline_a' => (int) env('AUTO_ALPACA_ORDERS_MAX_AGE_MINUTES_PIPELINE_A', 20),
        'max_age_minutes_pipeline_b' => (int) env('AUTO_ALPACA_ORDERS_MAX_AGE_MINUTES_PIPELINE_B', 20),
        'max_age_minutes_pipeline_c' => (int) env('AUTO_ALPACA_ORDERS_MAX_AGE_MINUTES_PIPELINE_C', 20),
        'max_age_minutes_pipeline_d' => (int) env('AUTO_ALPACA_ORDERS_MAX_AGE_MINUTES_PIPELINE_D', 20),
        'max_age_minutes_pipeline_f' => (int) env('AUTO_ALPACA_ORDERS_MAX_AGE_MINUTES_PIPELINE_F', 20),
        'max_age_minutes_pipeline_g' => (int) env('AUTO_ALPACA_ORDERS_MAX_AGE_MINUTES_PIPELINE_G', 20),
        'max_age_minutes_pipeline_i' => (int) env('AUTO_ALPACA_ORDERS_MAX_AGE_MINUTES_PIPELINE_I', 20),
        'max_age_minutes_pipeline_h' => (int) env('AUTO_ALPACA_ORDERS_MAX_AGE_MINUTES_PIPELINE_H', 20),  // H entry finder looks back up to 25 min
        'max_age_minutes_pipeline_j' => (int) env('AUTO_ALPACA_ORDERS_MAX_AGE_MINUTES_PIPELINE_J', 15),  // Pipeline J uses delayed pattern recognition
        'max_age_minutes_pipeline_l' => (int) env('AUTO_ALPACA_ORDERS_MAX_AGE_MINUTES_PIPELINE_L', 10),
        'max_loss_pipeline_j' => (float) env('MAX_LOSS_PIPELINE_J', 0.50),
        'max_alerts_per_minute_pipeline_j' => (int) env('TRADING__MAX_ALERTS_PER_MINUTE_PIPELINE_J', 0),
        // Separate cap for Pipeline L backtest-origin alerts (is_realtime=0)
        'max_age_minutes_pipeline_l_backtest' => (int) env('AUTO_ALPACA_ORDERS_MAX_AGE_MINUTES_PIPELINE_L_BACKTEST', 10),
        // Detect lagging rolling-window slots this many minutes BEFORE hitting max-age.
        // Example: max_age=10 and early_lead=11 => warn at >=1 minute lag.
        'stale_slot_early_lead_minutes' => (int) env('AUTO_ALPACA_STALE_SLOT_EARLY_LEAD_MINUTES', 11),
        'max_extension_pct' => (float) env('AUTO_ALPACA_MAX_EXTENSION_PCT', 0),  // Skip order if current price has moved > X% above signal price (0 = disabled)

        // Benchmark VWAP Gate: skip orders when benchmark (QQQM) is below its intraday VWAP.
        // Enable globally or per-pipeline. The intraday-high check is optional.
        'benchmark_symbol' => env('AUTO_ALPACA_BENCHMARK_SYMBOL', 'QQQM'),
        'benchmark_vwap_gate_enabled' => (bool) env('AUTO_ALPACA_BENCHMARK_VWAP_GATE', false),  // global default (off)
        'benchmark_max_pct_below_high' => env('AUTO_ALPACA_BENCHMARK_MAX_PCT_BELOW_HIGH') !== null ? (float) env('AUTO_ALPACA_BENCHMARK_MAX_PCT_BELOW_HIGH') : null,  // null = disabled
        // Per-pipeline overrides: true = force on, false = force off, null = use global default
        'benchmark_vwap_gate_pipeline_a' => env('AUTO_ALPACA_BENCHMARK_VWAP_GATE_PIPELINE_A') !== null ? (bool) env('AUTO_ALPACA_BENCHMARK_VWAP_GATE_PIPELINE_A') : null,
        'benchmark_vwap_gate_pipeline_b' => env('AUTO_ALPACA_BENCHMARK_VWAP_GATE_PIPELINE_B') !== null ? (bool) env('AUTO_ALPACA_BENCHMARK_VWAP_GATE_PIPELINE_B') : null,
        'benchmark_vwap_gate_pipeline_c' => env('AUTO_ALPACA_BENCHMARK_VWAP_GATE_PIPELINE_C') !== null ? (bool) env('AUTO_ALPACA_BENCHMARK_VWAP_GATE_PIPELINE_C') : null,
        'benchmark_vwap_gate_pipeline_d' => env('AUTO_ALPACA_BENCHMARK_VWAP_GATE_PIPELINE_D') !== null ? (bool) env('AUTO_ALPACA_BENCHMARK_VWAP_GATE_PIPELINE_D') : null,
        'benchmark_vwap_gate_pipeline_f' => env('AUTO_ALPACA_BENCHMARK_VWAP_GATE_PIPELINE_F') !== null ? (bool) env('AUTO_ALPACA_BENCHMARK_VWAP_GATE_PIPELINE_F') : null,
        'benchmark_vwap_gate_pipeline_g' => env('AUTO_ALPACA_BENCHMARK_VWAP_GATE_PIPELINE_G') !== null ? (bool) env('AUTO_ALPACA_BENCHMARK_VWAP_GATE_PIPELINE_G') : null,
        'benchmark_vwap_gate_pipeline_h' => env('AUTO_ALPACA_BENCHMARK_VWAP_GATE_PIPELINE_H') !== null ? (bool) env('AUTO_ALPACA_BENCHMARK_VWAP_GATE_PIPELINE_H') : null,
        'benchmark_vwap_gate_pipeline_i' => env('AUTO_ALPACA_BENCHMARK_VWAP_GATE_PIPELINE_I') !== null ? (bool) env('AUTO_ALPACA_BENCHMARK_VWAP_GATE_PIPELINE_I') : null,
        'benchmark_vwap_gate_pipeline_j' => env('AUTO_ALPACA_BENCHMARK_VWAP_GATE_PIPELINE_J') !== null ? (bool) env('AUTO_ALPACA_BENCHMARK_VWAP_GATE_PIPELINE_J') : null,
        'benchmark_vwap_gate_pipeline_k' => env('AUTO_ALPACA_BENCHMARK_VWAP_GATE_PIPELINE_K') !== null ? (bool) env('AUTO_ALPACA_BENCHMARK_VWAP_GATE_PIPELINE_K') : null,
        'benchmark_vwap_gate_pipeline_n' => env('AUTO_ALPACA_BENCHMARK_VWAP_GATE_PIPELINE_N') !== null ? (bool) env('AUTO_ALPACA_BENCHMARK_VWAP_GATE_PIPELINE_N') : null,
        'benchmark_vwap_gate_pipeline_e' => env('AUTO_ALPACA_BENCHMARK_VWAP_GATE_PIPELINE_E') !== null ? (bool) env('AUTO_ALPACA_BENCHMARK_VWAP_GATE_PIPELINE_E') : null,
        'benchmark_vwap_gate_pipeline_l' => env('AUTO_ALPACA_BENCHMARK_VWAP_GATE_PIPELINE_L') !== null ? (bool) env('AUTO_ALPACA_BENCHMARK_VWAP_GATE_PIPELINE_L') : null,
        'benchmark_vwap_gate_pipeline_m' => env('AUTO_ALPACA_BENCHMARK_VWAP_GATE_PIPELINE_M') !== null ? (bool) env('AUTO_ALPACA_BENCHMARK_VWAP_GATE_PIPELINE_M') : null,
        'benchmark_vwap_gate_pipeline_p' => env('AUTO_ALPACA_BENCHMARK_VWAP_GATE_PIPELINE_P') !== null ? (bool) env('AUTO_ALPACA_BENCHMARK_VWAP_GATE_PIPELINE_P') : null,
        'benchmark_vwap_gate_pipeline_q' => env('AUTO_ALPACA_BENCHMARK_VWAP_GATE_PIPELINE_Q') !== null ? (bool) env('AUTO_ALPACA_BENCHMARK_VWAP_GATE_PIPELINE_Q') : null,
        'benchmark_vwap_gate_pipeline_r' => env('AUTO_ALPACA_BENCHMARK_VWAP_GATE_PIPELINE_R') !== null ? (bool) env('AUTO_ALPACA_BENCHMARK_VWAP_GATE_PIPELINE_R') : null,

        // ML Scoring Daemon
        'daemon_socket' => env('TRADING_ML_DAEMON_SOCKET', storage_path('ml-scoring.sock')),
        'daemon_socket_display' => env('TRADING_ML_DAEMON_SOCKET_DISPLAY'),
        'base_path' => env('TRADING_ML_BASE_PATH'),  // Override base_path() for symlink scenarios
        'position_size' => (float) env('AUTO_ALPACA_POSITION_SIZE', 100),  // dollars per trade (fallback if alert has no calculated size)

        // Position sizing configuration
        'position_size_mode' => env('AUTO_ALPACA_POSITION_SIZE_MODE', 'dynamic'),  // 'fixed' or 'dynamic'
        'max_position_pct_of_liquidity' => (float) env('AUTO_ALPACA_MAX_POSITION_PCT_OF_LIQUIDITY', 10.0),  // % of liquidity
        'min_position_size' => (float) env('AUTO_ALPACA_MIN_POSITION_SIZE', 500),  // Minimum position size
        'max_position_size' => (float) env('AUTO_ALPACA_MAX_POSITION_SIZE', 5000),  // Maximum position size cap
        'min_dollar_volume_per_min' => (float) env('AUTO_ALPACA_MIN_DOLLAR_VOLUME_PER_MIN', 2500),  // Liquidity filter

        // Trading hours (EST timezone) - HH:MM format
        'trading_start_time' => env('AUTO_ALPACA_TRADING_START_TIME', '09:30'),  // Start time in EST (HH:MM)
        'trading_end_time' => env('AUTO_ALPACA_TRADING_END_TIME', '16:00'),  // End time in EST (HH:MM)

        // Profit-protection trailing stop (tiered: +0.75% / +1.25% / +2.00% / trail above)
        // Set AUTO_ALPACA_PROFIT_PROTECTION_ENABLED=true to activate (replaces legacy trailing stop logic)
        'profit_protection_enabled' => (bool) env('AUTO_ALPACA_PROFIT_PROTECTION_ENABLED', false),

        // Stop Loss Configuration
        'stop_loss_mode' => env('AUTO_ALPACA_STOP_LOSS_MODE', 'fixed'),  // 'fixed' or 'atr'
        'stop_loss_pct' => (float) env('AUTO_ALPACA_STOP_LOSS_PCT', 0.75),  // Fixed percentage mode

        // ATR-based stop loss settings
        'stop_loss_atr_multiplier' => (float) env('AUTO_ALPACA_STOP_LOSS_ATR_MULTIPLIER', 4.0),  // ATR multiplier
        'stop_loss_atr_min_pct' => (float) env('AUTO_ALPACA_STOP_LOSS_ATR_MIN_PCT', 1.00),  // Minimum stop %
        'stop_loss_atr_max_pct' => (float) env('AUTO_ALPACA_STOP_LOSS_ATR_MAX_PCT', 2.00),  // Maximum stop %

        'max_trades_per_day' => (int) env('AUTO_ALPACA_MAX_TRADES_PER_DAY', 999),  // Maximum number of buy orders per day (0 = unlimited)

        // Limit order settings (prevents slippage from fast-moving entries)
        'use_limit_orders' => (bool) env('AUTO_ALPACA_USE_LIMIT_ORDERS', false),  // true = limit, false = market
        'limit_slippage_pct' => (float) env('AUTO_ALPACA_LIMIT_SLIPPAGE_PCT', 0.3),  // Global default: max % above current price
        'current_price_max_age_minutes' => (int) env('AUTO_ALPACA_CURRENT_PRICE_MAX_AGE_MINUTES', 5),  // Treat one_minute_prices as stale after N minutes
        'limit_slippage_pct_stale_price' => (float) env('AUTO_ALPACA_LIMIT_SLIPPAGE_PCT_STALE_PRICE', 1.5),  // Wider band when price data is stale
        'partial_fill_stop_timeout_minutes' => (float) env('AUTO_ALPACA_PARTIAL_FILL_STOP_TIMEOUT_MINUTES', 2.0),  // Place stop for partially_filled orders after N minutes

        // SIP quote validation for live order placement
        'max_quote_age_seconds' => (int) env('ALPACA_MAX_QUOTE_AGE_SECONDS', 5),
        'max_spread_pct' => (float) env('ALPACA_MAX_SPREAD_PCT', 0.35),
        'marketable_limit_multiplier' => (float) env('ALPACA_MARKETABLE_LIMIT_ORDER', 1.0005),

        // Per-pipeline slippage overrides (null = use global default)
        // Momentum/explosive pipelines (A, N) need more room; RS/trend pipelines (F, K) can be tighter
        'limit_slippage_pct_pipeline_a' => env('AUTO_ALPACA_LIMIT_SLIPPAGE_PCT_PIPELINE_A') !== null ? (float) env('AUTO_ALPACA_LIMIT_SLIPPAGE_PCT_PIPELINE_A') : null,
        'limit_slippage_pct_pipeline_b' => env('AUTO_ALPACA_LIMIT_SLIPPAGE_PCT_PIPELINE_B') !== null ? (float) env('AUTO_ALPACA_LIMIT_SLIPPAGE_PCT_PIPELINE_B') : null,
        'limit_slippage_pct_pipeline_c' => env('AUTO_ALPACA_LIMIT_SLIPPAGE_PCT_PIPELINE_C') !== null ? (float) env('AUTO_ALPACA_LIMIT_SLIPPAGE_PCT_PIPELINE_C') : null,
        'limit_slippage_pct_pipeline_d' => env('AUTO_ALPACA_LIMIT_SLIPPAGE_PCT_PIPELINE_D') !== null ? (float) env('AUTO_ALPACA_LIMIT_SLIPPAGE_PCT_PIPELINE_D') : null,
        'limit_slippage_pct_pipeline_f' => env('AUTO_ALPACA_LIMIT_SLIPPAGE_PCT_PIPELINE_F') !== null ? (float) env('AUTO_ALPACA_LIMIT_SLIPPAGE_PCT_PIPELINE_F') : null,
        'limit_slippage_pct_pipeline_h' => env('AUTO_ALPACA_LIMIT_SLIPPAGE_PCT_PIPELINE_H') !== null ? (float) env('AUTO_ALPACA_LIMIT_SLIPPAGE_PCT_PIPELINE_H') : null,
        'limit_slippage_pct_pipeline_k' => env('AUTO_ALPACA_LIMIT_SLIPPAGE_PCT_PIPELINE_K') !== null ? (float) env('AUTO_ALPACA_LIMIT_SLIPPAGE_PCT_PIPELINE_K') : null,
        'limit_slippage_pct_pipeline_n' => env('AUTO_ALPACA_LIMIT_SLIPPAGE_PCT_PIPELINE_N') !== null ? (float) env('AUTO_ALPACA_LIMIT_SLIPPAGE_PCT_PIPELINE_N') : null,
        'limit_slippage_pct_pipeline_o' => env('AUTO_ALPACA_LIMIT_SLIPPAGE_PCT_PIPELINE_O') !== null ? (float) env('AUTO_ALPACA_LIMIT_SLIPPAGE_PCT_PIPELINE_O') : null,

        // Circuit Breaker: pause new entries when too many stops fire in a short window
        // If AUTO_ALPACA_CIRCUIT_BREAKER_ENABLED=true and X stops fire within Y minutes,
        // new orders are blocked for Z minutes.
        'circuit_breaker' => [
            'enabled' => (bool) env('AUTO_ALPACA_CIRCUIT_BREAKER_ENABLED', false),
            'stops_threshold' => (int) env('AUTO_ALPACA_CIRCUIT_BREAKER_STOPS_THRESHOLD', 3),   // stops needed to trip
            'window_minutes' => (int) env('AUTO_ALPACA_CIRCUIT_BREAKER_WINDOW_MINUTES', 20),    // rolling window to count stops in
            'pause_minutes' => (int) env('AUTO_ALPACA_CIRCUIT_BREAKER_PAUSE_MINUTES', 30),     // how long to pause new entries
        ],
    ],
];
