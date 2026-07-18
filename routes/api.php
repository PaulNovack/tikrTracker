<?php

use App\Http\Controllers\Internal\CppTradeSignalController;
use App\Http\Controllers\Internal\MarketDataIngestController;
use Illuminate\Support\Facades\Route;

Route::post('/internal/cpp-trade-signal', CppTradeSignalController::class)
    ->name('internal.cpp-trade-signal');

Route::post('/internal/market-data/quote', [MarketDataIngestController::class, 'quote']);
Route::post('/internal/market-data/partial-1m', [MarketDataIngestController::class, 'partialOneMinuteBar']);

// External Application Buy API — authenticated via ?token= query param
Route::post('/external/buy', \App\Http\Controllers\Api\ExternalBuyController::class)
    ->name('api.external.buy');
