<?php

$mode = strtoupper(env('WEBULL_MODE', 'DEV'));
$prefix = $mode === 'PROD' ? 'PROD_WEBULL_' : 'DEV_WEBULL_';

return [
    'mode' => $mode,
    'app_key' => env($prefix.'APP_KEY'),
    'app_secret' => env($prefix.'APP_SECRET'),
    'region' => env('WEBULL_REGION', 'US'),

    'base_url' => env($prefix.'BASE_URL', 'https://api.webull.com'),
    'account_id' => env($prefix.'ACCOUNT_ID'),

    'defaults' => [
        'tif' => env('WEBULL_DEFAULT_TIF', 'DAY'),
        'extended_hours' => filter_var(env('WEBULL_EXTENDED_HOURS', 'false'), FILTER_VALIDATE_BOOL),
        'instrument_category' => env('WEBULL_INSTRUMENT_CATEGORY', 'US_STOCK'),
    ],
];
