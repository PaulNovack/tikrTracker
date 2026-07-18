<?php

use App\Models\AlpacaOrder;
use Illuminate\Console\OutputStyle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\Console\Input\StringInput;
use Symfony\Component\Console\Output\NullOutput;

uses(RefreshDatabase::class);

// ─── Helpers ──────────────────────────────────────────────────────────────────

/**
 * Create a paired buy + stop-loss sell for testing P&L calculations.
 *
 * @param  array{date: string, buy_price: float, sell_price: float, qty: float, is_paper: bool}  $attrs
 */
function makeTrade(array $attrs): void
{
    $alpacaOrderId = fake()->uuid();

    AlpacaOrder::factory()->create([
        'alpaca_order_id' => $alpacaOrderId,
        'side' => 'buy',
        'status' => 'filled',
        'filled_avg_price' => $attrs['buy_price'],
        'filled_qty' => $attrs['qty'],
        'is_paper' => $attrs['is_paper'],
        'filled_at' => $attrs['date'].' 14:00:00',
        'order_type' => 'market',
    ]);

    AlpacaOrder::factory()->create([
        'parent_alpaca_order_id' => $alpacaOrderId,
        'side' => 'sell',
        'order_type' => 'stop',
        'status' => 'filled',
        'filled_avg_price' => $attrs['sell_price'],
        'filled_qty' => $attrs['qty'],
        'is_paper' => $attrs['is_paper'],
        'filled_at' => $attrs['date'].' 15:00:00',
    ]);
}

// ─── isCurrentlyPaper ─────────────────────────────────────────────────────────

it('detects paper mode from .secret contents', function () {
    $secretPath = tempnam(sys_get_temp_dir(), 'secret_');
    file_put_contents($secretPath, "ALPACA_PAPER_TRADING=true\n");

    $command = new \App\Console\Commands\TradingAutoRiskCheck;
    $ref = new ReflectionProperty($command, 'secretPath');
    $ref->setValue($command, $secretPath);

    $method = new ReflectionMethod($command, 'isCurrentlyPaper');
    expect($method->invoke($command))->toBeTrue();

    unlink($secretPath);
});

it('detects live mode from .secret contents', function () {
    $secretPath = tempnam(sys_get_temp_dir(), 'secret_');
    file_put_contents($secretPath, "ALPACA_PAPER_TRADING=false\n");

    $command = new \App\Console\Commands\TradingAutoRiskCheck;
    $ref = new ReflectionProperty($command, 'secretPath');
    $ref->setValue($command, $secretPath);

    $method = new ReflectionMethod($command, 'isCurrentlyPaper');
    expect($method->invoke($command))->toBeFalse();

    unlink($secretPath);
});

// ─── calculateDayPL ───────────────────────────────────────────────────────────

it('calculates live day P&L correctly', function () {
    makeTrade(['date' => today()->toDateString(), 'buy_price' => 100, 'sell_price' => 90, 'qty' => 10, 'is_paper' => false]);
    makeTrade(['date' => today()->toDateString(), 'buy_price' => 100, 'sell_price' => 115, 'qty' => 10, 'is_paper' => false]);

    $command = new \App\Console\Commands\TradingAutoRiskCheck;
    $method = new ReflectionMethod($command, 'calculateDayPL');

    $pl = $method->invoke($command, today(), false);
    expect($pl)->toBe(50.0); // (-100) + (+150) = +50
});

it('excludes paper trades from live P&L calculation', function () {
    makeTrade(['date' => today()->toDateString(), 'buy_price' => 100, 'sell_price' => 80, 'qty' => 10, 'is_paper' => true]);
    makeTrade(['date' => today()->toDateString(), 'buy_price' => 100, 'sell_price' => 110, 'qty' => 10, 'is_paper' => false]);

    $command = new \App\Console\Commands\TradingAutoRiskCheck;
    $method = new ReflectionMethod($command, 'calculateDayPL');

    $pl = $method->invoke($command, today(), false);
    expect($pl)->toBe(100.0); // Only the live +$100 win
});

it('calculates paper day P&L correctly', function () {
    makeTrade(['date' => today()->toDateString(), 'buy_price' => 100, 'sell_price' => 80, 'qty' => 10, 'is_paper' => true]);
    makeTrade(['date' => today()->toDateString(), 'buy_price' => 100, 'sell_price' => 110, 'qty' => 10, 'is_paper' => false]);

    $command = new \App\Console\Commands\TradingAutoRiskCheck;
    $method = new ReflectionMethod($command, 'calculateDayPL');

    $pl = $method->invoke($command, today(), true);
    expect($pl)->toBe(-200.0); // Only the paper -$200 loss
});

// ─── getLiveLosingStreak ──────────────────────────────────────────────────────

it('breaks streak on a profitable day', function () {
    // Yesterday: profit (breaks any streak)
    makeTrade(['date' => today()->subDay()->toDateString(), 'buy_price' => 100, 'sell_price' => 120, 'qty' => 10, 'is_paper' => false]);
    // 2 days ago: loss
    makeTrade(['date' => today()->subDays(2)->toDateString(), 'buy_price' => 100, 'sell_price' => 80, 'qty' => 10, 'is_paper' => false]);

    $command = new \App\Console\Commands\TradingAutoRiskCheck;
    $method = new ReflectionMethod($command, 'getLiveLosingStreak');

    expect($method->invoke($command))->toBe(0);
});

