<?php

namespace App\Providers;

use App\Services\TradingV2\Contracts\BarSourceInterface;
use App\Services\TradingV2\Repositories\RedisBarSource;
use Illuminate\Support\ServiceProvider;

class TradingV2ServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(BarSourceInterface::class, RedisBarSource::class);
    }
}
