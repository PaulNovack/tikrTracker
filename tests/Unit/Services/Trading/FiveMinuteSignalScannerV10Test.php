<?php

use App\Services\Trading\FiveMinuteSignalScannerV10;

beforeEach(function () {
    $this->scanner = app(FiveMinuteSignalScannerV10::class);
});

it('returns signals filtered by top performers', function () {
    $signals = $this->scanner->scan(
        assetType: 'stock',
        asOfTsEst: '2025-12-23 14:30:00',
        lookbackMinutes: 60,
        minMovePct: 0.6,
        volMult: 1.5,
        limit: 40
    );

    expect($signals)->toBeArray();

    if (! empty($signals)) {
        // Check signal structure
        expect($signals[0])
            ->toHaveKey('symbol')
            ->toHaveKey('asset_type')
            ->toHaveKey('signal_type', 'MOMO_5M')
            ->toHaveKey('signal_ts_est')
            ->toHaveKey('score')
            ->toHaveKey('meta');

        // Check meta includes new universe filtering fields
        expect($signals[0]['meta'])
            ->toHaveKey('universe_filtered', true)
            ->toHaveKey('universe_size')
            ->toHaveKey('pct_7d');
    }
});

it('returns empty array when no top performers found', function () {
    // Use a timestamp far in the past where we likely have no data
    $signals = $this->scanner->scan(
        assetType: 'stock',
        asOfTsEst: '2020-01-01 10:00:00',
        lookbackMinutes: 60,
        minMovePct: 0.6,
        volMult: 1.5,
        limit: 40
    );

    expect($signals)->toBeArray()->toBeEmpty();
});

it('has correct version', function () {
    expect($this->scanner->getVersion())->toBe('v10');
});
