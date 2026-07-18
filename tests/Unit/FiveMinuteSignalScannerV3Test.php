<?php

use App\Services\Trading\FiveMinuteSignalScannerV3;

beforeEach(function () {
    $this->scanner = app(FiveMinuteSignalScannerV3::class);
});

it('returns signals at early morning with adaptive lookback', function () {
    // At 09:40 with 60 min lookback, scanner needs data from 08:40 (pre-market)
    // Adaptive lookback should extend to previous day's afternoon session
    $results = $this->scanner->scan('stock', '2025-12-04 09:40:00', 60, 0.5, 1.5, 50, 0.0, true, false);

    expect($results)->toBeArray()
        ->and(count($results))->toBeGreaterThan(0)
        ->and($results[0])->toHaveKeys(['symbol', 'asset_type', 'signal_type', 'signal_ts_est', 'score', 'meta']);
})->skip('Requires production database with five_minute_prices data');

it('returns signals at mid-day without needing previous day', function () {
    // At 10:30 with 60 min lookback, all data available from current day
    $results = $this->scanner->scan('stock', '2025-12-04 10:30:00', 60, 0.5, 1.5, 50, 0.0, true, false);

    expect($results)->toBeArray()
        ->and(count($results))->toBeGreaterThan(0);
})->skip('Requires production database with five_minute_prices data');

it('filters using eligible_symbols_daily when enabled', function () {
    $withElig = $this->scanner->scan('stock', '2025-12-04 09:40:00', 60, 0.5, 1.5, 50, 0.0, true, true);
    $noElig = $this->scanner->scan('stock', '2025-12-04 09:40:00', 60, 0.5, 1.5, 50, 0.0, true, false);

    expect(count($withElig))->toBeLessThan(count($noElig))
        ->and(count($withElig))->toBeGreaterThan(0)
        ->and(count($noElig))->toBeGreaterThan(0);
})->skip('Requires production database with five_minute_prices and eligible_symbols_daily data');

it('returns proper signal structure', function () {
    $results = $this->scanner->scan('stock', '2025-12-04 10:30:00', 60, 0.5, 1.5, 5, 0.0, true, false);

    expect($results)->toBeArray();

    if (count($results) > 0) {
        $signal = $results[0];
        expect($signal)->toHaveKeys(['symbol', 'asset_type', 'signal_type', 'signal_ts_est', 'score', 'meta'])
            ->and($signal['signal_type'])->toBe('MOMO_5M')
            ->and($signal['asset_type'])->toBe('stock')
            ->and($signal['meta'])->toHaveKeys(['move_pct', 'vol_ratio', 'last_close', 'prev_close', 'last3_vol']);
    }
})->skip('Requires production database with five_minute_prices data');
