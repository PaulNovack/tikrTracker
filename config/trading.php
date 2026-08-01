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

    // Enable debug logging for entry finders (logs periodic counter stats)
    'entry_finder_debug' => (bool) env('TRADING_ENTRY_FINDER_DEBUG', false),

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
            'use_redis' => (bool) env('TRADING_PIPELINE_A_USE_REDIS', false),
            'ignore_types' => array_filter(array_map('trim', explode(',', env('ALERT_A_IGNORE_TYPES') ?? ''))),
            'no_filter_finder' => (bool) env('ALERT_A_NO_FILTER_FINDER', false),
            'enable_rs_filter' => (bool) env('ALERT_A_ENABLE_RS_FILTER', false),
        ],
        'b' => [
            'use_redis' => (bool) env('TRADING_PIPELINE_B_USE_REDIS', false),
            'ignore_types' => array_filter(array_map('trim', explode(',', env('ALERT_B_IGNORE_TYPES') ?? ''))),
            'no_filter_finder' => (bool) env('ALERT_B_NO_FILTER_FINDER', false),
            'enable_rs_filter' => (bool) env('ALERT_B_ENABLE_RS_FILTER', false),
        ],
        'c' => [
            'use_redis' => (bool) env('TRADING_PIPELINE_C_USE_REDIS', false),
            'ignore_types' => array_filter(array_map('trim', explode(',', env('ALERT_C_IGNORE_TYPES') ?? ''))),
            'no_filter_finder' => (bool) env('ALERT_C_NO_FILTER_FINDER', false),
            'enable_rs_filter' => (bool) env('ALERT_C_ENABLE_RS_FILTER', false),
        ],
        'd' => [
            'use_redis' => (bool) env('TRADING_PIPELINE_D_USE_REDIS', false),
            'ignore_types' => array_filter(array_map('trim', explode(',', env('ALERT_D_IGNORE_TYPES') ?? ''))),
            'no_filter_finder' => (bool) env('ALERT_D_NO_FILTER_FINDER', false),
            'enable_rs_filter' => (bool) env('ALERT_D_ENABLE_RS_FILTER', false),
        ],
        'e' => [
            'use_redis' => (bool) env('TRADING_PIPELINE_E_USE_REDIS', false),
            'ignore_types' => array_filter(array_map('trim', explode(',', env('ALERT_E_IGNORE_TYPES') ?? ''))),
            'no_filter_finder' => (bool) env('ALERT_E_NO_FILTER_FINDER', false),
            'ml_prefilter' => (bool) env('ALERT_E_ML_PREFILTER', false),
            'enable_rs_filter' => (bool) env('ALERT_E_ENABLE_RS_FILTER', false),
        ],
        'f' => [
            'use_redis' => (bool) env('TRADING_PIPELINE_F_USE_REDIS', false),
            'ignore_types' => array_filter(array_map('trim', explode(',', env('ALERT_F_IGNORE_TYPES') ?? ''))),
            'no_filter_finder' => (bool) env('ALERT_F_NO_FILTER_FINDER', false),
            'enable_rs_filter' => (bool) env('ALERT_F_ENABLE_RS_FILTER', false),
        ],
        'g' => [
            'use_redis' => (bool) env('TRADING_PIPELINE_G_USE_REDIS', false),
            'ignore_types' => array_filter(array_map('trim', explode(',', env('ALERT_G_IGNORE_TYPES') ?? ''))),
            'no_filter_finder' => (bool) env('ALERT_G_NO_FILTER_FINDER', false),
            'enable_rs_filter' => (bool) env('ALERT_G_ENABLE_RS_FILTER', false),
        ],
        'h' => [
            'use_redis' => (bool) env('TRADING_PIPELINE_H_USE_REDIS', false),
            'ignore_types' => array_filter(array_map('trim', explode(',', env('ALERT_H_IGNORE_TYPES') ?? ''))),
            'no_filter_finder' => (bool) env('ALERT_H_NO_FILTER_FINDER', false),
            'enable_rs_filter' => (bool) env('ALERT_H_ENABLE_RS_FILTER', false),
        ],
        'i' => [
            'use_redis' => (bool) env('TRADING_PIPELINE_I_USE_REDIS', false),
            'ignore_types' => array_filter(array_map('trim', explode(',', env('ALERT_I_IGNORE_TYPES') ?? ''))),
            'no_filter_finder' => (bool) env('ALERT_I_NO_FILTER_FINDER', false),
            'enable_rs_filter' => (bool) env('ALERT_I_ENABLE_RS_FILTER', false),
        ],
        'j' => [
            'use_redis' => (bool) env('TRADING_PIPELINE_J_USE_REDIS', false),
            'ignore_types' => array_filter(array_map('trim', explode(',', env('ALERT_J_IGNORE_TYPES') ?? ''))),
            'no_filter_finder' => (bool) env('ALERT_J_NO_FILTER_FINDER', false),
            'enable_rs_filter' => (bool) env('ALERT_J_ENABLE_RS_FILTER', false),
        ],
        'k' => [
            'use_redis' => (bool) env('TRADING_PIPELINE_K_USE_REDIS', false),
            'ignore_types' => array_filter(array_map('trim', explode(',', env('ALERT_K_IGNORE_TYPES') ?? ''))),
            'no_filter_finder' => (bool) env('ALERT_K_NO_FILTER_FINDER', false),
            'enable_rs_filter' => (bool) env('ALERT_K_ENABLE_RS_FILTER', false),
        ],
        'l' => [
            'use_redis' => (bool) env('TRADING_PIPELINE_L_USE_REDIS', false),
            'ignore_types' => array_filter(array_map('trim', explode(',', env('ALERT_L_IGNORE_TYPES') ?? ''))),
            'no_filter_finder' => (bool) env('ALERT_L_NO_FILTER_FINDER', false),
            'enable_rs_filter' => (bool) env('ALERT_L_ENABLE_RS_FILTER', false),
        ],
        'm' => [
            'use_redis' => (bool) env('TRADING_PIPELINE_M_USE_REDIS', false),
            'ignore_types' => array_filter(array_map('trim', explode(',', env('ALERT_M_IGNORE_TYPES') ?? ''))),
            'no_filter_finder' => (bool) env('ALERT_M_NO_FILTER_FINDER', false),
            'enable_rs_filter' => (bool) env('ALERT_M_ENABLE_RS_FILTER', false),
        ],
        'n' => [
            'use_redis' => (bool) env('TRADING_PIPELINE_N_USE_REDIS', false),
            'ignore_types' => array_filter(array_map('trim', explode(',', env('ALERT_N_IGNORE_TYPES') ?? ''))),
            'no_filter_finder' => (bool) env('ALERT_N_NO_FILTER_FINDER', false),
            'enable_rs_filter' => (bool) env('ALERT_N_ENABLE_RS_FILTER', false),
        ],
        'o' => [
            'use_redis' => (bool) env('TRADING_PIPELINE_O_USE_REDIS', false),
        ],
        'p' => [
            'use_redis' => (bool) env('TRADING_PIPELINE_P_USE_REDIS', false),
            'ignore_types' => array_filter(array_map('trim', explode(',', env('ALERT_P_IGNORE_TYPES') ?? ''))),
            'no_filter_finder' => (bool) env('ALERT_P_NO_FILTER_FINDER', false),
            'enable_rs_filter' => (bool) env('ALERT_P_ENABLE_RS_FILTER', false),
        ],
        'q' => [
            'use_redis' => (bool) env('TRADING_PIPELINE_Q_USE_REDIS', false),
            'ignore_types' => array_filter(array_map('trim', explode(',', env('ALERT_Q_IGNORE_TYPES') ?? ''))),
            'no_filter_finder' => (bool) env('ALERT_Q_NO_FILTER_FINDER', false),
            'enable_rs_filter' => (bool) env('ALERT_Q_ENABLE_RS_FILTER', false),
        ],
        'r' => [
            'use_redis' => (bool) env('TRADING_PIPELINE_R_USE_REDIS', false),
            'ignore_types' => array_filter(array_map('trim', explode(',', env('ALERT_R_IGNORE_TYPES') ?? ''))),
            'no_filter_finder' => (bool) env('ALERT_R_NO_FILTER_FINDER', false),
            'enable_rs_filter' => (bool) env('ALERT_R_ENABLE_RS_FILTER', false),
        ],
        's' => [
            'use_redis' => (bool) env('TRADING_PIPELINE_S_USE_REDIS', false),
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

    // v810 configuration moved to scanner class properties — see scanConfig()
    // v820 configuration moved to scanner class properties — see scanConfig()
    // v830 configuration moved to scanner class properties — see scanConfig()
    // v1100 configuration moved to scanner class properties — see scanConfig()
    // v1400 configuration moved to scanner class properties — see scanConfig()
    // v1600_2 configuration moved to scanner class properties — see scanConfig()

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

        // Per-pipeline ML threshold overrides (DB-only via TradingSettingService — env configs removed)
        'ml_threshold_pipeline_a' => null,
        'ml_threshold_pipeline_b' => null,
        'ml_threshold_pipeline_c' => null,
        'ml_threshold_pipeline_d' => null,
        'ml_threshold_pipeline_f' => null,
        'ml_threshold_pipeline_g' => null,
        'ml_threshold_pipeline_h' => null,
        'ml_threshold_pipeline_i' => null,
        'ml_threshold_pipeline_j' => null,
        'ml_threshold_pipeline_k' => null,
        'ml_threshold_pipeline_n' => null,
        'ml_threshold_pipeline_o' => null,
        'ml_threshold_pipeline_p' => null,

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
        'stop_loss_retry_step_pct' => (float) env('TRADING_STOP_LOSS_RETRY_STEP_PCT', 0.15),  // 0.15% per retry step

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
