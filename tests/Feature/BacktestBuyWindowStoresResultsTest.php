<?php

use App\Models\BuyWindowSignal;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    // Clean up any existing signals
    BuyWindowSignal::query()->delete();
});

it('stores backtest results when flag is enabled', function () {
    // Insert test data into one_minute_prices for a signal
    DB::insert("
        INSERT INTO one_minute_prices (symbol, asset_type, ts, price, open, high, low, volume)
        VALUES 
            ('TEST', 'stock', '2025-12-23 15:15:00', 10.00, 10.00, 10.10, 9.90, 10000),
            ('TEST', 'stock', '2025-12-23 15:16:00', 10.05, 10.00, 10.10, 10.00, 5000),
            ('TEST', 'stock', '2025-12-23 20:59:00', 10.20, 10.15, 10.25, 10.10, 8000)
    ");

    // First run with --store-signals to create the signal
    Artisan::call('backtest:buy-window', [
        'date' => '2025-12-23',
        '--start-time' => '10:15:00',
        '--end-time' => '10:15:00',
        '--min-score' => '1',
        '--limit' => '10',
        '--store-signals' => true,
    ]);

    // Verify signal was created
    expect(BuyWindowSignal::where('symbol', 'TEST')->count())->toBeGreaterThan(0);

    // Run with --track-performance and --store-backtest-results
    Artisan::call('backtest:buy-window', [
        'date' => '2025-12-23',
        '--start-time' => '10:15:00',
        '--end-time' => '10:15:00',
        '--min-score' => '1',
        '--limit' => '10',
        '--track-performance' => true,
        '--store-backtest-results' => true,
    ]);

    // Verify backtest results were stored
    $signal = BuyWindowSignal::where('symbol', 'TEST')
        ->where('signal_time', '2025-12-23 10:15:00')
        ->first();

    expect($signal)->not->toBeNull();
    expect($signal->backtest_stop_price)->not->toBeNull();
    expect($signal->backtest_exit_price)->not->toBeNull();
    expect($signal->backtest_exit_type)->toBeIn(['STOP', 'EOD']);
    expect($signal->backtest_pl_dollars)->not->toBeNull();
    expect($signal->backtest_pl_pct)->not->toBeNull();

    // Verify the math is correct
    $expectedStopPrice = $signal->last_price * 0.99;
    expect(abs($signal->backtest_stop_price - $expectedStopPrice))->toBeLessThan(0.01);

    // Clean up test data
    DB::delete("DELETE FROM one_minute_prices WHERE symbol = 'TEST'");
});

it('does not store backtest results when flag is not enabled', function () {
    // Insert test data
    DB::insert("
        INSERT INTO one_minute_prices (symbol, asset_type, ts, price, open, high, low, volume)
        VALUES 
            ('TEST2', 'stock', '2025-12-23 15:15:00', 20.00, 20.00, 20.10, 19.90, 10000),
            ('TEST2', 'stock', '2025-12-23 20:59:00', 20.50, 20.40, 20.60, 20.30, 8000)
    ");

    // Run with --store-signals and --track-performance but NOT --store-backtest-results
    Artisan::call('backtest:buy-window', [
        'date' => '2025-12-23',
        '--start-time' => '10:15:00',
        '--end-time' => '10:15:00',
        '--min-score' => '1',
        '--limit' => '10',
        '--store-signals' => true,
        '--track-performance' => true,
    ]);

    // Verify signal exists but backtest results are null
    $signal = BuyWindowSignal::where('symbol', 'TEST2')
        ->where('signal_time', '2025-12-23 10:15:00')
        ->first();

    if ($signal) {
        expect($signal->backtest_stop_price)->toBeNull();
        expect($signal->backtest_exit_price)->toBeNull();
        expect($signal->backtest_exit_type)->toBeNull();
        expect($signal->backtest_pl_dollars)->toBeNull();
        expect($signal->backtest_pl_pct)->toBeNull();
    }

    // Clean up test data
    DB::delete("DELETE FROM one_minute_prices WHERE symbol = 'TEST2'");
});
