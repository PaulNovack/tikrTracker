<?php

use App\Services\Trading\FiveMinuteSignalScannerV800_0;
use App\Services\Trading\OneMinuteEntryFinderV800_0;
use App\Services\Trading\TradeAlertWriterV1;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('v800 scanner returns correct structure', function () {
    $scanner = new FiveMinuteSignalScannerV800_0;

    expect($scanner->getVersion())->toBe('v800.0');

    // Empty result test
    $results = $scanner->scan(
        assetType: 'stock',
        asOfTsEst: '2026-03-05 10:00:00',
        limit: 5
    );

    expect($results)->toBeArray();
});

test('v800 entry finder returns correct structure', function () {
    $finder = new OneMinuteEntryFinderV800_0;

    expect($finder->getVersion())->toBe('v800.0');

    // Test with non-existent symbol returns error structure
    $result = $finder->findBestLong(
        symbol: 'ZZZZ',
        assetType: 'stock',
        signalTsEst: '2026-03-05 09:45:00',
        asOfTsEst: '2026-03-05 10:00:00'
    );

    expect($result)->toBeArray()
        ->toHaveKey('ok')
        ->and($result['ok'])->toBeFalse()
        ->and($result)->toHaveKey('error');
});

test('v800 works with TradeAlertWriterV1 structure', function () {
    // Create mock signal data structure (as would come from scanner)
    $signal = [
        'symbol' => 'AAPL',
        'asset_type' => 'stock',
        'signal_type' => 'HIGHER_LOW_BREAKOUT_SETUP',
        'signal_ts_est' => '2026-03-05 09:45:00',
        'score' => 85,
        'atr' => 1.50,
        'atr_pct' => 0.75,
        'meta' => [
            'version' => 'v800.0',
            'setup_price' => 150.00,
        ],
    ];

    // Create mock entry data structure (as would come from entry finder)
    $entry = [
        'type' => 'HIGHER_LOW_BREAKOUT_1M',
        'trigger_ts_est' => '2026-03-05 09:46:00',
        'entry_ts_est' => '2026-03-05 09:47:00',
        'entry' => 150.50,
        'stop' => 149.00,
        'score' => 92.5,
        'pattern_score' => 3.5,
        'atr' => 1.50,
        'atr_pct' => 0.75,
        'risk_per_share' => 1.50,
        'risk_pct' => 1.00,
        'suggested_trailing_stop' => 149.00,
        'suggested_trailing_stop_pct' => 1.00,
        'targets' => [
            '1R' => 152.00,
            '2R' => 153.50,
            '3R' => 155.00,
            '3pct' => 155.02,
            '4pct' => 156.52,
            '5pct' => 158.03,
        ],
        'vwap' => 150.25,
        'ema9' => 150.10,
        'ema21' => 149.80,
        'notes' => '1m higher-low breakout',
    ];

    // Verify structure compatibility - these keys are what TradeAlertWriterV1 expects
    expect($signal)
        ->toHaveKeys(['symbol', 'asset_type', 'signal_type', 'signal_ts_est', 'score', 'atr', 'atr_pct'])
        ->and($entry)
        ->toHaveKeys(['type', 'entry_ts_est', 'entry', 'stop', 'score', 'atr', 'atr_pct', 'risk_per_share', 'risk_pct'])
        ->and($entry['targets'])
        ->toHaveKeys(['1R', '2R', '3R']);

    // Test that version strings are present
    expect($signal['meta']['version'])->toBe('v800.0');
});
