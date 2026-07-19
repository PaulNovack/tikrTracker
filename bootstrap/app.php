<?php

// Set umask for proper file permissions in development
umask(0);

// Increase memory limit for large dataset operations (chart data, price caching)
// Set to 2GB to handle large datasets
ini_set('memory_limit', '2048M');

// Increase execution timeout for heavy data processing
ini_set('max_execution_time', 240);

// Increase input timeout for large requests
ini_set('max_input_time', 240);

// Suppress broken pipe notices in development server
// This addresses errno=32 "Broken pipe" errors from server.php
if (php_sapi_name() === 'cli-server') {
    error_reporting(E_ALL & ~E_NOTICE & ~E_WARNING);
    ini_set('display_errors', '0');
}

// Load .secret file if it exists (secrets not committed to git)
// Use createImmutable so .env takes priority — .secret only fills in missing vars
if (file_exists(dirname(__DIR__).'/.secret')) {
    $dotenv = Dotenv\Dotenv::createImmutable(dirname(__DIR__), '.secret');
    $dotenv->safeLoad();
}

use App\Http\Middleware\BeforeAfterMiddleware;
use App\Http\Middleware\CheckDisclaimerAcceptance;
use App\Http\Middleware\EnsureUserIsAdmin;
use App\Http\Middleware\HandleAppearance;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\LogTraffic;
use App\Http\Middleware\NotGuest;
use App\Http\Middleware\PreventGuestUserActions;
use App\Http\Middleware\TrustProxies;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->encryptCookies(except: ['appearance', 'sidebar_state']);

        $middleware->web(append: [
            BeforeAfterMiddleware::class,
            TrustProxies::class,
            HandleAppearance::class,
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
            LogTraffic::class,
        ]);

        $middleware->alias([
            'prevent.guest' => PreventGuestUserActions::class,
            'admin' => EnsureUserIsAdmin::class,
            'not_guest' => NotGuest::class,
            'disclaimer' => CheckDisclaimerAcceptance::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
