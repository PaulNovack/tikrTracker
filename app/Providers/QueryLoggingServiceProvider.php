<?php

namespace App\Providers;

use App\Services\QueryLogger;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\ServiceProvider;

class QueryLoggingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(QueryLogger::class, function () {
            return new QueryLogger;
        });
    }

    public function boot(): void
    {
        $this->app['events']->listen(QueryExecuted::class, QueryLogger::class);
    }
}
