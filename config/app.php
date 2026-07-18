<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Application Name
    |--------------------------------------------------------------------------
    |
    | This value is the name of your application, which will be used when the
    | framework needs to place the application's name in a notification or
    | other UI elements where an application name needs to be displayed.
    |
    */

    'name' => env('APP_NAME', 'Laravel'),

    /*
    |--------------------------------------------------------------------------
    | Application Environment
    |--------------------------------------------------------------------------
    |
    | This value determines the "environment" your application is currently
    | running in. This may determine how you prefer to configure various
    | services the application utilizes. Set this in your ".env" file.
    |
    */

    'env' => env('APP_ENV', 'production'),

    /*
    |--------------------------------------------------------------------------
    | Application Debug Mode
    |--------------------------------------------------------------------------
    |
    | When your application is in debug mode, detailed error messages with
    | stack traces will be shown on every error that occurs within your
    | application. If disabled, a simple generic error page is shown.
    |
    */

    'debug' => (bool) env('APP_DEBUG', false),

    /*
    |--------------------------------------------------------------------------
    | Disable Authentication Middleware
    |--------------------------------------------------------------------------
    |
    | When this is set to true, authentication middleware will be bypassed
    | for testing purposes. This should NEVER be enabled in production.
    | Only use this for local development and testing environments.
    |
    */

    'disable_auth_middleware' => (bool) env('DISABLE_AUTH_MIDDLEWARE', false),

    /*
    |--------------------------------------------------------------------------
    | Application URL
    |--------------------------------------------------------------------------
    |
    | This URL is used by the console to properly generate URLs when using
    | the Artisan command line tool. You should set this to the root of
    | the application so that it's available within Artisan commands.
    |
    */

    'url' => env('APP_URL', 'http://localhost'),

    'force_https' => env('APP_FORCE_HTTPS', false),

    /*
    |--------------------------------------------------------------------------
    | Application Timezone
    |--------------------------------------------------------------------------
    |
    | Here you may specify the default timezone for your application, which
    | will be used by the PHP date and date-time functions. The timezone
    | is set to "UTC" by default as it is suitable for most use cases.
    |
    */

    'timezone' => 'UTC',

    /*
    |--------------------------------------------------------------------------
    | Application Locale Configuration
    |--------------------------------------------------------------------------
    |
    | The application locale determines the default locale that will be used
    | by Laravel's translation / localization methods. This option can be
    | set to any locale for which you plan to have translation strings.
    |
    */

    'locale' => env('APP_LOCALE', 'en'),

    'fallback_locale' => env('APP_FALLBACK_LOCALE', 'en'),

    'faker_locale' => env('APP_FAKER_LOCALE', 'en_US'),

    /*
    |--------------------------------------------------------------------------
    | Encryption Key
    |--------------------------------------------------------------------------
    |
    | This key is utilized by Laravel's encryption services and should be set
    | to a random, 32 character string to ensure that all encrypted values
    | are secure. You should do this prior to deploying the application.
    |
    */

    'cipher' => 'AES-256-CBC',

    'key' => env('APP_KEY'),

    'previous_keys' => [
        ...array_filter(
            explode(',', (string) env('APP_PREVIOUS_KEYS', ''))
        ),
    ],

    /*
    |--------------------------------------------------------------------------
    | Maintenance Mode Driver
    |--------------------------------------------------------------------------
    |
    | These configuration options determine the driver used to determine and
    | manage Laravel's "maintenance mode" status. The "cache" driver will
    | allow maintenance mode to be controlled across multiple machines.
    |
    | Supported drivers: "file", "cache"
    |
    */

    'maintenance' => [
        'driver' => env('APP_MAINTENANCE_DRIVER', 'file'),
        'store' => env('APP_MAINTENANCE_STORE', 'database'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Watch Limit
    |--------------------------------------------------------------------------
    |
    | Maximum number of watches a user can have to prevent memory exhaustion
    | when loading chart data.
    |
    */

    'max_watches' => env('MAX_WATCHES', 6),

    /*
    |--------------------------------------------------------------------------
    | Stagnation Analysis Settings
    |--------------------------------------------------------------------------
    |
    | Configuration for momentum decay scanning (stagnation detection).
    | Short and long lookback windows in days, and flat threshold percentage.
    |
    */

    'stagnation_short_days' => (int) env('STAGNATION_SHORT_DAYS', 1),
    'stagnation_long_days' => (int) env('STAGNATION_LONG_DAYS', 3),
    'stagnation_threshold_pct' => (float) env('STAGNATION_THRESHOLD_PCT', 1.0),
    'good_positive_pct' => (float) env('GOOD_POSITIVE_PCT', 2.5),
    'great_positive_pct' => (float) env('GREAT_POSITIVE_PCT', 5.0),
    'negative_alert_pct' => (float) env('NEGATIVE_ALERT_PCT', -1.0),

    // Watch alert default thresholds
    'watch_default_up_pct' => (float) env('WATCH_DEFAULT_UP_PCT', 2.5),
    'watch_default_down_pct' => (float) env('WATCH_DEFAULT_DOWN_PCT', 2.5),

    // YFinance 5-minute update settings
    'only_watches_5minutes' => (bool) env('ONLY_WATCHES_5MINUTES', false),

    /*
    |--------------------------------------------------------------------------
    | Trade Alert Version Filter
    |--------------------------------------------------------------------------
    |
    | Filter trade alerts by algorithm version. Set in .env as TRADE_ALERT_VERSION.
    | Leave null to show all versions.
    |
    */

    'trade_alert_version' => env('TRADE_ALERT_VERSION', null),

    /*
    |--------------------------------------------------------------------------
    | Pipeline Versions
    |--------------------------------------------------------------------------
    |
    | Pipeline A through I versions for running multiple algorithms simultaneously.
    | Set in .env as TRADE_ALERT_A_VERSION, TRADE_ALERT_B_VERSION, etc.
    |
    */

    'trade_alert_a_version' => env('TRADE_ALERT_A_VERSION', 'v17.0'),
    'trade_alert_b_version' => env('TRADE_ALERT_B_VERSION', 'v19.0'),
    'trade_alert_c_version' => env('TRADE_ALERT_C_VERSION', 'v25.0'),
    'trade_alert_d_version' => env('TRADE_ALERT_D_VERSION', 'v26.0'),
    'trade_alert_e_version' => env('TRADE_ALERT_E_VERSION', 'v80.1'),
    'trade_alert_f_version' => env('TRADE_ALERT_F_VERSION', 'v70.0'),
    'trade_alert_g_version' => env('TRADE_ALERT_G_VERSION', 'v80.1'),
    'trade_alert_h_version' => env('TRADE_ALERT_H_VERSION', 'v26.1'),
    'trade_alert_i_version' => env('TRADE_ALERT_I_VERSION', 'v60.1'),
    'trade_alert_j_version' => env('TRADE_ALERT_J_VERSION', 'v2000.0'),
    'trade_alert_k_version' => env('TRADE_ALERT_K_VERSION', 'v1100.0'),
    'trade_alert_l_version' => env('TRADE_ALERT_L_VERSION', 'v1600.0'),
    'trading_market_benchmark_symbol' => env('TRADING_MARKET_BENCHMARK_SYMBOL', 'SPY'),
    'trade_alert_m_version' => env('TRADE_ALERT_M_VERSION', 'v1.0'),
    'trade_alert_n_version' => env('TRADE_ALERT_N_VERSION', 'v1200.0'),
    'trade_alert_o_version' => env('TRADE_ALERT_O_VERSION', 'v1500.0'),
    'trade_alert_p_version' => env('TRADE_ALERT_P_VERSION', 'v3000.0'),
    'trade_alert_q_version' => env('TRADE_ALERT_Q_VERSION', 'v27.0'),
    'trade_alert_q_run_cron' => env('TRADE_ALERT_Q_RUN_CRON', true),
    'trade_alert_r_version' => env('TRADE_ALERT_R_VERSION', 'rt-v1.0'),
    'trade_alert_s_version' => env('TRADE_ALERT_S_VERSION', 'rt-vwap-reversal-v1.0'),
    'trade_alert_external_version' => env('TRADE_ALERT_EXTERNAL_VERSION', 'external'),

    /*
    |--------------------------------------------------------------------------
    | External Buy API Token
    |--------------------------------------------------------------------------
    |
    | Token used to authenticate external applications placing buy orders
    | via the POST /api/external/buy endpoint.
    |
    */

    'external_buy_api_token' => env('EXTERNAL_BUY_API_TOKEN'),

    /*
    |--------------------------------------------------------------------------
    | Pipeline Alert Ignore Types
    |--------------------------------------------------------------------------
    |
    | Comma-separated list of entry types to ignore for each pipeline.
    | Set in .env as ALERT_A_IGNORE_TYPES, ALERT_B_IGNORE_TYPES, etc.
    | Use 'NONE' to not ignore any types.
    |
    */

    'alert_a_ignore_types' => env('ALERT_A_IGNORE_TYPES', 'NONE'),
    'alert_b_ignore_types' => env('ALERT_B_IGNORE_TYPES', 'NONE'),
    'alert_c_ignore_types' => env('ALERT_C_IGNORE_TYPES', 'NONE'),
    'alert_d_ignore_types' => env('ALERT_D_IGNORE_TYPES', 'NONE'),
    'alert_e_ignore_types' => env('ALERT_E_IGNORE_TYPES', 'NONE'),

    /*
    |--------------------------------------------------------------------------
    | Pipeline Filter Finder Settings
    |--------------------------------------------------------------------------
    |
    | Controls whether to bypass filtering for entries from the 5-minute finder.
    | Set in .env as ALERT_A_NO_FILTER_FINDER, ALERT_B_NO_FILTER_FINDER, etc.
    |
    | true  = Skip filtering, show ALL entries from 5-minute finder as alerts (unfiltered)
    | false = Apply normal filtering to 5-minute finder entries (default)
    |
    */

    'alert_a_no_filter_finder' => env('ALERT_A_NO_FILTER_FINDER', false),
    'alert_b_no_filter_finder' => env('ALERT_B_NO_FILTER_FINDER', false),
    'alert_c_no_filter_finder' => env('ALERT_C_NO_FILTER_FINDER', false),
    'alert_d_no_filter_finder' => env('ALERT_D_NO_FILTER_FINDER', false),
    'alert_e_no_filter_finder' => env('ALERT_E_NO_FILTER_FINDER', false),

];
