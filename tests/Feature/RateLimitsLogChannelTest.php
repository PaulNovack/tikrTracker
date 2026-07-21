<?php

use Illuminate\Support\Facades\Log;

it('writes to the rate-limits log file', function () {
    Log::channel('rate-limits')->warning('rate limits test');

    $path = storage_path('logs/laravel-rate-limits-'.now()->format('Y-m-d').'.log');

    expect(file_exists($path))->toBeTrue();
});