it('counts consecutive losing days correctly', function () {
    $days = collect([1, 2, 3])->filter(fn ($d) => ! today()->subDays($d)->isWeekend());

    foreach ($days as $daysAgo) {
        makeTrade(['date' => today()->subDays($daysAgo)->toDateString(), 'buy_price' => 100, 'sell_price' => 90, 'qty' => 10, 'is_paper' => false]);
    }

    $command = new \App\Console\Commands\TradingAutoRiskCheck;
    $method = new ReflectionMethod($command, 'getLiveLosingStreak');

    expect($method->invoke($command))->toBeGreaterThanOrEqual($days->count());
});

// ─── switchToPaper / switchToLive ─────────────────────────────────────────────

it('rewrites .secret to paper mode correctly', function () {
    $secretPath = tempnam(sys_get_temp_dir(), 'secret_');
    file_put_contents($secretPath, implode("\n", [
        'ALPACA_KEY_ID=AKI2VA2HCFAOH4SQZQFW6JMYRD',
        'ALPACA_SECRET_KEY=Ad4oCNXcR8qfQp53acW7bfAMqn3eNiUFVNvqhzG72dmq',
        'ALPACA_PAPER_TRADING=false',
        '#ALPACA_KEY_ID=PKUCUJYIO5PWCXJBGPAHWVSRF4',
        '#ALPACA_SECRET_KEY=GJQcSu2X9Zb6NFAVmr1nRCCjx1Bc3TJtRCeaA2A16HJV',
        '#ALPACA_PAPER_TRADING=true',
        'ALPACA_API_KEY=AKI2VA2HCFAOH4SQZQFW6JMYRD',
        'ALPACA_API_SECRET=Ad4oCNXcR8qfQp53acW7bfAMqn3eNiUFVNvqhzG72dmq',
        '#ALPACA_API_KEY=PKUCUJYIO5PWCXJBGPAHWVSRF4',
        '#ALPACA_API_SECRET=GJQcSu2X9Zb6NFAVmr1nRCCjx1Bc3TJtRCeaA2A16HJV',
    ]));

    $command = new \App\Console\Commands\TradingAutoRiskCheck;
    $command->setOutput(new OutputStyle(new StringInput(''), new NullOutput));
    $ref = new ReflectionProperty($command, 'secretPath');
    $ref->setValue($command, $secretPath);

    $method = new ReflectionMethod($command, 'switchToPaper');
    $method->invoke($command, 'Test reason', false);

    $contents = file_get_contents($secretPath);

    expect($contents)
        ->toContain('ALPACA_PAPER_TRADING=true')
        ->toContain('#ALPACA_PAPER_TRADING=false')
        ->toContain('ALPACA_KEY_ID=PKUCUJYIO5PWCXJBGPAHWVSRF4')
        ->toContain('#ALPACA_KEY_ID=AKI2VA2HCFAOH4SQZQFW6JMYRD');

    unlink($secretPath);
});

it('rewrites .secret to live mode correctly', function () {
    $secretPath = tempnam(sys_get_temp_dir(), 'secret_');
    file_put_contents($secretPath, implode("\n", [
        '#ALPACA_KEY_ID=AKI2VA2HCFAOH4SQZQFW6JMYRD',
        '#ALPACA_SECRET_KEY=Ad4oCNXcR8qfQp53acW7bfAMqn3eNiUFVNvqhzG72dmq',
        '#ALPACA_PAPER_TRADING=false',
        'ALPACA_KEY_ID=PKUCUJYIO5PWCXJBGPAHWVSRF4',
        'ALPACA_SECRET_KEY=GJQcSu2X9Zb6NFAVmr1nRCCjx1Bc3TJtRCeaA2A16HJV',
        'ALPACA_PAPER_TRADING=true',
        '#ALPACA_API_KEY=AKI2VA2HCFAOH4SQZQFW6JMYRD',
        '#ALPACA_API_SECRET=Ad4oCNXcR8qfQp53acW7bfAMqn3eNiUFVNvqhzG72dmq',
        'ALPACA_API_KEY=PKUCUJYIO5PWCXJBGPAHWVSRF4',
        'ALPACA_API_SECRET=GJQcSu2X9Zb6NFAVmr1nRCCjx1Bc3TJtRCeaA2A16HJV',
    ]));

    $command = new \App\Console\Commands\TradingAutoRiskCheck;
    $command->setOutput(new OutputStyle(new StringInput(''), new NullOutput));
    $ref = new ReflectionProperty($command, 'secretPath');
    $ref->setValue($command, $secretPath);

    $method = new ReflectionMethod($command, 'switchToLive');
    $method->invoke($command, 'Test reason', false);

    $contents = file_get_contents($secretPath);

    expect($contents)
        ->toContain('ALPACA_PAPER_TRADING=false')
        ->toContain('#ALPACA_PAPER_TRADING=true')
        ->toContain('ALPACA_KEY_ID=AKI2VA2HCFAOH4SQZQFW6JMYRD')
        ->toContain('#ALPACA_KEY_ID=PKUCUJYIO5PWCXJBGPAHWVSRF4');

    unlink($secretPath);
});

it('does not modify .secret in dry-run mode', function () {
    $secretPath = tempnam(sys_get_temp_dir(), 'secret_');
    $original = "ALPACA_PAPER_TRADING=false\n";
    file_put_contents($secretPath, $original);

    $command = new \App\Console\Commands\TradingAutoRiskCheck;
    $command->setOutput(new OutputStyle(new StringInput(''), new NullOutput));
    $ref = new ReflectionProperty($command, 'secretPath');
    $ref->setValue($command, $secretPath);

    $method = new ReflectionMethod($command, 'switchToPaper');
    $method->invoke($command, 'Test reason', true); // dry-run = true

    expect(file_get_contents($secretPath))->toBe($original);

    unlink($secretPath);
});
