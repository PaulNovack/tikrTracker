<?php

use App\Listeners\PlaceAlpacaOrderForHighScoreAlerts;
use App\Models\AlpacaOrder;
use App\Services\AlpacaPythonService;
use Illuminate\Support\Facades\DB;

function callSymbolRetradeCooldownData(
    PlaceAlpacaOrderForHighScoreAlerts $listener,
    string $symbol,
    int $waitMinutes,
): ?array {
    $reflection = new ReflectionClass($listener);
    $method = $reflection->getMethod('getSymbolRetradeCooldownData');
    $method->setAccessible(true);

    return $method->invoke($listener, $symbol, now('UTC'), $waitMinutes);
}

it('treats zero wait as same-day only retrading', function () {
    DB::table('alpaca_orders')->where('symbol', 'TEST')->delete();

    AlpacaOrder::create([
        'symbol' => 'TEST',
        'qty' => 10,
        'side' => 'buy',
        'order_type' => 'market',
        'status' => 'filled',
        'client_order_id' => 'order-zero-'.uniqid(),
        'alpaca_order_id' => 'alpaca-zero-'.uniqid(),
        'paper' => true,
        'created_at' => now('UTC')->subMinutes(30),
        'updated_at' => now('UTC')->subMinutes(30),
    ]);

    $listener = new PlaceAlpacaOrderForHighScoreAlerts(new AlpacaPythonService);

    $cooldownData = callSymbolRetradeCooldownData($listener, 'TEST', 0);

    expect($cooldownData)->not->toBeNull();
    expect($cooldownData['wait_minutes'])->toBe(0);
    expect($cooldownData['minutes_since_last_buy'])->toBe(30);
});

it('blocks retrades until the configured wait window expires', function () {
    DB::table('alpaca_orders')->where('symbol', 'TEST')->delete();

    AlpacaOrder::create([
        'symbol' => 'TEST',
        'qty' => 10,
        'side' => 'buy',
        'order_type' => 'market',
        'status' => 'filled',
        'client_order_id' => 'order-wait-'.uniqid(),
        'alpaca_order_id' => 'alpaca-wait-'.uniqid(),
        'paper' => true,
        'created_at' => now('UTC')->subMinutes(30),
        'updated_at' => now('UTC')->subMinutes(30),
    ]);

    $listener = new PlaceAlpacaOrderForHighScoreAlerts(new AlpacaPythonService);

    $cooldownData = callSymbolRetradeCooldownData($listener, 'TEST', 60);

    expect($cooldownData)->not->toBeNull();
    expect($cooldownData['wait_minutes'])->toBe(60);
    expect($cooldownData['minutes_since_last_buy'])->toBe(30);
});

it('allows retrades after the configured wait window has passed', function () {
    DB::table('alpaca_orders')->where('symbol', 'TEST')->delete();

    AlpacaOrder::create([
        'symbol' => 'TEST',
        'qty' => 10,
        'side' => 'buy',
        'order_type' => 'market',
        'status' => 'filled',
        'client_order_id' => 'order-old-'.uniqid(),
        'alpaca_order_id' => 'alpaca-old-'.uniqid(),
        'paper' => true,
        'created_at' => now('UTC')->subMinutes(61),
        'updated_at' => now('UTC')->subMinutes(61),
    ]);

    $listener = new PlaceAlpacaOrderForHighScoreAlerts(new AlpacaPythonService);

    $cooldownData = callSymbolRetradeCooldownData($listener, 'TEST', 60);

    expect($cooldownData)->toBeNull();
});
