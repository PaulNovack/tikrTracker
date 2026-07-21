<?php

namespace App\Providers;

use App\Services\Webull\WebullClient;
use App\Services\Webull\WebullTradingService;
use Illuminate\Support\ServiceProvider;

class WebullServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(WebullClient::class, function () {
            return new WebullClient(
                baseUrl: config('webull.base_url'),
                appKey: config('webull.app_key'),
                appSecret: config('webull.app_secret'),
                region: config('webull.region'),
            );
        });

        $this->app->singleton(WebullTradingService::class, function ($app) {
            return new WebullTradingService(
                client: $app->make(WebullClient::class),
                accountId: config('webull.account_id'),
                defaultTif: config('webull.defaults.tif'),
                defaultExtendedHours: (bool) config('webull.defaults.extended_hours'),
                instrumentCategory: config('webull.defaults.instrument_category'),
            );
        });
    }
}
