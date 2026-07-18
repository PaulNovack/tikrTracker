<?php

use App\Services\Trading\FiveMinuteSignalScannerV700_0;

beforeEach(function () {
    $this->scanner = app(FiveMinuteSignalScannerV700_0::class);
});

it('returns VWAP rejection short signals', function () {
    $signals = $this->scanner->scan(
        assetType: 'stock',
        asOfTsEst: '2026-02-05 14:30:00',
        lookbackMinutes: 60,
        minMovePct: -0.5,
        volMult: 1.0,
        limit: 30
    );

    expect($signals)->toBeArray();

    if (! empty($signals)) {
        // Check signal structure
        expect($signals[0])
            ->toHaveKey('symbol')
            ->toHaveKey('asset_type')
            ->toHaveKey('signal_type', 'VWAP_REJECTION_SHORT')
            ->toHaveKey('signal_ts_est')
            ->toHaveKey('score')
            ->toHaveKey('meta');

        // Check meta includes V700 specific fields
        expect($signals[0]['meta'])
            ->toHaveKey('version', 'v700.0')
            ->toHaveKey('goal', 'VWAP rejection short entries')
            ->toHaveKey('rejection_wick_pct')
            ->toHaveKey('vol_ratio')
            ->toHaveKey('vwap_dist_pct')
            ->toHaveKey('bars_below_vwap');
    }
});

it('returns empty array when no weak stocks found', function () {
    // Use a timestamp far in the past where we likely have no data
    $signals = $this->scanner->scan(
        assetType: 'stock',
        asOfTsEst: '2020-01-01 10:00:00',
        lookbackMinutes: 60,
        minMovePct: -0.5,
        volMult: 1.0,
        limit: 30
    );

    expect($signals)->toBeArray()->toBeEmpty();
});

it('has correct version', function () {
    expect($this->scanner->getVersion())->toBe('v700.0');
});

it('filters by score thresholds', function () {
    $signals = $this->scanner->scan(
        assetType: 'stock',
        asOfTsEst: '2026-02-05 14:30:00',
        lookbackMinutes: 60,
        minMovePct: -0.5,
        volMult: 1.0,
        limit: 30
    );

    if (! empty($signals)) {
        foreach ($signals as $signal) {
            $score = $signal['score'];
            $minScore = config('trading.v700.entry_score_min', 80);
            $maxScore = config('trading.v700.entry_score_max', 100);

            expect($score)->toBeGreaterThanOrEqual($minScore);
            expect($score)->toBeLessThanOrEqual($maxScore);
        }
    }

    expect($signals)->toBeArray();
});
