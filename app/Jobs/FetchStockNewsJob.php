<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;

class FetchStockNewsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public function __construct(private readonly string $symbol) {}

    public function handle(): void
    {
        Log::info("FetchStockNewsJob: fetching news for {$this->symbol}");

        Artisan::call('news:fetch-stock', [
            'symbol' => $this->symbol,
            '--no-article-text' => true,
        ]);
    }
}
